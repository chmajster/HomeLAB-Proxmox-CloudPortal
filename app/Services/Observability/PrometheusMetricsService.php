<?php

declare(strict_types=1);

namespace CloudPortal\Services\Observability;

use CloudPortal\Services\Provisioning\JobRepository;
use PDO;

final class PrometheusMetricsService
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function render(): string
    {
        $health = (new HealthService($this->pdo))->report();
        $jobs = (new JobRepository($this->pdo))->metrics();
        $lines = [];

        $this->gauge($lines, 'algen_cloudportal_up', 'Cloud Portal database health.', $health['ok'] ? 1 : 0);
        $this->gauge($lines, 'algen_cloudportal_ready', 'Cloud Portal readiness.', $health['ready'] ? 1 : 0);
        $this->gauge($lines, 'algen_cloudportal_worker_online', 'Whether a worker heartbeat is fresh.', $health['worker_online'] ? 1 : 0);
        $this->gauge($lines, 'algen_cloudportal_worker_age_seconds', 'Age of the latest worker heartbeat.', $health['worker_age_seconds'] ?? -1);
        $this->gauge($lines, 'algen_cloudportal_jobs_stuck', 'Running jobs older than the stuck threshold.', (int) ($health['jobs']['stuck_running'] ?? 0));

        foreach ($jobs as $status => $count) {
            $this->sample($lines, 'algen_cloudportal_jobs', ['status' => $status], (int) $count, 'gauge', 'Jobs by durable queue status.');
        }

        foreach ($this->countBy('virtual_machines', 'status') as $status => $count) {
            $this->sample($lines, 'algen_cloudportal_virtual_machines', ['status' => $status], $count, 'gauge', 'Portal virtual machines by status.');
        }
        foreach ($this->countBy('proxmox_connections', 'status') as $status => $count) {
            $this->sample($lines, 'algen_cloudportal_proxmox_connections', ['status' => $status], $count, 'gauge', 'Proxmox connections by status.');
        }
        foreach ($this->countBy('ip_addresses', 'state') as $state => $count) {
            $this->sample($lines, 'algen_cloudportal_ipam_addresses', ['state' => $state], $count, 'gauge', 'IPAM addresses by state.');
        }
        foreach ($this->countBySafe('reconciliation_incidents', 'severity', "status='open'") as $severity => $count) {
            $this->sample($lines, 'algen_cloudportal_reconciliation_incidents', ['severity' => $severity], $count, 'gauge', 'Open reconciliation incidents by severity.');
        }
        foreach ($this->countBySafe('api_tokens', 'status') as $status => $count) {
            $this->sample($lines, 'algen_cloudportal_api_tokens', ['status' => $status], $count, 'gauge', 'API tokens by lifecycle status.');
        }

        $this->gauge($lines, 'algen_cloudportal_sessions_active', 'Server-side sessions that are active and not expired.', $this->scalar("SELECT COUNT(*) FROM user_sessions WHERE revoked_at IS NULL AND (expires_at IS NULL OR expires_at>CURRENT_TIMESTAMP)"));
        $this->gauge($lines, 'algen_cloudportal_idempotency_processing', 'API idempotency keys currently processing.', $this->scalar("SELECT COUNT(*) FROM api_idempotency_keys WHERE state='processing' AND expires_at>CURRENT_TIMESTAMP"));
        $this->gauge($lines, 'algen_cloudportal_webhook_failed_deliveries', 'Webhook deliveries currently marked failed.', $this->scalar("SELECT COUNT(*) FROM webhook_deliveries WHERE status='failed'"));
        $this->gauge($lines, 'algen_cloudportal_mfa_enabled_users', 'Active users with MFA enabled.', $this->scalar("SELECT COUNT(*) FROM users WHERE status='active' AND mfa_enabled=1"));

        return implode("\n", $lines) . "\n";
    }

    /** @return array<string,int> */
    private function countBy(string $table, string $column): array
    {
        $allowed = [
            'virtual_machines.status',
            'proxmox_connections.status',
            'ip_addresses.state',
        ];
        if (!in_array($table . '.' . $column, $allowed, true)) {
            throw new \InvalidArgumentException('Unsupported metrics grouping.');
        }
        $rows = $this->pdo->query("SELECT {$column} label,COUNT(*) count FROM {$table} GROUP BY {$column}")->fetchAll();
        $result = [];
        foreach ($rows as $row) {
            $result[(string) $row['label']] = (int) $row['count'];
        }
        return $result;
    }

    /** @return array<string,int> */
    private function countBySafe(string $table, string $column, string $where = ''): array
    {
        $allowed = ['reconciliation_incidents.severity', 'api_tokens.status'];
        if (!in_array($table . '.' . $column, $allowed, true)) return [];
        try {
            $sql = "SELECT {$column} label,COUNT(*) count FROM {$table}" . ($where === '' ? '' : ' WHERE ' . $where) . " GROUP BY {$column}";
            $rows = $this->pdo->query($sql)->fetchAll();
            $result = [];
            foreach ($rows as $row) $result[(string) $row['label']] = (int) $row['count'];
            return $result;
        } catch (\Throwable) {
            // Keep metrics available during a rolling schema upgrade.
            return [];
        }
    }

    private function scalar(string $sql): int
    {
        try {
            return (int) $this->pdo->query($sql)->fetchColumn();
        } catch (\Throwable) {
            return 0;
        }
    }

    /** @param list<string> $lines */
    private function gauge(array &$lines, string $name, string $help, int|float $value): void
    {
        $this->sample($lines, $name, [], $value, 'gauge', $help);
    }

    /** @param list<string> $lines @param array<string,string> $labels */
    private function sample(array &$lines, string $name, array $labels, int|float $value, string $type, string $help): void
    {
        if (!in_array('# HELP ' . $name . ' ' . $help, $lines, true)) {
            $lines[] = '# HELP ' . $name . ' ' . $help;
            $lines[] = '# TYPE ' . $name . ' ' . $type;
        }
        $encodedLabels = [];
        foreach ($labels as $key => $label) {
            $escaped = str_replace(["\\", "\n", '"'], ["\\\\", '\\n', '\\"'], $label);
            $encodedLabels[] = $key . '="' . $escaped . '"';
        }
        $suffix = $encodedLabels === [] ? '' : '{' . implode(',', $encodedLabels) . '}';
        $lines[] = $name . $suffix . ' ' . $value;
    }
}

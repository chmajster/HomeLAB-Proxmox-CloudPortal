<?php

declare(strict_types=1);

namespace CloudPortal\Services\Placement;

use CloudPortal\Http\HttpException;
use PDO;

final class PlacementService
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @param list<string> $exclude */
    public function recommend(int $connectionId, ?string $requiredNode = null, array $exclude = []): string
    {
        $statement = $this->pdo->prepare(
            "SELECT node_name, status, cpu_usage, memory_total, memory_used, placement_weight
             FROM proxmox_nodes
             WHERE connection_id=:connection AND maintenance_mode=0
               AND LOWER(status) IN ('online','running','active')"
        );
        $statement->execute(['connection' => $connectionId]);
        $nodes = $statement->fetchAll();
        $best = null;
        $bestScore = -INF;
        foreach ($nodes as $node) {
            $name = (string) $node['node_name'];
            if ($requiredNode !== null && $name !== $requiredNode) {
                continue;
            }
            if (in_array($name, $exclude, true)) {
                continue;
            }
            $cpuFree = 1.0 - max(0.0, min(1.0, (float) ($node['cpu_usage'] ?? 1.0)));
            $total = max(1, (int) ($node['memory_total'] ?? 0));
            $used = max(0, (int) ($node['memory_used'] ?? $total));
            $memoryFree = max(0.0, min(1.0, ($total - $used) / $total));
            $weight = max(1, min(1000, (int) ($node['placement_weight'] ?? 100))) / 100.0;
            $running = $this->runningJobs($connectionId, $name);
            $score = ($cpuFree * 0.45) + ($memoryFree * 0.45) + ($weight * 0.10) - (min(20, $running) * 0.025);
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $name;
            }
        }
        if ($best === null) {
            throw new HttpException(409, $requiredNode === null ? 'No eligible Proxmox node is available for placement.' : 'The required Proxmox node is unavailable or in maintenance mode.');
        }
        return $best;
    }

    private function runningJobs(int $connectionId, string $node): int
    {
        $statement = $this->pdo->prepare(
            "SELECT COUNT(*) FROM jobs WHERE connection_id=:connection AND status='running'
             AND JSON_UNQUOTE(JSON_EXTRACT(payload, '$.node_name'))=:node"
        );
        $statement->execute(['connection' => $connectionId, 'node' => $node]);
        return (int) $statement->fetchColumn();
    }
}

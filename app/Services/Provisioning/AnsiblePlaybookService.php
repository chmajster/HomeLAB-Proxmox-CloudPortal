<?php

declare(strict_types=1);

namespace CloudPortal\Services\Provisioning;

use CloudPortal\Support\Config;

final class AnsiblePlaybookService
{
    public function __construct(
        private readonly string $playbooksDirectory,
        private readonly string $command = '/usr/bin/ansible-playbook',
        private readonly string $privateKeyPath = '/var/lib/algen-cloud-portal/.ssh/ansible_ed25519',
        private readonly string $publicKeyPath = '/var/lib/algen-cloud-portal/.ssh/ansible_ed25519.pub',
        private readonly int $timeout = 1200,
    ) {
    }

    public static function fromConfig(Config $config, string $root): self
    {
        return new self(
            (string) $config->get('provisioning.ansible_playbooks_directory', $root . '/ansible/playbooks'),
            (string) $config->get('provisioning.ansible_command', '/usr/bin/ansible-playbook'),
            (string) $config->get('provisioning.ansible_private_key', '/var/lib/algen-cloud-portal/.ssh/ansible_ed25519'),
            (string) $config->get('provisioning.ansible_public_key', '/var/lib/algen-cloud-portal/.ssh/ansible_ed25519.pub'),
            (int) $config->get('provisioning.ansible_timeout', 1200),
        );
    }

    /** @return list<array{id:string,name:string}> */
    public function playbooks(): array
    {
        $root = realpath($this->playbooksDirectory);
        if ($root === false || !is_dir($root)) return [];
        $prefix = rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

        $items = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || !$file->isFile()) continue;
            $extension = strtolower($file->getExtension());
            if (!in_array($extension, ['yml', 'yaml'], true)) continue;
            $path = $file->getRealPath();
            if ($path === false || !str_starts_with($path, $prefix)) continue;
            $relative = str_replace('\\', '/', substr($path, strlen($prefix)));
            if ($relative === '' || str_contains($relative, '..')) continue;
            $items[] = ['id' => $relative, 'name' => $relative];
        }
        usort($items, static fn (array $a, array $b): int => strnatcasecmp($a['name'], $b['name']));
        return $items;
    }

    public function validateSelection(string $playbook): ?string
    {
        $playbook = trim(str_replace('\\', '/', $playbook));
        if ($playbook === '') return null;
        if (str_starts_with($playbook, '/') || str_contains($playbook, '..') || preg_match('/^[A-Za-z0-9_.\/-]+\.(?:ya?ml)$/i', $playbook) !== 1) {
            throw new \InvalidArgumentException('Invalid Ansible playbook selection.');
        }
        $this->resolve($playbook);
        return $playbook;
    }

    public function controllerPublicKey(): string
    {
        $key = trim((string) @file_get_contents($this->publicKeyPath));
        if ($key === '') {
            throw new \RuntimeException('Ansible controller public key is not configured or cannot be read: ' . $this->publicKeyPath);
        }
        if (preg_match('/^(ssh-(?:ed25519|rsa)|ecdsa-sha2-nistp(?:256|384|521))\s+[A-Za-z0-9+\/=]+(?:\s+.*)?$/', $key) !== 1) {
            throw new \RuntimeException('Configured Ansible controller public key is invalid.');
        }
        return $key;
    }

    /** @return array{playbook:string,host:string,exit_code:int,output:string} */
    public function run(string $playbook, string $host, string $user, array $extraVars = []): array
    {
        $result = $this->runInventory($playbook, [[
            'host_alias' => 'target',
            'ip_address' => $host,
            'ansible_user' => $user,
            'variables' => [],
        ]], $extraVars);
        return [
            'playbook' => $result['playbook'],
            'host' => $host,
            'exit_code' => $result['exit_code'],
            'output' => $result['output'],
        ];
    }

    /**
     * @param list<array<string,mixed>> $hosts
     * @param array<string,mixed> $extraVars
     * @return array{playbook:string,hosts:list<string>,exit_code:int,output:string}
     */
    public function runInventory(string $playbook, array $hosts, array $extraVars = []): array
    {
        $playbookPath = $this->resolve($playbook);
        if (!is_file($this->command) || !is_executable($this->command)) {
            throw new \RuntimeException('ansible-playbook is not installed or executable: ' . $this->command);
        }
        if (!is_file($this->privateKeyPath) || !is_readable($this->privateKeyPath)) {
            throw new \RuntimeException('Ansible controller private key is not configured or readable: ' . $this->privateKeyPath);
        }
        if ($hosts === []) throw new \RuntimeException('Ansible inventory is empty.');

        $normalized = [];
        $aliases = [];
        foreach ($hosts as $host) {
            $ip = trim((string) ($host['ip_address'] ?? ''));
            $user = trim((string) ($host['ansible_user'] ?? ''));
            $alias = trim((string) ($host['host_alias'] ?? ''));
            if (filter_var($ip, FILTER_VALIDATE_IP) === false) throw new \RuntimeException('Ansible target IP address is invalid.');
            if (preg_match('/^[a-z_][a-z0-9_-]{0,31}$/', $user) !== 1) throw new \RuntimeException('Ansible target username is invalid.');
            if (preg_match('/^[A-Za-z0-9_.-]{1,120}$/', $alias) !== 1) throw new \RuntimeException('Ansible host alias is invalid.');
            if (isset($aliases[$alias])) throw new \RuntimeException('Ansible inventory contains a duplicate host alias: ' . $alias);
            $aliases[$alias] = true;
            $normalized[] = [
                'host_alias' => $alias,
                'ip_address' => $ip,
                'ansible_user' => $user,
                'variables' => is_array($host['variables'] ?? null) ? $host['variables'] : [],
            ];
        }
        $this->assertVariableMap($extraVars);
        foreach ($normalized as $host) $this->assertVariableMap($host['variables']);

        foreach (array_values(array_unique(array_column($normalized, 'ip_address'))) as $ip) $this->waitForSsh($ip);

        $inventoryPath = $this->temporaryInventory($normalized);
        try {
            $parts = [
                escapeshellarg($this->command),
                '-i', escapeshellarg($inventoryPath),
                '--private-key', escapeshellarg($this->privateKeyPath),
                '--timeout', '30',
                '--ssh-common-args', escapeshellarg('-o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o ConnectTimeout=10'),
            ];
            if ($extraVars !== []) {
                $parts[] = '--extra-vars';
                $parts[] = escapeshellarg(json_encode($extraVars, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            }
            $parts[] = escapeshellarg($playbookPath);
            $result = $this->execute($parts);
        } finally {
            @unlink($inventoryPath);
        }

        return [
            'playbook' => $playbook,
            'hosts' => array_values(array_column($normalized, 'host_alias')),
            'exit_code' => $result['exit_code'],
            'output' => $result['output'],
        ];
    }

    /** @param list<array<string,mixed>> $hosts */
    private function temporaryInventory(array $hosts): string
    {
        $path = sys_get_temp_dir() . '/algen-ansible-inventory-' . bin2hex(random_bytes(12)) . '.yml';
        $lines = ['---', 'all:', '  hosts:'];
        foreach ($hosts as $host) {
            $lines[] = '    ' . json_encode((string) $host['host_alias'], JSON_THROW_ON_ERROR) . ':';
            $lines[] = '      ansible_host: ' . json_encode((string) $host['ip_address'], JSON_THROW_ON_ERROR);
            $lines[] = '      ansible_user: ' . json_encode((string) $host['ansible_user'], JSON_THROW_ON_ERROR);
            foreach ($host['variables'] as $key => $value) {
                $lines[] = '      ' . $key . ': ' . json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            }
        }
        if (file_put_contents($path, implode("\n", $lines) . "\n", LOCK_EX) === false) {
            throw new \RuntimeException('Could not create temporary Ansible inventory.');
        }
        @chmod($path, 0600);
        return $path;
    }

    /** @param array<string,mixed> $variables */
    private function assertVariableMap(array $variables): void
    {
        foreach ($variables as $key => $_) {
            if (!is_string($key) || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $key) !== 1) {
                throw new \RuntimeException('Ansible variable name is invalid: ' . (string) $key);
            }
        }
    }

    /** @param list<string> $parts @return array{exit_code:int,output:string} */
    private function execute(array $parts): array
    {
        $command = implode(' ', [
            'ANSIBLE_HOST_KEY_CHECKING=False',
            'ANSIBLE_LOCAL_TEMP=/tmp/algen-ansible-local',
            'ANSIBLE_SSH_CONTROL_PATH_DIR=/tmp/algen-ansible-cp',
            implode(' ', $parts),
        ]);
        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open($command, $descriptors, $pipes);
        if (!is_resource($process)) throw new \RuntimeException('Could not start ansible-playbook.');

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $stdout = '';
        $stderr = '';
        $deadline = time() + max(30, $this->timeout);
        $exitCode = null;
        try {
            do {
                $stdout .= (string) stream_get_contents($pipes[1]);
                $stderr .= (string) stream_get_contents($pipes[2]);
                $status = proc_get_status($process);
                if (!$status['running']) {
                    $exitCode = (int) $status['exitcode'];
                    break;
                }
                if (time() >= $deadline) {
                    proc_terminate($process, 15);
                    usleep(500000);
                    $status = proc_get_status($process);
                    if ($status['running']) proc_terminate($process, 9);
                    throw new \RuntimeException('Ansible playbook timed out after ' . max(30, $this->timeout) . ' seconds.');
                }
                usleep(200000);
            } while (true);
            $stdout .= (string) stream_get_contents($pipes[1]);
            $stderr .= (string) stream_get_contents($pipes[2]);
        } finally {
            fclose($pipes[1]);
            fclose($pipes[2]);
            $closed = proc_close($process);
            if ($exitCode === null || $exitCode < 0) $exitCode = $closed >= 0 ? $closed : $exitCode;
        }

        $combined = trim($stdout . ($stderr === '' ? '' : "\n" . $stderr));
        if (($exitCode ?? 1) !== 0) {
            throw new \RuntimeException('Ansible playbook failed with exit code ' . (int) $exitCode . ($combined === '' ? '' : ': ' . mb_substr($combined, -1500)));
        }
        return ['exit_code' => (int) $exitCode, 'output' => mb_substr($combined, -10000)];
    }

    private function waitForSsh(string $host): void
    {
        $deadline = time() + min(300, max(30, intdiv(max(30, $this->timeout), 2)));
        do {
            $socket = @fsockopen($host, 22, $errno, $error, 5.0);
            if (is_resource($socket)) {
                fclose($socket);
                return;
            }
            sleep(5);
        } while (time() < $deadline);
        throw new \RuntimeException('Timed out waiting for SSH on ' . $host . ':22.');
    }

    private function resolve(string $playbook): string
    {
        $root = realpath($this->playbooksDirectory);
        if ($root === false || !is_dir($root)) throw new \RuntimeException('Ansible playbooks directory does not exist: ' . $this->playbooksDirectory);
        $candidate = realpath($root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $playbook));
        if ($candidate === false || !is_file($candidate)) throw new \InvalidArgumentException('Selected Ansible playbook does not exist.');
        $prefix = rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        if (!str_starts_with($candidate, $prefix)) throw new \InvalidArgumentException('Selected Ansible playbook is outside the configured playbooks directory.');
        $extension = strtolower(pathinfo($candidate, PATHINFO_EXTENSION));
        if (!in_array($extension, ['yml', 'yaml'], true)) throw new \InvalidArgumentException('Selected Ansible playbook must be a .yml or .yaml file.');
        return $candidate;
    }
}

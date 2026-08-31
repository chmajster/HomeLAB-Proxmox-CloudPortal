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
    public function run(string $playbook, string $host, string $user): array
    {
        $playbookPath = $this->resolve($playbook);
        if (filter_var($host, FILTER_VALIDATE_IP) === false) {
            throw new \RuntimeException('Ansible target IP address is invalid.');
        }
        if (preg_match('/^[a-z_][a-z0-9_-]{0,31}$/', $user) !== 1) {
            throw new \RuntimeException('Ansible target username is invalid.');
        }
        if (!str_starts_with($this->command, '/') || preg_match('/[\r\n\0]/', $this->command) === 1) {
            throw new \RuntimeException('ansible-playbook command must be an absolute executable path.');
        }
        if (!is_file($this->command) || !is_executable($this->command)) {
            throw new \RuntimeException('ansible-playbook is not installed or executable: ' . $this->command);
        }
        if (!is_file($this->privateKeyPath) || !is_readable($this->privateKeyPath)) {
            throw new \RuntimeException('Ansible controller private key is not configured or readable: ' . $this->privateKeyPath);
        }

        $this->waitForSsh($host);
        $extraVars = json_encode([
            'cloudportal_target_ip' => $host,
            'cloudportal_target_user' => $user,
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        $command = [
            $this->command,
            '-i', $host . ',',
            '-u', $user,
            '--private-key', $this->privateKeyPath,
            '--timeout', '30',
            '--ssh-common-args', '-o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o ConnectTimeout=10',
            '--extra-vars', $extraVars,
            $playbookPath,
        ];
        $environment = getenv();
        if (!is_array($environment)) {
            $environment = [];
        }
        $environment['ANSIBLE_HOST_KEY_CHECKING'] = 'False';
        $environment['ANSIBLE_LOCAL_TEMP'] = '/tmp/algen-ansible-local';
        $environment['ANSIBLE_SSH_CONTROL_PATH_DIR'] = '/tmp/algen-ansible-cp';

        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open($command, $descriptors, $pipes, null, $environment, ['bypass_shell' => true]);
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
            if ($exitCode === null || $exitCode < 0) {
                $exitCode = $closed >= 0 ? $closed : $exitCode;
            }
        }

        $combined = trim($stdout . ($stderr === '' ? '' : "\n" . $stderr));
        if (($exitCode ?? 1) !== 0) {
            throw new \RuntimeException('Ansible playbook failed with exit code ' . (int) $exitCode . ($combined === '' ? '' : ': ' . mb_substr($combined, -1500)));
        }
        return [
            'playbook' => $playbook,
            'host' => $host,
            'exit_code' => (int) $exitCode,
            'output' => mb_substr($combined, -5000),
        ];
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
        if ($root === false || !is_dir($root)) {
            throw new \RuntimeException('Ansible playbooks directory does not exist: ' . $this->playbooksDirectory);
        }
        $candidate = realpath($root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $playbook));
        if ($candidate === false || !is_file($candidate)) {
            throw new \InvalidArgumentException('Selected Ansible playbook does not exist.');
        }
        $prefix = rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        if (!str_starts_with($candidate, $prefix)) {
            throw new \InvalidArgumentException('Selected Ansible playbook is outside the configured playbooks directory.');
        }
        $extension = strtolower(pathinfo($candidate, PATHINFO_EXTENSION));
        if (!in_array($extension, ['yml', 'yaml'], true)) {
            throw new \InvalidArgumentException('Selected Ansible playbook must be a .yml or .yaml file.');
        }
        return $candidate;
    }
}

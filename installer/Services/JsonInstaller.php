<?php

declare(strict_types=1);

namespace CloudPortal\Installer\Services;

use CloudPortal\Application;
use CloudPortal\Installer\InstallerState;
use CloudPortal\Installer\Validators\InstallerValidationException;
use CloudPortal\Security\Crypto;

final class JsonInstaller
{
    private readonly InstallerState $state;
    private readonly RequirementsChecker $requirements;
    private readonly DatabaseInstaller $database;
    private readonly ProxmoxTester $proxmox;
    private readonly RuntimeConfigWriter $configWriter;
    private readonly InstallerLogger $logger;

    public function __construct(private readonly Application $app)
    {
        $this->state = new InstallerState();
        $this->requirements = new RequirementsChecker($app->root);
        $this->database = new DatabaseInstaller($app->root . '/database/schema.sql');
        $this->proxmox = new ProxmoxTester();
        $this->configWriter = new RuntimeConfigWriter($app->root);
        $this->logger = new InstallerLogger($app->root . '/storage/logs/installer.log');
    }

    /** @return array{stages:list<array{name:string,status:string,detail:string}>,summary:array<string,mixed>} */
    public function run(string $path): array
    {
        $configuration = [];
        try {
            $configuration = (new JsonInstallationConfig())->load($path, $this->detectedBaseUrl());
            $checks = $this->requirements->check();
            if ($this->requirements->hasErrors($checks)) {
                $failed = array_map(
                    static fn (array $check): string => $check['name'],
                    array_values(array_filter($checks, static fn (array $check): bool => $check['status'] === 'error')),
                );
                throw new \RuntimeException('Server requirements failed: ' . implode(', ', $failed) . '.');
            }

            $this->state->put('requirements_auto_passed', $this->requirements->allPassed($checks));
            $this->state->security();
            $this->state->markCompleted(1);

            if ($this->state->completedStep() < 2) {
                $this->installDatabase($configuration['database']);
                $this->state->markCompleted(2);
            }
            if ($this->state->completedStep() < 4) {
                $this->installAdministrator($configuration['administrator']);
                $this->state->markCompleted(4);
            }
            if ($this->state->completedStep() < 5) {
                $this->installProxmox($configuration['proxmox']);
                $this->state->markCompleted(5);
            }
            if ($this->state->completedStep() < 6) {
                $this->state->put('portal', $configuration['portal']);
                $this->saveConfiguration();
                $this->state->markCompleted(6);
            }

            if (!is_array($this->state->get('config_written'))) {
                $this->saveConfiguration();
            }

            $finalizer = new InstallationFinalizer(
                $this->database,
                $this->configWriter,
                new InstallationLock($this->app->root . '/storage/installed.lock'),
                $this->proxmox,
            );
            $result = $finalizer->finalize($this->state->all());
            $this->state->markCompleted(9);
            $this->state->finish($result['summary']);
            $this->consumeConfigurationFile($path);
            session_regenerate_id(true);
            return $result;
        } catch (\Throwable $exception) {
            $this->logger->error('json_install', $exception, $this->secrets($configuration));
            throw $exception;
        }
    }

    /** @param array<string,mixed> $config */
    private function installDatabase(array $config): void
    {
        $result = $this->database->test($config);
        if ($result['existing_tables'] && !($config['confirm_existing'] ?? false)) {
            throw new InstallerValidationException(
                'The database is not empty. Set database.confirm_existing to true only after verifying the target database.',
                ['database.confirm_existing' => 'Explicit confirmation is required for a non-empty database.'],
            );
        }
        if ($result['portal_table_count'] > 0 && !$result['compatible_portal_schema']) {
            throw new \RuntimeException('The database contains conflicting portal tables without a compatible schema marker. Select another database.');
        }
        unset($config['confirm_existing']);
        $this->state->put('database', $config);
        $this->state->put('database_test', $result);
        $this->state->put('schema', $this->database->initialize($config));
    }

    /** @param array<string,mixed> $input */
    private function installAdministrator(array $input): void
    {
        $database = $this->state->get('database');
        if (!is_array($database)) {
            throw new \RuntimeException('Database settings are missing.');
        }
        if (!is_array($this->state->get('schema'))) {
            $this->state->put('schema', $this->database->initialize($database));
        }
        $known = $this->state->get('administrator');
        $id = (new AdministratorInstaller())->create(
            $this->database->connect($database),
            $input,
            is_array($known) ? (int) ($known['id'] ?? 0) : null,
        );
        $this->state->put('administrator', [
            'id' => $id,
            'username' => $input['username'],
            'email' => $input['email'],
            'test_account' => false,
        ]);
    }

    /** @param array<string,mixed> $config */
    private function installProxmox(array $config): void
    {
        if (($config['skipped'] ?? false) === true) {
            $this->state->put('proxmox', ['skipped' => true]);
            return;
        }

        $test = $this->proxmox->test($config);
        $secret = (string) $config['token_secret'];
        unset($config['token_secret']);
        try {
            $config['token_secret_encrypted'] = (new Crypto($this->state->security()['encryption_key']))->encrypt($secret);
            $config['test'] = $test;
            $this->state->put('proxmox', $config);
        } finally {
            sodium_memzero($secret);
        }
    }

    private function saveConfiguration(): void
    {
        foreach ($this->state->security() as $secret) {
            if (strlen((string) base64_decode($secret, true)) !== 32) {
                throw new \RuntimeException('Security key generation failed.');
            }
        }
        $path = $this->configWriter->write($this->state->all());
        $this->state->put('config_written', ['path' => basename($path), 'verified' => true]);
    }

    private function detectedBaseUrl(): string
    {
        $https = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== '' && $_SERVER['HTTPS'] !== 'off';
        $host = preg_replace('/[^A-Za-z0-9.\-:\[\]]/', '', (string) ($_SERVER['HTTP_HOST'] ?? 'localhost')) ?: 'localhost';
        return ($https ? 'https' : 'http') . '://' . $host . $this->app->basePath();
    }

    /** @param array<string,mixed> $configuration @return list<string> */
    private function secrets(array $configuration): array
    {
        return array_values(array_filter([
            (string) ($configuration['database']['password'] ?? ''),
            (string) ($configuration['administrator']['password'] ?? ''),
            (string) ($configuration['proxmox']['token_secret'] ?? ''),
            (string) ($this->state->get('security', [])['app_key'] ?? ''),
            (string) ($this->state->get('security', [])['encryption_key'] ?? ''),
            (string) ($this->state->get('security', [])['csrf_secret'] ?? ''),
        ], static fn (string $value): bool => $value !== ''));
    }

    private function consumeConfigurationFile(string $path): void
    {
        if (@unlink($path)) {
            return;
        }
        if (@file_put_contents($path, "{}\n", LOCK_EX) !== false) {
            return;
        }
        $this->logger->error(
            'json_cleanup',
            new \RuntimeException('Installation completed but install.json could not be deleted or cleared. Remove it manually.'),
        );
    }
}

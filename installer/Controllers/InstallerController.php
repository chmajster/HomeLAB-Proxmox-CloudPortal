<?php

declare(strict_types=1);

namespace CloudPortal\Installer\Controllers;

use CloudPortal\Application;
use CloudPortal\Http\HttpException;
use CloudPortal\Http\Request;
use CloudPortal\Http\Response;
use CloudPortal\Installer\InstallerState;
use CloudPortal\Installer\Services\AdministratorInstaller;
use CloudPortal\Installer\Services\DatabaseInstaller;
use CloudPortal\Installer\Services\InstallationFailed;
use CloudPortal\Installer\Services\InstallationFinalizer;
use CloudPortal\Installer\Services\InstallationLock;
use CloudPortal\Installer\Services\InstallerLogger;
use CloudPortal\Installer\Services\ProxmoxTester;
use CloudPortal\Installer\Services\RequirementsChecker;
use CloudPortal\Installer\Services\RuntimeConfigWriter;
use CloudPortal\Installer\Validators\InstallerInput;
use CloudPortal\Installer\Validators\InstallerValidationException;
use CloudPortal\Security\Crypto;
use CloudPortal\Support\View;

final class InstallerController
{
    private readonly InstallerState $state;
    private readonly RequirementsChecker $requirements;
    private readonly DatabaseInstaller $database;
    private readonly ProxmoxTester $proxmox;
    private readonly RuntimeConfigWriter $configWriter;
    private readonly InstallerLogger $logger;
    private readonly View $view;

    public function __construct(private readonly Application $app)
    {
        $this->state = new InstallerState();
        $this->requirements = new RequirementsChecker($app->root);
        $this->database = new DatabaseInstaller($app->root . '/database/schema.sql');
        $this->proxmox = new ProxmoxTester();
        $this->configWriter = new RuntimeConfigWriter($app->root);
        $this->logger = new InstallerLogger($app->root . '/storage/logs/installer.log');
        $this->view = new View($app->root . '/installer/Views', ['basePath' => $app->basePath()]);
    }

    public function show(Request $request): Response
    {
        if ($this->app->installed()) return $this->locked();
        $step = filter_var($request->query('step', 0), FILTER_VALIDATE_INT);
        $step = $step === false ? 0 : max(0, min(9, (int) $step));
        $requirements = [];
        if ($step === 0 || $step === 1) {
            $checks = $this->requirements->check();
            $allPassed = $this->requirements->allPassed($checks);
            $this->state->put('requirements_auto_passed', $allPassed);
            if ($step === 1 && $allPassed) {
                if ($this->state->completedStep() >= 0) $this->state->markCompleted(1);
                return Response::redirect($this->app->url('/install?step=' . $this->state->nextStep()));
            }
            if ($step === 1) $requirements = $checks;
        }
        if (!$this->state->canView($step)) return Response::redirect($this->app->url('/install?step=' . $this->state->nextStep()));
        $error = $_SESSION['installer_error'] ?? null;
        $fieldErrors = $_SESSION['installer_field_errors'] ?? [];
        $old = $_SESSION['installer_old'] ?? [];
        unset($_SESSION['installer_error'], $_SESSION['installer_field_errors'], $_SESSION['installer_old']);
        $values = $this->values($step, is_array($old) ? $old : []);
        return Response::html($this->view->render('wizard', [
            'step' => $step, 'completed' => $this->state->completedStep(), 'csrf' => $this->app->csrf->token(),
            'requirements' => $requirements, 'error' => $error, 'fieldErrors' => is_array($fieldErrors) ? $fieldErrors : [],
            'values' => $values, 'timezones' => timezone_identifiers_list(), 'version' => Application::VERSION,
            'requirementsHidden' => (bool) $this->state->get('requirements_auto_passed', false),
        ], 'layout'));
    }

    public function submit(Request $request): Response
    {
        $this->guardUninstalled();
        $this->app->csrf->verify($request);
        $step = filter_var($request->input('step'), FILTER_VALIDATE_INT);
        if ($step === false) throw new HttpException(422, 'Invalid installer step.');
        $step = (int) $step;
        $this->state->assertSubmittable($step);
        if ($step <= $this->state->completedStep()) return Response::redirect($this->app->url('/install?step=' . $this->state->nextStep()));
        try {
            match ($step) {
                0 => $this->welcome(), 1 => $this->acceptRequirements(), 2 => $this->saveDatabase($request),
                4 => $this->saveAdministrator($request), 5 => $this->saveProxmox($request),
                6 => $this->savePortal($request),
                default => throw new HttpException(409, 'Final installation must be started from the progress screen.'),
            };
            $this->state->markCompleted($step);
            return Response::redirect($this->app->url('/install?step=' . $this->state->nextStep()));
        } catch (InstallerValidationException $exception) {
            $_SESSION['installer_error'] = $exception->getMessage();
            $_SESSION['installer_field_errors'] = $exception->fields;
            $_SESSION['installer_old'] = $this->safeOldInput($request->all());
        } catch (\Throwable $exception) {
            $this->log($step, $exception, $request);
            $_SESSION['installer_error'] = $this->safeMessage($exception);
            $_SESSION['installer_old'] = $this->safeOldInput($request->all());
        }
        return Response::redirect($this->app->url('/install?step=' . max(0, min(9, $step))));
    }

    public function testDatabase(Request $request): Response
    {
        $this->guardUninstalled();
        $this->app->csrf->verify($request);
        try {
            $config = InstallerInput::database($request->all());
            return Response::json(['data' => $this->database->test($config)]);
        } catch (InstallerValidationException $exception) {
            throw new HttpException(422, $exception->getMessage(), ['fields' => $exception->fields]);
        } catch (\Throwable $exception) {
            $this->log(2, $exception, $request);
            throw new HttpException(422, $this->safeMessage($exception));
        }
    }

    public function testProxmox(Request $request): Response
    {
        $this->guardUninstalled();
        $this->app->csrf->verify($request);
        try {
            $config = InstallerInput::proxmox($request->all());
            if (($config['skipped'] ?? false) === true) throw new InstallerValidationException('Enter connection data before testing Proxmox.');
            return Response::json(['data' => $this->proxmox->test($config)]);
        } catch (InstallerValidationException $exception) {
            throw new HttpException(422, $exception->getMessage(), ['fields' => $exception->fields]);
        } catch (\Throwable $exception) {
            $this->log(5, $exception, $request);
            throw new HttpException(422, $this->safeMessage($exception));
        }
    }

    public function recheckRequirements(Request $request): Response
    {
        $this->guardUninstalled();
        $this->app->csrf->verify($request);
        $checks = $this->requirements->check();
        return Response::json(['data' => ['checks' => $checks, 'can_continue' => !$this->requirements->hasErrors($checks)]]);
    }

    public function finalize(Request $request): Response
    {
        $this->guardUninstalled();
        $this->app->csrf->verify($request);
        if ($this->state->completedStep() < 6) throw new HttpException(409, 'Complete the preceding installer steps first.');
        $finalizer = new InstallationFinalizer(
            $this->database, $this->configWriter,
            new InstallationLock($this->app->root . '/storage/installed.lock'), $this->proxmox,
        );
        try {
            // Resume sessions that reached the final screen before the
            // standalone runtime configuration screen was removed.
            if (!is_array($this->state->get('config_written'))) $this->saveConfiguration();
            $result = $finalizer->finalize($this->state->all());
            $this->state->markCompleted(9);
            $this->state->finish($result['summary']);
            session_regenerate_id(true);
            return Response::json(['data' => ['stages' => $result['stages'], 'redirect' => $this->app->url('/install/finish')]]);
        } catch (InstallationFailed $exception) {
            $this->logger->error('finalize', $exception, $this->secrets());
            return Response::json(['error' => ['message' => 'Installation failed at the indicated stage. Correct the problem and retry.', 'stages' => $this->safeStages($exception->stages)]], 500);
        } catch (\Throwable $exception) {
            $this->logger->error('finalize', $exception, $this->secrets());
            throw new HttpException(500, 'Installation failed safely. Review storage/logs/installer.log and retry.');
        }
    }

    public function finish(Request $request): Response
    {
        $summary = $this->state->finishSummary();
        if (!$this->app->installed() || $summary === null) return $this->locked();
        return Response::html($this->view->render('finish', ['summary' => $summary], 'layout'));
    }

    private function welcome(): void
    {
        $this->state->security();
        $checks = $this->requirements->check();
        $this->state->put('requirements_auto_passed', $this->requirements->allPassed($checks));
    }

    private function acceptRequirements(): void
    {
        $checks = $this->requirements->check();
        if ($this->requirements->hasErrors($checks)) throw new \RuntimeException('Resolve all requirement errors before continuing.');
        $this->state->put('requirements_auto_passed', $this->requirements->allPassed($checks));
    }

    private function saveDatabase(Request $request): void
    {
        $config = InstallerInput::database($request->all());
        $result = $this->database->test($config);
        if ($result['existing_tables'] && !$config['confirm_existing']) throw new InstallerValidationException('Explicit confirmation is required for a non-empty database.', ['confirm_existing_database' => 'Confirm that existing tables must be preserved.']);
        if ($result['portal_table_count'] > 0 && !$result['compatible_portal_schema']) throw new \RuntimeException('The database contains conflicting portal tables without a compatible schema marker. Select another database.');
        unset($config['confirm_existing']);
        $this->state->put('database', $config);
        $this->state->put('database_test', $result);
        $this->initializeDatabase();
    }

    private function initializeDatabase(): void
    {
        $config = $this->state->get('database');
        if (!is_array($config)) throw new \RuntimeException('Database settings are missing.');
        $this->state->put('schema', $this->database->initialize($config));
    }

    private function saveAdministrator(Request $request): void
    {
        $input = InstallerInput::administrator($request->all());
        $database = $this->state->get('database');
        if (!is_array($database)) throw new \RuntimeException('Database settings are missing.');
        // Resume sessions created before the schema screen was removed.
        if (!is_array($this->state->get('schema'))) $this->initializeDatabase();
        $known = $this->state->get('administrator');
        $id = (new AdministratorInstaller())->create($this->database->connect($database), $input, is_array($known) ? (int) ($known['id'] ?? 0) : null);
        $this->state->put('administrator', [
            'id' => $id,
            'username' => $input['username'],
            'email' => $input['email'],
            'test_account' => $input['test_account'],
        ]);
    }

    private function saveProxmox(Request $request): void
    {
        $config = InstallerInput::proxmox($request->all());
        if (($config['skipped'] ?? false) === true) {
            $this->state->put('proxmox', ['skipped' => true]);
            return;
        }
        $test = $this->proxmox->test($config);
        $secret = (string) $config['token_secret'];
        unset($config['token_secret']);
        $config['token_secret_encrypted'] = (new Crypto($this->state->security()['encryption_key']))->encrypt($secret);
        $config['test'] = $test;
        $this->state->put('proxmox', $config);
        sodium_memzero($secret);
    }

    private function savePortal(Request $request): void
    {
        $this->state->put('portal', InstallerInput::portal($request->all()));
        $this->saveConfiguration();
    }

    private function saveSecurity(): void
    {
        $security = $this->state->security();
        foreach ($security as $secret) {
            if (strlen((string) base64_decode($secret, true)) !== 32) throw new \RuntimeException('Security key generation failed.');
        }
    }

    private function saveConfiguration(): void
    {
        // Security keys are generated and verified automatically without a
        // separate installer screen. This also repairs older resumed sessions.
        $this->saveSecurity();
        $path = $this->configWriter->write($this->state->all());
        $this->state->put('config_written', ['path' => basename($path), 'verified' => true]);
    }

    private function guardUninstalled(): void
    {
        if ($this->app->installed()) throw new HttpException(403, 'Application already installed.');
    }

    private function locked(): Response
    {
        return Response::html($this->view->render('locked', [], 'layout'), 403);
    }

    /** @param array<string,mixed> $old @return array<string,mixed> */
    private function values(int $step, array $old): array
    {
        $stored = match ($step) {
            2 => $this->state->get('database', []), 4 => $this->state->get('administrator', []),
            5 => $this->state->get('proxmox', []), 6 => $this->state->get('portal', []), default => [],
        };
        $defaults = match ($step) {
            2 => ['db_driver' => 'mysql', 'db_host' => '127.0.0.1', 'db_port' => 3306, 'db_name' => '', 'db_user' => ''],
            5 => ['connection_name' => 'Primary Proxmox', 'hostname' => '', 'port' => 8006, 'realm' => 'pve', 'verify_ssl' => true],
            6 => ['portal_name' => 'Algen Cloud Portal', 'base_url' => $this->detectedBaseUrl(), 'timezone' => 'Europe/Warsaw', 'locale' => 'pl', 'session_lifetime' => 7200],
            default => [],
        };
        if (is_array($stored)) {
            if (isset($stored['host'])) $stored = ['db_host' => $stored['host'], 'db_port' => $stored['port'], 'db_name' => $stored['name'], 'db_user' => $stored['user'], 'db_driver' => 'mysql'];
            if (isset($stored['name']) && $step === 5) $stored['connection_name'] = $stored['name'];
            if (isset($stored['name']) && $step === 6) $stored = ['portal_name' => $stored['name'], 'base_url' => $stored['url'], 'timezone' => $stored['timezone'], 'locale' => $stored['locale'], 'session_lifetime' => $stored['session_lifetime']];
        }
        return [...$defaults, ...(is_array($stored) ? $stored : []), ...$old];
    }

    private function detectedBaseUrl(): string
    {
        $https = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== '' && $_SERVER['HTTPS'] !== 'off';
        $host = preg_replace('/[^A-Za-z0-9.\-:\[\]]/', '', (string) ($_SERVER['HTTP_HOST'] ?? 'localhost')) ?: 'localhost';
        return ($https ? 'https' : 'http') . '://' . $host . $this->app->basePath();
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    private function safeOldInput(array $input): array
    {
        foreach (['db_password', 'password', 'password_confirmation', 'api_token_secret', '_csrf'] as $key) unset($input[$key]);
        return $input;
    }

    private function log(int $step, \Throwable $exception, Request $request): void
    {
        $secrets = $this->secrets();
        foreach (['db_password', 'password', 'password_confirmation', 'api_token_secret'] as $key) $secrets[] = (string) $request->input($key, '');
        $this->logger->error('step_' . $step, $exception, $secrets);
    }

    /** @return list<string> */
    private function secrets(): array
    {
        $state = $this->state->all();
        return array_values(array_filter([
            (string) ($state['database']['password'] ?? ''), (string) ($state['security']['app_key'] ?? ''),
            (string) ($state['security']['encryption_key'] ?? ''), (string) ($state['security']['csrf_secret'] ?? ''),
            (string) ($state['proxmox']['token_secret_encrypted'] ?? ''),
        ]));
    }

    private function safeMessage(\Throwable $exception): string
    {
        if ($exception instanceof HttpException || $exception instanceof InstallerValidationException || $exception instanceof \RuntimeException) return mb_substr($exception->getMessage(), 0, 800);
        return 'The operation failed safely. Review the installer log and retry.';
    }

    /** @param list<array{name:string,status:string,detail:string}> $stages @return list<array{name:string,status:string,detail:string}> */
    private function safeStages(array $stages): array
    {
        return array_map(static fn (array $stage): array => ['name' => $stage['name'], 'status' => $stage['status'], 'detail' => $stage['status'] === 'failed' ? 'Failed; see the technical installer log.' : $stage['detail']], $stages);
    }
}

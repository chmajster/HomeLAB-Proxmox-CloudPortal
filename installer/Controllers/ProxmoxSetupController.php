<?php

declare(strict_types=1);

namespace CloudPortal\Installer\Controllers;

use CloudPortal\Application;
use CloudPortal\Http\HttpException;
use CloudPortal\Http\Request;
use CloudPortal\Http\Response;
use CloudPortal\Installer\InstallerState;
use CloudPortal\Installer\Services\InstallerLogger;
use CloudPortal\Installer\Services\ProxmoxPasswordBootstrapper;
use CloudPortal\Installer\Services\ProxmoxTester;
use CloudPortal\Installer\Validators\InstallerInput;
use CloudPortal\Installer\Validators\InstallerValidationException;
use CloudPortal\Security\Crypto;

final class ProxmoxSetupController
{
    private readonly InstallerState $state;
    private readonly ProxmoxTester $tester;
    private readonly ProxmoxPasswordBootstrapper $passwordBootstrapper;
    private readonly InstallerLogger $logger;

    public function __construct(private readonly Application $app)
    {
        $this->state = new InstallerState();
        $this->tester = new ProxmoxTester();
        $this->passwordBootstrapper = new ProxmoxPasswordBootstrapper();
        $this->logger = new InstallerLogger($app->root . '/storage/logs/installer.log');
    }

    public function save(Request $request): Response
    {
        $this->guard();
        $this->app->csrf->verify($request);
        $this->state->assertSubmittable(5);
        if (5 <= $this->state->completedStep()) {
            return Response::redirect($this->app->url('/install?step=' . $this->state->nextStep()));
        }

        $password = '';
        $tokenSecret = '';
        $created = null;
        $passwordConfig = null;
        try {
            if (filter_var($request->input('skip_proxmox', false), FILTER_VALIDATE_BOOL)) {
                unset($_SESSION['installer_proxmox_replace_token']);
                $this->state->put('proxmox', ['skipped' => true]);
                $this->state->markCompleted(5);
                return Response::redirect($this->app->url('/install?step=' . $this->state->nextStep()));
            }

            $mode = (string) $request->input('proxmox_auth_mode', 'token');
            if ($mode === 'password') {
                $passwordConfig = $this->passwordConfig($request->all());
                $password = (string) $passwordConfig['password'];

                $replacementKey = $this->tokenReplacementKey($passwordConfig);
                $pendingReplacement = (string) ($_SESSION['installer_proxmox_replace_token'] ?? '');
                $replaceExisting = $pendingReplacement !== '' && hash_equals($pendingReplacement, $replacementKey);
                unset($_SESSION['installer_proxmox_replace_token']);

                $created = $this->passwordBootstrapper->createToken($passwordConfig, $replaceExisting);
                $tokenSecret = (string) $created['token_secret'];
                $config = [
                    'skipped' => false,
                    'name' => $passwordConfig['name'],
                    'hostname' => $passwordConfig['hostname'],
                    'port' => $passwordConfig['port'],
                    'realm' => $passwordConfig['realm'],
                    'token_id' => $created['token_id'],
                    'token_secret' => $tokenSecret,
                    'verify_ssl' => $passwordConfig['verify_ssl'],
                    'created_automatically' => true,
                ];
                $test = $this->tester->test($config);
                $this->storeTokenConfig($config, $test);
            } else {
                unset($_SESSION['installer_proxmox_replace_token']);
                $config = InstallerInput::proxmox($request->all());
                $tokenSecret = (string) $config['token_secret'];
                $test = $this->tester->test($config);
                $this->storeTokenConfig($config, $test);
            }

            $this->state->markCompleted(5);
            return Response::redirect($this->app->url('/install?step=' . $this->state->nextStep()));
        } catch (InstallerValidationException $exception) {
            $_SESSION['installer_error'] = $exception->getMessage();
            $_SESSION['installer_field_errors'] = $exception->fields;
            $_SESSION['installer_old'] = $this->safeOldInput($request->all());
        } catch (\Throwable $exception) {
            $tokenConflict = $exception instanceof \RuntimeException
                && $exception->getCode() === 409
                && is_array($passwordConfig);

            if ($tokenConflict) {
                $replacementKey = $this->tokenReplacementKey($passwordConfig);
                $_SESSION['installer_proxmox_replace_token'] = $replacementKey;
                $exception = new \RuntimeException(
                    'Token API „' . $replacementKey . '” już istnieje. Czy usunąć istniejący token i utworzyć nowy? '
                    . 'Aby potwierdzić usunięcie i zastąpienie tokenu, wpisz ponownie hasło Proxmox, wybierz tryb „Login i hasło — utwórz token automatycznie” i kliknij „Kontynuuj”. '
                    . 'Jeśli chcesz zachować istniejący token, zmień nazwę tworzonego tokenu przed ponownym kliknięciem „Kontynuuj”.',
                    409,
                    $exception,
                );
            }

            if (is_array($created) && is_array($passwordConfig)) {
                $this->passwordBootstrapper->deleteTokenBestEffort(
                    $passwordConfig,
                    (string) $created['username'],
                    (string) $created['token_name'],
                );
            }
            if (!$tokenConflict) {
                $this->logger->error('step_5', $exception, array_values(array_filter([$password, $tokenSecret])));
            }
            $_SESSION['installer_error'] = mb_substr($exception->getMessage(), 0, 800);
            $_SESSION['installer_old'] = $this->safeOldInput($request->all());
        } finally {
            if ($password !== '') sodium_memzero($password);
            if ($tokenSecret !== '') sodium_memzero($tokenSecret);
        }

        return Response::redirect($this->app->url('/install?step=5'));
    }

    public function test(Request $request): Response
    {
        $this->guard();
        $this->app->csrf->verify($request);
        $password = '';
        $tokenSecret = '';
        try {
            if (filter_var($request->input('skip_proxmox', false), FILTER_VALIDATE_BOOL)) {
                throw new InstallerValidationException('Podaj dane połączenia przed testem Proxmox.');
            }

            $mode = (string) $request->input('proxmox_auth_mode', 'token');
            if ($mode === 'password') {
                $config = $this->passwordConfig($request->all());
                $password = (string) $config['password'];
                $result = $this->passwordBootstrapper->test($config);
                $result['auth_mode'] = 'password';
                $result['token_created'] = false;
                return Response::json(['data' => $result]);
            }

            $config = InstallerInput::proxmox($request->all());
            $tokenSecret = (string) $config['token_secret'];
            $result = $this->tester->test($config);
            $result['auth_mode'] = 'token';
            return Response::json(['data' => $result]);
        } catch (InstallerValidationException $exception) {
            throw new HttpException(422, $exception->getMessage(), ['fields' => $exception->fields]);
        } catch (\Throwable $exception) {
            $this->logger->error('step_5_test', $exception, array_values(array_filter([$password, $tokenSecret])));
            throw new HttpException(422, mb_substr($exception->getMessage(), 0, 800));
        } finally {
            if ($password !== '') sodium_memzero($password);
            if ($tokenSecret !== '') sodium_memzero($tokenSecret);
        }
    }

    /** @param array<string,mixed> $config @param array<string,mixed> $test */
    private function storeTokenConfig(array $config, array $test): void
    {
        $secret = (string) $config['token_secret'];
        unset($config['token_secret']);
        $config['token_secret_encrypted'] = (new Crypto($this->state->security()['encryption_key']))->encrypt($secret);
        $config['test'] = $test;
        $this->state->put('proxmox', $config);
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    private function passwordConfig(array $input): array
    {
        $name = trim((string) ($input['connection_name'] ?? ''));
        $hostname = trim((string) ($input['hostname'] ?? ''));
        $hostname = preg_replace('#^https?://#i', '', rtrim($hostname, '/')) ?? '';
        $port = filter_var($input['port'] ?? 8006, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]);
        $realm = trim((string) ($input['realm'] ?? 'pve'));
        $username = trim((string) ($input['proxmox_username'] ?? ''));
        $password = (string) ($input['proxmox_password'] ?? '');
        $tokenName = trim((string) ($input['api_token_name'] ?? 'cloudportal'));
        $verifySsl = !isset($input['verify_ssl']) || filter_var($input['verify_ssl'], FILTER_VALIDATE_BOOL);
        $fields = [];

        if ($name === '' || mb_strlen($name) > 100) $fields['connection_name'] = 'Nazwa połączenia jest wymagana.';
        if (!$this->host($hostname)) $fields['hostname'] = 'Podaj prawidłowy hostname lub adres IP Proxmox.';
        if ($port === false) $fields['port'] = 'Port musi być liczbą od 1 do 65535.';
        if (preg_match('/^[A-Za-z0-9._-]{1,64}$/', $realm) !== 1) $fields['realm'] = 'Realm jest nieprawidłowy.';

        $matches = [];
        if (preg_match('/^(?<user>[A-Za-z0-9._-]+)(?:@(?<user_realm>[A-Za-z0-9._-]+))?$/', $username, $matches) !== 1) {
            $fields['proxmox_username'] = 'Podaj login w formacie root@pam, admin@pve albo samą nazwę użytkownika.';
        }
        if ($password === '' || strlen($password) > 1024) $fields['proxmox_password'] = 'Hasło Proxmox jest wymagane.';
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/', $tokenName) !== 1) {
            $fields['api_token_name'] = 'Nazwa tokenu musi mieć 1-64 znaki: litery, cyfry, kropka, _ lub -.';
        }
        if ($fields !== []) throw new InstallerValidationException('Popraw dane logowania Proxmox.', $fields);

        $explicitRealm = (string) ($matches['user_realm'] ?? '');
        if ($explicitRealm !== '') $realm = $explicitRealm;

        return [
            'name' => $name,
            'hostname' => $hostname,
            'port' => (int) $port,
            'realm' => $realm,
            'username' => $username,
            'password' => $password,
            'token_name' => $tokenName,
            'verify_ssl' => $verifySsl,
        ];
    }

    /** @param array<string,mixed> $config */
    private function tokenReplacementKey(array $config): string
    {
        $username = trim((string) ($config['username'] ?? ''));
        $realm = trim((string) ($config['realm'] ?? 'pve'));
        if (!str_contains($username, '@')) $username .= '@' . $realm;
        return $username . '!' . (string) ($config['token_name'] ?? 'cloudportal');
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    private function safeOldInput(array $input): array
    {
        foreach (['api_token_secret', 'proxmox_password', '_csrf'] as $key) unset($input[$key]);
        return $input;
    }

    private function host(string $host): bool
    {
        $bare = str_starts_with($host, '[') && str_ends_with($host, ']') ? substr($host, 1, -1) : $host;
        return filter_var($bare, FILTER_VALIDATE_IP) !== false
            || preg_match('/^(?:[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?\.)*[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?$/', $host) === 1;
    }

    private function guard(): void
    {
        if ($this->app->installed()) throw new HttpException(403, 'Application already installed.');
    }
}

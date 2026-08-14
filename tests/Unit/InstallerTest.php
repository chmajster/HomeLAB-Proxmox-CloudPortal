<?php

declare(strict_types=1);

namespace CloudPortal\Tests\Unit;

use CloudPortal\Application;
use CloudPortal\Http\HttpException;
use CloudPortal\Installer\InstallerState;
use CloudPortal\Installer\Services\InstallationLock;
use CloudPortal\Installer\Services\InstallerLogger;
use CloudPortal\Installer\Services\ProxmoxTester;
use CloudPortal\Installer\Services\RequirementsChecker;
use CloudPortal\Installer\Services\RuntimeConfigWriter;
use CloudPortal\Installer\Services\SensitiveFilePermissions;
use CloudPortal\Installer\Validators\InstallerInput;
use CloudPortal\Installer\Validators\InstallerValidationException;
use PHPUnit\Framework\TestCase;

final class InstallerTest extends TestCase
{
    /** @var list<string> */
    private array $temporaryDirectories = [];

    protected function setUp(): void
    {
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        foreach ($this->temporaryDirectories as $directory) $this->removeDirectory($directory);
        $_SESSION = [];
    }

    public function testReleaseWorksWithoutComposerAndProtectsRuntimeFiles(): void
    {
        $root = dirname(__DIR__, 2);
        self::assertFileExists($root . '/autoload.php');
        self::assertStringContainsString("'/autoload.php'", (string) file_get_contents($root . '/public/index.php'));
        $gitignore = (string) file_get_contents($root . '/.gitignore');
        self::assertStringContainsString('/config/runtime.php', $gitignore);
        self::assertStringContainsString('/storage/installed.lock', $gitignore);
        $htaccess = (string) file_get_contents($root . '/.htaccess');
        self::assertStringContainsString('RewriteRule ^public(?:/|$) - [F,L,NC]', $htaccess);
        self::assertStringContainsString('RewriteRule ^assets/(.*)$ public/assets/$1 [END]', $htaccess);
    }

    public function testInterfaceUsesBundledAccessibleSvgIconsInsteadOfFontGlyphs(): void
    {
        $root = dirname(__DIR__, 2);
        $sprite = (string) file_get_contents($root . '/public/assets/icons.svg');
        self::assertStringContainsString('<symbol id="i-dashboard"', $sprite);
        self::assertStringContainsString('<symbol id="i-proxmox"', $sprite);
        self::assertStringContainsString('<symbol id="i-shield-check"', $sprite);
        self::assertStringNotContainsString('<script', strtolower($sprite));

        $interface = implode("\n", array_map(static fn (string $path): string => (string) file_get_contents($root . '/' . $path), [
            'resources/views/pages/portal.php', 'installer/Views/wizard.php', 'installer/Views/finish.php',
            'installer/Views/locked.php', 'public/assets/js/app.js', 'public/assets/js/installer.js',
        ]));
        self::assertStringContainsString('/assets/icons.svg#i-', $interface);
        self::assertDoesNotMatchRegularExpression('/[☁▦◇⌁✓×○◌↪☰◐⌂▣⌘◫↻♙◎▱≡≣⚙]/u', $interface);
        self::assertStringContainsString('aria-hidden="true"', $interface);
    }

    public function testAdminInterfaceExposesCreationButtonsForEveryCreatableResource(): void
    {
        $root = dirname(__DIR__, 2);
        $source = (string) file_get_contents($root . '/public/assets/js/app.js');
        foreach (['users', 'projects', 'proxmox', 'plans', 'quotas', 'settings'] as $resource) {
            self::assertMatchesRegularExpression('/\\b' . preg_quote($resource, '/') . ": \\{pl:'[^']+', en:'[^']+'\\}/", $source);
            self::assertMatchesRegularExpression('/\\b' . preg_quote($resource, '/') . ': `/', $source);
        }
        self::assertStringContainsString("projects:()=>managedResource('projects')", $source);
        self::assertStringContainsString("networks:()=>isAdmin?networkResource():simpleResource('networks')", $source);
        self::assertStringContainsString("templates:()=>isAdmin?templateResource():simpleResource('templates')", $source);
        self::assertStringContainsString('storages:storageResource', $source);
        self::assertStringContainsString('id="adminCreateToggle"', $source);
        self::assertStringContainsString('aria-controls="adminCreatePanel"', $source);
        self::assertStringContainsString("projects: {pl:'Utwórz projekt', en:'Create project'}", $source);
        self::assertStringContainsString("api('/api/v1/admin/networks/discovery')", $source);
        self::assertStringContainsString("api('/api/v1/admin/storages/discovery')", $source);
        self::assertStringContainsString("api('/api/v1/admin/templates/discovery')", $source);
        self::assertStringContainsString("api('/api/v1/admin/vms/discovery')", $source);
        self::assertStringContainsString("'Odśwież z Proxmox':'Refresh from Proxmox'", $source);
        self::assertStringContainsString("'Skonfiguruj IPAM':'Configure IPAM'", $source);
        self::assertStringContainsString("'Template dostępne w Proxmox':'Templates available in Proxmox'", $source);
        self::assertStringContainsString("'Storage Proxmox':'Proxmox storage'", $source);
        self::assertStringContainsString("'Maszyny wirtualne Proxmox':'Proxmox virtual machines'", $source);
        self::assertStringContainsString("data-live-action=\"suspend\"", $source);
        self::assertStringContainsString("data-live-action=\"console\"", $source);
        self::assertStringContainsString("data-live-delete-snapshot", $source);
        self::assertStringContainsString("api('/api/v1/admin/template-builder/options')", $source);
        self::assertStringContainsString("'/api/v1/admin/iso-uploads'", $source);
        self::assertStringContainsString("id=\"isoUploadForm\"", $source);
        self::assertStringContainsString("id=\"installVmForm\"", $source);
        self::assertStringContainsString("id=\"convertVmForm\"", $source);
        self::assertStringNotContainsString("networks: {pl:'Utwórz sieć', en:'Create network'}", $source);
        self::assertStringNotContainsString("templates: {pl:'Dodaj template', en:'Add template'}", $source);
        self::assertStringNotContainsString("storages: {pl:'Dodaj storage', en:'Add storage'}", $source);

        $routes = (string) file_get_contents($root . '/routes/api.php');
        self::assertStringContainsString("'/api/v1/admin/networks/discovery'", $routes);
        self::assertStringContainsString("'/api/v1/admin/storages/discovery'", $routes);
        self::assertStringContainsString("'/api/v1/admin/templates/discovery'", $routes);
        self::assertStringContainsString("'/api/v1/admin/vms/discovery'", $routes);
        self::assertStringContainsString("'/api/v1/admin/proxmox-vms/{connectionId}/{node}/{vmid}/status/{action}'", $routes);
        self::assertStringContainsString("'/api/v1/admin/proxmox-vms/{connectionId}/{node}/{vmid}/snapshots'", $routes);
        self::assertStringContainsString("'/api/v1/admin/proxmox-vms/{connectionId}/{node}/{vmid}/console'", $routes);
        self::assertStringContainsString("'/api/v1/admin/iso-uploads/{uploadId}/chunks'", $routes);
        self::assertStringContainsString("'/api/v1/admin/template-builder/vms'", $routes);
        self::assertStringContainsString("'/api/v1/admin/template-builder/convert'", $routes);

        $layout = (string) file_get_contents($root . '/resources/views/layouts/app.php');
        self::assertStringContainsString("app.js?v=<?= \$assetVersion('js/app.js') ?>", $layout);
    }

    public function testInstallerProgressLinksOnlyReachableSteps(): void
    {
        $root = dirname(__DIR__, 2);
        $view = new \CloudPortal\Support\View($root . '/installer/Views', ['basePath' => '/cloud']);
        $html = $view->render('wizard', [
            'step' => 1,
            'completed' => 4,
            'csrf' => 'test-token',
            'requirements' => [],
            'error' => null,
            'fieldErrors' => [],
            'values' => [],
            'timezones' => ['UTC'],
            'version' => Application::VERSION,
        ], null);

        self::assertStringContainsString('href="/cloud/install?step=0"', $html);
        self::assertStringContainsString('href="/cloud/install?step=4"', $html);
        self::assertStringContainsString('href="/cloud/install?step=5"', $html);
        self::assertStringNotContainsString('step=3', $html);
        self::assertStringNotContainsString('step=7', $html);
        self::assertStringNotContainsString('step=8', $html);
        self::assertStringNotContainsString('Utworzenie wersjonowanego schematu', $html);
        self::assertStringNotContainsString('Bezpieczne sekrety aplikacji', $html);
        self::assertStringNotContainsString('Zapis konfiguracji runtime', $html);
        self::assertStringContainsString('Krok 2 z 7', $html);
        self::assertStringContainsString('>5</span><small>Proxmox</small>', $html);
        self::assertStringContainsString('aria-current="step"', $html);
        self::assertStringContainsString('id="continueButton" href="/cloud/install?step=2">Dalej', $html);
        self::assertStringNotContainsString('Wróć do bieżącego kroku', $html);
        self::assertSame(5, substr_count($html, 'class="installer-step-link" href='));
        self::assertSame(2, substr_count($html, 'class="installer-step-link" aria-disabled="true"'));
    }

    public function testInstallerUsesAScopedLightThemeOnEveryInstallerView(): void
    {
        $root = dirname(__DIR__, 2);
        $view = new \CloudPortal\Support\View($root . '/installer/Views', ['basePath' => '/cloud']);
        $html = $view->render('locked', [], 'layout');
        $css = (string) file_get_contents($root . '/public/assets/css/app.css');
        $guestLayout = (string) file_get_contents($root . '/resources/views/layouts/guest.php');

        self::assertStringContainsString('<html lang="pl" data-bs-theme="light">', $html);
        self::assertStringContainsString('<meta name="color-scheme" content="light">', $html);
        self::assertStringContainsString('<meta name="theme-color" content="#f6f8fc">', $html);
        self::assertStringContainsString('.installer-bg {', $css);
        self::assertStringContainsString('--portal-panel: #fff;', $css);
        self::assertStringContainsString('.installer-bg .alert-danger', $css);
        self::assertStringContainsString('data-bs-theme="dark"', $guestLayout);
    }

    public function testEnvironmentStepIsHiddenOnlyWhenEveryRequirementPassed(): void
    {
        $root = dirname(__DIR__, 2);
        $view = new \CloudPortal\Support\View($root . '/installer/Views', ['basePath' => '/cloud']);
        $html = $view->render('wizard', [
            'step' => 2, 'completed' => 1, 'csrf' => 'test-token', 'requirements' => [],
            'requirementsHidden' => true, 'error' => null, 'fieldErrors' => [], 'values' => [],
            'timezones' => ['UTC'], 'version' => Application::VERSION,
        ], null);

        self::assertStringNotContainsString('Kontrola środowiska', $html);
        self::assertStringNotContainsString('>Wymagania</small>', $html);
        self::assertStringNotContainsString('step=1', $html);
        self::assertStringContainsString('Krok 2 z 6', $html);
        self::assertStringContainsString('installer-progress steps-6', $html);
        self::assertStringContainsString('href="/cloud/install?step=0"', $html);
    }

    public function testInstallerStateSurvivesRefreshAndRejectsSkippedSteps(): void
    {
        $state = new InstallerState();
        self::assertSame(-1, $state->completedStep());
        self::assertFalse($state->canView(1));
        try {
            $state->assertSubmittable(1);
            self::fail('Skipping a step was accepted.');
        } catch (HttpException $exception) {
            self::assertSame(409, $exception->status);
        }
        $state->markCompleted(0);
        $state->put('database', ['host' => 'db.test']);
        $refreshed = new InstallerState();
        self::assertSame(0, $refreshed->completedStep());
        self::assertSame(['host' => 'db.test'], $refreshed->get('database'));
        $refreshed->markCompleted(0);
        self::assertSame(0, $refreshed->completedStep());
        $refreshed->put('requirements_auto_passed', true);
        self::assertSame(2, $refreshed->nextStep());
        self::assertFalse($refreshed->canView(1));
        self::assertTrue($refreshed->canView(2));
        $refreshed->markCompleted(2);
        self::assertSame(4, $refreshed->nextStep());
        self::assertFalse($refreshed->canView(3));
        self::assertTrue($refreshed->canView(4));
        $refreshed->markCompleted(6);
        self::assertSame(9, $refreshed->nextStep());
        self::assertFalse($refreshed->canView(7));
        self::assertFalse($refreshed->canView(8));
        self::assertTrue($refreshed->canView(9));
    }

    public function testSecurityKeysHaveFullEntropyAndRemainStableDuringRefresh(): void
    {
        $state = new InstallerState();
        $first = $state->security();
        $second = (new InstallerState())->security();
        self::assertSame($first, $second);
        self::assertCount(3, array_unique($first));
        foreach ($first as $key) self::assertSame(32, strlen((string) base64_decode($key, true)));
    }

    public function testDatabaseAndAdministratorValidationRejectUnsafeInput(): void
    {
        $this->expectException(InstallerValidationException::class);
        InstallerInput::database(['db_host' => "db.test\r\nInjected", 'db_port' => 70000, 'db_name' => 'bad name', 'db_user' => '']);
    }

    public function testValidDatabaseAndStrongAdministratorInputAreNormalized(): void
    {
        $database = InstallerInput::database([
            'db_host' => '127.0.0.1', 'db_port' => '3306', 'db_name' => 'cloud_test',
            'db_user' => 'portal', 'db_password' => 'secret', 'confirm_existing_database' => '1',
        ]);
        self::assertSame(3306, $database['port']);
        self::assertTrue($database['confirm_existing']);
        $administrator = InstallerInput::administrator([
            'username' => 'owner', 'email' => '',
            'password' => 'A-secure-password-123', 'password_confirmation' => 'A-secure-password-123',
        ]);
        self::assertSame('owner', $administrator['username']);
        self::assertSame('owner@localhost.invalid', $administrator['email']);
        self::assertFalse($administrator['resume']);
        self::assertFalse($administrator['test_account']);
        $portal = InstallerInput::portal(['portal_name' => 'Portal', 'base_url' => 'https://example.test/cloud', 'timezone' => 'UTC', 'locale' => 'pl', 'session_lifetime' => 7200]);
        self::assertSame('https://example.test/cloud', $portal['url']);
    }

    public function testExplicitTestAdministratorShortcutUsesDocumentedCredentials(): void
    {
        $administrator = InstallerInput::administrator(['use_test_administrator' => '1']);
        self::assertSame('admin', $administrator['username']);
        self::assertSame('admin@localhost.invalid', $administrator['email']);
        self::assertSame('1', $administrator['password']);
        self::assertFalse($administrator['resume']);
        self::assertTrue($administrator['test_account']);
    }

    public function testOptionalEmailStillRejectsAnInvalidProvidedAddress(): void
    {
        $this->expectException(InstallerValidationException::class);
        InstallerInput::administrator([
            'username' => 'owner', 'email' => 'not-an-email',
            'password' => 'A-secure-password-123', 'password_confirmation' => 'A-secure-password-123',
        ]);
    }

    public function testRequirementsExposePassWarningAndErrorContractWithoutInternetProbe(): void
    {
        $checks = (new RequirementsChecker(dirname(__DIR__, 2)))->check();
        self::assertNotEmpty($checks);
        foreach ($checks as $check) {
            self::assertSame(['name', 'required', 'detected', 'status'], array_keys($check));
            self::assertContains($check['status'], ['pass', 'warning', 'error']);
        }
        $checker = new RequirementsChecker(dirname(__DIR__, 2));
        self::assertTrue($checker->allPassed([['name' => 'PHP', 'required' => 'Yes', 'detected' => 'Yes', 'status' => 'pass']]));
        self::assertFalse($checker->allPassed([['name' => 'mod_rewrite', 'required' => 'Yes', 'detected' => 'Unknown', 'status' => 'warning']]));
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/installer/Services/RequirementsChecker.php');
        self::assertDoesNotMatchRegularExpression('/curl_|file_get_contents\s*\(\s*[\'\"]https?:/i', $source);
    }

    public function testProxmoxTestReadsAllRequiredApiResources(): void
    {
        $requested = [];
        $tester = new ProxmoxTester(static function () use (&$requested): object {
            return new class($requested) {
                /** @param list<string> $requested */
                public function __construct(private array &$requested) {}
                public function get(string $path): array
                {
                    $this->requested[] = $path;
                    return match ($path) {
                        '/cluster/status' => [['type' => 'cluster', 'name' => 'test-cluster']],
                        '/nodes' => [['node' => 'pve1'], ['node' => 'pve2']],
                        '/version' => ['version' => '9.0.1'],
                        '/storage' => [['storage' => 'local']],
                    };
                }
            };
        });
        $result = $tester->test($this->proxmoxConfig('not-returned-to-browser'));
        self::assertSame(['/cluster/status', '/nodes', '/version', '/storage'], $requested);
        self::assertSame(['cluster' => 'test-cluster', 'nodes' => 2, 'version' => '9.0.1', 'storages' => 1], $result);
        self::assertStringNotContainsString('not-returned-to-browser', json_encode($result, JSON_THROW_ON_ERROR));
    }

    public function testBadProxmoxTokenProducesOnlySafeError(): void
    {
        $secret = 'very-secret-proxmox-token';
        $tester = new ProxmoxTester(static fn (): object => throw new \RuntimeException('Rejected token ' . $secret));
        try {
            $tester->test($this->proxmoxConfig($secret));
            self::fail('Invalid token was accepted.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('przed otrzymaniem statusu HTTP', $exception->getMessage());
            self::assertStringNotContainsString($secret, $exception->getMessage());
        }
    }

    public function testProxmoxHttpFailureIncludesExactSafeStatusAndApiDetail(): void
    {
        $secret = 'very-secret-proxmox-token';
        $tester = new ProxmoxTester(static fn (): object => throw new \CloudPortal\Services\Proxmox\ProxmoxException(
            'authentication failure for ' . $secret,
            401,
            ['errors' => ['token' => 'authentication failed']],
        ));

        try {
            $tester->test($this->proxmoxConfig($secret));
            self::fail('Invalid token was accepted.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('HTTP 401 (Unauthorized)', $exception->getMessage());
            self::assertStringContainsString('authentication failure', $exception->getMessage());
            self::assertStringContainsString('Token ID lub sekret są nieprawidłowe', $exception->getMessage());
            self::assertStringNotContainsString($secret, $exception->getMessage());
        }
    }

    public function testProxmoxTransportFailureIncludesCurlCodeAndEndpoint(): void
    {
        $tester = new ProxmoxTester(static fn (): object => throw new \CloudPortal\Services\Proxmox\ProxmoxException(
            'Proxmox connection failed: Connection refused', 0, null, 7,
        ));

        try {
            $tester->test($this->proxmoxConfig('safe-secret'));
            self::fail('Unavailable endpoint was accepted.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('cURL 7', $exception->getMessage());
            self::assertStringContainsString('pve.example.test:8006', $exception->getMessage());
            self::assertStringContainsString('odrzuca połączenie', $exception->getMessage());
        }
    }

    public function testInstallerExplainsThatABareLoginIsNotAProxmoxToken(): void
    {
        try {
            InstallerInput::proxmox([
                'connection_name' => 'S1', 'hostname' => '10.0.0.1', 'port' => '8006', 'realm' => 'pve',
                'api_token_id' => 'root', 'api_token_secret' => '1', 'verify_ssl' => false,
            ]);
            self::fail('A username was accepted as an API token ID.');
        } catch (InstallerValidationException $exception) {
            self::assertStringContainsString('root@pam!cloudportal', $exception->getMessage());
            self::assertArrayHasKey('api_token_id', $exception->fields);
        }
    }

    public function testRuntimeConfigIsPendingUntilActivationAndLock(): void
    {
        $root = $this->temporaryRoot();
        $state = $this->completeState();
        $writer = new RuntimeConfigWriter($root);
        $path = $writer->write($state);
        $pending = require $path;
        self::assertFalse($pending['app']['installed']);
        self::assertFileDoesNotExist($root . '/storage/installed.lock');
        self::assertFalse((new Application($root))->installed());

        $writer->write($state);
        $writer->activate($state['install_id']);
        (new InstallationLock($root . '/storage/installed.lock'))->create($state['install_id'], Application::VERSION);
        self::assertTrue((require $path)['app']['installed']);
        self::assertTrue((new Application($root))->installed());
    }

    public function testRuntimeAndLockWorkOnTheProjectFilesystemUsedByWindowsAndWsl(): void
    {
        $root = $this->temporaryRoot(dirname(__DIR__, 2) . '/storage/cache');
        $state = $this->completeState();
        $writer = new RuntimeConfigWriter($root);
        $path = $writer->write($state);
        $writer->verify($state['install_id'], false);
        $writer->activate($state['install_id']);
        $writer->verify($state['install_id'], true);
        $lockPath = $root . '/storage/installed.lock';
        (new InstallationLock($lockPath))->create($state['install_id'], Application::VERSION);
        self::assertFileExists($path);
        self::assertFileExists($lockPath);
        self::assertTrue(SensitiveFilePermissions::areSafe($path));
        self::assertTrue(SensitiveFilePermissions::areSafe($lockPath));
        self::assertTrue((new Application($root))->installed());
    }

    public function testRuntimeVerificationRejectsExposedPermissionsOnPosixFilesystems(): void
    {
        $root = $this->temporaryRoot();
        $state = $this->completeState();
        $writer = new RuntimeConfigWriter($root);
        $path = $writer->write($state);
        if (SensitiveFilePermissions::usesWindowsAcl($path)) self::markTestSkipped('POSIX permission bits are not authoritative on this filesystem.');
        chmod($path, 0644);
        clearstatcache(true, $path);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('unsafe POSIX permissions');
        $writer->verify($state['install_id']);
    }

    public function testInterruptedInstallationCanRetryWithoutDuplicateConfigOrLock(): void
    {
        $root = $this->temporaryRoot();
        $state = $this->completeState();
        $writer = new RuntimeConfigWriter($root);
        $first = $writer->write($state);
        $second = $writer->write($state);
        self::assertSame($first, $second);
        $writer->activate($state['install_id']);
        $writer->activate($state['install_id']);
        $lock = new InstallationLock($root . '/storage/installed.lock');
        $lock->create($state['install_id'], Application::VERSION);
        $before = file_get_contents($root . '/storage/installed.lock');
        $lock->create($state['install_id'], Application::VERSION);
        self::assertSame($before, file_get_contents($root . '/storage/installed.lock'));
    }

    public function testRuntimeWriterRefusesToOverwriteAnotherInstallation(): void
    {
        $root = $this->temporaryRoot();
        $writer = new RuntimeConfigWriter($root);
        $writer->write($this->completeState());
        $other = $this->completeState();
        $other['install_id'] = str_repeat('b', 32);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('another installation');
        $writer->write($other);
    }

    public function testInstallerLogRedactsEveryProvidedSecret(): void
    {
        $root = $this->temporaryRoot();
        $secret = 'database-password-that-must-not-leak';
        $logger = new InstallerLogger($root . '/storage/logs/installer.log');
        $logger->error('database', new \RuntimeException('Failure contained ' . $secret), [$secret]);
        $log = (string) file_get_contents($root . '/storage/logs/installer.log');
        self::assertStringContainsString('[REDACTED]', $log);
        self::assertStringNotContainsString($secret, $log);
    }

    /** @return array<string,mixed> */
    private function completeState(): array
    {
        return [
            'install_id' => str_repeat('a', 32),
            'database' => ['host' => '127.0.0.1', 'port' => 3306, 'name' => 'cloud_test', 'user' => 'portal', 'password' => 'db-secret'],
            'portal' => ['name' => 'Test Portal', 'url' => 'https://portal.example.test', 'timezone' => 'Europe/Warsaw', 'locale' => 'pl', 'session_lifetime' => 7200],
            'security' => ['app_key' => base64_encode(random_bytes(32)), 'encryption_key' => base64_encode(random_bytes(32)), 'csrf_secret' => base64_encode(random_bytes(32))],
        ];
    }

    /** @return array<string,mixed> */
    private function proxmoxConfig(string $secret): array
    {
        return ['hostname' => 'pve.example.test', 'port' => 8006, 'realm' => 'pve', 'token_id' => 'portal@pve!cloud', 'token_secret' => $secret, 'verify_ssl' => true];
    }

    private function temporaryRoot(?string $parent = null): string
    {
        $root = rtrim($parent ?? sys_get_temp_dir(), '/\\') . '/cloud-portal-installer-' . bin2hex(random_bytes(8));
        foreach (['config', 'storage', 'storage/logs', 'storage/cache', 'resources/views'] as $directory) {
            if (!mkdir($root . '/' . $directory, 0700, true) && !is_dir($root . '/' . $directory)) throw new \RuntimeException('Cannot create test directory.');
        }
        copy(dirname(__DIR__, 2) . '/config/defaults.php', $root . '/config/defaults.php');
        $this->temporaryDirectories[] = $root;
        return $root;
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) return;
        $items = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($items as $item) $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        rmdir($directory);
    }
}

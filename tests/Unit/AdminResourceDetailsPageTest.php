<?php

declare(strict_types=1);

namespace CloudPortal\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class AdminResourceDetailsPageTest extends TestCase
{
    public function testDedicatedAdminResourceRoutesAreRegisteredBeforeGenericRoutes(): void
    {
        $api = file_get_contents(__DIR__ . '/../../routes/api.php');
        $web = file_get_contents(__DIR__ . '/../../routes/web.php');
        self::assertIsString($api);
        self::assertIsString($web);
        self::assertStringContainsString("/api/v1/admin/details/{resource}/{id}", $api);
        self::assertStringContainsString("/admin/{resource}/{id}", $web);
        self::assertLessThan(strpos($api, "/api/v1/admin/{resource}"), strpos($api, "/api/v1/admin/details/{resource}/{id}"));
    }

    public function testDetailsApiDoesNotExposeStoredSecrets(): void
    {
        $controller = file_get_contents(__DIR__ . '/../../app/Controllers/AdminResourceDetailsController.php');
        self::assertIsString($controller);
        self::assertStringContainsString("private const ALLOWED = ['users', 'proxmox', 'networks', 'templates', 'storages', 'plans']", $controller);
        self::assertStringNotContainsString('api_token_secret_encrypted', $controller);
        self::assertStringNotContainsString('password_hash', $controller);
    }

    public function testAdminActionsNavigateToPagesInsteadOfPromptingForIdsOrSecrets(): void
    {
        $navigation = file_get_contents(__DIR__ . '/../../public/assets/js/admin-resource-navigation.js');
        $project = file_get_contents(__DIR__ . '/../../public/assets/js/project-details.js');
        self::assertIsString($navigation);
        self::assertIsString($project);
        self::assertStringNotContainsString('prompt(', $navigation);
        self::assertStringContainsString('/admin/users/', $navigation);
        self::assertStringContainsString('/admin/proxmox/', $navigation);
        self::assertStringContainsString('/projects/', $navigation);
        self::assertStringContainsString('addProjectMember', $project);
        self::assertStringContainsString('assignProjectAccess', $project);
        self::assertStringContainsString('/api/v1/admin/projects/${projectId}/members', $project);
        self::assertStringContainsString('/api/v1/admin/projects/${projectId}/access', $project);
    }

    public function testSpaIsDisabledOnDedicatedAdminDetailsPage(): void
    {
        $layout = file_get_contents(__DIR__ . '/../../resources/views/layouts/app.php');
        self::assertIsString($layout);
        self::assertStringContainsString("'admin-resource-details'", $layout);
        self::assertStringContainsString('admin-resource-details.js', $layout);
        self::assertStringContainsString('admin-resource-navigation.js', $layout);
    }
}

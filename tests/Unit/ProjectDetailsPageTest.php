<?php

declare(strict_types=1);

namespace CloudPortal\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class ProjectDetailsPageTest extends TestCase
{
    public function testProjectDetailsUseDedicatedPageInsteadOfModal(): void
    {
        $root = dirname(__DIR__, 2);
        $routes = (string) file_get_contents($root . '/routes/web.php');
        $layout = (string) file_get_contents($root . '/resources/views/layouts/app.php');
        $enhancements = (string) file_get_contents($root . '/public/assets/js/portal-enhancements.js');
        $details = (string) file_get_contents($root . '/public/assets/js/project-details.js');

        self::assertStringContainsString("'/projects/{id}'", $routes);
        self::assertStringContainsString('ProjectDetailsPageController', $routes);
        self::assertStringContainsString("'project-details'", $layout);
        self::assertStringContainsString('project-details.js', $layout);
        self::assertStringContainsString('[data-admin-action="project-details"][data-id]', $enhancements);
        self::assertStringContainsString('location.assign(appUrl(`/projects/${id}`))', $enhancements);
        self::assertStringContainsString('event.stopImmediatePropagation()', $enhancements);
        self::assertStringContainsString('/api/v1/admin/projects/${projectId}', $details);
        self::assertStringContainsString('Wróć do projektów', $details);
        self::assertStringContainsString('Członkowie projektu', $details);
        self::assertStringContainsString('Przypisane sieci', $details);
        self::assertStringContainsString('Przypisany storage', $details);
    }
}

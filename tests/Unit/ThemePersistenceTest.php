<?php

declare(strict_types=1);

namespace CloudPortal\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class ThemePersistenceTest extends TestCase
{
    public function testSharedThemeBootstrapLoadsBeforeStylesheet(): void
    {
        $root = dirname(__DIR__, 2);
        $layout = (string) file_get_contents($root . '/resources/views/layouts/app.php');
        $themeScript = (string) file_get_contents($root . '/public/assets/js/theme.js');

        $themePosition = strpos($layout, '/assets/js/theme.js');
        $cssPosition = strpos($layout, '/assets/css/app.css');

        self::assertNotFalse($themePosition);
        self::assertNotFalse($cssPosition);
        self::assertLessThan($cssPosition, $themePosition);
        self::assertStringContainsString("localStorage.getItem(storageKey)", $themeScript);
        self::assertStringContainsString("root.dataset.bsTheme = theme", $themeScript);
    }

    public function testVmDetailsKeepsAndCanChangeStoredThemeWithoutAppJs(): void
    {
        $root = dirname(__DIR__, 2);
        $layout = (string) file_get_contents($root . '/resources/views/layouts/app.php');
        $themeScript = (string) file_get_contents($root . '/public/assets/js/theme.js');

        self::assertStringContainsString("['vm-details', 'project-details', 'admin-resource-details', 'settings']", $layout);
        self::assertStringContainsString("['vm-details', 'project-details', 'admin-resource-details']", $themeScript);
        self::assertStringContainsString("document.getElementById('themeButton')", $themeScript);
        self::assertStringContainsString("localStorage.setItem(storageKey, next)", $themeScript);
    }
}

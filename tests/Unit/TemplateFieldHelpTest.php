<?php

declare(strict_types=1);

namespace CloudPortal\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class TemplateFieldHelpTest extends TestCase
{
    public function testTemplateCatalogFieldsHaveHoverAndKeyboardHelp(): void
    {
        $root = dirname(__DIR__, 2);
        $layout = (string) file_get_contents($root . '/resources/views/layouts/app.php');
        $script = (string) file_get_contents($root . '/public/assets/js/template-field-help.js');
        $styles = (string) file_get_contents($root . '/public/assets/css/template-field-help.css');

        self::assertStringContainsString("\$page === 'templates'", $layout);
        self::assertStringContainsString('template-field-help.js', $layout);
        self::assertStringContainsString('template-field-help.css', $layout);

        foreach ([
            'templateConnection',
            'templateNode',
            'templateVmid',
            'templateName',
            'templateOs',
            'templateDescription',
        ] as $field) {
            self::assertStringContainsString($field, $script);
        }

        self::assertStringContainsString('Pole jest tylko do odczytu.', $script);
        self::assertStringContainsString('Portal użyje go jako źródła klonowania.', $script);
        self::assertStringContainsString('Nie zmienia nazwy template w Proxmox.', $script);
        self::assertStringContainsString("help.tabIndex = 0", $script);
        self::assertStringContainsString("help.setAttribute('aria-label', text)", $script);
        self::assertStringContainsString("help.textContent = '?'", $script);

        self::assertStringContainsString('.field-help:hover::after', $styles);
        self::assertStringContainsString('.field-help:focus-visible::after', $styles);
        self::assertStringContainsString('content: attr(data-help)', $styles);
    }
}

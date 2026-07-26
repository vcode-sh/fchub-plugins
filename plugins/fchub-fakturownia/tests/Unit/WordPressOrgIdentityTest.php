<?php

declare(strict_types=1);

namespace FChubFakturownia\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class WordPressOrgIdentityTest extends TestCase
{
    public function testMainPluginFileDeclaresWordPressOrgIdentity(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/fchub-fakturownia.php');

        self::assertIsString($source);
        self::assertStringContainsString('Plugin Name: FCHub Fakturownia', $source);
        self::assertStringContainsString('Plugin URI: https://fchub.co/docs/fchub-fakturownia', $source);
        self::assertStringContainsString('Version: 1.1.2', $source);
        self::assertStringContainsString('Requires at least: 7.0', $source);
        self::assertStringContainsString('Tested up to: 7.0', $source);
        self::assertStringContainsString('Requires PHP: 8.3', $source);
        self::assertStringContainsString('Requires Plugins: fluent-cart', $source);
        self::assertStringContainsString('Text Domain: fchub-fakturownia', $source);
        self::assertStringContainsString("define('FCHUB_FAKTUROWNIA_VERSION', '1.1.2');", $source);
        self::assertStringNotContainsString('Update URI:', $source);
        self::assertStringNotContainsString('GitHubUpdater', $source);
    }
}

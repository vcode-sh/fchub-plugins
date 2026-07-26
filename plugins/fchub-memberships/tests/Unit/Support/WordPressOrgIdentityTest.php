<?php

declare(strict_types=1);

namespace FChubMemberships\Tests\Unit\Support;

use FChubMemberships\Tests\Unit\PluginTestCase;

final class WordPressOrgIdentityTest extends PluginTestCase
{
    public function test_plugin_declares_the_wordpress_org_release_identity(): void
    {
        $pluginRoot = dirname(__DIR__, 3);
        $source = file_get_contents($pluginRoot . '/fchub-memberships.php');
        $composer = json_decode(
            (string) file_get_contents($pluginRoot . '/composer.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        self::assertIsString($source);
        self::assertStringContainsString('Plugin Name: FCHub Memberships', $source);
        self::assertStringContainsString('Version: 1.4.1', $source);
        self::assertStringContainsString('Requires at least: 7.0', $source);
        self::assertStringContainsString('Requires PHP: 8.3', $source);
        self::assertStringContainsString('Requires Plugins: fluent-cart', $source);
        self::assertStringContainsString('Tested up to:    7.0', $source);
        self::assertStringNotContainsString('Update URI:', $source);
        self::assertStringNotContainsString('GitHubUpdater', $source);
        self::assertStringContainsString(
            "define('FCHUB_MEMBERSHIPS_VERSION', '1.4.1')",
            $source,
        );
        self::assertSame('>=8.3', $composer['require']['php'] ?? null);
    }
}

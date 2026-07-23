<?php

declare(strict_types=1);

namespace FChubMemberships\Tests\Unit\Core;

use FChubMemberships\Tests\Unit\PluginTestCase;

final class VersionMetadataContractTest extends PluginTestCase
{
    public function test_release_metadata_matches_the_plugin_header(): void
    {
        $pluginRoot = dirname(__DIR__, 3);
        $header = file_get_contents($pluginRoot . '/fchub-memberships.php');
        $package = json_decode((string) file_get_contents($pluginRoot . '/package.json'), true, 512, JSON_THROW_ON_ERROR);
        $packageLock = json_decode(
            (string) file_get_contents($pluginRoot . '/package-lock.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $versions = json_decode(
            (string) file_get_contents(dirname($pluginRoot, 2) . '/web-docs/lib/versions.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $readme = (string) file_get_contents($pluginRoot . '/README.md');

        self::assertIsString($header);
        self::assertMatchesRegularExpression('/^\s*\*\s+Version:\s*(?<version>[^\r\n*]+)/m', $header);
        preg_match('/^\s*\*\s+Version:\s*(?<version>[^\r\n*]+)/m', $header, $matches);

        $headerVersion = trim($matches['version']);

        self::assertSame($headerVersion, $versions['plugins']['fchub-memberships']['version']);
        self::assertSame($headerVersion, $package['version']);
        self::assertSame($headerVersion, $packageLock['version']);
        self::assertSame($headerVersion, $packageLock['packages']['']['version']);
        self::assertStringContainsString('Version: `' . $headerVersion . '`', $readme);
        self::assertStringContainsString('Current plugin version: `' . $headerVersion . '`', $readme);
    }
}

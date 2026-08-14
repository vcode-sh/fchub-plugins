<?php

declare(strict_types=1);

namespace FChubFakturownia\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class WordPressOrgPackageContractTest extends TestCase
{
    public function testVersionAndIdentitySurfacesStayAligned(): void
    {
        $pluginRoot = dirname(__DIR__, 2);
        $mainSource = (string) file_get_contents($pluginRoot . '/fchub-fakturownia.php');
        $composer = json_decode((string) file_get_contents($pluginRoot . '/composer.json'), true);
        $lock = json_decode((string) file_get_contents($pluginRoot . '/composer.lock'), true);

        // The value is owned by VersionMetadataContractTest; here it only has to exist.
        self::assertMatchesRegularExpression('/^\s*\*\s+Version:\s*\S+/m', $mainSource);
        self::assertMatchesRegularExpression("/FCHUB_FAKTUROWNIA_VERSION', '\\S+'/", $mainSource);
        self::assertStringNotContainsString('FCHub - Fakturownia', $mainSource);
        self::assertSame('>=8.3', $composer['require']['php'] ?? null);
        self::assertSame('>=8.3', $lock['platform']['php'] ?? null);
    }

    public function testPackageInputsExcludeDevelopmentFilesAndBundledUpdater(): void
    {
        $pluginRoot = dirname(__DIR__, 2);
        $distIgnore = (string) file_get_contents($pluginRoot . '/.distignore');

        foreach ([
            'vendor/',
            'tests/',
            'composer.json',
            'composer.lock',
            'phpcs.xml',
            'phpstan.neon',
            'phpstan-bootstrap.php',
            'phpstan-stubs.stub',
            'README.md',
        ] as $ignoredPath) {
            self::assertStringContainsString($ignoredPath, $distIgnore);
        }

        // Updater presence is governed by tests/repository/updater-presence-contract.test.mjs.
        self::assertFileEquals(dirname($pluginRoot, 2) . '/LICENSE', $pluginRoot . '/LICENSE');
    }
}

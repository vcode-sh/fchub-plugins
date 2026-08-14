<?php

declare(strict_types=1);

namespace FChubFakturownia\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Every file that declares the plugin version agrees with the plugin header.
 *
 * The header is the source of truth because `build.sh` and the release workflow
 * both read the release version from it. A bump that misses one of these files
 * ships a plugin that disagrees with itself, so the version is asserted here and
 * nowhere else — the other suites assert the shape of a declaration, never its
 * value. `tests/bootstrap.php` is deliberately excluded: its `-test` sentinel is
 * not a declaration of the release version.
 */
final class VersionMetadataContractTest extends TestCase
{
    public function testEveryVersionDeclarationMatchesThePluginHeader(): void
    {
        $pluginRoot = dirname(__DIR__, 2);
        $mainFile = (string) file_get_contents($pluginRoot . '/fchub-fakturownia.php');
        $readme = (string) file_get_contents($pluginRoot . '/readme.txt');

        self::assertMatchesRegularExpression('/^\s*\*\s+Version:\s*(?<version>[^\r\n*]+)/m', $mainFile);
        preg_match('/^\s*\*\s+Version:\s*(?<version>[^\r\n*]+)/m', $mainFile, $matches);

        $version = trim($matches['version']);
        $define = sprintf("define('FCHUB_FAKTUROWNIA_VERSION', '%s');", $version);

        self::assertStringContainsString($define, $mainFile);
        self::assertStringContainsString($define, (string) file_get_contents($pluginRoot . '/phpstan-bootstrap.php'));
        self::assertStringContainsString('Stable tag: ' . $version, $readme);
        self::assertStringContainsString('= ' . $version . ' =', $readme);
    }
}

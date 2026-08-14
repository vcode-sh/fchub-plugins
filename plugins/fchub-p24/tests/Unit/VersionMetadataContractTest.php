<?php

namespace FChubP24\Tests\Unit;

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
class VersionMetadataContractTest extends TestCase
{
    public function testEveryVersionDeclarationMatchesThePluginHeader(): void
    {
        $pluginRoot = dirname(__DIR__, 2);
        $mainFile = (string) file_get_contents($pluginRoot . '/fchub-p24.php');
        $readme = (string) file_get_contents($pluginRoot . '/readme.txt');

        $this->assertMatchesRegularExpression('/^\s*\*\s+Version:\s*(?<version>[^\r\n*]+)/m', $mainFile);
        preg_match('/^\s*\*\s+Version:\s*(?<version>[^\r\n*]+)/m', $mainFile, $matches);

        $version = trim($matches['version']);
        $define = sprintf("define('FCHUB_P24_VERSION', '%s');", $version);

        $this->assertStringContainsString($define, $mainFile);
        $this->assertStringContainsString($define, (string) file_get_contents($pluginRoot . '/phpstan-bootstrap.php'));
        $this->assertStringContainsString('Stable tag: ' . $version, $readme);
        $this->assertStringContainsString('= ' . $version . ' =', $readme);
    }
}

<?php

namespace FChubP24\Tests\Unit;

use PHPUnit\Framework\TestCase;

class WordPressOrgIdentityTest extends TestCase
{
    private string $pluginRoot;

    protected function setUp(): void
    {
        $this->pluginRoot = dirname(__DIR__, 2);
    }

    public function testPluginHeaderMatchesWordPressOrgCandidate(): void
    {
        $plugin = file_get_contents($this->pluginRoot . '/fchub-p24.php');

        $this->assertIsString($plugin);
        $this->assertStringContainsString('Plugin Name: FCHub Przelewy24', $plugin);
        $this->assertStringContainsString('Plugin URI: https://fchub.co/docs/fchub-p24', $plugin);
        $this->assertStringContainsString('Version: 1.0.4', $plugin);
        $this->assertStringContainsString('Requires at least: 7.0', $plugin);
        $this->assertStringContainsString('Tested up to: 7.0', $plugin);
        $this->assertStringContainsString('Requires PHP: 8.3', $plugin);
        $this->assertStringContainsString('Requires Plugins: fluent-cart', $plugin);
        $this->assertStringContainsString('Text Domain: fchub-p24', $plugin);
        $this->assertStringNotContainsString('Domain Path:', $plugin);
        $this->assertStringContainsString("define('FCHUB_P24_VERSION', '1.0.4');", $plugin);
        $this->assertStringNotContainsString('Update URI:', $plugin);
        $this->assertStringNotContainsString('GitHubUpdater', $plugin);
    }

    public function testUpdaterAndTestDebrisAreAbsent(): void
    {
        $this->assertFileDoesNotExist($this->pluginRoot . '/lib/GitHubUpdater.php');
        $this->assertFileDoesNotExist($this->pluginRoot . '/test-updater.php');
        $this->assertFileDoesNotExist($this->pluginRoot . '/.DS_Store');
    }

    public function testReadmeAndLicenseMatchCandidate(): void
    {
        $readme = file_get_contents($this->pluginRoot . '/readme.txt');

        $this->assertIsString($readme);
        $this->assertStringContainsString('=== FCHub Przelewy24 ===', $readme);
        $this->assertStringContainsString('Contributors: vcodesh', $readme);
        $this->assertStringContainsString('Requires at least: 7.0', $readme);
        $this->assertStringContainsString('Tested up to: 7.0', $readme);
        $this->assertStringContainsString('Stable tag: 1.0.4', $readme);
        $this->assertStringContainsString('Requires PHP: 8.3', $readme);
        $this->assertStringContainsString('https://developers.przelewy24.pl/', $readme);
        $this->assertStringContainsString('https://www.przelewy24.pl/polityka-prywatnosci', $readme);
        $this->assertFileExists($this->pluginRoot . '/LICENSE');
    }

    public function testDistributionIncludesWordPressOrgDocumentationAndNeutralAsset(): void
    {
        $distignore = file_get_contents($this->pluginRoot . '/.distignore');
        $gateway = file_get_contents($this->pluginRoot . '/app/Gateway/Przelewy24Gateway.php');

        $this->assertIsString($distignore);
        $this->assertStringNotContainsString('*.md', $distignore);
        foreach (['README.md', 'lib/', 'phpcs.xml', 'phpstan.neon', 'phpstan-bootstrap.php', 'phpstan-functions.php'] as $ignoredFile) {
            $this->assertStringContainsString($ignoredFile, $distignore);
        }
        $this->assertIsString($gateway);
        $this->assertStringContainsString('assets/fchub-payment.svg', $gateway);
        $this->assertFileExists($this->pluginRoot . '/assets/fchub-payment.svg');
        $this->assertFileDoesNotExist($this->pluginRoot . '/assets/przelewy24-logo.svg');
        $this->assertFileDoesNotExist($this->pluginRoot . '/assets/przelewy24-icon.svg');
    }

    public function testDeactivationCleanupIsRestrictedToPluginActionGroup(): void
    {
        $plugin = file_get_contents($this->pluginRoot . '/fchub-p24.php');

        $this->assertIsString($plugin);
        $this->assertStringContainsString(
            "as_unschedule_all_actions('fchub_p24_process_renewal', [], 'fchub-p24')",
            $plugin
        );
    }

    public function testUninstallUsesFluentCartModelsInsteadOfDirectDatabaseQueries(): void
    {
        $uninstall = file_get_contents($this->pluginRoot . '/uninstall.php');

        $this->assertIsString($uninstall);
        $this->assertStringContainsString('\FluentCart\App\Models\Meta::query()', $uninstall);
        $this->assertStringContainsString('\FluentCart\App\Models\OrderMeta::query()', $uninstall);
        $this->assertStringNotContainsString('global $wpdb', $uninstall);
        $this->assertStringNotContainsString('SHOW TABLES', $uninstall);
        $this->assertStringNotContainsString('$wpdb->', $uninstall);
    }
}

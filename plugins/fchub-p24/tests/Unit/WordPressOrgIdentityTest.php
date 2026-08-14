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
        // The value is owned by VersionMetadataContractTest; here it only has to exist.
        $this->assertMatchesRegularExpression('/^\s*\*\s+Version:\s*\S+/m', $plugin);
        $this->assertStringContainsString('Requires at least: 7.0', $plugin);
        $this->assertStringContainsString('Tested up to: 7.0', $plugin);
        $this->assertStringContainsString('Requires PHP: 8.3', $plugin);
        $this->assertStringContainsString('Requires Plugins: fluent-cart', $plugin);
        $this->assertStringContainsString('Text Domain: fchub-p24', $plugin);
        $this->assertStringNotContainsString('Domain Path:', $plugin);
        $this->assertMatchesRegularExpression("/define\\('FCHUB_P24_VERSION', '\\S+'\\);/", $plugin);
        // Update URI and updater registration are governed by tests/repository/updater-presence-contract.test.mjs.
    }

    public function testUpdaterAndTestDebrisAreAbsent(): void
    {
        // Updater presence is governed by tests/repository/updater-presence-contract.test.mjs.
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
        $this->assertMatchesRegularExpression('/^Stable tag: \S+$/m', $readme);
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
        // lib/ must ship now that the guarded updater require is back — see tests/repository/updater-presence-contract.test.mjs.
        foreach (['README.md', 'phpcs.xml', 'phpstan.neon', 'phpstan-bootstrap.php', 'phpstan-functions.php'] as $ignoredFile) {
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

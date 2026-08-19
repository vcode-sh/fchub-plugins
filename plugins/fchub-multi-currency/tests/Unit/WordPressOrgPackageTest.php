<?php

declare(strict_types=1);

namespace FChubMultiCurrency\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class WordPressOrgPackageTest extends TestCase
{
    private string $pluginRoot;

    protected function setUp(): void
    {
        $this->pluginRoot = dirname(__DIR__, 2);
    }

    #[Test]
    public function readmeDeclaresTheReleaseAndVerifiedContributor(): void
    {
        $readme = file_get_contents($this->pluginRoot . '/readme.txt');
        $publisher = json_decode(
            (string) file_get_contents(dirname($this->pluginRoot, 2) . '/wporg/publisher.json'),
            true,
        );

        self::assertIsString($readme);
        self::assertIsArray($publisher);
        self::assertSame('vcodesh', $publisher['wordpressOrgUsername'] ?? null);
        self::assertStringContainsString('=== FCHub Multi-Currency ===', $readme);
        self::assertStringContainsString('Contributors: vcodesh', $readme);
        self::assertStringNotContainsString('vcode_sh', $readme);
        // The value is owned by VersionMetadataContractTest; here it only has to exist.
        self::assertMatchesRegularExpression('/^Stable tag: \S+$/m', $readme);
        self::assertStringContainsString('Requires at least: 7.0', $readme);
        self::assertStringContainsString('Tested up to: 7.0', $readme);
        self::assertStringContainsString('Requires PHP: 8.3', $readme);
        self::assertLessThan(10_000, strlen($readme));
    }

    #[Test]
    public function readmeDisclosesSettlementExternalServicesAndLocalData(): void
    {
        $readme = file_get_contents($this->pluginRoot . '/readme.txt');

        self::assertIsString($readme);
        self::assertStringContainsString('orders and payments remain in the store base currency', $readme);
        self::assertStringContainsString('== External services ==', $readme);
        self::assertStringContainsString('https://www.ecb.europa.eu/stats/eurofxref/eurofxref-daily.xml', $readme);
        self::assertStringContainsString('https://v6.exchangerate-api.com/v6/{key}/latest/{base}', $readme);
        self::assertStringContainsString('https://openexchangerates.org/api/latest.json', $readme);
        self::assertStringContainsString('Manual makes no remote request', $readme);
        self::assertStringContainsString('rate history', $readme);
        self::assertStringContainsString('event log', $readme);
        self::assertStringContainsString('currency preference', $readme);
        self::assertStringContainsString('90 days', $readme);
        self::assertStringContainsString('Privacy Tools', $readme);
    }

    #[Test]
    public function licenceAndDistributionRulesShipTheWordPressOrgFiles(): void
    {
        $licence = file_get_contents($this->pluginRoot . '/LICENSE');
        $distignore = file_get_contents($this->pluginRoot . '/.distignore');

        self::assertIsString($licence);
        self::assertStringContainsString('GNU GENERAL PUBLIC LICENSE', $licence);
        self::assertStringContainsString('Version 2, June 1991', $licence);
        self::assertIsString($distignore);
        self::assertStringNotContainsString('readme.txt', $distignore);
        self::assertStringNotContainsString('LICENSE', $distignore);
        self::assertStringContainsString('tests/', $distignore);
        self::assertStringContainsString('.phpunit.result.cache', $distignore);
        // Updater presence is governed by tests/repository/updater-presence-contract.test.mjs.
    }

    #[Test]
    public function translationsLoadFromTheLanguagesDirectory(): void
    {
        $mainFile = (string) file_get_contents($this->pluginRoot . '/fchub-multi-currency.php');

        self::assertStringContainsString('Domain Path: /languages', $mainFile);
        self::assertStringContainsString("load_plugin_textdomain('fchub-multi-currency'", $mainFile);
    }

    /**
     * The POT is the string catalogue translators start from. The sentinels
     * pin every extraction surface: visitor-facing PHP, admin REST PHP, the
     * admin SPA JS, the block editor JS, and block.json metadata. A missing
     * sentinel means an extraction surface silently fell out of the build.
     */
    #[Test]
    public function potCataloguesEveryStringSurface(): void
    {
        $potPath = $this->pluginRoot . '/languages/fchub-multi-currency.pot';

        self::assertFileExists($potPath);
        $pot = (string) file_get_contents($potPath);

        $sentinels = [
            'Prices are now shown in %s.',                  // visitor-facing PHP template
            'Your payment will be processed in {base_currency}.', // checkout disclosure default
            'Settings saved successfully.',                 // admin REST message
            'Multi-Currency Enabled',                       // admin SPA component JS
            'Network error. Please check your connection.', // admin SPA entry JS
            'Search currency',                              // preview + frontend shared string
            'Use global switcher defaults',                 // block editor JS
            'Currency Switcher',                            // block.json title
        ];

        foreach ($sentinels as $sentinel) {
            self::assertStringContainsString(
                'msgid "' . $sentinel . '"',
                $pot,
                "POT must catalogue: $sentinel",
            );
        }

        self::assertGreaterThan(
            100,
            substr_count($pot, "\nmsgid "),
            'The catalogue lost most of its strings; the extraction excluded too much.',
        );
    }

    /**
     * WordPress 6.5+ prefers a .l10n.php translation file over the .mo it
     * sits beside: a plain PHP array opcache holds, instead of a binary
     * parse on every request of every translated page. The plugin's floor
     * is far above 6.5, so every locale must ship all three artifacts.
     */
    #[Test]
    public function everyLocaleShipsThePerformantTranslationFormat(): void
    {
        $catalogues = glob($this->pluginRoot . '/languages/*.po') ?: [];

        self::assertNotEmpty($catalogues, 'The shipped locales are expected in languages/.');

        foreach ($catalogues as $catalogue) {
            $base = substr($catalogue, 0, -3);
            self::assertFileExists($base . '.mo', basename($catalogue) . ' must ship its .mo');
            self::assertFileExists($base . '.l10n.php', basename($catalogue) . ' must ship its .l10n.php');

            // WordPress loads .l10n.php FIRST and never checks freshness, so
            // a .po edited and rebuilt as .mo alone (Poedit's default save)
            // would be silently masked by a stale .l10n.php. The revision
            // header ties the artifact to the catalogue it was built from.
            preg_match('/"PO-Revision-Date: ([^\\\\"]+)/', (string) file_get_contents($catalogue), $match);
            self::assertNotEmpty($match[1] ?? '', basename($catalogue) . ' must carry PO-Revision-Date');

            $artifact = require $base . '.l10n.php';
            self::assertSame(
                $match[1],
                $artifact['po-revision-date'] ?? null,
                basename($base . '.l10n.php') . ' is stale — run composer i18n:build, never make-mo alone.',
            );
        }
    }

    /**
     * wp-cli's make-json (i18n-command ≤ 2.12) matches script names against
     * the unescaped pattern "/.min.js$/", so a name like "…admin.js" is
     * treated as a minified artifact and its JED translation file is written
     * under an md5 WordPress never computes — that script's translations
     * silently vanish. No translatable script may carry a name the buggy
     * pattern matches unless it genuinely is a .min.js artifact.
     */
    #[Test]
    public function translatableScriptNamesSurviveMakeJson(): void
    {
        $scripts = array_merge(
            glob($this->pluginRoot . '/admin/*.js') ?: [],
            glob($this->pluginRoot . '/admin/components/*.js') ?: [],
            glob($this->pluginRoot . '/blocks/*/*.js') ?: [],
        );

        self::assertNotEmpty($scripts);

        foreach ($scripts as $script) {
            $name = basename($script);
            if (str_ends_with($name, '.min.js')) {
                continue;
            }

            self::assertDoesNotMatchRegularExpression(
                '/.min.js$/',
                $name,
                "$name would be mangled by wp i18n make-json; rename it so its JED file matches what WordPress loads.",
            );
        }
    }

    #[Test]
    public function composerMetadataDeclaresThePhpFloorAndLicence(): void
    {
        $composer = json_decode(
            (string) file_get_contents($this->pluginRoot . '/composer.json'),
            true,
        );
        $lock = json_decode(
            (string) file_get_contents($this->pluginRoot . '/composer.lock'),
            true,
        );

        self::assertIsArray($composer);
        self::assertSame('>=8.3', $composer['require']['php'] ?? null);
        self::assertSame('GPL-2.0-or-later', $composer['license'] ?? null);
        self::assertIsArray($lock);
        self::assertSame('>=8.3', $lock['platform']['php'] ?? null);
    }
}

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

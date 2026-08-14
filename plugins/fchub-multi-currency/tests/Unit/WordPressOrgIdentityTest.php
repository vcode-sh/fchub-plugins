<?php

declare(strict_types=1);

namespace FChubMultiCurrency\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class WordPressOrgIdentityTest extends TestCase
{
    #[Test]
    public function mainPluginFileDeclaresTheWordPressOrgIdentity(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/fchub-multi-currency.php');

        self::assertIsString($source);
        self::assertStringContainsString('Plugin Name: FCHub Multi-Currency', $source);
        self::assertStringContainsString('Plugin URI: https://fchub.co/docs/fchub-multi-currency', $source);
        // The value is owned by VersionMetadataContractTest; here it only has to exist.
        self::assertMatchesRegularExpression('/^\s*\*\s+Version:\s*\S+/m', $source);
        self::assertStringContainsString('Requires at least: 7.0', $source);
        self::assertStringContainsString('Tested up to: 7.0', $source);
        self::assertStringContainsString('Requires PHP: 8.3', $source);
        self::assertStringContainsString('Requires Plugins: fluent-cart', $source);
        self::assertStringContainsString('Text Domain: fchub-multi-currency', $source);
        self::assertMatchesRegularExpression("/define\('FCHUB_MC_VERSION', '\S+'\);/", $source);
        // Update URI and updater registration are governed by tests/repository/updater-presence-contract.test.mjs.
    }
}

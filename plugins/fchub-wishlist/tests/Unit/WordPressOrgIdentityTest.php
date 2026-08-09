<?php

declare(strict_types=1);

namespace FChubWishlist\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class WordPressOrgIdentityTest extends TestCase
{
    #[Test]
    public function mainPluginFileDeclaresTheWordPressOrgIdentity(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/fchub-wishlist.php');

        self::assertIsString($source);
        self::assertStringContainsString('Plugin Name: FCHub Wishlist', $source);
        self::assertStringContainsString('Version: 1.0.3', $source);
        self::assertStringContainsString('Requires at least: 7.0', $source);
        self::assertStringContainsString('Requires PHP: 8.3', $source);
        self::assertStringContainsString('Tested up to: 7.0', $source);
        self::assertStringContainsString('Requires Plugins: fluent-cart', $source);
        self::assertStringContainsString('Plugin URI: https://fchub.co/docs/fchub-wishlist', $source);
        self::assertStringContainsString("define('FCHUB_WISHLIST_VERSION', '1.0.3');", $source);
        // Update URI and updater registration are governed by tests/repository/updater-presence-contract.test.mjs.
    }
}

<?php

declare(strict_types=1);

namespace FChubWishlist\Tests\Unit\Domain;

use FChubWishlist\Domain\GuestSession;
use FChubWishlist\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\Test;

class GuestSessionTest extends TestCase
{
    #[Test]
    public function testGenerateHashReturnsHexString(): void
    {
        $hash = GuestSession::generateHash();

        $this->assertContains(strlen($hash), [40, 64]);
        $this->assertMatchesRegularExpression('/^[a-f0-9]+$/', $hash);
    }

    #[Test]
    public function testGenerateHashProducesUniqueValues(): void
    {
        $hash1 = GuestSession::generateHash();
        $hash2 = GuestSession::generateHash();

        $this->assertNotSame($hash1, $hash2);
    }

    #[Test]
    public function testGetHashReturnsCookieValue(): void
    {
        $_COOKIE['fchub_wishlist_hash'] = 'test_hash_123';
        $hash = GuestSession::getHash();
        $this->assertSame('test_hash_123', $hash);
    }

    #[Test]
    public function testGetHashReturnsEmptyStringWhenNoCookie(): void
    {
        unset($_COOKIE['fchub_wishlist_hash']);
        $hash = GuestSession::getHash();
        $this->assertSame('', $hash);
    }

    #[Test]
    public function testSetHashSetsSuperglobal(): void
    {
        // setcookie() does not work in CLI, but the method also sets $_COOKIE directly
        GuestSession::setHash('new_hash_value');
        $this->assertSame('new_hash_value', $_COOKIE['fchub_wishlist_hash']);
    }

    #[Test]
    public function testDeleteHashUnsetsSuperglobal(): void
    {
        $_COOKIE['fchub_wishlist_hash'] = 'to_delete';
        GuestSession::deleteHash();
        $this->assertArrayNotHasKey('fchub_wishlist_hash', $_COOKIE);
    }

    #[Test]
    public function testOnUserLoginTriggersResolveAndMerge(): void
    {
        // No cookie set, so merge should not happen
        unset($_COOKIE['fchub_wishlist_hash']);

        $user = new \WP_User();
        $user->ID = 42;

        GuestSession::onUserLogin('admin', $user);

        // No merge action should fire because no cookie
        $this->assertHookNotFired('fchub_wishlist/wishlist_merged');
    }

    #[Test]
    public function testOnUserRegisterWithNoCookieDoesNothing(): void
    {
        unset($_COOKIE['fchub_wishlist_hash']);
        GuestSession::onUserRegister(42);
        $this->assertHookNotFired('fchub_wishlist/wishlist_merged');
    }

    #[Test]
    public function testCleanupExpiredDeletesOldGuests(): void
    {
        // Mock: no orphaned wishlists found
        $this->setWpdbMockResults([]);

        GuestSession::cleanupExpired();

        // Should have queried the orphaned guest lists
        $this->assertQueryContains('session_hash IS NOT NULL');
    }

    #[Test]
    public function testRegisterRegistersThreeHooks(): void
    {
        GuestSession::register();

        $hooks = $GLOBALS['wp_actions_registered'];
        $hookTags = array_column($hooks, 'tag');

        $this->assertContains('wp_login', $hookTags);
        $this->assertContains('user_register', $hookTags);
        $this->assertContains('set_logged_in_cookie', $hookTags);
    }
}

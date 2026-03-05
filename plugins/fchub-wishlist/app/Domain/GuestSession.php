<?php

declare(strict_types=1);

namespace FChubWishlist\Domain;

use FChubWishlist\Domain\Actions\MergeGuestWishlistAction;
use FChubWishlist\Domain\Context\WishlistContextResolver;
use FChubWishlist\Storage\WishlistItemRepository;
use FChubWishlist\Storage\WishlistRepository;
use FChubWishlist\Support\Constants;
use FChubWishlist\Support\Hooks;
use FChubWishlist\Support\Logger;

defined('ABSPATH') || exit;

class GuestSession
{
    public static function register(): void
    {
        add_action('wp_login', [self::class, 'onUserLogin'], 10, 2);
        add_action('user_register', [self::class, 'onUserRegister'], 10, 1);
        add_action('set_logged_in_cookie', [self::class, 'onSetLoggedInCookie'], 10, 6);
    }

    public static function getHash(): string
    {
        return isset($_COOKIE[Constants::COOKIE_KEY])
            ? sanitize_text_field(wp_unslash($_COOKIE[Constants::COOKIE_KEY]))
            : '';
    }

    public static function setHash(string $hash): void
    {
        $days = (int) apply_filters('fchub_wishlist/cookie_expiry_days', Constants::COOKIE_DAYS);

        setcookie(Constants::COOKIE_KEY, $hash, [
            'expires'  => time() + ($days * DAY_IN_SECONDS),
            'path'     => COOKIEPATH,
            'domain'   => COOKIE_DOMAIN,
            'secure'   => is_ssl(),
            'httponly'  => true,
            'samesite' => 'Lax',
        ]);

        $_COOKIE[Constants::COOKIE_KEY] = $hash;
    }

    public static function deleteHash(): void
    {
        setcookie(Constants::COOKIE_KEY, '', [
            'expires'  => time() - DAY_IN_SECONDS,
            'path'     => COOKIEPATH,
            'domain'   => COOKIE_DOMAIN,
            'secure'   => is_ssl(),
            'httponly'  => true,
            'samesite' => 'Lax',
        ]);

        unset($_COOKIE[Constants::COOKIE_KEY]);
    }

    public static function generateHash(): string
    {
        try {
            return bin2hex(random_bytes(32));
        } catch (\Throwable $e) {
            return sha1('fchub_wishlist_' . wp_generate_uuid4() . time());
        }
    }

    public static function onUserLogin(string $userLogin, \WP_User $user): void
    {
        self::mergeIfCookieExists($user->ID);
    }

    public static function onUserRegister(int $userId): void
    {
        self::mergeIfCookieExists($userId);
    }

    /**
     * Handle edge case: set_logged_in_cookie fires before wp_login in some flows.
     */
    public static function onSetLoggedInCookie(
        string $cookie,
        int $expire,
        int $expiration,
        int $userId,
        string $scheme,
        string $token,
    ): void {
        if (did_action('wp_login') > 0) {
            return;
        }
        self::mergeIfCookieExists($userId);
    }

    public static function cleanupExpired(): void
    {
        $defaultDays = (int) Hooks::getSetting('guest_cleanup_days', Constants::COOKIE_DAYS);
        $days = (int) apply_filters('fchub_wishlist/guest_cleanup_days', $defaultDays);
        $batchSize = max(1, (int) apply_filters('fchub_wishlist/guest_cleanup_batch_size', 500));

        $repo = new WishlistRepository();
        $itemRepo = new WishlistItemRepository();

        $removed = 0;
        $batches = 0;

        do {
            $expired = $repo->getOrphanedGuestLists($days, $batchSize);
            if (empty($expired)) {
                break;
            }

            $wishlistIds = array_map('intval', array_column($expired, 'id'));
            $itemRepo->deleteByWishlistIds($wishlistIds);
            $removed += $repo->deleteByIds($wishlistIds);
            $batches++;
        } while (count($expired) === $batchSize);

        if ($removed > 0) {
            Logger::info('Cleaned up expired guest wishlists', [
                'count'   => $removed,
                'batches' => $batches,
            ]);
        }
    }

    private static function mergeIfCookieExists(int $userId): void
    {
        $hash = self::getHash();
        if (!$hash) {
            return;
        }

        $wishlists = new WishlistRepository();
        $context = new WishlistContextResolver($wishlists);
        $merger = new MergeGuestWishlistAction($wishlists, $context);

        $merger->execute($hash, $userId);
        self::deleteHash();
    }
}

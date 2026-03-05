<?php

declare(strict_types=1);

namespace FChubWishlist\Http\Controllers\Pub;

use FChubWishlist\Domain\Context\WishlistContextResolver;
use FChubWishlist\Domain\GuestSession;
use FChubWishlist\Support\Hooks;

defined('ABSPATH') || exit;

final class WishlistMutationGuard
{
    private const GUEST_CREATE_WINDOW_SECONDS = 300;
    private const GUEST_CREATE_MAX_ATTEMPTS = 10;
    private const GUEST_CREATE_RATE_LIMIT_KEY = 'fchub_wishlist_guest_create_';

    public static function assertAllowed(string $guestMessage): ?\WP_REST_Response
    {
        if (!Hooks::isEnabled()) {
            return new \WP_REST_Response([
                'success' => false,
                'message' => __('Wishlist is currently disabled.', 'fchub-wishlist'),
            ], 403);
        }

        if (!get_current_user_id() && !Hooks::isGuestEnabled()) {
            return new \WP_REST_Response([
                'success' => false,
                'message' => $guestMessage,
            ], 403);
        }

        if (
            !get_current_user_id()
            && Hooks::isGuestEnabled()
            && GuestSession::getHash() === ''
            && !self::canCreateGuestWishlist()
        ) {
            return new \WP_REST_Response([
                'success' => false,
                'message' => __('Too many wishlist creation attempts. Please wait a few minutes and try again.', 'fchub-wishlist'),
            ], 429);
        }

        return null;
    }

    public static function resolveWishlist(): ?array
    {
        if (!Hooks::isEnabled()) {
            return null;
        }

        $resolver = WishlistContextResolver::make();
        $userId = get_current_user_id();

        if ($userId) {
            return $resolver->getOrCreateForUser($userId);
        }

        if (!Hooks::isGuestEnabled()) {
            return null;
        }

        $hash = GuestSession::getHash();
        if (!$hash) {
            if (!self::canCreateGuestWishlist()) {
                return null;
            }

            $hash = GuestSession::generateHash();
            GuestSession::setHash($hash);

            $wishlist = $resolver->getOrCreateForGuest($hash);
            if ($wishlist) {
                self::registerGuestWishlistCreation();
            }

            return $wishlist;
        }

        return $resolver->getOrCreateForGuest($hash);
    }

    private static function canCreateGuestWishlist(): bool
    {
        $key = self::buildRateLimitKey();
        $state = get_transient($key);

        if (!is_array($state)) {
            return true;
        }

        $count = (int) ($state['count'] ?? 0);
        return $count < self::GUEST_CREATE_MAX_ATTEMPTS;
    }

    private static function registerGuestWishlistCreation(): void
    {
        $key = self::buildRateLimitKey();
        $state = get_transient($key);
        $count = is_array($state) ? (int) ($state['count'] ?? 0) : 0;

        set_transient($key, [
            'count' => $count + 1,
        ], self::GUEST_CREATE_WINDOW_SECONDS);
    }

    private static function buildRateLimitKey(): string
    {
        $ip = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '';
        $agent = isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'])) : '';

        return self::GUEST_CREATE_RATE_LIMIT_KEY . md5($ip . '|' . $agent);
    }
}

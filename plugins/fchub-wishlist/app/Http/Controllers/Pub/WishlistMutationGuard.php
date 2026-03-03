<?php

declare(strict_types=1);

namespace FChubWishlist\Http\Controllers\Pub;

use FChubWishlist\Domain\Context\WishlistContextResolver;
use FChubWishlist\Domain\GuestSession;
use FChubWishlist\Support\Hooks;

defined('ABSPATH') || exit;

final class WishlistMutationGuard
{
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
            $hash = GuestSession::generateHash();
            GuestSession::setHash($hash);
        }

        return $resolver->getOrCreateForGuest($hash);
    }
}

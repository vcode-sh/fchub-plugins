<?php

declare(strict_types=1);

namespace FChubWishlist\Domain\Context;

use FChubWishlist\Storage\WishlistRepository;
use FChubWishlist\Support\Hooks;

defined('ABSPATH') || exit;

class WishlistContextResolver
{
    private WishlistRepository $wishlists;

    public function __construct(WishlistRepository $wishlists)
    {
        $this->wishlists = $wishlists;
    }

    /**
     * Build a WishlistContextResolver with its own WishlistRepository.
     */
    public static function make(): self
    {
        return new self(new WishlistRepository());
    }

    /**
     * Resolve the current wishlist based on user login state and guest cookie.
     *
     * Returns the wishlist array or null if no identity is available.
     */
    public function resolve(): ?array
    {
        $userId = get_current_user_id();

        if ($userId > 0) {
            return $this->getOrCreateForUser($userId);
        }

        $hash = $this->getGuestHash();

        if ($hash) {
            return $this->getOrCreateForGuest($hash);
        }

        return null;
    }

    /**
     * Get or create a wishlist for a logged-in user.
     */
    public function getOrCreateForUser(int $userId): array
    {
        $wishlist = $this->wishlists->findByUserId($userId);

        if ($wishlist) {
            return $wishlist;
        }

        $customerId = Hooks::getCustomerId($userId);

        $id = $this->wishlists->create([
            'user_id'     => $userId,
            'customer_id' => $customerId,
        ]);

        do_action('fchub_wishlist/wishlist_created', $id, $userId, false);

        return $this->wishlists->find($id);
    }

    /**
     * Get or create a wishlist for a guest session hash.
     */
    public function getOrCreateForGuest(string $hash): array
    {
        $wishlist = $this->wishlists->findBySessionHash($hash);

        if ($wishlist) {
            return $wishlist;
        }

        $id = $this->wishlists->create([
            'session_hash' => $hash,
        ]);

        do_action('fchub_wishlist/wishlist_created', $id, 0, true);

        return $this->wishlists->find($id);
    }

    /**
     * Read the guest cookie hash.
     */
    private function getGuestHash(): string
    {
        return isset($_COOKIE['fchub_wishlist_hash'])
            ? sanitize_text_field(wp_unslash($_COOKIE['fchub_wishlist_hash']))
            : '';
    }
}

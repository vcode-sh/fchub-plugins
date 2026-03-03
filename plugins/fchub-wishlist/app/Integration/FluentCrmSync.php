<?php

declare(strict_types=1);

namespace FChubWishlist\Integration;

defined('ABSPATH') || exit;

use FChubWishlist\Support\Constants;
use FChubWishlist\Support\Hooks;
use FChubWishlist\Storage\WishlistRepository;

class FluentCrmSync
{
    public function register(): void
    {
        if (!defined('FLUENTCRM') || !function_exists('FluentCrmApi')) {
            return;
        }

        $settings = Hooks::getSettings();
        if (($settings['fluentcrm_enabled'] ?? 'yes') !== 'yes') {
            return;
        }

        add_action('fchub_wishlist/item_added', [$this, 'onItemAdded'], 10, 4);
        add_action('fchub_wishlist/item_removed', [$this, 'onItemRemoved'], 10, 4);
    }

    public function onItemAdded(int $userId, int $productId, int $variantId, int $wishlistId): void
    {
        if (!$userId) {
            return;
        }

        $contact = $this->getContact($userId);
        if (!$contact) {
            return;
        }

        $settings = Hooks::getSettings();
        $tagPrefix = $settings['fluentcrm_tag_prefix'] ?? 'wishlist:';
        $tagName = $tagPrefix . 'active';

        $tagId = $this->ensureTag($tagName, $settings);
        if ($tagId) {
            $contact->attachTags([$tagId]);
        }
    }

    public function onItemRemoved(int $userId, int $productId, int $variantId, int $wishlistId): void
    {
        if (!$userId) {
            return;
        }

        $wishlist = (new WishlistRepository())->findByUserId($userId);
        if ($wishlist && $wishlist['item_count'] > 0) {
            return;
        }

        $contact = $this->getContact($userId);
        if (!$contact) {
            return;
        }

        $settings = Hooks::getSettings();
        $tagPrefix = $settings['fluentcrm_tag_prefix'] ?? 'wishlist:';
        $tagName = $tagPrefix . 'active';

        $tagId = $this->findTagByTitle($tagName);
        if ($tagId) {
            $contact->detachTags([$tagId]);
        }
    }

    private function getContact(int $userId): ?object
    {
        $contact = FluentCrmApi('contacts')->getContactByUserRef($userId);
        if ($contact) {
            return $contact;
        }

        $user = get_userdata($userId);
        if (!$user) {
            return null;
        }

        return FluentCrmApi('contacts')->createOrUpdate([
            'email'      => $user->user_email,
            'user_id'    => $userId,
            'first_name' => $user->first_name,
            'last_name'  => $user->last_name,
        ]);
    }

    private function ensureTag(string $tagName, array $settings): ?int
    {
        $existing = $this->findTagByTitle($tagName);
        if ($existing) {
            return $existing;
        }

        if (($settings['fluentcrm_auto_create_tags'] ?? 'yes') !== 'yes') {
            return null;
        }

        $result = FluentCrmApi('tags')->importBulk([
            ['title' => $tagName, 'slug' => sanitize_title($tagName)],
        ]);

        if (!empty($result) && isset($result[0])) {
            return $result[0]->id ?? null;
        }

        return $this->findTagByTitle($tagName);
    }

    private function findTagByTitle(string $title): ?int
    {
        $tag = FluentCrmApi('tags')->getInstance()->newQuery()
            ->where('title', $title)
            ->first();

        return $tag ? (int) $tag->id : null;
    }
}

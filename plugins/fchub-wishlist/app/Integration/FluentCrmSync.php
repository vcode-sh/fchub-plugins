<?php

declare(strict_types=1);

namespace FChubWishlist\Integration;

defined('ABSPATH') || exit;

use FChubWishlist\Support\Constants;
use FChubWishlist\Support\Hooks;
use FChubWishlist\Storage\WishlistItemRepository;
use FChubWishlist\Storage\WishlistRepository;

class FluentCrmSync
{
    private const TAG_CACHE_KEY_PREFIX = 'fchub_wishlist_fcrm_tag_';

    /** @var array<string, int> */
    private array $tagIdCache = [];

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
        if ($wishlist && (new WishlistItemRepository())->countByWishlistId((int) $wishlist['id']) > 0) {
            return;
        }

        $contact = $this->getExistingContact($userId);
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

    public static function detachActiveTagForUser(int $userId): void
    {
        if (!$userId || !defined('FLUENTCRM') || !function_exists('FluentCrmApi')) {
            return;
        }

        $sync = new self();
        $sync->detachActiveTag($userId);
    }

    private function detachActiveTag(int $userId): void
    {
        $contact = $this->getExistingContact($userId);
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

    private function getExistingContact(int $userId): ?object
    {
        return FluentCrmApi('contacts')->getContactByUserRef($userId);
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
            $tagId = (int) ($result[0]->id ?? 0);
            if ($tagId > 0) {
                $this->tagIdCache[$tagName] = $tagId;
                set_transient(self::TAG_CACHE_KEY_PREFIX . md5($tagName), $tagId, HOUR_IN_SECONDS);
                return $tagId;
            }
        }

        return $this->findTagByTitle($tagName);
    }

    private function findTagByTitle(string $title): ?int
    {
        if (array_key_exists($title, $this->tagIdCache)) {
            return $this->tagIdCache[$title] > 0 ? $this->tagIdCache[$title] : null;
        }

        $transientKey = self::TAG_CACHE_KEY_PREFIX . md5($title);
        $cachedId = get_transient($transientKey);
        if ($cachedId !== false) {
            $cached = (int) $cachedId;
            $this->tagIdCache[$title] = $cached;
            return $cached > 0 ? $cached : null;
        }

        $tag = FluentCrmApi('tags')->getInstance()->newQuery()
            ->where('title', $title)
            ->first();

        $tagId = $tag ? (int) $tag->id : 0;
        $this->tagIdCache[$title] = $tagId;
        set_transient($transientKey, $tagId, HOUR_IN_SECONDS);

        return $tagId > 0 ? $tagId : null;
    }
}

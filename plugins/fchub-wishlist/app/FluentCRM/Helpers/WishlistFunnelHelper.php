<?php

declare(strict_types=1);

namespace FChubWishlist\FluentCRM\Helpers;

defined('ABSPATH') || exit;

use FluentCrm\App\Services\Funnel\FunnelHelper;
use FChubWishlist\Storage\WishlistRepository;
use FChubWishlist\Storage\WishlistItemRepository;

class WishlistFunnelHelper
{
    /**
     * Prepare FluentCRM subscriber data from a WordPress user.
     *
     * @return array<string, mixed>|null
     */
    public static function prepareUserData(\WP_User $user): ?array
    {
        return FunnelHelper::prepareUserData($user);
    }

    /**
     * Get a FluentCRM subscriber by user ID.
     */
    public static function getSubscriberByUserId(int $userId): ?object
    {
        $user = get_user_by('ID', $userId);
        if (!$user) {
            return null;
        }

        return FunnelHelper::getSubscriber($user->user_email);
    }

    /**
     * Resolve a WP user ID from a FluentCRM subscriber.
     */
    public static function resolveUserIdFromSubscriber(object $subscriber): ?int
    {
        if ($subscriber->user_id) {
            return (int) $subscriber->user_id;
        }

        $user = get_user_by('email', $subscriber->email);
        return $user ? $user->ID : null;
    }

    /**
     * Get FluentCart product options for selector fields.
     *
     * @return array<int, array{id: string, title: string}>
     */
    public static function getProductOptions(string $search = ''): array
    {
        global $wpdb;

        $sql = "SELECT ID, post_title FROM {$wpdb->posts}
                WHERE post_type = 'fluent-products' AND post_status = 'publish'";

        $params = [];
        if ($search !== '') {
            $sql .= " AND post_title LIKE %s";
            $params[] = '%' . $wpdb->esc_like($search) . '%';
        }

        $sql .= " ORDER BY post_title ASC LIMIT 200";

        $rows = $params
            ? $wpdb->get_results($wpdb->prepare($sql, ...$params), ARRAY_A)
            : $wpdb->get_results($sql, ARRAY_A);

        return array_map(fn(array $row) => [
            'id'    => (string) $row['ID'],
            'title' => $row['post_title'],
        ], $rows ?: []);
    }

    /**
     * Get FluentCart variant options for a product.
     *
     * @return array<int, array{id: string, title: string}>
     */
    public static function getVariantOptions(int $productId = 0, string $search = ''): array
    {
        global $wpdb;
        $table = $wpdb->prefix . 'fct_product_variations';

        $sql = "SELECT id, variation_title, post_id FROM {$table} WHERE item_status = 'active'";
        $params = [];

        if ($productId > 0) {
            $sql .= " AND post_id = %d";
            $params[] = $productId;
        }

        if ($search !== '') {
            $sql .= " AND variation_title LIKE %s";
            $params[] = '%' . $wpdb->esc_like($search) . '%';
        }

        $sql .= " ORDER BY variation_title ASC LIMIT 200";

        $rows = $params
            ? $wpdb->get_results($wpdb->prepare($sql, ...$params), ARRAY_A)
            : $wpdb->get_results($sql, ARRAY_A);

        return array_map(fn(array $row) => [
            'id'    => (string) $row['id'],
            'title' => $row['variation_title'],
        ], $rows ?: []);
    }

    /**
     * Get the user's default wishlist, creating one if needed.
     */
    public static function getOrCreateWishlist(int $userId): ?array
    {
        $repo = new WishlistRepository();
        $wishlist = $repo->findByUserId($userId);

        if ($wishlist) {
            return $wishlist;
        }

        $id = $repo->create([
            'user_id' => $userId,
            'title'   => 'Wishlist',
        ]);

        return $id ? $repo->find($id) : null;
    }

    /**
     * Get item count for a user's wishlist.
     */
    public static function getUserItemCount(int $userId): int
    {
        $repo = new WishlistRepository();
        $wishlist = $repo->findByUserId($userId);

        return $wishlist ? (int) $wishlist['item_count'] : 0;
    }
}

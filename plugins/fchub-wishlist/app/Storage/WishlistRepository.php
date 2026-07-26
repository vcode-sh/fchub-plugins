<?php

declare(strict_types=1);

namespace FChubWishlist\Storage;

defined('ABSPATH') || exit;

class WishlistRepository
{
    private string $table;
    private WishlistRepositoryMaintenance $maintenance;

    public function __construct()
    {
        global $wpdb;
        $this->table = $wpdb->prefix . 'fchub_wishlist_lists';
        $this->maintenance = new WishlistRepositoryMaintenance($this->table);
    }

    public function find(int $id): ?array
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Wishlist ownership and counts are live, mutation-heavy access-control state.
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM %i WHERE id = %d",
            $this->table,
            $id
        ), ARRAY_A);

        return $row ? $this->hydrate($row) : null;
    }

    public function findByUserId(int $userId): ?array
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Wishlist ownership is live access-control state and must not be stale during resolution.
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM %i WHERE user_id = %d",
            $this->table,
            $userId
        ), ARRAY_A);

        return $row ? $this->hydrate($row) : null;
    }

    public function findBySessionHash(string $hash): ?array
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Guest ownership can transfer during login and must be resolved from live state.
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM %i WHERE session_hash = %s AND user_id IS NULL",
            $this->table,
            $hash
        ), ARRAY_A);

        return $row ? $this->hydrate($row) : null;
    }

    public function findByCustomerId(int $customerId): ?array
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Customer ownership is live access-control state and must not be stale during resolution.
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM %i WHERE customer_id = %d",
            $this->table,
            $customerId
        ), ARRAY_A);

        return $row ? $this->hydrate($row) : null;
    }

    public function create(array $data): int
    {
        global $wpdb;

        $now = current_time('mysql');
        $insert = [
            'user_id'      => $data['user_id'] ?? null,
            'customer_id'  => $data['customer_id'] ?? null,
            'session_hash' => $data['session_hash'] ?? null,
            'title'        => $data['title'] ?? 'Wishlist',
            'item_count'   => (int) ($data['item_count'] ?? 0),
            'created_at'   => $now,
            'updated_at'   => $now,
        ];

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- $wpdb->insert is the WordPress CRUD API for this plugin-owned custom table.
        $result = $wpdb->insert($this->table, $insert);
        if ($result === false) {
            return 0;
        }

        return (int) $wpdb->insert_id;
    }

    public function update(int $id, array $data): bool
    {
        global $wpdb;

        $update = ['updated_at' => current_time('mysql')];

        $allowedFields = ['user_id', 'customer_id', 'session_hash', 'title', 'item_count'];
        foreach ($allowedFields as $field) {
            if (array_key_exists($field, $data)) {
                $update[$field] = $data[$field];
            }
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- $wpdb->update is the WordPress CRUD API; this write has no result cache.
        return $wpdb->update($this->table, $update, ['id' => $id]) !== false;
    }

    public function delete(int $id): bool
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- $wpdb->delete is the WordPress CRUD API; this write has no result cache.
        return $wpdb->delete($this->table, ['id' => $id]) !== false;
    }

    public function incrementItemCount(int $id): void
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic counters have no WordPress CRUD equivalent and no cached list rows are retained.
        $wpdb->query($wpdb->prepare(
            "UPDATE %i SET item_count = item_count + 1, updated_at = %s WHERE id = %d",
            $this->table,
            current_time('mysql'),
            $id
        ));
    }

    public function decrementItemCount(int $id): void
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic counters have no WordPress CRUD equivalent and no cached list rows are retained.
        $wpdb->query($wpdb->prepare(
            "UPDATE %i SET item_count = GREATEST(item_count - 1, 0), updated_at = %s WHERE id = %d",
            $this->table,
            current_time('mysql'),
            $id
        ));
    }

    public function recalculateItemCount(int $id): void
    {
        global $wpdb;
        $itemsTable = $wpdb->prefix . 'fchub_wishlist_items';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Recalculation must count live rows immediately after bulk mutations.
        $count = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM %i WHERE wishlist_id = %d",
            $itemsTable,
            $id
        ));

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- $wpdb->update is the WordPress CRUD API; this write persists the fresh count directly.
        $wpdb->update(
            $this->table,
            ['item_count' => $count, 'updated_at' => current_time('mysql')],
            ['id' => $id]
        );
    }

    public function transferToUser(int $id, int $userId, ?int $customerId): bool
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- $wpdb->update is the WordPress CRUD API; ownership changes are never cached.
        return $wpdb->update(
            $this->table,
            [
                'user_id'      => $userId,
                'customer_id'  => $customerId,
                'session_hash' => null,
                'updated_at'   => current_time('mysql'),
            ],
            ['id' => $id]
        ) !== false;
    }

    public function deleteBySessionHash(string $hash): int
    {
        return $this->maintenance->deleteBySessionHash($hash);
    }

    /**
     * Get orphaned guest wishlists older than the specified number of days.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getOrphanedGuestLists(int $olderThanDays, int $limit = 0): array
    {
        return array_map([$this, 'hydrate'], $this->maintenance->getOrphanedGuestLists($olderThanDays, $limit));
    }

    public function deleteByIds(array $wishlistIds): int
    {
        return $this->maintenance->deleteByIds($wishlistIds);
    }

    public function getItemCount(int $id): int
    {
        return $this->maintenance->getItemCount($id);
    }

    private function hydrate(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $row['user_id'] = $row['user_id'] !== null ? (int) $row['user_id'] : null;
        $row['customer_id'] = $row['customer_id'] !== null ? (int) $row['customer_id'] : null;
        $row['item_count'] = (int) $row['item_count'];
        return $row;
    }
}

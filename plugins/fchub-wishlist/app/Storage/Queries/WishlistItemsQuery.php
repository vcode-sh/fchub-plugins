<?php

declare(strict_types=1);

namespace FChubWishlist\Storage\Queries;

defined('ABSPATH') || exit;

class WishlistItemsQuery
{
    private string $itemsTable;
    private string $listsTable;
    private string $variationsTable;
    private string $detailsTable;

    public function __construct()
    {
        global $wpdb;
        $this->itemsTable = $wpdb->prefix . 'fchub_wishlist_items';
        $this->listsTable = $wpdb->prefix . 'fchub_wishlist_lists';
        $this->variationsTable = $wpdb->prefix . 'fct_product_variations';
        $this->detailsTable = $wpdb->prefix . 'fct_product_details';
    }

    public function getItemsForUser(int $userId, int $page = 1, int $perPage = 20): array
    {
        return $this->getItemsForListOwner('l.user_id = %d', [$userId], $page, $perPage);
    }

    public function getItemsForGuest(string $sessionHash, int $page = 1, int $perPage = 20): array
    {
        return $this->getItemsForListOwner(
            'l.session_hash = %s AND l.user_id IS NULL',
            [$sessionHash],
            $page,
            $perPage
        );
    }

    public function getProductIdsInWishlist(int $wishlistId): array
    {
        global $wpdb;

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT product_id, variant_id FROM {$this->itemsTable} WHERE wishlist_id = %d",
            $wishlistId
        ), ARRAY_A);

        return array_map(function (array $row): array {
            return [
                'product_id' => (int) $row['product_id'],
                'variant_id' => (int) $row['variant_id'],
            ];
        }, $rows ?: []);
    }

    public function getItemsWithProductData(int $wishlistId): array
    {
        return $this->getItemsForWishlist($wishlistId, 1, 0)['items'];
    }

    public function getItemsWithProductDataPaginated(int $wishlistId, int $page = 1, int $perPage = 20): array
    {
        return $this->getItemsForWishlist($wishlistId, $page, $perPage);
    }

    private function getItemsForListOwner(string $whereClause, array $params, int $page, int $perPage): array
    {
        $fromClause = "{$this->itemsTable} i INNER JOIN {$this->listsTable} l ON i.wishlist_id = l.id";

        return $this->runItemsQuery($fromClause, $whereClause, $params, $page, $perPage, false);
    }

    private function getItemsForWishlist(int $wishlistId, int $page, int $perPage): array
    {
        return $this->runItemsQuery(
            "{$this->itemsTable} i",
            'i.wishlist_id = %d',
            [$wishlistId],
            $page,
            $perPage,
            true
        );
    }

    private function runItemsQuery(
        string $fromClause,
        string $whereClause,
        array $whereParams,
        int $page,
        int $perPage,
        bool $applyItemFilter
    ): array {
        global $wpdb;

        $total = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$fromClause} WHERE {$whereClause}",
            ...$whereParams
        ));

        $queryParams = $whereParams;
        $limitClause = '';
        if ($perPage > 0) {
            $offset = max(0, ($page - 1) * $perPage);
            $limitClause = ' LIMIT %d OFFSET %d';
            $queryParams[] = $perPage;
            $queryParams[] = $offset;
        }

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT
                i.*,
                p.post_title AS product_title,
                p.post_status AS product_status,
                p.post_name AS product_slug,
                v.variation_title AS variant_title,
                v.item_price AS current_price,
                v.item_status AS variant_status,
                v.sku AS variant_sku
             FROM {$fromClause}
             LEFT JOIN {$wpdb->posts} p ON i.product_id = p.ID
             {$this->getVariantJoinClause()}
             WHERE {$whereClause}
             ORDER BY i.created_at DESC{$limitClause}",
            ...$queryParams
        ), ARRAY_A);

        return [
            'items' => $this->mapRows($rows ?: [], $applyItemFilter),
            'total' => $total,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function mapRows(array $rows, bool $applyItemFilter): array
    {
        return array_map(function (array $row) use ($applyItemFilter): array {
            $item = $this->hydrateWithProduct($row);

            if (!$applyItemFilter) {
                return $item;
            }

            return apply_filters('fchub_wishlist/item_data', $item);
        }, $rows);
    }

    private function getVariantJoinClause(): string
    {
        return "LEFT JOIN {$this->detailsTable} pd ON pd.post_id = i.product_id
             LEFT JOIN (
                 SELECT picked.post_id, MIN(picked.id) AS id
                 FROM {$this->variationsTable} picked
                 INNER JOIN (
                     SELECT post_id, MIN(serial_index) AS min_serial_index
                     FROM {$this->variationsTable}
                     WHERE item_status = 'active'
                     GROUP BY post_id
                 ) first_active
                     ON first_active.post_id = picked.post_id
                    AND first_active.min_serial_index = picked.serial_index
                 WHERE picked.item_status = 'active'
                 GROUP BY picked.post_id
             ) dv ON dv.post_id = i.product_id
             LEFT JOIN {$this->variationsTable} v ON v.id = COALESCE(
                 NULLIF(i.variant_id, 0),
                 NULLIF(pd.default_variation_id, 0),
                 dv.id
             )";
    }

    private function hydrateWithProduct(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $row['wishlist_id'] = (int) $row['wishlist_id'];
        $row['product_id'] = (int) $row['product_id'];
        $row['variant_id'] = (int) $row['variant_id'];
        $row['price_at_addition'] = (float) $row['price_at_addition'];
        $row['product_title'] = $row['product_title'] ?? '';
        $row['product_status'] = $row['product_status'] ?? '';
        $row['product_slug'] = $row['product_slug'] ?? '';
        $row['variant_title'] = $row['variant_title'] ?? '';
        $row['current_price'] = isset($row['current_price']) ? (float) $row['current_price'] : 0.0;
        $row['variant_status'] = $row['variant_status'] ?? '';
        $row['variant_sku'] = $row['variant_sku'] ?? '';
        return $row;
    }
}

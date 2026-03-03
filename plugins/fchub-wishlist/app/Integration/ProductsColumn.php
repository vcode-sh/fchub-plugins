<?php

declare(strict_types=1);

namespace FChubWishlist\Integration;

use FChubWishlist\Storage\WishlistItemRepository;

defined('ABSPATH') || exit;

/**
 * Adds a "Wishlists" custom column to FluentCart's admin product list table.
 *
 * Uses render_template for a heart icon + count display. Data is attached
 * to each product via a single bulk query per page load.
 */
final class ProductsColumn
{
    public static function register(): void
    {
        add_filter('fluent_cart/products_table_columns', [self::class, 'registerColumn']);
        add_filter('fluent_cart/products_list', [self::class, 'attachWishlistCounts']);
    }

    /**
     * @param array<string, array<string, mixed>> $columns
     * @return array<string, array<string, mixed>>
     */
    public static function registerColumn(array $columns): array
    {
        $columns['wishlist_count'] = [
            'label'           => __('Wishlists', 'fchub-wishlist'),
            'render_template' => true,
            'template'        => self::columnTemplate(),
        ];

        return $columns;
    }

    /**
     * Attach wishlist count to each product in the paginated collection.
     *
     * @param \FluentCart\Framework\Pagination\LengthAwarePaginator $products
     * @return \FluentCart\Framework\Pagination\LengthAwarePaginator
     */
    public static function attachWishlistCounts($products)
    {
        $collection = $products->getCollection();

        if ($collection->isEmpty()) {
            return $products;
        }

        $productIds = $collection->pluck('ID')->all();
        $repo       = new WishlistItemRepository();
        $counts     = $repo->countByProductIds($productIds);

        $collection->transform(function ($product) use ($counts) {
            $product->_fchub_wishlist_count = $counts[$product->ID] ?? 0;
            return $product;
        });

        return $products;
    }

    private static function columnTemplate(): string
    {
        return '<span style="display:inline-flex;align-items:center;gap:4px;white-space:nowrap">'
            . '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="14" height="14" '
            . 'fill="none" stroke="#94a3b8" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">'
            . '<path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78'
            . 'l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg>'
            . '<span v-if="data.product._fchub_wishlist_count" style="font-weight:500">'
            . '{{ data.product._fchub_wishlist_count }}</span>'
            . '<span v-else style="color:#c0c4cc">&mdash;</span>'
            . '</span>';
    }
}

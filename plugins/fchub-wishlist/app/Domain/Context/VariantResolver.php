<?php

declare(strict_types=1);

namespace FChubWishlist\Domain\Context;

defined('ABSPATH') || exit;

class VariantResolver
{
    /**
     * Resolve the variant ID for a product.
     *
     * If variant_id is 0, attempt to find the default variant from the product
     * detail table, falling back to the first active variant.
     */
    public function resolve(int $productId, int $variantId): int
    {
        if ($variantId > 0) {
            return $variantId;
        }

        return $this->getDefaultVariant($productId);
    }

    /**
     * Get the default variant for a product.
     *
     * First checks fct_product_details.default_variation_id,
     * then falls back to the first active variant in fct_product_variations.
     */
    private function getDefaultVariant(int $productId): int
    {
        global $wpdb;

        $detailsTable = $wpdb->prefix . 'fct_product_details';
        $variationsTable = $wpdb->prefix . 'fct_product_variations';

        // Try default_variation_id from product details, but only if the variant is still active.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- FluentCart has no query API for its variation tables, and variant availability must be read live.
        $defaultId = $wpdb->get_var($wpdb->prepare(
            "SELECT v.id
             FROM %i d
             INNER JOIN %i v
                ON v.id = d.default_variation_id
               AND v.item_status = 'active'
             WHERE d.post_id = %d
             LIMIT 1",
            $detailsTable,
            $variationsTable,
            $productId
        ));

        if ($defaultId && (int) $defaultId > 0) {
            return (int) $defaultId;
        }

        // Fallback: first active variant for this product
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- FluentCart has no query API for its variation tables, and variant availability must be read live.
        $firstVariant = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM %i WHERE post_id = %d AND item_status = 'active' ORDER BY id ASC LIMIT 1",
            $variationsTable,
            $productId
        ));

        return $firstVariant ? (int) $firstVariant : 0;
    }
}

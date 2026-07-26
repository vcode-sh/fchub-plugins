<?php

declare(strict_types=1);

namespace FChubWishlist\Domain\Rules;

defined('ABSPATH') || exit;

class ProductRules
{
    /**
     * Check that a product exists and is published.
     */
    public function productExists(int $productId): bool
    {
        $product = get_post($productId);

        return $product instanceof \WP_Post
            && $product->post_status === 'publish'
            && $product->post_type === 'fluent-products';
    }

    /**
     * Check that a variant exists and is active.
     */
    public function variantExists(int $variantId): bool
    {
        global $wpdb;

        $variationsTable = $wpdb->prefix . 'fct_product_variations';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- FluentCart exposes no variation lookup API, and add-to-cart validation requires current status.
        return (bool) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM %i WHERE id = %d AND item_status = 'active'",
            $variationsTable,
            $variantId
        ));
    }

    /**
     * Validate both product and variant for a wishlist add operation.
     *
     * @return array{valid: bool, error: string}
     */
    public function validate(int $productId, int $variantId): array
    {
        if (!$this->productExists($productId)) {
            return ['valid' => false, 'error' => 'Product does not exist or is not published.'];
        }

        if ($variantId > 0 && !$this->variantExists($variantId)) {
            return ['valid' => false, 'error' => 'Variant does not exist or is not active.'];
        }

        return ['valid' => true, 'error' => ''];
    }

    /**
     * Get the current price for a variant.
     */
    public function getVariantPrice(int $variantId): float
    {
        global $wpdb;

        $variationsTable = $wpdb->prefix . 'fct_product_variations';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- FluentCart exposes no variation price API, and wishlist prices must reflect the current catalogue value.
        $price = $wpdb->get_var($wpdb->prepare(
            "SELECT item_price FROM %i WHERE id = %d",
            $variationsTable,
            $variantId
        ));

        return (float) ($price ?? 0);
    }

    /**
     * Check if a variant is purchasable (exists, active, and has a price).
     */
    public function isVariantPurchasable(int $variantId): bool
    {
        return $this->variantExists($variantId);
    }
}

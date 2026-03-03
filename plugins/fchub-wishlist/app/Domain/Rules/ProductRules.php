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
        global $wpdb;

        return (bool) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->posts} WHERE ID = %d AND post_status = 'publish' AND post_type = 'fluent-products'",
            $productId
        ));
    }

    /**
     * Check that a variant exists and is active.
     */
    public function variantExists(int $variantId): bool
    {
        global $wpdb;

        $variationsTable = $wpdb->prefix . 'fct_product_variations';

        return (bool) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$variationsTable} WHERE id = %d AND item_status = 'active'",
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

        $price = $wpdb->get_var($wpdb->prepare(
            "SELECT item_price FROM {$variationsTable} WHERE id = %d",
            $variantId
        ));

        return (float) ($price ?? 0);
    }

    /**
     * Check if a variant is purchasable (exists, active, and has a price).
     */
    public function isVariantPurchasable(int $variantId): bool
    {
        global $wpdb;

        $variationsTable = $wpdb->prefix . 'fct_product_variations';

        return (bool) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$variationsTable} WHERE id = %d AND item_status = 'active'",
            $variantId
        ));
    }
}

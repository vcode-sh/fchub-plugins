<?php

declare(strict_types=1);

namespace FChubWishlist\Http\Requests;

defined('ABSPATH') || exit;

final class ItemRequest
{
    public static function getProductId(\WP_REST_Request $request): int
    {
        return absint($request->get_param('product_id'));
    }

    public static function getVariantId(\WP_REST_Request $request): int
    {
        return absint($request->get_param('variant_id') ?? 0);
    }

    /**
     * Validate the item request has valid product and variant IDs.
     *
     * @return \WP_Error|true
     */
    public static function validate(\WP_REST_Request $request): \WP_Error|bool
    {
        $productId = self::getProductId($request);

        if ($productId < 1) {
            return new \WP_Error(
                'invalid_product_id',
                __('A valid product ID is required.', 'fchub-wishlist'),
                ['status' => 400]
            );
        }

        return true;
    }
}

<?php

declare(strict_types=1);

namespace FChubWishlist\Integration;

defined('ABSPATH') || exit;

final class AddonsRegistration
{
    public static function register(): void
    {
        add_filter('fluent_cart/integration/addons', [self::class, 'registerAddon']);
    }

    /**
     * @param array<string, array<string, mixed>> $addons
     * @return array<string, array<string, mixed>>
     */
    public static function registerAddon(array $addons): array
    {
        $addons['fchub-wishlist'] = [
            'title'       => __('FCHub Wishlist', 'fchub-wishlist'),
            'description' => __('Wishlist functionality for FluentCart with guest support, FluentCRM integration, and customer portal.', 'fchub-wishlist'),
            'logo'        => FCHUB_WISHLIST_URL . 'assets/icons/wishlist.svg',
            'enabled'     => true,
            'categories'  => ['enhancement'],
        ];

        return $addons;
    }
}

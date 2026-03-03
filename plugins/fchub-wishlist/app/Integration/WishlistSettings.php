<?php

declare(strict_types=1);

namespace FChubWishlist\Integration;

use FChubWishlist\Support\Constants;
use FluentCart\Framework\Support\Arr;

defined('ABSPATH') || exit;

final class WishlistSettings
{
    public static function register(): void
    {
        $slug = 'fchub-wishlist';
        add_filter("fluent_cart/integration/global_integration_settings_{$slug}", [self::class, 'getGlobalSettings'], 10, 2);
        add_filter("fluent_cart/integration/global_integration_fields_{$slug}", [self::class, 'getGlobalFields'], 10, 2);
        add_action("fluent_cart/integration/save_global_integration_settings_{$slug}", [self::class, 'saveGlobalSettings'], 10, 1);
    }

    /**
     * @return array<string, mixed>
     */
    public static function getSettings(): array
    {
        $saved = get_option(Constants::OPTION_SETTINGS, []);
        return wp_parse_args(is_array($saved) ? $saved : [], Constants::DEFAULT_SETTINGS);
    }

    public static function getGlobalSettings($settings, $args): array
    {
        return self::getSettings();
    }

    public static function getGlobalFields($fields, $args): array
    {
        $fieldSettings = [
            'logo'             => FCHUB_WISHLIST_URL . 'assets/icons/wishlist.svg',
            'save_button_text' => __('Save Settings', 'fchub-wishlist'),
            'valid_message'    => __('Wishlist integration is active', 'fchub-wishlist'),
            'invalid_message'  => __('Wishlist integration is disabled', 'fchub-wishlist'),
            'fields'           => [
                'enabled' => [
                    'type'    => 'select',
                    'label'   => __('Enable Wishlist', 'fchub-wishlist'),
                    'tips'    => __('Master switch for the wishlist feature across the store.', 'fchub-wishlist'),
                    'options' => [
                        'yes' => __('Yes', 'fchub-wishlist'),
                        'no'  => __('No', 'fchub-wishlist'),
                    ],
                ],
                'guest_wishlist_enabled' => [
                    'type'    => 'select',
                    'label'   => __('Guest Wishlists', 'fchub-wishlist'),
                    'tips'    => __('Allow non-logged-in visitors to save wishlists via cookie.', 'fchub-wishlist'),
                    'options' => [
                        'yes' => __('Yes', 'fchub-wishlist'),
                        'no'  => __('No', 'fchub-wishlist'),
                    ],
                ],
                'auto_remove_purchased' => [
                    'type'    => 'select',
                    'label'   => __('Auto-Remove Purchased', 'fchub-wishlist'),
                    'tips'    => __('Automatically remove items from the wishlist after purchase.', 'fchub-wishlist'),
                    'options' => [
                        'yes' => __('Yes', 'fchub-wishlist'),
                        'no'  => __('No', 'fchub-wishlist'),
                    ],
                ],
                'fluentcrm_enabled' => [
                    'type'    => 'select',
                    'label'   => __('FluentCRM Sync', 'fchub-wishlist'),
                    'tips'    => __('Tag contacts in FluentCRM based on wishlisted products.', 'fchub-wishlist'),
                    'options' => [
                        'yes' => __('Yes', 'fchub-wishlist'),
                        'no'  => __('No', 'fchub-wishlist'),
                    ],
                ],
                'full_settings' => [
                    'type'  => 'link',
                    'label' => __('Full Settings', 'fchub-wishlist'),
                    'tips'  => __('Configure UI options, cleanup, email reminders, and more.', 'fchub-wishlist'),
                    'url'   => admin_url('admin.php?page=fluent-cart#/settings/fchub-wishlist'),
                ],
            ],
        ];

        wp_send_json([
            'data' => [
                'integration' => self::getSettings(),
                'settings'    => $fieldSettings,
            ],
        ], 200);
    }

    public static function saveGlobalSettings($args): void
    {
        $integration = Arr::get($args, 'integration', []);
        $settings    = self::getSettings();
        $settings['enabled'] = Arr::get($integration, 'enabled', 'yes') === 'yes' ? 'yes' : 'no';
        $settings['guest_wishlist_enabled'] = Arr::get($integration, 'guest_wishlist_enabled', 'yes') === 'yes' ? 'yes' : 'no';
        $settings['auto_remove_purchased'] = Arr::get($integration, 'auto_remove_purchased', 'yes') === 'yes' ? 'yes' : 'no';
        $settings['fluentcrm_enabled'] = Arr::get($integration, 'fluentcrm_enabled', 'yes') === 'yes' ? 'yes' : 'no';

        update_option(Constants::OPTION_SETTINGS, $settings);

        wp_send_json([
            'data' => [
                'message' => __('Wishlist settings saved.', 'fchub-wishlist'),
                'status'  => true,
            ],
        ], 200);
    }
}

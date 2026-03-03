<?php

declare(strict_types=1);

namespace FChubWishlist\Http\Requests;

use FChubWishlist\Support\Constants;

defined('ABSPATH') || exit;

final class SettingsRequest
{
    private const TOGGLE_FIELDS = [
        'enabled',
        'guest_wishlist_enabled',
        'auto_remove_purchased',
        'show_on_product_cards',
        'show_on_single_product',
        'counter_badge_enabled',
        'email_reminder_enabled',
        'fluentcrm_enabled',
        'fluentcrm_auto_create_tags',
        'uninstall_remove_data',
    ];

    private const TEXT_FIELDS = [
        'icon_style',
        'button_text',
        'button_text_remove',
        'fluentcrm_tag_prefix',
    ];

    private const INT_FIELDS = [
        'max_items_per_list',
        'email_reminder_days',
        'guest_cleanup_days',
    ];

    private const ALLOWED_ICON_STYLES = ['heart', 'bookmark', 'star'];

    /**
     * Validate and sanitise settings input.
     *
     * @return array<string, mixed> Sanitised settings ready for storage.
     */
    public static function validate(array $data): array
    {
        $defaults = Constants::DEFAULT_SETTINGS;
        $clean = [];

        foreach (self::TOGGLE_FIELDS as $field) {
            if (isset($data[$field])) {
                $clean[$field] = $data[$field] === 'yes' ? 'yes' : 'no';
            }
        }

        foreach (self::TEXT_FIELDS as $field) {
            if (isset($data[$field])) {
                $clean[$field] = sanitize_text_field($data[$field]);
            }
        }

        foreach (self::INT_FIELDS as $field) {
            if (isset($data[$field])) {
                $clean[$field] = max(1, (int) $data[$field]);
            }
        }

        if (isset($clean['icon_style']) && !in_array($clean['icon_style'], self::ALLOWED_ICON_STYLES, true)) {
            $clean['icon_style'] = $defaults['icon_style'];
        }

        if (isset($clean['max_items_per_list'])) {
            $clean['max_items_per_list'] = min(1000, $clean['max_items_per_list']);
        }

        if (isset($clean['email_reminder_days'])) {
            $clean['email_reminder_days'] = min(365, $clean['email_reminder_days']);
        }

        if (isset($clean['guest_cleanup_days'])) {
            $clean['guest_cleanup_days'] = min(365, $clean['guest_cleanup_days']);
        }

        return $clean;
    }
}

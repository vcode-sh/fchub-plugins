<?php

declare(strict_types=1);

namespace FChubWishlist\Support;

defined('ABSPATH') || exit;

final class Constants
{
    // REST namespace
    public const REST_NAMESPACE = 'fchub-wishlist/v1';

    // Option keys
    public const OPTION_SETTINGS = 'fchub_wishlist_settings';
    public const OPTION_DB_VERSION = 'fchub_wishlist_db_version';

    // Hook prefix
    public const HOOK_PREFIX = 'fchub_wishlist/';

    // Cookie
    public const COOKIE_KEY = 'fchub_wishlist_hash';
    public const COOKIE_DAYS = 30;

    // Table names (without wpdb prefix)
    public const TABLE_LISTS = 'fchub_wishlist_lists';
    public const TABLE_ITEMS = 'fchub_wishlist_items';

    // Default settings (aligned with plan section 20)
    public const DEFAULT_SETTINGS = [
        // General
        'enabled'                    => 'yes',
        'guest_wishlist_enabled'     => 'yes',
        'auto_remove_purchased'      => 'yes',
        'max_items_per_list'         => 100,

        // UI
        'show_on_product_cards'      => 'yes',
        'show_on_single_product'     => 'yes',
        'icon_style'                 => 'heart',
        'button_text'                => 'Add to Wishlist',
        'button_text_remove'         => 'Remove from Wishlist',
        'counter_badge_enabled'      => 'yes',

        // Notifications
        'email_reminder_enabled'     => 'no',
        'email_reminder_days'        => 14,

        // Cleanup
        'guest_cleanup_days'         => 30,

        // FluentCRM
        'fluentcrm_enabled'          => 'yes',
        'fluentcrm_tag_prefix'       => 'wishlist:',
        'fluentcrm_auto_create_tags' => 'yes',

        // Data
        'uninstall_remove_data'      => 'no',
    ];

    // Cron hooks
    public const CRON_CLEANUP_GUESTS = 'fchub_wishlist_cleanup_guests';
    public const CRON_CLEANUP_ORPHANS = 'fchub_wishlist_cleanup_orphans';
    public const CRON_REMINDER = 'fchub_wishlist_reminder';
}

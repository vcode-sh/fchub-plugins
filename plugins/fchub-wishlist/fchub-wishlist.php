<?php
/**
 * Plugin Name: FCHub - Wishlist
 * Plugin URI: https://fchub.co
 * Description: Wishlist system for FluentCart with guest support, FluentCRM integration, and customer portal
 * Version: 1.0.0
 * Author: Vibe Code
 * Author URI: https://x.com/vcode_sh
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: fchub-wishlist
 * Domain Path: /languages
 * Requires at least: 6.7
 * Tested up to:    6.7
 * Requires PHP: 8.3
 */

defined('ABSPATH') || exit;

define('FCHUB_WISHLIST_VERSION', '1.0.0');
define('FCHUB_WISHLIST_FILE', __FILE__);
define('FCHUB_WISHLIST_PATH', plugin_dir_path(__FILE__));
define('FCHUB_WISHLIST_URL', plugin_dir_url(__FILE__));
define('FCHUB_WISHLIST_DB_VERSION', '1.0.0');

// Autoloader
spl_autoload_register(function ($class) {
    $prefix = 'FChubWishlist\\';
    $baseDir = FCHUB_WISHLIST_PATH . 'app/';

    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }

    $relativeClass = substr($class, $len);
    $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';

    if (file_exists($file)) {
        require_once $file;
    }
});

/**
 * Plugin activation: create database tables and register cron jobs.
 */
register_activation_hook(__FILE__, function () {
    FChubWishlist\Support\Migrations::run();

    if (!wp_next_scheduled('fchub_wishlist_cleanup_guests')) {
        wp_schedule_event(time(), 'daily', 'fchub_wishlist_cleanup_guests');
    }
    if (!wp_next_scheduled('fchub_wishlist_cleanup_orphans')) {
        wp_schedule_event(time(), 'weekly', 'fchub_wishlist_cleanup_orphans');
    }
    if (!wp_next_scheduled('fchub_wishlist_reminder')) {
        wp_schedule_event(time(), 'daily', 'fchub_wishlist_reminder');
    }
});

/**
 * Plugin deactivation: unregister cron jobs, preserve tables.
 */
register_deactivation_hook(__FILE__, function () {
    wp_clear_scheduled_hook('fchub_wishlist_cleanup_guests');
    wp_clear_scheduled_hook('fchub_wishlist_cleanup_orphans');
    wp_clear_scheduled_hook('fchub_wishlist_reminder');
});

/**
 * Boot the plugin after FluentCart and FluentCRM are loaded.
 * FluentCart registers its integrations on 'init' priority 2,
 * so we use priority 3 to ensure all dependencies are available.
 */
add_action('init', function () {
    if (!defined('FLUENTCART_VERSION')) {
        return;
    }

    if (!defined('FLUENTCRM')) {
        return;
    }

    // Run migrations if DB version changed
    $currentDbVersion = get_option('fchub_wishlist_db_version', '0');
    if (version_compare($currentDbVersion, FCHUB_WISHLIST_DB_VERSION, '<')) {
        FChubWishlist\Support\Migrations::run();
        update_option('fchub_wishlist_db_version', FCHUB_WISHLIST_DB_VERSION);
    }

    FChubWishlist\Bootstrap\Plugin::boot();
}, 3);

/**
 * Register FluentCRM automation triggers, actions, and filters.
 */
add_action('init', function () {
    if (defined('FLUENTCRM')) {
        FChubWishlist\FluentCRM\WishlistAutomation::boot();
    }
}, 30);

/**
 * Register admin menu page.
 */
add_action('admin_menu', function () {
    if (!defined('FLUENTCART_VERSION')) {
        return;
    }
    FChubWishlist\Support\AdminMenu::register();
}, 20);

/**
 * Cron: delete guest wishlists older than 30 days.
 */
add_action('fchub_wishlist_cleanup_guests', function () {
    if (!defined('FLUENTCART_VERSION')) {
        return;
    }
    FChubWishlist\Domain\GuestSession::cleanupExpired();
});

/**
 * Cron: remove items for deleted/trashed products.
 */
add_action('fchub_wishlist_cleanup_orphans', function () {
    if (!defined('FLUENTCART_VERSION')) {
        return;
    }
    (new FChubWishlist\Domain\Actions\CleanupOrphansAction())->execute();
});

/**
 * Cron: send wishlist reminder emails.
 */
add_action('fchub_wishlist_reminder', function () {
    if (!defined('FLUENTCART_VERSION')) {
        return;
    }
    (new FChubWishlist\Email\WishlistReminderEmail())->sendPendingReminders();
});

/**
 * Admin notice when FluentCart is missing.
 */
add_action('admin_notices', function () {
    if (defined('FLUENTCART_VERSION') && defined('FLUENTCRM')) {
        return;
    }

    $missing = [];
    if (!defined('FLUENTCART_VERSION')) {
        $missing[] = 'FluentCart';
    }
    if (!defined('FLUENTCRM')) {
        $missing[] = 'FluentCRM';
    }

    printf(
        '<div class="notice notice-error"><p>%s</p></div>',
        sprintf(
            /* translators: %s: comma-separated list of missing plugin names */
            esc_html__('FCHub Wishlist requires %s to be installed and activated.', 'fchub-wishlist'),
            esc_html(implode(' and ', $missing))
        )
    );
});

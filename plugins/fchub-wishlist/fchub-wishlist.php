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
 * Update URI: https://fchub.co/fchub-wishlist
 */

defined('ABSPATH') || exit;

define('FCHUB_WISHLIST_VERSION', '1.0.0');
define('FCHUB_WISHLIST_FILE', __FILE__);
define('FCHUB_WISHLIST_PATH', plugin_dir_path(__FILE__));
define('FCHUB_WISHLIST_URL', plugin_dir_url(__FILE__));
define('FCHUB_WISHLIST_DB_VERSION', '1.0.1');

require_once __DIR__ . '/lib/GitHubUpdater.php';
FCHub_GitHub_Updater::register('fchub-wishlist', plugin_basename(__FILE__), FCHUB_WISHLIST_VERSION);

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

$fchubWishlistCleanupGuestsHook = FChubWishlist\Support\Constants::CRON_CLEANUP_GUESTS;
$fchubWishlistCleanupOrphansHook = FChubWishlist\Support\Constants::CRON_CLEANUP_ORPHANS;
$fchubWishlistReminderHook = FChubWishlist\Support\Constants::CRON_REMINDER;

/**
 * Plugin activation: create database tables and register scheduled actions.
 */
register_activation_hook(__FILE__, function () {
    FChubWishlist\Support\Migrations::run();
    update_option('fchub_wishlist_db_version', FCHUB_WISHLIST_DB_VERSION);

    global $fchubWishlistCleanupGuestsHook, $fchubWishlistCleanupOrphansHook, $fchubWishlistReminderHook;

    if (function_exists('as_schedule_recurring_action')) {
        as_schedule_recurring_action(time(), DAY_IN_SECONDS, $fchubWishlistCleanupGuestsHook, [], 'fchub-wishlist', true);
        as_schedule_recurring_action(time(), WEEK_IN_SECONDS, $fchubWishlistCleanupOrphansHook, [], 'fchub-wishlist', true);
        as_schedule_recurring_action(time(), DAY_IN_SECONDS, $fchubWishlistReminderHook, [], 'fchub-wishlist', true);
    } else {
        if (!wp_next_scheduled($fchubWishlistCleanupGuestsHook)) {
            wp_schedule_event(time(), 'daily', $fchubWishlistCleanupGuestsHook);
        }
        if (!wp_next_scheduled($fchubWishlistCleanupOrphansHook)) {
            wp_schedule_event(time(), 'weekly', $fchubWishlistCleanupOrphansHook);
        }
        if (!wp_next_scheduled($fchubWishlistReminderHook)) {
            wp_schedule_event(time(), 'daily', $fchubWishlistReminderHook);
        }
    }
});

/**
 * Plugin deactivation: unregister scheduled actions, preserve tables.
 */
register_deactivation_hook(__FILE__, function () {
    global $fchubWishlistCleanupGuestsHook, $fchubWishlistCleanupOrphansHook, $fchubWishlistReminderHook;

    if (function_exists('as_unschedule_all_actions')) {
        as_unschedule_all_actions($fchubWishlistCleanupGuestsHook, [], 'fchub-wishlist');
        as_unschedule_all_actions($fchubWishlistCleanupOrphansHook, [], 'fchub-wishlist');
        as_unschedule_all_actions($fchubWishlistReminderHook, [], 'fchub-wishlist');
    }
    wp_clear_scheduled_hook($fchubWishlistCleanupGuestsHook);
    wp_clear_scheduled_hook($fchubWishlistCleanupOrphansHook);
    wp_clear_scheduled_hook($fchubWishlistReminderHook);
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
    if (defined('FLUENTCART_VERSION') && defined('FLUENTCRM')) {
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
add_action($fchubWishlistCleanupGuestsHook, function () {
    if (!defined('FLUENTCART_VERSION')) {
        return;
    }
    FChubWishlist\Domain\GuestSession::cleanupExpired();
});

/**
 * Cron: remove items for deleted/trashed products.
 */
add_action($fchubWishlistCleanupOrphansHook, function () {
    if (!defined('FLUENTCART_VERSION')) {
        return;
    }
    (new FChubWishlist\Domain\Actions\CleanupOrphansAction())->execute();
});

/**
 * Cron: send wishlist reminder emails.
 */
add_action($fchubWishlistReminderHook, function () {
    if (!defined('FLUENTCART_VERSION')) {
        return;
    }
    (new FChubWishlist\Email\WishlistReminderEmail())->sendPendingReminders();
});

/**
 * Async email dispatch via Action Scheduler.
 */
add_action('fchub_wishlist_send_email', function (string $to, string $subject, string $body, array $headers) {
    $sent = wp_mail($to, $subject, $body, $headers);
    if (!$sent) {
        FChubWishlist\Support\Logger::error('Wishlist email send failed', [
            'to' => $to,
        ]);
    }
}, 10, 4);

/**
 * Admin notice when FluentCart is missing.
 */
add_action('admin_notices', function () {
    if (defined('FLUENTCART_VERSION') && defined('FLUENTCRM')) {
        return;
    }

    if (!defined('FLUENTCART_VERSION')) {
        printf(
            '<div class="notice notice-error"><p>%s</p></div>',
            esc_html__('FCHub Wishlist requires FluentCart to be installed and activated.', 'fchub-wishlist')
        );
        return;
    }

    if (defined('FLUENTCRM')) {
        return;
    }

    printf(
        '<div class="notice notice-warning"><p>%s</p></div>',
        esc_html__(
            'FCHub Wishlist is running without FluentCRM. Wishlist automation and contact sync features are disabled until FluentCRM is activated.',
            'fchub-wishlist'
        )
    );
});

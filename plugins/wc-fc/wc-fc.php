<?php
/**
 * Plugin Name: FCHub - WC Migrator
 * Plugin URI: https://fchub.co
 * Description: Migrate WooCommerce data (products, customers, orders, subscriptions, coupons) to FluentCart.
 * Version: 1.0.2
 * Author: Vibe Code
 * Author URI: https://x.com/vcode_sh
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: wc-fc
 * Domain Path: /languages
 * Requires at least: 6.4
 * Requires PHP: 8.1
 * Tested up to:    6.7
 * Requires Plugins: woocommerce, fluent-cart
 * Update URI: https://fchub.co/wc-fc
 */

defined('ABSPATH') or die;

define('WCFC_VERSION', '1.0.2');
define('WCFC_PLUGIN_PATH', plugin_dir_path(__FILE__));
define('WCFC_PLUGIN_URL', plugin_dir_url(__FILE__));
define('WCFC_PLUGIN_FILE', __FILE__);

require_once __DIR__ . '/lib/GitHubUpdater.php';
FCHub_GitHub_Updater::register('wc-fc', plugin_basename(__FILE__), WCFC_VERSION);

/**
 * PSR-4 autoloader for the WcFc namespace.
 *
 * Maps WcFc\* to app/ and WcFc\Database\* to database/Migrations/.
 */
spl_autoload_register(function ($class) {
    $prefix = 'WcFc\\';
    $len = strlen($prefix);

    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }

    $relativeClass = substr($class, $len);

    // Special mapping for database namespace.
    $dbPrefix = 'Database\\';
    if (strncmp($dbPrefix, $relativeClass, strlen($dbPrefix)) === 0) {
        $dbRelative = substr($relativeClass, strlen($dbPrefix));
        $file = __DIR__ . '/database/Migrations/' . str_replace('\\', '/', $dbRelative) . '.php';
    } else {
        $file = __DIR__ . '/app/' . str_replace('\\', '/', $relativeClass) . '.php';
    }

    if (file_exists($file)) {
        require $file;
    }
});

/**
 * Activation: create migration tables.
 */
register_activation_hook(__FILE__, function () {
    $migration = new \WcFc\Database\CreateMigrationTables();
    $migration->up();
});

/**
 * Deactivation: optionally clean up transients.
 */
register_deactivation_hook(__FILE__, function () {
    delete_option('wcfc_migration_state');
});

/**
 * Bootstrap admin functionality.
 */
add_action('admin_notices', function () {
    if (!class_exists('WooCommerce') || !defined('FLUENTCART_VERSION')) {
        echo '<div class="notice notice-error"><p>';
        echo esc_html__('FCHub - WC Migrator requires WooCommerce and FluentCart to be active.', 'wc-fc');
        echo '</p></div>';
    }
});

add_action('init', function () {
    if (!class_exists('WooCommerce') || !defined('FLUENTCART_VERSION')) {
        return;
    }

    if (!is_admin()) {
        return;
    }

    $adminMenu = new \WcFc\Admin\AdminMenu();
    $adminMenu->register();
}, 20);

add_action('rest_api_init', function () {
    if (!class_exists('WooCommerce') || !defined('FLUENTCART_VERSION')) {
        return;
    }

    $controller = new \WcFc\Admin\AdminController();
    $controller->registerRoutes();
});

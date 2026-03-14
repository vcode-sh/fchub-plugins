<?php
/**
 * Plugin Name: CartShift
 * Plugin URI: https://fchub.co
 * Description: Migrate WooCommerce data (products, customers, orders, subscriptions, coupons) to FluentCart.
 * Version: 1.0.3
 * Author: Vibe Code
 * Author URI: https://x.com/vcode_sh
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: cartshift
 * Domain Path: /languages
 * Requires at least: 6.4
 * Requires PHP: 8.1
 * Tested up to:    6.7
 * Requires Plugins: woocommerce, fluent-cart
 * Update URI: https://fchub.co/cartshift
 */

defined('ABSPATH') or die;

define('CARTSHIFT_VERSION', '1.0.3');
define('CARTSHIFT_DB_VERSION', '1');
define('CARTSHIFT_PLUGIN_PATH', plugin_dir_path(__FILE__));
define('CARTSHIFT_PLUGIN_URL', plugin_dir_url(__FILE__));
define('CARTSHIFT_PLUGIN_FILE', __FILE__);

require_once __DIR__ . '/lib/GitHubUpdater.php';
FCHub_GitHub_Updater::register('cartshift', plugin_basename(__FILE__), CARTSHIFT_VERSION);

/**
 * PSR-4 autoloader for the CartShift namespace.
 *
 * Maps CartShift\* to app/ and CartShift\Database\* to database/Migrations/.
 */
spl_autoload_register(function ($class) {
    $prefix = 'CartShift\\';
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

add_action('plugins_loaded', function () {
    load_plugin_textdomain('cartshift', false, 'cartshift/languages');
});

/**
 * Activation: run versioned database migrations.
 */
register_activation_hook(__FILE__, function () {
    \CartShift\Support\Migrations::run();
});

/**
 * Deactivation: optionally clean up transients.
 */
register_deactivation_hook(__FILE__, function () {
    delete_option('cartshift_migration_state');
});

/**
 * Bootstrap the plugin via the module system.
 *
 * WordPress enforces `Requires Plugins: woocommerce, fluent-cart` at activation.
 * Dependency checks happen in the preflight endpoint, not here.
 */
add_action('plugins_loaded', fn () => \CartShift\Core\PluginBootstrap::boot(), 20);

if (defined('WP_CLI') && WP_CLI) {
    \CartShift\CLI\MigrateCommand::register();
}

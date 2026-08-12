<?php

/**
 * Plugin Name: CartShift
 * Plugin URI: https://fchub.co
 * Description: Migrate WooCommerce data (products, customers, orders, subscriptions, coupons) to FluentCart.
 * Version: 1.5.0
 * Author: Vibe Code
 * Author URI: https://x.com/vcode_sh
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: cartshift
 * Domain Path: /languages
 * Requires at least: 6.8
 * Requires PHP: 8.3
 * Tested up to:    7.0
 * Update URI: https://fchub.co/cartshift
 */

/*
 * There is deliberately no `Requires Plugins` header.
 *
 * It read `woocommerce, fluent-cart`, which WordPress enforces as an AND. That was
 * right while CartShift only ever ran on one site holding both. It is wrong for a
 * cross-runtime migration, where by definition neither site holds both: the source
 * runs WooCommerce and WooCommerce Subscriptions, the destination runs FluentCart,
 * and CartShift has to boot on each to export from one and stage into the other.
 * The header is satisfiable on neither, so it blocked the very topology plan
 * section 3 mandates — found by activating on a real source, not by any test,
 * because WordPress enforces this itself and every test stubs the environment.
 *
 * The header cannot express "either", so the check moved to where it can answer
 * properly. `CartShift\Domain\Subscription\RuntimeCompatibilityProbe` reports which
 * runtime this is, which APIs are present and which are missing, each with a stable
 * reason code; `CartShift\Validator\PreflightCheck` refuses an operation the booted
 * runtime cannot support. Both are strictly more precise than the header: they can
 * say "this is a valid source but not a valid target", which is exactly the sentence
 * an operator needs and the one a plugin header can never write.
 */

defined('ABSPATH') or die;

define('CARTSHIFT_VERSION', '1.5.0');
define('CARTSHIFT_DB_VERSION', '8');
define('CARTSHIFT_PLUGIN_PATH', plugin_dir_path(__FILE__));
define('CARTSHIFT_PLUGIN_URL', plugin_dir_url(__FILE__));
define('CARTSHIFT_PLUGIN_FILE', __FILE__);

// A missing updater must mean "no automatic updates", never a fatal on activation —
// a WordPress.org build omits the file on purpose.
if (file_exists(__DIR__ . '/lib/GitHubUpdater.php')) {
    require_once __DIR__ . '/lib/GitHubUpdater.php';
    FCHub_GitHub_Updater::register('cartshift', plugin_basename(__FILE__), CARTSHIFT_VERSION);
}

/**
 * PSR-4 autoloader for the CartShift namespace.
 *
 * Maps CartShift\* to app/. Schema changes live in CartShift\Support\Migrations,
 * so there is no separate database/ namespace to resolve.
 */
spl_autoload_register(function ($class) {
    $prefix = 'CartShift\\';
    $len = strlen($prefix);

    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }

    $relativeClass = substr($class, $len);

    $file = __DIR__ . '/app/' . str_replace('\\', '/', $relativeClass) . '.php';

    // H1: Prevent path traversal — resolved path must stay within plugin directory.
    if (file_exists($file)) {
        $realPath = realpath($file);
        if ($realPath && str_starts_with($realPath, __DIR__)) {
            require $realPath;
        }
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
add_action('plugins_loaded', fn() => \CartShift\Core\PluginBootstrap::boot(), 20);

if (defined('WP_CLI') && WP_CLI) {
    \CartShift\CLI\MigrateCommand::register();
    \CartShift\CLI\SubscriptionCommand::register();
    \CartShift\CLI\TransferCommand::register();
}

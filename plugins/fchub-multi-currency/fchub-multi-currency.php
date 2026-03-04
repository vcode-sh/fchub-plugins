<?php

/**
 * Plugin Name: FCHub - Multi-Currency
 * Plugin URI: https://fchub.co
 * Description: Display-layer multi-currency for FluentCart with exchange rate management and checkout disclosure
 * Version: 1.0.0
 * Author: Vibe Code
 * Author URI: https://x.com/vcode_sh
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: fchub-multi-currency
 * Domain Path: /languages
 * Requires at least: 6.7
 * Tested up to:    6.7
 * Requires PHP: 8.3
 * Update URI: https://fchub.co/fchub-multi-currency
 */

defined('ABSPATH') || exit;

define('FCHUB_MC_VERSION', '1.0.0');
define('FCHUB_MC_FILE', __FILE__);
define('FCHUB_MC_PATH', plugin_dir_path(__FILE__));
define('FCHUB_MC_URL', plugin_dir_url(__FILE__));
define('FCHUB_MC_DB_VERSION', '1.0.0');

require_once __DIR__ . '/lib/GitHubUpdater.php';
FCHub_GitHub_Updater::register('fchub-multi-currency', plugin_basename(__FILE__), FCHUB_MC_VERSION);

// Autoloader
spl_autoload_register(function ($class) {
    $prefix = 'FChubMultiCurrency\\';
    $baseDir = FCHUB_MC_PATH . 'app/';

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
 * Plugin activation: create database tables and schedule rate refresh cron.
 */
register_activation_hook(__FILE__, function () {
    FChubMultiCurrency\Support\Migrations::run();
    update_option('fchub_mc_db_version', FCHUB_MC_DB_VERSION);

    if (!wp_next_scheduled('fchub_mc_refresh_rates')) {
        wp_schedule_event(time(), 'six_hours', 'fchub_mc_refresh_rates');
    }
});

/**
 * Plugin deactivation: unregister scheduled actions, preserve tables.
 */
register_deactivation_hook(__FILE__, function () {
    wp_clear_scheduled_hook('fchub_mc_refresh_rates');
});

/**
 * Register custom cron interval for rate refresh.
 */
add_filter('cron_schedules', function (array $schedules): array {
    $schedules['six_hours'] = [
        'interval' => 6 * HOUR_IN_SECONDS,
        'display'  => __('Every Six Hours', 'fchub-multi-currency'),
    ];
    return $schedules;
});

/**
 * Boot the plugin after FluentCart is loaded.
 * FluentCart registers its integrations on 'init' priority 2,
 * so we use priority 3 to ensure all dependencies are available.
 */
add_action('init', function () {
    if (!defined('FLUENTCART_VERSION')) {
        return;
    }

    // Run migrations if DB version changed
    $currentDbVersion = get_option('fchub_mc_db_version', '0');
    if (version_compare($currentDbVersion, FCHUB_MC_DB_VERSION, '<')) {
        FChubMultiCurrency\Support\Migrations::run();
        update_option('fchub_mc_db_version', FCHUB_MC_DB_VERSION);
    }

    FChubMultiCurrency\Bootstrap\Plugin::boot();
}, 3);

/**
 * Register sidebar submenu under FluentCart.
 */
add_action('admin_menu', function () {
    if (!defined('FLUENTCART_VERSION')) {
        return;
    }
    FChubMultiCurrency\Support\AdminMenu::register();
}, 20);

/**
 * Cron: refresh exchange rates from provider.
 */
add_action('fchub_mc_refresh_rates', function () {
    if (!defined('FLUENTCART_VERSION')) {
        return;
    }
    (new FChubMultiCurrency\Domain\Actions\RefreshRatesAction(
        new FChubMultiCurrency\Storage\ExchangeRateRepository(),
        new FChubMultiCurrency\Storage\RatesCacheStore(),
    ))->execute();
});

/**
 * Admin notice when FluentCart is missing.
 */
add_action('admin_notices', function () {
    if (defined('FLUENTCART_VERSION')) {
        return;
    }

    printf(
        '<div class="notice notice-error"><p>%s</p></div>',
        esc_html__('FCHub Multi-Currency requires FluentCart to be installed and activated.', 'fchub-multi-currency')
    );
});

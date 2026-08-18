<?php

/**
 * Plugin Name: FCHub Multi-Currency
 * Plugin URI: https://fchub.co/docs/fchub-multi-currency
 * Description: Display-layer multi-currency for FluentCart with exchange rate management and checkout disclosure
 * Version: 1.4.8
 * Author: Vibe Code
 * Author URI: https://x.com/vcode_sh
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: fchub-multi-currency
 * Domain Path: /languages
 * Requires at least: 7.0
 * Tested up to: 7.0
 * Requires PHP: 8.3
 * Requires Plugins: fluent-cart
 * Update URI: https://fchub.co/fchub-multi-currency
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

define('FCHUB_MC_VERSION', '1.4.8');
define('FCHUB_MC_FILE', __FILE__);
define('FCHUB_MC_PATH', plugin_dir_path(__FILE__));
define('FCHUB_MC_URL', plugin_dir_url(__FILE__));
define('FCHUB_MC_DB_VERSION', '1.1.0');

// Updates come from GitHub releases until this plugin is actually listed on
// WordPress.org. Without it WordPress has no update channel for the plugin at all.
// Guarded because a WordPress.org build deliberately omits the file, and a missing
// updater must mean "no automatic updates", never a fatal on activation.
if (file_exists(__DIR__ . '/lib/GitHubUpdater.php')) {
    require_once __DIR__ . '/lib/GitHubUpdater.php';
    FCHub_GitHub_Updater::register('fchub-multi-currency', plugin_basename(__FILE__), FCHUB_MC_VERSION);
}

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
 * Plugin activation: create database tables and honour saved provider consent.
 */
register_activation_hook(__FILE__, function () {
    FChubMultiCurrency\Support\Migrations::run();
    update_option('fchub_mc_db_version', FCHUB_MC_DB_VERSION);

    $optionStore = new FChubMultiCurrency\Storage\OptionStore();
    $optionStore->ensureExplicitRateProvider();
    FChubMultiCurrency\Support\RateSchedule::sync($optionStore);
});

/**
 * Plugin deactivation: unregister scheduled actions, preserve tables.
 */
register_deactivation_hook(__FILE__, function () {
    wp_clear_scheduled_hook('fchub_mc_refresh_rates');
    wp_clear_scheduled_hook('fchub_mc_daily_maintenance');
});

/**
 * Register dynamic cron interval for rate refresh based on saved setting.
 */
add_filter('cron_schedules', function (array $schedules): array {
    $settings = get_option('fchub_mc_settings', []);
    $hours = max(1, (int) ($settings['rate_refresh_interval_hrs'] ?? 6));

    $schedules['fchub_mc_rate_interval'] = [
        'interval' => $hours * HOUR_IN_SECONDS,
        'display'  => sprintf(
            // translators: %d is the number of hours between rate refreshes
            __('Every %d Hours', 'fchub-multi-currency'),
            $hours,
        ),
    ];
    return $schedules;
});

/**
 * Boot the plugin after FluentCart is loaded. FLUENTCART_VERSION is defined at
 * FluentCart's plugin load, so it is testable here; priority 3 keeps our hook
 * registrations ahead of FluentCart's own init work (its app boots at
 * init 10) while staying after its constants and autoloader exist.
 */
add_action('init', function () {
    // Self-hosted plugins get no wordpress.org language packs; the languages
    // directory in the ZIP is the delivery channel. Loaded before the
    // FluentCart guard so the missing-dependency notice translates too.
    load_plugin_textdomain('fchub-multi-currency', false, dirname(plugin_basename(__FILE__)) . '/languages');

    if (!defined('FLUENTCART_VERSION')) {
        return;
    }

    // Run migrations if DB version changed
    $currentDbVersion = get_option('fchub_mc_db_version', '0');
    if (version_compare($currentDbVersion, FCHUB_MC_DB_VERSION, '<')) {
        FChubMultiCurrency\Support\Migrations::run();
        update_option('fchub_mc_db_version', FCHUB_MC_DB_VERSION);
    }

    // Restore a missing schedule only when a remote provider was explicitly saved.
    $optionStore = new FChubMultiCurrency\Storage\OptionStore();
    $optionStore->ensureExplicitRateProvider();
    FChubMultiCurrency\Support\RateSchedule::sync($optionStore);

    // Retention runs on its own daily schedule: the refresh cron only exists
    // for remote providers, and a manual-rate store still accumulates event
    // log rows it must eventually shed.
    if (!wp_next_scheduled('fchub_mc_daily_maintenance')) {
        wp_schedule_event(time(), 'daily', 'fchub_mc_daily_maintenance');
    }

    FChubMultiCurrency\Bootstrap\Plugin::boot();
}, 3);

/**
 * Cron: prune plugin-owned tables to their retention window.
 */
add_action('fchub_mc_daily_maintenance', function () {
    (new FChubMultiCurrency\Storage\EventLogRepository())->pruneOlderThan(90);
    (new FChubMultiCurrency\Storage\Queries\RateHistoryQuery())->pruneOlderThan(90);
});

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

/**
 * Public API: Format a base-currency price in the visitor's display currency.
 *
 * Other FCHub plugins can call this to render multi-currency aware prices:
 *   fchub_mc_format_price(999) → "€9.34"
 *
 * Falls back to FluentCart's default formatting when multi-currency is inactive
 * or the visitor is browsing in the base currency.
 *
 * @param float $basePrice Price in FluentCart's cent-based storage unit
 * @return string Formatted price
 */
function fchub_mc_format_price(float $basePrice): string
{
    return FChubMultiCurrency\Integration\PublicPriceApi::formatPrice($basePrice);
}

/**
 * Get the display currency code for a specific order.
 *
 * Returns the converted currency the customer placed the order in, or null for
 * a base-currency order. Checkout leaves a bookkeeping marker on base-currency
 * orders; that marker is not multicurrency data and never surfaces here.
 *
 * @param int $orderId FluentCart order ID
 * @return string|null Currency code (e.g., 'EUR') or null
 */
function fchub_mc_get_order_display_currency(int $orderId): ?string
{
    return FChubMultiCurrency\Integration\PublicPriceApi::getOrderDisplayCurrency($orderId);
}

/**
 * Format a base-currency price using a specific order's display currency.
 *
 * Uses the exchange rate that was captured at checkout time for that order.
 * Falls back to base currency formatting if no multicurrency data exists.
 *
 *   fchub_mc_format_order_price(9999, 42) → "€84.99"
 *
 * @param float $basePrice Price in FluentCart's cent-based storage unit
 * @param int $orderId FluentCart order ID
 * @return string Formatted price
 */
function fchub_mc_format_order_price(float $basePrice, int $orderId): string
{
    return FChubMultiCurrency\Integration\PublicPriceApi::formatOrderPrice($basePrice, $orderId);
}

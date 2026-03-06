<?php

/**
 * Plugin Name: FCHub - Multi-Currency
 * Plugin URI: https://fchub.co
 * Description: Display-layer multi-currency for FluentCart with exchange rate management and checkout disclosure
 * Version: 1.1.4
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

declare(strict_types=1);

defined('ABSPATH') || exit;

define('FCHUB_MC_VERSION', '1.1.4');
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
        wp_schedule_event(time(), 'fchub_mc_rate_interval', 'fchub_mc_refresh_rates');
    }
});

/**
 * Plugin deactivation: unregister scheduled actions, preserve tables.
 */
register_deactivation_hook(__FILE__, function () {
    wp_clear_scheduled_hook('fchub_mc_refresh_rates');
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

    // Re-schedule cron if it went missing (WP cron cleanup, options reset, etc.)
    if (!wp_next_scheduled('fchub_mc_refresh_rates')) {
        wp_schedule_event(time(), 'fchub_mc_rate_interval', 'fchub_mc_refresh_rates');
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

    (new FChubMultiCurrency\Storage\Queries\RateHistoryQuery())->pruneOlderThan(90);
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
 *   fchub_mc_format_price(9.99) → "€9.34"
 *
 * Falls back to FluentCart's default formatting when multi-currency is inactive
 * or the visitor is browsing in the base currency.
 *
 * @param float $basePrice Price in the store's base currency
 * @return string Formatted price HTML
 */
function fchub_mc_format_price(float $basePrice): string
{
    if (!defined('FLUENTCART_VERSION')) {
        return (string) $basePrice;
    }

    if (!FChubMultiCurrency\Support\Hooks::isEnabled()) {
        return \FluentCart\Api\CurrencySettings::getPriceHtml($basePrice);
    }

    // Reuse the already-resolved context if available (avoids rebuilding the full
    // resolver chain + DB queries on every call in a product listing loop)
    $context = FChubMultiCurrency\Domain\Services\CurrencyContextService::getResolved();

    if ($context === null) {
        $optionStore = new FChubMultiCurrency\Storage\OptionStore();
        $contextService = new FChubMultiCurrency\Domain\Services\CurrencyContextService(
            FChubMultiCurrency\Bootstrap\Modules\ContextModule::buildResolverChain($optionStore),
            $optionStore,
        );
        $context = $contextService->resolve();
    }

    if ($context->isBaseDisplay) {
        return \FluentCart\Api\CurrencySettings::getPriceHtml($basePrice);
    }

    $converted = function_exists('bcmul')
        ? (float) bcmul((string) $basePrice, $context->rate->rate, 8)
        : ((float) $basePrice * (float) $context->rate->rate);

    $roundingMode = FChubMultiCurrency\Domain\Enums\RoundingMode::from(
        $optionStore->get('rounding_mode', 'half_up'),
    );
    $decimals = $context->displayCurrency->decimals;

    $rounded = match ($roundingMode) {
        FChubMultiCurrency\Domain\Enums\RoundingMode::None     => $converted,
        FChubMultiCurrency\Domain\Enums\RoundingMode::HalfUp   => round($converted, $decimals, PHP_ROUND_HALF_UP),
        FChubMultiCurrency\Domain\Enums\RoundingMode::HalfDown => round($converted, $decimals, PHP_ROUND_HALF_DOWN),
        FChubMultiCurrency\Domain\Enums\RoundingMode::Ceil     => (float) (ceil($converted * (10 ** $decimals)) / (10 ** $decimals)),
        FChubMultiCurrency\Domain\Enums\RoundingMode::Floor    => (float) (floor($converted * (10 ** $decimals)) / (10 ** $decimals)),
    };

    return \FluentCart\Api\CurrencySettings::getPriceHtml($rounded, $context->displayCurrency->code);
}

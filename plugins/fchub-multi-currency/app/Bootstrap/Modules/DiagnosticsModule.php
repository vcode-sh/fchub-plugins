<?php

declare(strict_types=1);

namespace FChubMultiCurrency\Bootstrap\Modules;

use FChubMultiCurrency\Bootstrap\ModuleContract;
use FChubMultiCurrency\Storage\ExchangeRateRepository;
use FChubMultiCurrency\Storage\OptionStore;

defined('ABSPATH') || exit;

final class DiagnosticsModule implements ModuleContract
{
    public function register(): void
    {
        add_action('admin_notices', [self::class, 'showStaleRateWarning']);
    }

    public static function showStaleRateWarning(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $optionStore = new OptionStore();
        $settings = $optionStore->all();

        if (($settings['enabled'] ?? 'yes') !== 'yes') {
            return;
        }

        $staleThresholdHrs = (int) ($settings['stale_threshold_hrs'] ?? 24);
        $staleThresholdSeconds = $staleThresholdHrs * 3600;

        // Cache the stale check for 5 minutes to avoid a DB query on every admin page
        $cacheKey = 'fchub_mc_has_stale_rates';
        $cached = get_transient($cacheKey);

        if ($cached === 'no') {
            return;
        }

        if ($cached !== 'yes') {
            $baseCurrency = $settings['base_currency'] ?? 'USD';
            $repository = new ExchangeRateRepository();
            $rates = $repository->findAllLatest($baseCurrency);

            if (empty($rates)) {
                set_transient($cacheKey, 'no', 5 * MINUTE_IN_SECONDS);
                return;
            }

            $hasStale = false;
            foreach ($rates as $rate) {
                if ($rate->isStale($staleThresholdSeconds)) {
                    $hasStale = true;
                    break;
                }
            }

            set_transient($cacheKey, $hasStale ? 'yes' : 'no', 5 * MINUTE_IN_SECONDS);

            if (!$hasStale) {
                return;
            }
        }

        printf(
            '<div class="notice notice-warning"><p>%s</p></div>',
            esc_html__(
                'FCHub Multi-Currency: Some exchange rates are stale. Check the diagnostics page or trigger a manual refresh.',
                'fchub-multi-currency',
            ),
        );
    }
}

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

        $baseCurrency = $settings['base_currency'] ?? 'USD';
        $repository = new ExchangeRateRepository();
        $rates = $repository->findAllLatest($baseCurrency);

        if (empty($rates)) {
            return;
        }

        $hasStale = false;
        foreach ($rates as $rate) {
            if ($rate->isStale($staleThresholdSeconds)) {
                $hasStale = true;
                break;
            }
        }

        if (!$hasStale) {
            return;
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

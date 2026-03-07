<?php

declare(strict_types=1);

namespace FChubMultiCurrency\Http\Controllers\Admin;

use FChubMultiCurrency\Storage\ExchangeRateRepository;
use FChubMultiCurrency\Storage\EventLogRepository;
use FChubMultiCurrency\Storage\OptionStore;
use FChubMultiCurrency\Support\FeatureFlags;

defined('ABSPATH') || exit;

final class DiagnosticsController
{
    public function get(\WP_REST_Request $request): \WP_REST_Response
    {
        $optionStore = new OptionStore();
        $settings = $optionStore->all();
        $baseCurrency = $settings['base_currency'] ?? 'USD';

        $repository = new ExchangeRateRepository();
        $rates = $repository->findAllLatest($baseCurrency);
        $eventLogRepository = new EventLogRepository();
        $staleThreshold = ((int) ($settings['stale_threshold_hrs'] ?? 24)) * 3600;

        $staleRates = [];

        foreach ($rates as $rate) {
            if ($rate->isStale($staleThreshold)) {
                $staleRates[] = $rate->quoteCurrency;
            }
        }

        return new \WP_REST_Response([
            'data' => [
                'plugin_version'    => FCHUB_MC_VERSION,
                'db_version'        => get_option('fchub_mc_db_version', '0'),
                'base_currency'     => $baseCurrency,
                'rate_count'        => count($rates),
                'stale_rates'       => $staleRates,
                'feature_flags'     => FeatureFlags::all(),
                'event_counts'      => $eventLogRepository->countByEvent(),
                'top_switched_currencies' => $eventLogRepository->topCurrenciesForEvent('context_switched', 5),
                'fluentcart_version' => defined('FLUENTCART_VERSION') ? FLUENTCART_VERSION : 'not installed',
                'fluentcrm_active'  => defined('FLUENTCRM'),
                'php_version'       => PHP_VERSION,
                'bcmath_available'  => extension_loaded('bcmath'),
            ],
        ]);
    }
}

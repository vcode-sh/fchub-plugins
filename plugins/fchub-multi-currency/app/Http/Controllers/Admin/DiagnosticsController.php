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

        $fluentcrmFieldsStatus = null;

        if (defined('FLUENTCRM')) {
            $fieldSlugs = [
                'preferred_currency'        => $settings['fluentcrm_field_preferred'] ?? 'preferred_currency',
                'last_order_display_currency' => $settings['fluentcrm_field_last_order'] ?? 'last_order_display_currency',
                'last_order_fx_rate'        => $settings['fluentcrm_field_last_rate'] ?? 'last_order_fx_rate',
            ];

            $fluentcrmFieldsStatus = [];

            foreach ($fieldSlugs as $key => $slug) {
                try {
                    if (class_exists(\FluentCrm\App\Models\CustomContactField::class)) {
                        $fluentcrmFieldsStatus[$key] = \FluentCrm\App\Models\CustomContactField::where('slug', $slug)->exists();
                    } else {
                        $fluentcrmFieldsStatus[$key] = null;
                    }
                } catch (\Throwable $e) {
                    $fluentcrmFieldsStatus[$key] = null;
                }
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
                'fluentcrm_fields_status' => $fluentcrmFieldsStatus,
                'php_version'       => PHP_VERSION,
                'bcmath_available'  => extension_loaded('bcmath'),
            ],
        ]);
    }
}

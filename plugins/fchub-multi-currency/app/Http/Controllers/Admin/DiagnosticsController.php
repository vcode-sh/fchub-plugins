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

        $fluentcrmFieldsStatus = defined('FLUENTCRM')
            ? self::crmFieldsStatus(self::crmFieldSlugs($settings))
            : null;

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

    /**
     * Quick action: create whichever of the three FluentCRM custom fields are
     * missing, under the configured slugs, so the CRM sync has somewhere to
     * write without the admin hand-building fields. Existing fields are never
     * touched; running it twice creates nothing new.
     */
    public function createCrmFields(\WP_REST_Request $request): \WP_REST_Response
    {
        if (!defined('FLUENTCRM') || !class_exists(\FluentCrm\App\Models\CustomContactField::class)) {
            return new \WP_REST_Response([
                'data' => [
                    'code'    => 'fluentcrm_unavailable',
                    'message' => __('FluentCRM is not active, so there is nowhere to create the fields.', 'fchub-multi-currency'),
                ],
            ], 409);
        }

        $slugs = self::crmFieldSlugs((new OptionStore())->all());
        $labels = [
            'preferred_currency'          => __('Preferred Currency', 'fchub-multi-currency'),
            'last_order_display_currency' => __('Last Order Display Currency', 'fchub-multi-currency'),
            'last_order_fx_rate'          => __('Last Order FX Rate', 'fchub-multi-currency'),
        ];

        $model = new \FluentCrm\App\Models\CustomContactField();
        $fields = $model->getGlobalFields()['fields'] ?? [];
        $existingSlugs = array_map(
            static fn(array $field): string => (string) ($field['slug'] ?? ''),
            array_filter($fields, 'is_array'),
        );

        foreach ($slugs as $key => $slug) {
            if (!in_array($slug, $existingSlugs, true)) {
                // The sync writes plain strings, rate included, so text is the
                // faithful field type for all three.
                $fields[] = ['slug' => $slug, 'label' => $labels[$key], 'type' => 'text'];
            }
        }

        $model->saveGlobalFields($fields);

        return new \WP_REST_Response([
            'data' => [
                'fluentcrm_fields_status' => self::crmFieldsStatus($slugs),
            ],
        ]);
    }

    /**
     * @param array<string, mixed> $settings
     * @return array<string, string>
     */
    private static function crmFieldSlugs(array $settings): array
    {
        return [
            'preferred_currency'          => (string) ($settings['fluentcrm_field_preferred'] ?? 'preferred_currency'),
            'last_order_display_currency' => (string) ($settings['fluentcrm_field_last_order'] ?? 'last_order_display_currency'),
            'last_order_fx_rate'          => (string) ($settings['fluentcrm_field_last_rate'] ?? 'last_order_fx_rate'),
        ];
    }

    /**
     * @param array<string, string> $fieldSlugs
     * @return array<string, bool|null> true = present, false = missing, null = unknowable
     */
    private static function crmFieldsStatus(array $fieldSlugs): array
    {
        if (!class_exists(\FluentCrm\App\Models\CustomContactField::class)) {
            return array_fill_keys(array_keys($fieldSlugs), null);
        }

        try {
            $globalFields = (new \FluentCrm\App\Models\CustomContactField())->getGlobalFields();
            $availableSlugs = [];

            foreach ($globalFields['fields'] ?? [] as $field) {
                if (is_array($field) && isset($field['slug'])) {
                    $availableSlugs[] = (string) $field['slug'];
                }
            }

            $status = [];
            foreach ($fieldSlugs as $key => $slug) {
                $status[$key] = in_array($slug, $availableSlugs, true);
            }

            return $status;
        } catch (\Throwable) {
            return array_fill_keys(array_keys($fieldSlugs), null);
        }
    }
}

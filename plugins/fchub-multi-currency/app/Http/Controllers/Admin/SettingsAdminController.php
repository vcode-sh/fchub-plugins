<?php

declare(strict_types=1);

namespace FChubMultiCurrency\Http\Controllers\Admin;

use FChubMultiCurrency\Domain\Enums\CurrencyPosition;
use FChubMultiCurrency\Domain\Enums\RateProvider;
use FChubMultiCurrency\Domain\Enums\RoundingMode;
use FChubMultiCurrency\Storage\OptionStore;
use FluentCart\App\Helpers\CurrenciesHelper;

defined('ABSPATH') || exit;

final class SettingsAdminController
{
    public function get(\WP_REST_Request $request): \WP_REST_Response
    {
        $optionStore = new OptionStore();

        return new \WP_REST_Response([
            'data' => [
                'settings' => $optionStore->all(),
            ],
        ]);
    }

    public function save(\WP_REST_Request $request): \WP_REST_Response
    {
        $params = $request->get_json_params();
        if (!is_array($params)) {
            return new \WP_REST_Response([
                'data' => [
                    'message' => 'Invalid JSON payload.',
                ],
            ], 400);
        }

        $optionStore = new OptionStore();

        $previousInterval = (int) $optionStore->get('rate_refresh_interval_hrs', 6);

        $allowedKeys = array_keys(\FChubMultiCurrency\Support\Constants::DEFAULT_SETTINGS);
        $sanitized = [];

        foreach ($allowedKeys as $key) {
            if (!array_key_exists($key, $params)) {
                continue;
            }

            $value = $params[$key];

            $sanitized[$key] = match ($key) {
                'display_currencies' => is_array($value) ? self::sanitizeDisplayCurrencies($value) : [],
                'enabled',
                'url_param_enabled',
                'cookie_enabled',
                'geo_enabled',
                'checkout_disclosure_enabled',
                'show_rate_freshness_badge',
                'fluentcrm_enabled',
                'fluentcrm_auto_create_tags',
                'fluentcommunity_enabled',
                'uninstall_remove_data' => self::sanitizeYesNo($value),
                'base_currency',
                'default_display_currency' => strtoupper(sanitize_text_field((string) $value)),
                'url_param_key' => self::sanitizeUrlParamKey((string) $value),
                'rate_provider' => self::sanitizeEnum((string) $value, array_column(RateProvider::cases(), 'value'), 'exchange_rate_api'),
                'stale_fallback' => self::sanitizeEnum((string) $value, ['base', 'last_known'], 'base'),
                'rounding_mode' => self::sanitizeEnum((string) $value, array_column(RoundingMode::cases(), 'value'), 'half_up'),
                'cookie_lifetime_days' => max(1, min(365, (int) $value)),
                'rate_refresh_interval_hrs' => max(1, min(168, (int) $value)),
                'stale_threshold_hrs' => max(1, min(720, (int) $value)),
                'rounding_precision' => max(0, min(4, (int) $value)),
                'checkout_disclosure_text' => sanitize_textarea_field((string) $value),
                default => sanitize_text_field((string) $value),
            };
        }

        $optionStore->save($sanitized);

        // Reschedule cron if the rate refresh interval changed
        $newInterval = (int) ($sanitized['rate_refresh_interval_hrs'] ?? $previousInterval);
        if ($newInterval !== $previousInterval) {
            wp_clear_scheduled_hook('fchub_mc_refresh_rates');
            wp_schedule_event(time(), 'fchub_mc_rate_interval', 'fchub_mc_refresh_rates');
        }

        return new \WP_REST_Response([
            'data' => [
                'message'  => 'Settings saved successfully.',
                'settings' => $optionStore->all(),
            ],
        ]);
    }

    /**
     * Validate and sanitize display_currencies entries.
     *
     * @param array<int, mixed> $currencies
     * @return array<int, array{code: string, name: string, symbol: string, decimals: int, position: string}>
     */
    private static function sanitizeDisplayCurrencies(array $currencies): array
    {
        $validCodes    = CurrenciesHelper::getCurrencies();
        $validPositions = array_column(CurrencyPosition::cases(), 'value');
        $seen          = [];
        $clean         = [];

        foreach ($currencies as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $code = strtoupper(trim((string) ($entry['code'] ?? '')));

            if ($code === '' || !isset($validCodes[$code])) {
                continue;
            }

            if (isset($seen[$code])) {
                continue;
            }
            $seen[$code] = true;

            $decimals = (int) ($entry['decimals'] ?? 2);
            $decimals = max(0, min(4, $decimals));

            $position = (string) ($entry['position'] ?? 'left');
            if (!in_array($position, $validPositions, true)) {
                $position = 'left';
            }

            $clean[] = [
                'code'     => $code,
                'name'     => sanitize_text_field((string) ($entry['name'] ?? $validCodes[$code])),
                'symbol'   => wp_kses_post((string) ($entry['symbol'] ?? $code)),
                'decimals' => $decimals,
                'position' => $position,
            ];
        }

        return $clean;
    }

    private static function sanitizeYesNo(mixed $value): string
    {
        return ((string) $value === 'yes') ? 'yes' : 'no';
    }

    /**
     * @param array<int, string> $allowed
     */
    private static function sanitizeEnum(string $value, array $allowed, string $default): string
    {
        $value = sanitize_text_field($value);

        return in_array($value, $allowed, true) ? $value : $default;
    }

    private static function sanitizeUrlParamKey(string $value): string
    {
        $clean = preg_replace('/[^a-zA-Z0-9_-]/', '', sanitize_text_field($value));
        if (!is_string($clean) || $clean === '') {
            return 'currency';
        }

        return $clean;
    }
}

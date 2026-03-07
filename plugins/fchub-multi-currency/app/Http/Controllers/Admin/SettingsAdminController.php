<?php

declare(strict_types=1);

namespace FChubMultiCurrency\Http\Controllers\Admin;

use FChubMultiCurrency\Domain\Enums\CurrencyPosition;
use FChubMultiCurrency\Domain\Enums\RateProvider;
use FChubMultiCurrency\Domain\Enums\RoundingMode;
use FChubMultiCurrency\Storage\OptionStore;
use FChubMultiCurrency\Support\Constants;
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

        $allowedKeys = array_keys(Constants::DEFAULT_SETTINGS);
        $sanitized = [];

        foreach ($allowedKeys as $key) {
            if (!array_key_exists($key, $params)) {
                continue;
            }

            $value = $params[$key];

            $sanitized[$key] = match ($key) {
                'display_currencies' => is_array($value) ? self::sanitizeDisplayCurrencies($value) : [],
                'switcher_defaults' => is_array($value) ? self::sanitizeSwitcherDefaults($value) : Constants::SWITCHER_DEFAULTS,
                'enabled',
                'url_param_enabled',
                'cookie_enabled',
                'account_persistence_enabled',
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
     * @return array<int, array{
     *     code: string,
     *     name: string,
     *     symbol: string,
     *     decimals: int,
     *     position: string,
     *     decimal_separator: string,
     *     thousand_separator: string
     * }>
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
                'symbol'   => sanitize_text_field(
                    html_entity_decode((string) ($entry['symbol'] ?? $code), ENT_QUOTES, 'UTF-8'),
                ),
                'decimals' => $decimals,
                'position' => $position,
                'decimal_separator' => self::sanitizeDecimalSeparator($entry['decimal_separator'] ?? ''),
                'thousand_separator' => self::sanitizeThousandSeparator($entry['thousand_separator'] ?? ''),
            ];
        }

        return $clean;
    }

    /**
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    private static function sanitizeSwitcherDefaults(array $values): array
    {
        $validCodes = CurrenciesHelper::getCurrencies();
        $favoriteCurrencies = [];
        foreach (($values['favorite_currencies'] ?? []) as $currencyCode) {
            if (!is_string($currencyCode)) {
                continue;
            }

            $code = strtoupper(sanitize_text_field($currencyCode));
            if (preg_match('/^[A-Z]{3}$/', $code) !== 1 || !isset($validCodes[$code])) {
                continue;
            }

            $favoriteCurrencies[] = $code;
        }

        return [
            'preset'                => self::sanitizeEnum(
                (string) ($values['preset'] ?? 'default'),
                ['default', 'pill', 'minimal', 'subtle', 'glass', 'contrast'],
                'default',
            ),
            'label_position'        => self::sanitizeEnum(
                (string) ($values['label_position'] ?? 'before'),
                ['before', 'after', 'above', 'below'],
                'before',
            ),
            'show_flag'             => self::sanitizeYesNo($values['show_flag'] ?? 'yes'),
            'show_code'             => self::sanitizeYesNo($values['show_code'] ?? 'yes'),
            'show_symbol'           => self::sanitizeYesNo($values['show_symbol'] ?? 'no'),
            'show_name'             => self::sanitizeYesNo($values['show_name'] ?? 'no'),
            'show_option_flags'     => self::sanitizeYesNo($values['show_option_flags'] ?? 'yes'),
            'show_option_codes'     => self::sanitizeYesNo($values['show_option_codes'] ?? 'yes'),
            'show_option_symbols'   => self::sanitizeYesNo($values['show_option_symbols'] ?? 'no'),
            'show_option_names'     => self::sanitizeYesNo($values['show_option_names'] ?? 'yes'),
            'show_active_indicator' => self::sanitizeYesNo($values['show_active_indicator'] ?? 'yes'),
            'show_rate_badge'       => self::sanitizeYesNo($values['show_rate_badge'] ?? 'yes'),
            'show_rate_value'       => self::sanitizeYesNo($values['show_rate_value'] ?? 'no'),
            'show_context_note'     => self::sanitizeYesNo($values['show_context_note'] ?? 'no'),
            'search_mode'           => self::sanitizeEnum(
                (string) ($values['search_mode'] ?? 'off'),
                ['off', 'inline'],
                'off',
            ),
            'favorite_currencies'   => array_values(array_unique($favoriteCurrencies)),
            'show_favorites_first'  => self::sanitizeYesNo($values['show_favorites_first'] ?? 'yes'),
            'size'                  => self::sanitizeEnum((string) ($values['size'] ?? 'md'), ['sm', 'md', 'lg'], 'md'),
            'width_mode'            => self::sanitizeEnum((string) ($values['width_mode'] ?? 'auto'), ['auto', 'full'], 'auto'),
            'dropdown_position'     => self::sanitizeEnum((string) ($values['dropdown_position'] ?? 'auto'), ['auto', 'start', 'end'], 'auto'),
            'dropdown_direction'    => self::sanitizeEnum((string) ($values['dropdown_direction'] ?? 'auto'), ['auto', 'down', 'up'], 'auto'),
        ];
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

    private static function sanitizeDecimalSeparator(mixed $value): string
    {
        $value = (string) $value;
        return in_array($value, ['', '.', ','], true) ? $value : '';
    }

    private static function sanitizeThousandSeparator(mixed $value): string
    {
        $value = (string) $value;
        return in_array($value, ['', '.', ',', ' ', 'none'], true) ? $value : '';
    }
}

<?php

declare(strict_types=1);

namespace FChubMultiCurrency\Http\Controllers\Admin;

use FChubMultiCurrency\Domain\Enums\CurrencyPosition;
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
        $optionStore = new OptionStore();

        $previousInterval = (int) $optionStore->get('rate_refresh_interval_hrs', 6);

        $allowedKeys = array_keys(\FChubMultiCurrency\Support\Constants::DEFAULT_SETTINGS);
        $sanitized = [];

        foreach ($allowedKeys as $key) {
            if (!array_key_exists($key, $params)) {
                continue;
            }

            $value = $params[$key];

            if ($key === 'display_currencies' && is_array($value)) {
                $value = self::sanitizeDisplayCurrencies($value);
            }

            $sanitized[$key] = match (true) {
                is_array($value) => $value,
                is_int($value)   => $value,
                default          => sanitize_text_field((string) $value),
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
}

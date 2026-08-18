<?php

declare(strict_types=1);

namespace FChubMultiCurrency\Storage;

use FChubMultiCurrency\Integration\FluentCartCurrency;
use FChubMultiCurrency\Support\Constants;

defined('ABSPATH') || exit;

final class OptionStore
{
    /**
     * The merged settings for this request. Merging normalizes every configured
     * currency and asks FluentCart for the base code, and a storefront render
     * asks for settings dozens of times — so it happens once, here. Every write
     * in this class clears it; nothing else writes the option.
     *
     * @var array<string, mixed>|null
     */
    private static ?array $memo = null;

    public static function resetMemo(): void
    {
        self::$memo = null;
    }

    /**
     * @param array<int, mixed> $currencies
     * @return array<int, array<string, mixed>>
     */
    private static function normalizeDisplayCurrencies(array $currencies): array
    {
        $normalized = [];

        foreach ($currencies as $currency) {
            if (!is_array($currency)) {
                continue;
            }

            $normalized[] = array_merge($currency, [
                'symbol' => html_entity_decode((string) ($currency['symbol'] ?? ''), ENT_QUOTES, 'UTF-8'),
                'decimal_separator' => (string) ($currency['decimal_separator'] ?? ''),
                'thousand_separator' => (string) ($currency['thousand_separator'] ?? ''),
            ]);
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $defaults
     * @param array<string, mixed> $saved
     * @return array<string, mixed>
     */
    private static function mergeSettings(array $defaults, array $saved): array
    {
        $merged = array_merge($defaults, $saved);

        $defaultSwitcherDefaults = $defaults['switcher_defaults'] ?? [];
        $savedSwitcherDefaults = $saved['switcher_defaults'] ?? [];

        if (is_array($defaultSwitcherDefaults)) {
            $merged['switcher_defaults'] = array_merge(
                $defaultSwitcherDefaults,
                is_array($savedSwitcherDefaults) ? $savedSwitcherDefaults : [],
            );
        }

        if (isset($merged['display_currencies']) && is_array($merged['display_currencies'])) {
            $merged['display_currencies'] = self::normalizeDisplayCurrencies($merged['display_currencies']);
        }

        $merged['base_currency'] = FluentCartCurrency::code();

        return $merged;
    }

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        if (self::$memo === null) {
            $saved = get_option(Constants::OPTION_SETTINGS, []);

            self::$memo = self::mergeSettings(
                Constants::DEFAULT_SETTINGS,
                is_array($saved) ? $saved : [],
            );
        }

        return self::$memo;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $settings = $this->all();

        return $settings[$key] ?? $default;
    }

    public function ensureExplicitRateProvider(): void
    {
        $saved = get_option(Constants::OPTION_SETTINGS, []);
        $saved = is_array($saved) ? $saved : [];

        if (array_key_exists('rate_provider', $saved)) {
            return;
        }

        $saved['rate_provider'] = 'manual';
        update_option(Constants::OPTION_SETTINGS, $saved);
        self::resetMemo();
    }

    /**
     * @param array<string, mixed> $values
     */
    public function save(array $values): void
    {
        $current = $this->all();
        $merged = self::mergeSettings($current, $values);

        update_option(Constants::OPTION_SETTINGS, $merged);
        self::resetMemo();
    }
}

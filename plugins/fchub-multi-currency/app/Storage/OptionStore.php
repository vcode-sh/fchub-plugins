<?php

declare(strict_types=1);

namespace FChubMultiCurrency\Storage;

use FChubMultiCurrency\Support\Constants;

defined('ABSPATH') || exit;

final class OptionStore
{
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

        return $merged;
    }

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        $saved = get_option(Constants::OPTION_SETTINGS, []);

        return self::mergeSettings(
            Constants::DEFAULT_SETTINGS,
            is_array($saved) ? $saved : [],
        );
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $settings = $this->all();

        return $settings[$key] ?? $default;
    }

    /**
     * @param array<string, mixed> $values
     */
    public function save(array $values): void
    {
        $current = $this->all();
        $merged = self::mergeSettings($current, $values);

        update_option(Constants::OPTION_SETTINGS, $merged);
    }
}

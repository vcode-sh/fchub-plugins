<?php

declare(strict_types=1);

namespace FChubMultiCurrency\Domain\ValueObjects;

defined('ABSPATH') || exit;

/**
 * The normalised currency codes a storefront visitor may select.
 */
final readonly class SelectableCurrencyCodes
{
    /**
     * @param string[] $codes
     */
    private function __construct(
        private string $baseCurrencyCode,
        private array $codes,
    ) {
    }

    /**
     * @param array<int|string, mixed> $displayCurrencies
     */
    public static function from(string $baseCurrencyCode, array $displayCurrencies): self
    {
        $baseCurrencyCode = strtoupper(trim($baseCurrencyCode));
        $codes = [$baseCurrencyCode];

        foreach ($displayCurrencies as $currency) {
            if (!is_array($currency)) {
                continue;
            }

            $code = strtoupper(trim((string) ($currency['code'] ?? '')));
            if ($code !== '') {
                $codes[] = $code;
            }
        }

        return new self(
            $baseCurrencyCode,
            array_values(array_unique(array_filter($codes))),
        );
    }

    /**
     * @param array<string, mixed> $settings
     */
    public static function fromSettings(array $settings): self
    {
        return self::from(
            (string) ($settings['base_currency'] ?? 'USD'),
            is_array($settings['display_currencies'] ?? null) ? $settings['display_currencies'] : [],
        );
    }

    public function contains(string $currencyCode): bool
    {
        return in_array(strtoupper(trim($currencyCode)), $this->codes, true);
    }

    /**
     * @return string[]
     */
    public function all(): array
    {
        return $this->codes;
    }

    /**
     * @return string[]
     */
    public function quoteCurrencies(): array
    {
        return array_values(array_filter(
            $this->codes,
            fn(string $code): bool => $code !== $this->baseCurrencyCode,
        ));
    }
}

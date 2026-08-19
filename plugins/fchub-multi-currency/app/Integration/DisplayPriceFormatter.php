<?php

declare(strict_types=1);

namespace FChubMultiCurrency\Integration;

use FChubMultiCurrency\Domain\Enums\CurrencyPosition;
use FChubMultiCurrency\Domain\Enums\RoundingMode;
use FChubMultiCurrency\Domain\Services\RoundingPolicy;
use FChubMultiCurrency\Domain\ValueObjects\Currency;
use FChubMultiCurrency\Storage\OptionStore;

defined('ABSPATH') || exit;

/** Formats converted FluentCart minor units with the selected display currency's own metadata. */
final class DisplayPriceFormatter
{
    public static function format(
        float $baseMinorUnits,
        string $rate,
        string $currencyCode,
        OptionStore $optionStore,
    ): string {
        $convertedMinorUnits = function_exists('bcmul')
            ? bcmul((string) $baseMinorUnits, $rate, 8)
            : (string) ($baseMinorUnits * (float) $rate);
        [$currency, $decimalSeparator, $thousandSeparator] = self::currencyMetadata(
            $currencyCode,
            $optionStore,
        );
        $roundingMode = RoundingMode::tryFrom((string) $optionStore->get('rounding_mode', 'half_up'))
            ?? RoundingMode::HalfUp;
        $roundedMinorUnits = self::roundMinorUnits(
            $convertedMinorUnits,
            $currency->decimals,
            $roundingMode,
        );
        $majorAmount = RoundingPolicy::charm(
            $roundedMinorUnits / 100,
            (string) $optionStore->get('charm_rounding', 'none'),
            $currency->decimals,
        );
        $negative = $majorAmount < 0;
        $number = number_format(
            abs($majorAmount),
            $currency->decimals,
            $decimalSeparator,
            $thousandSeparator,
        );
        $symbol = html_entity_decode($currency->symbol, ENT_QUOTES, 'UTF-8');
        $formatted = match ($currency->position) {
            CurrencyPosition::Right => $number . $symbol,
            CurrencyPosition::LeftSpace => $symbol . ' ' . $number,
            CurrencyPosition::RightSpace => $number . ' ' . $symbol,
            default => $symbol . $number,
        };

        return ($negative ? '-' : '') . $formatted;
    }

    private static function roundMinorUnits(
        string $minorUnits,
        int $displayDecimals,
        RoundingMode $roundingMode,
    ): float {
        if ($displayDecimals <= 2) {
            return (float) (new RoundingPolicy($roundingMode, 2 - $displayDecimals))->apply($minorUnits);
        }

        $scale = 10 ** ($displayDecimals - 2);
        $scaledMinorUnits = function_exists('bcmul')
            ? bcmul($minorUnits, (string) $scale, 8)
            : (string) ((float) $minorUnits * $scale);
        $roundedScaledMinorUnits = (new RoundingPolicy($roundingMode))->apply($scaledMinorUnits);

        return $roundedScaledMinorUnits / $scale;
    }

    /**
     * @return array{Currency, string, string}
     */
    private static function currencyMetadata(string $currencyCode, OptionStore $optionStore): array
    {
        $currencyCode = strtoupper($currencyCode);
        $shopSeparators = FluentCartCurrency::separators();
        $currencies = $optionStore->get('display_currencies', []);

        if (is_array($currencies)) {
            foreach ($currencies as $currencyData) {
                if (!is_array($currencyData)) {
                    continue;
                }
                if (strtoupper((string) ($currencyData['code'] ?? '')) !== $currencyCode) {
                    continue;
                }

                return [
                    Currency::from($currencyData),
                    self::separator($currencyData['decimal_separator'] ?? '', $shopSeparators['decimal'], false),
                    self::separator($currencyData['thousand_separator'] ?? '', $shopSeparators['thousand'], true),
                ];
            }
        }

        return [
            FluentCartCurrency::displayCurrency($currencyCode),
            $shopSeparators['decimal'],
            $shopSeparators['thousand'],
        ];
    }

    private static function separator(mixed $value, string $fallback, bool $allowNone): string
    {
        $separator = (string) $value;
        if ($allowNone && $separator === 'none') {
            return '';
        }

        return $separator !== '' ? $separator : $fallback;
    }
}

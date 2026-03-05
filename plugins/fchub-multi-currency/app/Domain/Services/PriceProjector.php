<?php

declare(strict_types=1);

namespace FChubMultiCurrency\Domain\Services;

use FChubMultiCurrency\Domain\ValueObjects\ExchangeRate;
use FChubMultiCurrency\Domain\ValueObjects\MoneyAmount;

defined('ABSPATH') || exit;

final class PriceProjector
{
    public function __construct(
        private RoundingPolicy $roundingPolicy,
    ) {
    }

    public function project(int $baseMinorUnits, ExchangeRate $rate, string $displayCurrencyCode): MoneyAmount
    {
        if ($rate->baseCurrency === $rate->quoteCurrency) {
            return new MoneyAmount(
                minorUnits: $baseMinorUnits,
                currencyCode: $displayCurrencyCode,
            );
        }

        $converted = function_exists('bcmul')
            ? bcmul((string) $baseMinorUnits, $rate->rate, 8)
            : (string) ((float) $baseMinorUnits * (float) $rate->rate);
        $rounded = $this->roundingPolicy->apply($converted);

        return new MoneyAmount(
            minorUnits: $rounded,
            currencyCode: $displayCurrencyCode,
        );
    }
}

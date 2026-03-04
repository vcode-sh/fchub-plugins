<?php

declare(strict_types=1);

namespace FChubMultiCurrency\Domain\ValueObjects;

defined('ABSPATH') || exit;

final readonly class MoneyAmount
{
    public function __construct(
        public int $minorUnits,
        public string $currencyCode,
    ) {
    }

    public static function fromFloat(float $amount, string $code, int $decimals = 2): self
    {
        $multiplier = 10 ** $decimals;

        return new self(
            minorUnits: (int) round($amount * $multiplier),
            currencyCode: strtoupper($code),
        );
    }

    public function toFloat(int $decimals = 2): float
    {
        $divisor = 10 ** $decimals;

        return $this->minorUnits / $divisor;
    }
}

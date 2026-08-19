<?php

declare(strict_types=1);

namespace FChubMultiCurrency\Domain\Services;

use FChubMultiCurrency\Domain\Enums\CharmRounding;
use FChubMultiCurrency\Domain\Enums\RoundingMode;

defined('ABSPATH') || exit;

final class RoundingPolicy
{
    public function __construct(
        private RoundingMode $mode,
        private int $precision = 0,
    ) {
    }

    /**
     * Round a converted value in minor units.
     *
     * Precision controls the rounding granularity:
     *   0 = nearest minor unit (cent)
     *   1 = nearest 10 minor units
     *   2 = nearest 100 minor units (whole major unit)
     */
    public function apply(string $value): int
    {
        if ($this->precision <= 0) {
            return match ($this->mode) {
                RoundingMode::None     => (int) $value,
                RoundingMode::HalfUp   => (int) round((float) $value, 0, PHP_ROUND_HALF_UP),
                RoundingMode::HalfDown => (int) round((float) $value, 0, PHP_ROUND_HALF_DOWN),
                RoundingMode::Ceil     => (int) ceil((float) $value),
                RoundingMode::Floor    => (int) floor((float) $value),
            };
        }

        $step = 10 ** $this->precision;
        $floatValue = (float) $value;

        return match ($this->mode) {
            RoundingMode::None     => (int) (($floatValue >= 0
                ? floor($floatValue / $step)
                : ceil($floatValue / $step)) * $step),
            RoundingMode::HalfUp   => (int) (round($floatValue / $step, 0, PHP_ROUND_HALF_UP) * $step),
            RoundingMode::HalfDown => (int) (round($floatValue / $step, 0, PHP_ROUND_HALF_DOWN) * $step),
            RoundingMode::Ceil     => (int) (ceil($floatValue / $step) * $step),
            RoundingMode::Floor    => (int) (floor($floatValue / $step) * $step),
        };
    }

    /**
     * The psychological-pricing step, applied after conversion and decimal
     * rounding. Display-only like every converted number here: checkout still
     * charges the base currency, and the disclosure already calls converted
     * prices approximate.
     *
     * Only a positive selling price is charmed. Zero stays free and a negative
     * stays a discount: an ending on either is a wrong number, not a nicer one.
     */
    public static function charm(float $amount, string $rule, int $decimals): float
    {
        if ($amount <= 0) {
            return $amount;
        }

        $charm = CharmRounding::tryFrom($rule) ?? CharmRounding::None;

        if ($decimals === 0 && ($charm === CharmRounding::Ending99 || $charm === CharmRounding::Ending95)) {
            $charm = CharmRounding::Whole;
        }

        return match ($charm) {
            CharmRounding::None      => $amount,
            CharmRounding::Whole     => round($amount),
            CharmRounding::Ending99  => ceil($amount) - 0.01,
            CharmRounding::Ending95  => ceil($amount) - 0.05,
            CharmRounding::Nearest5  => round($amount / 5) * 5,
            CharmRounding::Nearest10 => round($amount / 10) * 10,
        };
    }
}

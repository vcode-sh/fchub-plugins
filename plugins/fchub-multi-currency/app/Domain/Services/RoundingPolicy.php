<?php

declare(strict_types=1);

namespace FChubMultiCurrency\Domain\Services;

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
            RoundingMode::None     => (int) $floatValue,
            RoundingMode::HalfUp   => (int) (round($floatValue / $step, 0, PHP_ROUND_HALF_UP) * $step),
            RoundingMode::HalfDown => (int) (round($floatValue / $step, 0, PHP_ROUND_HALF_DOWN) * $step),
            RoundingMode::Ceil     => (int) (ceil($floatValue / $step) * $step),
            RoundingMode::Floor    => (int) (floor($floatValue / $step) * $step),
        };
    }
}

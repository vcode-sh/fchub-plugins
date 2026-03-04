<?php

declare(strict_types=1);

namespace FChubMultiCurrency\Domain\Services;

use FChubMultiCurrency\Domain\Enums\RoundingMode;

defined('ABSPATH') || exit;

final class RoundingPolicy
{
    public function __construct(
        private RoundingMode $mode,
    ) {
    }

    public function apply(string $value): int
    {
        return match ($this->mode) {
            RoundingMode::None     => (int) $value,
            RoundingMode::HalfUp   => (int) round((float) $value, 0, PHP_ROUND_HALF_UP),
            RoundingMode::HalfDown => (int) round((float) $value, 0, PHP_ROUND_HALF_DOWN),
            RoundingMode::Ceil     => (int) ceil((float) $value),
            RoundingMode::Floor    => (int) floor((float) $value),
        };
    }
}

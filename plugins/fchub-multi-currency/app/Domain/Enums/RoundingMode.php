<?php

declare(strict_types=1);

namespace FChubMultiCurrency\Domain\Enums;

defined('ABSPATH') || exit;

enum RoundingMode: string
{
    case None     = 'none';
    case HalfUp   = 'half_up';
    case HalfDown = 'half_down';
    case Ceil     = 'ceil';
    case Floor    = 'floor';

    public function label(): string
    {
        return match ($this) {
            self::None     => __('Truncate (no rounding)', 'fchub-multi-currency'),
            self::HalfUp   => __('Round half up (standard)', 'fchub-multi-currency'),
            self::HalfDown => __('Round half down', 'fchub-multi-currency'),
            self::Ceil     => __('Always round up', 'fchub-multi-currency'),
            self::Floor    => __('Always round down', 'fchub-multi-currency'),
        };
    }
}

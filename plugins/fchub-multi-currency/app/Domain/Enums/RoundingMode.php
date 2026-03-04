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
            self::None     => 'No rounding',
            self::HalfUp   => 'Round half up (standard)',
            self::HalfDown => 'Round half down',
            self::Ceil     => 'Always round up',
            self::Floor    => 'Always round down',
        };
    }
}

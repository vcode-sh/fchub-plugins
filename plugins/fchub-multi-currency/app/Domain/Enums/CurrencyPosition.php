<?php

declare(strict_types=1);

namespace FChubMultiCurrency\Domain\Enums;

defined('ABSPATH') || exit;

enum CurrencyPosition: string
{
    case Left       = 'left';
    case Right      = 'right';
    case LeftSpace  = 'left_space';
    case RightSpace = 'right_space';
}

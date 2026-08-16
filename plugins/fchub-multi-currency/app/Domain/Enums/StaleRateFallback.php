<?php

declare(strict_types=1);

namespace FChubMultiCurrency\Domain\Enums;

defined('ABSPATH') || exit;

enum StaleRateFallback: string
{
    case Base = 'base';
    case LastKnown = 'last_known';
}

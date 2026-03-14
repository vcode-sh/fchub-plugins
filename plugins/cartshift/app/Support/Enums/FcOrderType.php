<?php

declare(strict_types=1);

namespace CartShift\Support\Enums;

defined('ABSPATH') || exit;

enum FcOrderType: string
{
    case Payment = 'payment';
    case Subscription = 'subscription';
    case Renewal = 'renewal';
}

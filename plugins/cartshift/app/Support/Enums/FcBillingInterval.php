<?php

declare(strict_types=1);

namespace CartShift\Support\Enums;

defined('ABSPATH') || exit;

enum FcBillingInterval: string
{
    case Daily = 'daily';
    case Weekly = 'weekly';
    case Monthly = 'monthly';
    case Quarterly = 'quarterly';
    case HalfYearly = 'half_yearly';
    case Yearly = 'yearly';

    public static function fromWooCommerce(string $period, int $interval = 1): self
    {
        return match (true) {
            $period === 'day' => self::Daily,
            $period === 'week' => self::Weekly,
            $period === 'month' && $interval === 3 => self::Quarterly,
            $period === 'month' && $interval === 6 => self::HalfYearly,
            $period === 'month' => self::Monthly,
            $period === 'year' => self::Yearly,
            default => self::Monthly,
        };
    }
}

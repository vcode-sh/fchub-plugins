<?php

declare(strict_types=1);

namespace CartShift\Support\Enums;

defined('ABSPATH') || exit;

enum FcSubscriptionStatus: string
{
    case Active = 'active';
    case Paused = 'paused';
    case Canceled = 'canceled';
    case Expired = 'expired';
    case Expiring = 'expiring';
    case Pending = 'pending';

    public static function fromWooCommerce(string $wcStatus): self
    {
        return match ($wcStatus) {
            'active' => self::Active,
            'on-hold' => self::Paused,
            'cancelled', 'switched' => self::Canceled,
            'expired' => self::Expired,
            'pending-cancel' => self::Expiring,
            'pending' => self::Pending,
            default => self::Pending,
        };
    }
}

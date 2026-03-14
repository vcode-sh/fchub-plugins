<?php

declare(strict_types=1);

namespace CartShift\Support\Enums;

defined('ABSPATH') || exit;

enum FcPaymentStatus: string
{
    case Pending = 'pending';
    case Paid = 'paid';
    case Failed = 'failed';
    case Refunded = 'refunded';
    case PartiallyRefunded = 'partially_refunded';

    public static function fromWooCommerce(string $wcStatus): self
    {
        return match ($wcStatus) {
            'pending', 'on-hold' => self::Pending,
            'processing', 'completed' => self::Paid,
            'cancelled', 'failed' => self::Failed,
            'refunded' => self::Refunded,
            default => self::Pending,
        };
    }
}

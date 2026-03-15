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
        // Strip 'wc-' prefix if present (raw DB values include it, get_status() does not).
        $wcStatus = str_starts_with($wcStatus, 'wc-') ? substr($wcStatus, 3) : $wcStatus;

        return match ($wcStatus) {
            'pending', 'on-hold' => self::Pending,
            'processing', 'completed' => self::Paid,
            'cancelled', 'failed' => self::Failed,
            'refunded' => self::Refunded,
            default => self::Pending,
        };
    }
}

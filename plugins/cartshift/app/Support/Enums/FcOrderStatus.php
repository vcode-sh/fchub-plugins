<?php

declare(strict_types=1);

namespace CartShift\Support\Enums;

defined('ABSPATH') || exit;

enum FcOrderStatus: string
{
    case Processing = 'processing';
    case Completed = 'completed';
    case OnHold = 'on-hold';
    case Canceled = 'canceled';
    case Failed = 'failed';

    public static function fromWooCommerce(string $wcStatus): self
    {
        return match ($wcStatus) {
            'pending' => self::OnHold,
            'processing' => self::Processing,
            'on-hold' => self::OnHold,
            'completed' => self::Completed,
            'cancelled' => self::Canceled,
            'refunded' => self::Completed,
            'failed' => self::Failed,
            default => self::OnHold,
        };
    }
}

<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\Order;

defined('ABSPATH') || exit;

final readonly class CouponProjection
{
    /** @param array<string, mixed> $row */
    public function __construct(public array $row)
    {
        if (!array_key_exists('coupon_id', $row) || ($row['coupon_id'] !== null && (int) $row['coupon_id'] <= 0)
            || trim((string) ($row['code'] ?? '')) === '' || (int) ($row['amount'] ?? -1) < 0) {
            throw new \InvalidArgumentException('Coupon projection is invalid.');
        }
    }
}

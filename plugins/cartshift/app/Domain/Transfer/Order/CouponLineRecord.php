<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\Order;

use CartShift\Domain\Transfer\SourceIdentity;

defined('ABSPATH') || exit;

final readonly class CouponLineRecord
{
    public function __construct(
        public SourceIdentity $identity,
        public int $sourceLineId,
        public string $code,
        public int $discount,
        public int $discountTax,
    ) {
        if ($sourceLineId <= 0 || $code === '' || $discount < 0 || $discountTax < 0) {
            throw new \InvalidArgumentException('Coupon-line values are invalid.');
        }
    }

    public function toArray(): array
    {
        return ['identity' => $this->identity->canonical(), 'source_line_id' => $this->sourceLineId,
            'code' => $this->code, 'discount' => $this->discount, 'discount_tax' => $this->discountTax];
    }
}

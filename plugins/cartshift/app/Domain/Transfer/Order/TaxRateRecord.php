<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\Order;

use CartShift\Domain\Transfer\SourceIdentity;

defined('ABSPATH') || exit;

final readonly class TaxRateRecord
{
    public function __construct(
        public SourceIdentity $identity,
        public int $sourceLineId,
        public int $sourceRateId,
        public string $code,
        public string $label,
        public string $percentage,
        public bool $compound,
        public int $orderTax,
        public int $shippingTax,
        public int $taxableAmount,
        public bool $included,
    ) {
        if ($sourceLineId <= 0 || $sourceRateId < 0 || preg_match('/\A-?[0-9]+(?:\.[0-9]+)?\z/D', $percentage) !== 1) {
            throw new \InvalidArgumentException('Tax-rate source values are invalid.');
        }
    }

    public function toArray(): array
    {
        return [
            'identity' => $this->identity->canonical(), 'source_line_id' => $this->sourceLineId,
            'source_rate_id' => $this->sourceRateId, 'code' => $this->code, 'label' => $this->label,
            'percentage' => $this->percentage, 'compound' => $this->compound, 'order_tax' => $this->orderTax,
            'shipping_tax' => $this->shippingTax, 'taxable_amount' => $this->taxableAmount, 'included' => $this->included,
        ];
    }
}

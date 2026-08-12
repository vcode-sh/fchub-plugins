<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\Order;

use CartShift\Domain\Transfer\SourceIdentity;

defined('ABSPATH') || exit;

final readonly class ShippingLineRecord
{
    /** @param array<int|string, int> $taxAllocations @param array<string, scalar|null> $meta */
    public function __construct(
        public SourceIdentity $identity,
        public int $sourceLineId,
        public string $methodId,
        public int $instanceId,
        public string $title,
        public int $total,
        public int $tax,
        public array $taxAllocations,
        public array $meta,
    ) {
        if ($sourceLineId <= 0 || $instanceId < 0) {
            throw new \InvalidArgumentException('Shipping-line identity is invalid.');
        }
    }

    public function toArray(): array
    {
        return ['identity' => $this->identity->canonical(), 'source_line_id' => $this->sourceLineId,
            'method_id' => $this->methodId, 'instance_id' => $this->instanceId, 'title' => $this->title,
            'total' => $this->total, 'tax' => $this->tax, 'tax_allocations' => $this->taxAllocations,
            'meta' => $this->meta];
    }
}

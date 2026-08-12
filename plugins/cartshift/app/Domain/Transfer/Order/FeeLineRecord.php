<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\Order;

use CartShift\Domain\Transfer\SourceIdentity;

defined('ABSPATH') || exit;

final readonly class FeeLineRecord
{
    /** @param array<int|string, int> $taxAllocations @param array<string, scalar|null> $meta */
    public function __construct(
        public SourceIdentity $identity,
        public int $sourceLineId,
        public string $name,
        public int $total,
        public int $tax,
        public array $taxAllocations,
        public array $meta,
    ) {
        if ($sourceLineId <= 0) {
            throw new \InvalidArgumentException('Fee-line identity is invalid.');
        }
    }

    public function toArray(): array
    {
        return ['identity' => $this->identity->canonical(), 'source_line_id' => $this->sourceLineId,
            'name' => $this->name, 'total' => $this->total, 'tax' => $this->tax,
            'tax_allocations' => $this->taxAllocations, 'meta' => $this->meta];
    }
}

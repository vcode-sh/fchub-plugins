<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\Product;

use CartShift\Domain\Transfer\SourceIdentity;

defined('ABSPATH') || exit;

final readonly class StockProfile
{
    public function __construct(
        public StockOwnership $ownership,
        public ?SourceIdentity $owner,
        public ?int $quantity,
        public string $status,
        public string $backorders,
        public bool $soldIndividually,
        public ?int $lowStockThreshold,
    ) {
        if (!in_array($status, ['instock', 'outofstock', 'onbackorder'], true)) {
            throw new \InvalidArgumentException('Unknown WooCommerce stock status.');
        }

        if (!in_array($backorders, ['no', 'notify', 'yes'], true)) {
            throw new \InvalidArgumentException('Unknown WooCommerce backorder policy.');
        }

        if ($ownership === StockOwnership::None && ($owner !== null || $quantity !== null)) {
            throw new \InvalidArgumentException('Unmanaged stock cannot claim an owner or quantity.');
        }

        if ($ownership !== StockOwnership::None && $owner === null) {
            throw new \InvalidArgumentException('Managed stock requires an explicit owner.');
        }

        if ($lowStockThreshold !== null && $lowStockThreshold < 0) {
            throw new \InvalidArgumentException('Low-stock threshold cannot be negative.');
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'ownership' => $this->ownership->value,
            'owner' => $this->owner?->canonical(),
            'quantity' => $this->quantity,
            'status' => $this->status,
            'backorders' => $this->backorders,
            'sold_individually' => $this->soldIndividually,
            'low_stock_threshold' => $this->lowStockThreshold,
        ];
    }
}

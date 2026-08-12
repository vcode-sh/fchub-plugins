<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\Order;

use CartShift\Domain\Transfer\SourceIdentity;

defined('ABSPATH') || exit;

final readonly class OrderLineRecord
{
    /** @param list<array{key: string, value: scalar|null, display_key: string, display_value: scalar|null}> $attributeSnapshot
     *  @param array<int|string, int> $taxAllocations
     *  @param array<string, scalar|null> $otherInfo
     *  @param array<string, scalar|null> $lineMeta
     */
    public function __construct(
        public SourceIdentity $identity,
        public int $sourceLineId,
        public SourceIdentity $product,
        public SourceIdentity $variation,
        public string $name,
        public string $sku,
        public array $attributeSnapshot,
        public int $quantity,
        public int $cartIndex,
        public int $unitPrice,
        public int $subtotal,
        public int $subtotalTax,
        public int $discountTotal,
        public int $discountTax,
        public int $taxTotal,
        public int $lineTotal,
        public int $refundTotal,
        public string $costDisposition,
        public int $fulfilledQuantity,
        public string $rate,
        public ?string $createdUtc,
        public array $taxAllocations,
        public array $otherInfo,
        public array $lineMeta,
    ) {
        if ($sourceLineId <= 0 || $quantity <= 0 || $cartIndex < 0 || $fulfilledQuantity < 0) {
            throw new \InvalidArgumentException('Order-line identity, quantity and indexes must be representable integers.');
        }
        if (!array_is_list($attributeSnapshot)) {
            throw new \InvalidArgumentException('Order-line attribute snapshot must be a list.');
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'identity' => $this->identity->canonical(), 'source_line_id' => $this->sourceLineId,
            'product' => $this->product->canonical(), 'variation' => $this->variation->canonical(),
            'name' => $this->name, 'sku' => $this->sku, 'attribute_snapshot' => $this->attributeSnapshot,
            'quantity' => $this->quantity, 'cart_index' => $this->cartIndex, 'unit_price' => $this->unitPrice,
            'subtotal' => $this->subtotal, 'subtotal_tax' => $this->subtotalTax,
            'discount_total' => $this->discountTotal, 'discount_tax' => $this->discountTax,
            'tax_total' => $this->taxTotal, 'line_total' => $this->lineTotal,
            'refund_total' => $this->refundTotal, 'cost_disposition' => $this->costDisposition,
            'fulfilled_quantity' => $this->fulfilledQuantity, 'rate' => $this->rate,
            'created_utc' => $this->createdUtc, 'tax_allocations' => $this->taxAllocations,
            'other_info' => $this->otherInfo, 'line_meta' => $this->lineMeta,
        ];
    }
}

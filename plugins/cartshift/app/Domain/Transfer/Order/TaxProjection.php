<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\Order;

defined('ABSPATH') || exit;

final readonly class TaxProjection
{
    /** @param array<string, mixed> $row */
    public function __construct(public array $row)
    {
        $id = $row['tax_rate_id'] ?? null;
        if (!is_int($id) || !array_key_exists('order_tax', $row) || !array_key_exists('shipping_tax', $row)) {
            throw new \InvalidArgumentException('Tax projection is incomplete.');
        }
    }
}

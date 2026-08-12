<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\Order;

defined('ABSPATH') || exit;

final readonly class FeeProjection
{
    /** @param array<string, mixed> $row */
    public function __construct(public array $row)
    {
        if (($row['payment_type'] ?? null) !== 'fee' || ($row['quantity'] ?? null) !== 1) {
            throw new \InvalidArgumentException('Fee projection does not use FluentCart fee semantics.');
        }
    }
}

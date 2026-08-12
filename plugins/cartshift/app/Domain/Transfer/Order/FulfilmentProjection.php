<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\Order;

defined('ABSPATH') || exit;

final readonly class FulfilmentProjection
{
    /** @param array<string, int> $fulfilledQuantities */
    public function __construct(
        public string $fulfilmentType,
        public string $shippingStatus,
        public array $fulfilledQuantities,
    ) {
    }
}

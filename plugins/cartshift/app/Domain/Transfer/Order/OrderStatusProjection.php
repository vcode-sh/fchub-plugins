<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\Order;

defined('ABSPATH') || exit;

final readonly class OrderStatusProjection
{
    public function __construct(
        public string $orderStatus,
        public string $paymentStatus,
        public string $fulfilmentImplication,
        public bool $custom,
    ) {
    }
}

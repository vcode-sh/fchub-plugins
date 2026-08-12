<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\Order;

defined('ABSPATH') || exit;

final readonly class PaymentGraph
{
    /**
     * @param list<PaymentEventRecord> $charges
     * @param array<string, list<PaymentEventRecord>> $refundsByChargeSourceId
     */
    public function __construct(
        public array $charges,
        public array $refundsByChargeSourceId,
        public int $grossPaid,
        public int $totalRefunded,
    ) {
        if ($grossPaid < 0 || $totalRefunded < 0) {
            throw new \InvalidArgumentException('Payment graph totals cannot be negative.');
        }
    }
}

<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\Order;

defined('ABSPATH') || exit;

final readonly class PaymentGraphProjection
{
    /** @param list<array<string, mixed>> $charges @param list<array<string, mixed>> $refunds */
    public function __construct(
        public array $charges,
        public array $refunds,
        public int $grossPaid,
        public int $totalRefunded,
        public string $paymentStatus,
    ) {
        if (!in_array($paymentStatus, ['pending', 'paid', 'partially_refunded', 'refunded'], true)) {
            throw new \InvalidArgumentException('Payment graph projection status is invalid.');
        }
    }
}

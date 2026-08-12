<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\Order;

defined('ABSPATH') || exit;

final readonly class CustomerAggregateResult
{
    public function __construct(
        public int $customerId,
        public string $sourceFingerprint,
        public string $targetFingerprint,
        public bool $reused,
    ) {
        if ($customerId <= 0
            || preg_match('/\A[a-f0-9]{64}\z/D', $sourceFingerprint) !== 1
            || preg_match('/\A[a-f0-9]{64}\z/D', $targetFingerprint) !== 1) {
            throw new \InvalidArgumentException('Customer aggregate result is invalid.');
        }
    }
}

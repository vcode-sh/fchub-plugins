<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\Order;

defined('ABSPATH') || exit;

final readonly class OrderStageResult
{
    /** @param array<string, int> $targetMap */
    public function __construct(
        public int $targetId,
        public array $targetMap,
        public string $targetFingerprint,
        public bool $reused,
    ) {
        if ($targetId <= 0 || preg_match('/\A[a-f0-9]{64}\z/D', $targetFingerprint) !== 1) {
            throw new \InvalidArgumentException('Order stage result target evidence is invalid.');
        }
        foreach ($targetMap as $identity => $id) {
            if (!is_string($identity) || $identity === '' || !is_int($id) || $id <= 0) {
                throw new \InvalidArgumentException('Order stage result map is invalid.');
            }
        }
    }
}

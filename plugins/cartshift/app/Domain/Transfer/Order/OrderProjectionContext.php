<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\Order;

defined('ABSPATH') || exit;

final readonly class OrderProjectionContext
{
    /**
     * @param array<string, array{post_id: int, object_id: int, fulfillment_type: string, historical_variation_unlinked?: bool}> $productTargets Exact order-line identity keys.
     * @param array<string, int|null> $couponTargets
     * @param array<string, int> $taxRateTargets
     */
    public function __construct(
        public array $productTargets,
        public array $couponTargets,
        public array $taxRateTargets,
        public string $paymentMode,
        public string $historicalPaymentTitle,
        public bool $taxRoundingAtSubtotal,
    ) {
        if (!in_array($paymentMode, ['live', 'test'], true) || trim($historicalPaymentTitle) === '') {
            throw new \InvalidArgumentException('Order projection payment evidence is invalid.');
        }
        foreach ($productTargets as $target) {
            $unlinked = ($target['historical_variation_unlinked'] ?? false) === true;
            if (($target['post_id'] ?? 0) <= 0
                || (($target['object_id'] ?? -1) < 0)
                || (($target['object_id'] ?? 0) === 0 && !$unlinked)
                || (($target['object_id'] ?? 0) > 0 && $unlinked)
                || trim((string) ($target['fulfillment_type'] ?? '')) === '') {
                throw new \InvalidArgumentException('Order projection contains an invalid product target.');
            }
        }
        foreach ($couponTargets as $target) {
            if ($target !== null && $target <= 0) {
                throw new \InvalidArgumentException('Coupon targets are positive IDs or null, never zero.');
            }
        }
        foreach ($taxRateTargets as $target) {
            if ($target <= 0) {
                throw new \InvalidArgumentException('Mapped target tax-rate IDs must be positive.');
            }
        }
    }
}

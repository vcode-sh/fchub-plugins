<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\Order;

use CartShift\Domain\Transfer\SourceRecordException;

defined('ABSPATH') || exit;

final readonly class OrderStatusPolicy
{
    private const array BUILT_IN = [
        'pending' => ['on-hold', 'pending', 'unknown'],
        'processing' => ['processing', 'paid', 'unknown'],
        'on-hold' => ['on-hold', 'pending', 'unknown'],
        'completed' => ['completed', 'paid', 'unknown'],
        'cancelled' => ['canceled', 'failed', 'unknown'],
        'refunded' => ['completed', 'refunded', 'unknown'],
        'failed' => ['failed', 'failed', 'unknown'],
    ];

    /** @param array<string, array{order_status: string, payment_status: string, fulfilment_implication: string}> $customMappings */
    public function __construct(private array $customMappings = [])
    {
        foreach ($customMappings as $status => $mapping) {
            $status = $this->normalize($status);
            if ($status === '' || isset(self::BUILT_IN[$status])) {
                throw new \InvalidArgumentException('Custom status mapping key is invalid or shadows Woo core.');
            }
            $this->assertMapping($mapping);
        }
    }

    public function project(string $sourceStatus): OrderStatusProjection
    {
        $status = $this->normalize($sourceStatus);
        if (isset(self::BUILT_IN[$status])) {
            [$order, $payment, $fulfilment] = self::BUILT_IN[$status];
            return new OrderStatusProjection($order, $payment, $fulfilment, false);
        }

        $mapping = $this->customMappings[$status] ?? $this->customMappings['wc-' . $status] ?? null;
        if (!is_array($mapping)) {
            throw new SourceRecordException(
                'unsupported_order_status',
                'unsupported_order_status: source status has no operator-approved target mapping.',
            );
        }
        $this->assertMapping($mapping);
        return new OrderStatusProjection(
            $mapping['order_status'],
            $mapping['payment_status'],
            $mapping['fulfilment_implication'],
            true,
        );
    }

    private function normalize(string $status): string
    {
        $status = strtolower(trim($status));
        return str_starts_with($status, 'wc-') ? substr($status, 3) : $status;
    }

    /** @param array<string, mixed> $mapping */
    private function assertMapping(array $mapping): void
    {
        if (!in_array($mapping['order_status'] ?? null, ['processing', 'completed', 'on-hold', 'canceled', 'failed'], true)
            || !in_array($mapping['payment_status'] ?? null, ['pending', 'paid', 'failed', 'refunded', 'partially_refunded'], true)
            || !in_array($mapping['fulfilment_implication'] ?? null, ['unknown', 'none', 'unshipped', 'historical_complete'], true)) {
            throw new \InvalidArgumentException('Custom status mapping must declare valid order, payment and fulfilment outcomes.');
        }
    }
}

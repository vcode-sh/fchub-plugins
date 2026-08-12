<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\Order;

defined('ABSPATH') || exit;

/** Checked, event-free persistence for one historical FluentCart order graph. */
interface OrderTargetGateway
{
    /** @param array<string, mixed> $fields */
    public function createOrder(array $fields): int;

    /** @param array<string, mixed> $fields */
    public function createItem(int $orderId, array $fields): int;

    /** @param array<string, mixed> $fields */
    public function createAddress(int $orderId, array $fields): int;

    /** @param array<string, mixed> $fields */
    public function createCoupon(int $orderId, array $fields): int;

    /** @param array<string, mixed> $fields */
    public function createTaxRate(int $orderId, array $fields): int;

    /** @param array<string, mixed> $fields */
    public function createTransaction(int $orderId, array $fields): int;

    /** @param array<string, mixed> $fields */
    public function createMeta(int $orderId, array $fields): int;

    public function exists(int $orderId): bool;

    /**
     * Reload through an independent query path.
     *
     * @return array{
     *   order: array<string,mixed>|null,
     *   items: list<array<string,mixed>>,
     *   addresses: list<array<string,mixed>>,
     *   coupons: list<array<string,mixed>>,
     *   tax_rates: list<array<string,mixed>>,
     *   transactions: list<array<string,mixed>>,
     *   meta: list<array<string,mixed>>
     * }
     */
    public function snapshot(int $orderId): array;
}

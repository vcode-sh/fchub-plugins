<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\Customer;

defined('ABSPATH') || exit;

interface CustomerTargetGateway
{
    /** @param array<string, mixed> $fields */
    public function createCustomer(array $fields): int;
    /** @param array<string, mixed> $fields */
    public function createAddress(int $customerId, array $fields): int;
    public function exists(int $customerId): bool;
    /** @return array{customer: array<string,mixed>|null, addresses: list<array<string,mixed>>} */
    public function snapshot(int $customerId): array;
}

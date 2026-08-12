<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\Subscription;

defined('ABSPATH') || exit;

interface SubscriptionTargetGateway
{
    /** @param array<string,mixed> $row */
    public function create(array $row): int;
    public function exists(int $subscriptionId): bool;
    /** @return array<string,mixed> */
    public function snapshot(int $subscriptionId): array;
    public function linkTransaction(int $transactionId, int $subscriptionId, string $orderType): void;
    public function writeCorrection(int $subscriptionId, string $key, int $value): void;
}

<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\Order;

use CartShift\Domain\Transfer\SourceIdentity;

defined('ABSPATH') || exit;

interface CustomerAggregateGateway
{
    public function customerExists(int $customerId): bool;

    /** @return list<array{status:string,currency:string,paid:int,refund:int,rate:int,created_at:string}> */
    public function orders(int $customerId): array;

    /** @param array<string,mixed> $aggregate */
    public function write(int $customerId, array $aggregate): void;

    /** @return array<string,mixed> */
    public function snapshot(int $customerId): array;

    /** @return array<string,mixed> A separately executed SQL projection. */
    public function independentProjection(int $customerId): array;

    /** @return array<string,mixed>|null */
    public function receipt(SourceIdentity $source, string $runId, int $generation): ?array;

    /** @param array<string,mixed> $receipt */
    public function storeReceipt(array $receipt): void;
}

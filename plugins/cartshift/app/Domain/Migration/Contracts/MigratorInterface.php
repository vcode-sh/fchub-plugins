<?php

declare(strict_types=1);

namespace CartShift\Domain\Migration\Contracts;

defined('ABSPATH') || exit;

interface MigratorInterface
{
    /**
     * The entity type this migrator handles (e.g. 'products', 'customers').
     */
    public function entityType(): string;

    /**
     * Count the total number of WC records available for migration.
     */
    public function count(): int;

    /**
     * Run one-time setup before the first batch (e.g. taxonomy migrations).
     * Called once per entity type when offset is 0.
     */
    public function initialize(): void;

    /**
     * Execute the full migration for this entity type (synchronous).
     */
    public function run(): void;

    /**
     * Fetch a batch of WC records at the given offset.
     *
     * @return mixed[]
     */
    public function fetchBatch(int $offset, int $limit): array;

    /**
     * Process a single WC record. Return false to mark as skipped.
     */
    public function processRecord(mixed $record): int|false;

    /**
     * Get the WC ID from a record (for logging).
     */
    public function getRecordId(mixed $record): string;
}

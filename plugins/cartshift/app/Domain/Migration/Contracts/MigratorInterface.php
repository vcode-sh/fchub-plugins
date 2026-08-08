<?php

declare(strict_types=1);

namespace CartShift\Domain\Migration\Contracts;

defined('ABSPATH') || exit;

use CartShift\Domain\Scope\MigrationScope;

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
     * Count the records of this entity type that are already in the ID map.
     *
     * Lets the UI say "1,204 of 5,000 already migrated" without loading a
     * single mapping row.
     */
    public function migratedCount(): int;

    /**
     * Run one-time setup before the first batch (e.g. taxonomy migrations).
     * Called once per entity type when offset is 0.
     */
    public function initialize(): void;

    /**
     * The read-only counterpart of initialize(), for a dry run.
     *
     * A dry run must create nothing — no WordPress terms, no posts, no FluentCart
     * rows — so it cannot call initialize(). But it still has to answer the
     * questions initialize() sets up the answers to: a coupon restricted to a
     * category can only be judged against a category map. Implementations resolve
     * what already exists in FluentCart and register a synthetic ID for what does
     * not, writing simulated ID-map rows and nothing else.
     *
     * Called once per entity type when offset is 0, in place of initialize().
     */
    public function initializeSimulated(): void;

    /**
     * Fetch the next batch of WC records after the given cursor.
     *
     * Keyset pagination, not LIMIT/OFFSET. The cursor is an opaque marker
     * produced by cursorFor() — for most migrators the source primary key of
     * the last record handed out, so the query becomes an index seek
     * (`WHERE id > :cursor ORDER BY id ASC LIMIT n`) instead of a scan that
     * throws away everything before the offset.
     *
     * A null cursor means "start at the beginning of this entity".
     *
     * @return mixed[]
     */
    public function fetchBatch(string|int|null $cursor, int $limit): array;

    /**
     * Hydrate exactly the given WC records, for a retry run.
     *
     * The complement of fetchBatch(): no cursor, no ordering promise, no
     * pagination — the caller already knows which records it wants, because it
     * read them out of a previous run's log. An ID that no longer resolves to a
     * record is simply absent from the result, so the returned array may be
     * shorter than the one asked for and may be empty.
     *
     * The IDs are in the form getRecordId() returns, which is what the log
     * stores. For most migrators that is the source primary key as a string;
     * CustomerMigrator is the exception, and says so in its own docblock.
     *
     * Implementations must not touch pagination state — a retry run does not
     * keyset, and a fetchByIds() call that moved the cursor would corrupt any
     * normal run sharing the instance.
     *
     * @param array<int, string|int> $wcIds
     *
     * @return mixed[]
     */
    public function fetchByIds(array $wcIds): array;

    /**
     * Migrate under this scope instead of the one in MigrationState.
     *
     * A real run never calls this. It exists for /preview, which has to count
     * what a scope *would* migrate without starting anything and without
     * writing a scope anywhere — so it hands the scope to the migrator
     * directly rather than through state.
     *
     * Declared on the contract rather than only on AbstractMigrator because
     * ScopePreview holds a list<MigratorInterface> and calls this on each one.
     */
    public function useScope(MigrationScope $scope): void;

    /**
     * The cursor value reached by having handed out this record.
     *
     * Must be strictly monotonic in the order fetchBatch() returns records,
     * so that feeding it back in never re-reads the same row. Termination is
     * therefore guaranteed even for a record that can never be migrated.
     */
    public function cursorFor(mixed $record): string|int;

    /**
     * Process a single WC record. Return false to mark as skipped.
     */
    public function processRecord(mixed $record): int|false;

    /**
     * Validate a single record without creating any FC records (dry-run mode).
     *
     * @return bool True if the record is valid, false to skip.
     */
    public function validateRecord(mixed $record): bool;

    /**
     * Get the WC ID from a record (for logging).
     */
    public function getRecordId(mixed $record): string;
}

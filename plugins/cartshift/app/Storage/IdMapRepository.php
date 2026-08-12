<?php

declare(strict_types=1);

namespace CartShift\Storage;

use CartShift\Domain\Transfer\Identity\IdentityConflict;
use CartShift\Domain\Transfer\Identity\CheckedMappingStore;
use CartShift\Domain\Transfer\Identity\MapState;
use CartShift\Domain\Transfer\Identity\MappingRecord;
use CartShift\Domain\Transfer\SourceIdentity;
use CartShift\Support\Constants;
use CartShift\Support\DatabaseTransaction;

defined('ABSPATH') || exit;

final class IdMapRepository implements CheckedMappingStore
{
    private readonly string $table;

    /**
     * In-request lookup memo: entity_type => wc_id => fc_id, where null records a known miss.
     *
     * A migration resolves the same handful of IDs per order, line item, variation and
     * coupon condition, so an uncached repository fires hundreds of thousands of
     * single-row SELECTs on a large store. The memo lives for one request only —
     * which is why it cannot be the whole of a dry run's simulation. See store().
     *
     * @var array<string, array<string, int|null>>
     */
    private array $memo = [];

    /** @var array<string, MappingRecord|null> */
    private array $recordMemo = [];

    /** True while this repository is serving a dry run. */
    private bool $simulating = false;

    /**
     * Which source's mappings this repository speaks for.
     *
     * Defaults to `local`, which is what schema v7 backfilled every existing
     * row to, so a same-site migration behaves exactly as it did before the
     * column existed. It matters for cross-site runs: two WooCommerce installs
     * hand out the same small integers, and product 42 from the club site is
     * not product 42 from the shop site. Every read, write and delete below
     * carries it — a lookup that forgot to would resolve somebody else's
     * mapping, which is the one failure mode this column exists to prevent.
     *
     * Not to be confused with `is_simulated`. That asks whether a run is a
     * rehearsal; this asks whose data it is.
     */
    private readonly string $sourceKey;

    public function __construct(string $sourceKey = Constants::DEFAULT_SOURCE_KEY)
    {
        global $wpdb;
        $this->table     = $wpdb->prefix . 'cartshift_id_map';
        $this->sourceKey = $sourceKey;
    }

    /**
     * Put this repository into, or out of, dry-run mode.
     *
     * Derived from MigrationState on every batch rather than latched, because the
     * repository is a container singleton: a one-way switch on a shared object is
     * one refactor away from a real migration inheriting a dry run's mode. The
     * caller owns the question "is this run a rehearsal"; this class only answers
     * to it.
     */
    public function setSimulating(bool $simulating): void
    {
        if ($simulating !== $this->simulating) {
            // The memo cannot be shared across realms: a miss memoised while real-only
            // reads were in force is not a miss once simulated rows are visible too.
            $this->flushMemo();
        }

        $this->simulating = $simulating;
    }

    public function isSimulating(): bool
    {
        return $this->simulating;
    }

    /**
     * Store a WC-to-FC ID mapping.
     *
     * A dry run writes too, flagged `is_simulated = 1`. It has to: the orchestrator
     * processes one entity type per processBatch() call and both REST and Action
     * Scheduler run one batch per request, so products are validated in an earlier
     * request than the coupons and orders referring to them. A memo that dies with
     * the request therefore answers "no such product" to every dependency lookup,
     * and the dry run over-reports precisely the outcomes it exists to predict.
     * Only WP-CLI, which drives every batch in one process, ever saw it work.
     *
     * Nothing outside CartShift's own table is written either way, and every read
     * a real migration makes excludes the simulated realm — see getFcId().
     */
    /**
     * @deprecated V2 transfer code must use storeOrThrow().
     * @internal Legacy migration compatibility only.
     */
    public function store(
        string $entityType,
        string $wcId,
        int $fcId,
        string $migrationId = '',
        bool $createdByMigration = true,
    ): void {
        global $wpdb;

        $inserted = $wpdb->insert(
            $this->table,
            [
                'source_key'           => $this->sourceKey,
                'entity_type'          => $entityType,
                'wc_id'                => $wcId,
                'fc_id'                => $fcId,
                'migration_id'         => $migrationId,
                'created_by_migration' => $createdByMigration ? 1 : 0,
                'is_simulated'         => $this->simulating ? 1 : 0,
                'created_at'           => gmdate('Y-m-d H:i:s'),
            ],
            ['%s', '%s', '%s', '%d', '%s', '%d', '%d', '%s'],
        );

        // A mapping that does not land is the one silent `$wpdb` failure with
        // consequences after the run has finished: rollback deletes what the ID
        // map remembers, so a record created without a row here is one nothing
        // will ever take back out, and a re-run duplicates it because the
        // "already migrated?" check has nothing to find.
        //
        // The log repository is built here rather than injected. It takes no
        // constructor arguments, this path runs only when MySQL has already
        // refused a write, and threading a second repository through the six
        // call sites that say `new IdMapRepository()` would buy nothing.
        if ($inserted === false) {
            (new MigrationLogRepository())->recordWriteFailure(
                $migrationId,
                $entityType,
                $wcId,
                'the ID map entry (rollback will not be able to remove this record)',
            );

            return;
        }

        // isset() is false for a memoised miss, so a verified write replaces it
        // while an earlier hit keeps legacy "first match wins" semantics.
        if (!isset($this->memo[$entityType][$wcId])) {
            $this->memo[$entityType][$wcId] = $fcId;
        }
    }

    public function storeOrThrow(
        SourceIdentity $identity,
        int $targetId,
        string $migrationId,
        string $sourceFingerprint,
        string $targetFingerprint,
        MapState $state,
        bool $createdByMigration,
        int $generation = 1,
    ): MappingRecord {
        $this->assertMigrationId($migrationId);
        $candidate = new MappingRecord(
            $identity,
            $targetId,
            $sourceFingerprint,
            $targetFingerprint,
            $state,
        );

        global $wpdb;

        $inserted = $wpdb->insert(
            $this->table,
            [
                'source_key' => $identity->sourceKey,
                'entity_type' => $identity->entityType,
                'wc_id' => $identity->sourceId,
                'fc_id' => $targetId,
                'migration_id' => $migrationId,
                'created_by_migration' => $createdByMigration ? 1 : 0,
                'is_simulated' => 0,
                'source_fingerprint' => $sourceFingerprint,
                'target_fingerprint' => $targetFingerprint,
                'record_state' => $state->value,
                'created_at' => gmdate('Y-m-d H:i:s'),
                'updated_at' => gmdate('Y-m-d H:i:s'),
            ],
            ['%s', '%s', '%s', '%d', '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s'],
        );

        $stored = $this->readStoredMapping($identity, false);

        if ($inserted === false) {
            if ($stored !== null && $this->storedMappingIsCompatible(
                $stored,
                $candidate,
                $migrationId,
                $createdByMigration,
            )) {
                return $this->remember($stored['record']);
            }

            if ($stored !== null && $stored['record']->state === MapState::RolledBack) {
                return $this->reclaimRolledBack(
                    $stored,
                    $candidate,
                    $migrationId,
                    $createdByMigration,
                    $generation,
                );
            }

            if ($stored !== null) {
                throw IdentityConflict::forIdentity($identity);
            }

            throw new \RuntimeException('Checked identity-map insert failed.');
        }

        if ($stored === null || !$this->storedMappingIsCompatible(
            $stored,
            $candidate,
            $migrationId,
            $createdByMigration,
        )) {
            throw IdentityConflict::forIdentity($identity);
        }

        return $this->remember($stored['record']);
    }

    /** @param array{record: MappingRecord, migration_id: string, created_by_migration: bool} $stored */
    private function reclaimRolledBack(
        array $stored,
        MappingRecord $candidate,
        string $migrationId,
        bool $createdByMigration,
        int $generation,
    ): MappingRecord {
        if ($generation < 2) {
            throw new \RuntimeException('identity_map_reclaim_requires_new_generation');
        }
        if (DatabaseTransaction::depth() < 1) {
            throw new \RuntimeException('identity_map_reclaim_requires_transaction');
        }
        if (hash_equals($stored['migration_id'], $migrationId) || !$candidate->isActive()) {
            throw IdentityConflict::forIdentity($candidate->identity);
        }
        global $wpdb;
        $now = gmdate('Y-m-d H:i:s');
        $claimed = $wpdb->update($this->table, [
            'fc_id' => $candidate->targetId,
            'migration_id' => $migrationId,
            'created_by_migration' => $createdByMigration ? 1 : 0,
            'source_fingerprint' => $candidate->sourceFingerprint,
            'target_fingerprint' => $candidate->targetFingerprint,
            'record_state' => MapState::Claimed->value,
            'updated_at' => $now,
        ], [
            'source_key' => $candidate->identity->sourceKey,
            'entity_type' => $candidate->identity->entityType,
            'wc_id' => $candidate->identity->sourceId,
            'fc_id' => $stored['record']->targetId,
            'migration_id' => $stored['migration_id'],
            'is_simulated' => 0,
            'record_state' => MapState::RolledBack->value,
            'target_fingerprint' => $stored['record']->targetFingerprint,
        ]);
        if ($claimed === false) {
            throw new \RuntimeException('Checked identity-map reclaim failed.');
        }
        if ($claimed !== 1) {
            throw IdentityConflict::forIdentity($candidate->identity);
        }
        if ($candidate->state !== MapState::Claimed) {
            $advanced = $wpdb->update($this->table, [
                'record_state' => $candidate->state->value,
                'updated_at' => $now,
            ], [
                'source_key' => $candidate->identity->sourceKey,
                'entity_type' => $candidate->identity->entityType,
                'wc_id' => $candidate->identity->sourceId,
                'fc_id' => $candidate->targetId,
                'migration_id' => $migrationId,
                'is_simulated' => 0,
                'record_state' => MapState::Claimed->value,
                'source_fingerprint' => $candidate->sourceFingerprint,
                'target_fingerprint' => $candidate->targetFingerprint,
            ]);
            if ($advanced === false) {
                throw new \RuntimeException('Checked identity-map reclaim transition failed.');
            }
            if ($advanced !== 1) {
                throw IdentityConflict::forIdentity($candidate->identity);
            }
        }
        $reclaimed = $this->readStoredMapping($candidate->identity, false);
        if ($reclaimed === null || !$this->storedMappingIsCompatible($reclaimed, $candidate, $migrationId, $createdByMigration)) {
            throw IdentityConflict::forIdentity($candidate->identity);
        }
        return $this->remember($reclaimed['record']);
    }

    public function transitionOrThrow(
        SourceIdentity $identity,
        MapState $expected,
        MapState $next,
        string $expectedTargetFingerprint,
        string $nextTargetFingerprint,
    ): MappingRecord {
        if ($expected === $next) {
            throw new \InvalidArgumentException('A mapping transition must change state.');
        }

        $this->assertFingerprint($expectedTargetFingerprint);
        $this->assertFingerprint($nextTargetFingerprint);

        global $wpdb;

        $updated = $wpdb->update(
            $this->table,
            [
                'record_state' => $next->value,
                'target_fingerprint' => $nextTargetFingerprint,
                'updated_at' => gmdate('Y-m-d H:i:s'),
            ],
            [
                'source_key' => $identity->sourceKey,
                'entity_type' => $identity->entityType,
                'wc_id' => $identity->sourceId,
                'is_simulated' => 0,
                'record_state' => $expected->value,
                'target_fingerprint' => $expectedTargetFingerprint,
            ],
            ['%s', '%s', '%s'],
            ['%s', '%s', '%s', '%d', '%s', '%s'],
        );

        if ($updated === false) {
            throw new \RuntimeException('Checked identity-map transition failed.');
        }

        if ($updated !== 1) {
            throw IdentityConflict::forIdentity($identity);
        }

        $stored = $this->readStoredMapping($identity, false);

        if (
            $stored === null
            || $stored['record']->state !== $next
            || $stored['record']->targetFingerprint !== $nextTargetFingerprint
        ) {
            throw IdentityConflict::forIdentity($identity);
        }

        return $this->remember($stored['record']);
    }

    public function get(SourceIdentity $identity): ?MappingRecord
    {
        $key = $identity->canonical();

        if (array_key_exists($key, $this->recordMemo)) {
            return $this->recordMemo[$key];
        }

        $stored = $this->readStoredMapping($identity, true);
        $record = $stored['record'] ?? null;
        $this->recordMemo[$key] = $record;

        return $record;
    }

    /**
     * Get the FluentCart ID for a given WC entity (first match).
     *
     * A real run never sees a simulated row. That is the safety-critical invariant
     * of the whole simulated-persistence design: a dry run leaves rows behind that
     * point at synthetic IDs no FluentCart record has, so resolving one during a
     * real migration would write a reference to nothing.
     *
     * A dry run, conversely, sees both realms and prefers the real row — a store
     * that has already migrated its products should have its dry run report them
     * as already migrated, exactly as the real run would.
     */
    public function getFcId(string $entityType, string $wcId): int|null
    {
        if (array_key_exists($wcId, $this->memo[$entityType] ?? [])) {
            return $this->memo[$entityType][$wcId];
        }

        global $wpdb;

        $result = $wpdb->get_var($wpdb->prepare(
            "SELECT fc_id FROM {$this->table}
             WHERE source_key = %s AND entity_type = %s AND wc_id = %s" . $this->realmPredicate() . "
             ORDER BY is_simulated ASC, id ASC
             LIMIT 1",
            $this->sourceKey,
            $entityType,
            $wcId,
        ));

        $fcId = $result !== null ? (int) $result : null;

        // Misses are memoised too, so a repeated lookup for an unmigrated record
        // does not hit the database again.
        $this->memo[$entityType][$wcId] = $fcId;

        return $fcId;
    }

    /**
     * The realm filter every reference lookup carries.
     *
     * A fixed literal, never interpolated user input: real runs are pinned to
     * `is_simulated = 0`, dry runs see everything and let the ORDER BY prefer the
     * real row.
     */
    private function realmPredicate(): string
    {
        return $this->simulating ? '' : ' AND is_simulated = 0';
    }

    /**
     * Load every mapping for one entity type in a single query.
     *
     * Used to rehydrate taxonomy maps between batches without replaying the
     * one-time setup work. First row wins, mirroring getFcId().
     *
     * @return array<string, int> wc_id => fc_id
     */
    public function getMapForEntityType(string $entityType): array
    {
        global $wpdb;

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT wc_id, fc_id FROM {$this->table}
             WHERE source_key = %s AND entity_type = %s" . $this->realmPredicate() . "
             ORDER BY is_simulated ASC, id ASC",
            $this->sourceKey,
            $entityType,
        ));

        $map = [];

        foreach ($rows ?: [] as $row) {
            $wcId = (string) $row->wc_id;

            if (isset($map[$wcId])) {
                continue;
            }

            $map[$wcId] = (int) $row->fc_id;
        }

        $this->memo[$entityType] = $map + ($this->memo[$entityType] ?? []);

        return $map;
    }

    /**
     * Drop the in-request memo. Call after out-of-band writes to the table.
     */
    public function flushMemo(): void
    {
        $this->memo = [];
        $this->recordMemo = [];
    }

    /**
     * @return array{record: MappingRecord, migration_id: string, created_by_migration: bool}|null
     */
    private function readStoredMapping(SourceIdentity $identity, bool $activeOnly): ?array
    {
        global $wpdb;

        $active = $activeOnly ? " AND record_state <> 'rolled_back'" : '';
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT source_key, entity_type, wc_id, fc_id, migration_id,
                    created_by_migration, source_fingerprint, target_fingerprint, record_state
             FROM {$this->table}
             WHERE source_key = %s AND entity_type = %s AND wc_id = %s
               AND is_simulated = 0{$active}
             ORDER BY id ASC
             LIMIT 1",
            $identity->sourceKey,
            $identity->entityType,
            $identity->sourceId,
        ));

        if (trim((string) ($wpdb->last_error ?? '')) !== '') {
            throw new \RuntimeException('Checked identity-map read failed.');
        }

        if (!isset($rows[0])) {
            return null;
        }

        $row = (array) $rows[0];
        $state = MapState::tryFrom((string) ($row['record_state'] ?? ''));

        if ($state === null) {
            throw new \RuntimeException('Identity-map row has an unknown record state.');
        }

        try {
            $record = new MappingRecord(
                $identity,
                (int) ($row['fc_id'] ?? 0),
                isset($row['source_fingerprint']) ? (string) $row['source_fingerprint'] : null,
                isset($row['target_fingerprint']) ? (string) $row['target_fingerprint'] : null,
                $state,
            );
        } catch (\InvalidArgumentException $exception) {
            throw new \RuntimeException('Identity-map row violates the v8 mapping contract.', 0, $exception);
        }

        return [
            'record' => $record,
            'migration_id' => (string) ($row['migration_id'] ?? ''),
            'created_by_migration' => (int) ($row['created_by_migration'] ?? 0) === 1,
        ];
    }

    /**
     * @param array{record: MappingRecord, migration_id: string, created_by_migration: bool} $stored
     */
    private function storedMappingIsCompatible(
        array $stored,
        MappingRecord $candidate,
        string $migrationId,
        bool $createdByMigration,
    ): bool {
        return $stored['record']->isCompatibleWith($candidate)
            && hash_equals($stored['migration_id'], $migrationId)
            && $stored['created_by_migration'] === $createdByMigration;
    }

    private function remember(MappingRecord $record): MappingRecord
    {
        $key = $record->identity->canonical();
        $this->recordMemo[$key] = $record;

        DatabaseTransaction::afterRollback(function () use ($key): void {
            unset($this->recordMemo[$key]);
        });

        return $record;
    }

    private function assertMigrationId(string $migrationId): void
    {
        if ($migrationId === '' || strlen($migrationId) > 36) {
            throw new \InvalidArgumentException('Migration ID must be between 1 and 36 bytes.');
        }
    }

    private function assertFingerprint(string $fingerprint): void
    {
        if (preg_match('/\A[a-f0-9]{64}\z/D', $fingerprint) !== 1) {
            throw new \InvalidArgumentException('Target fingerprint must be a lowercase SHA-256 value.');
        }
    }

    /**
     * Get all FC IDs for an entity type, optionally filtered by migration.
     *
     * Hard-pinned to the real realm regardless of simulation mode. This feeds
     * finalisation and rollback, both of which act on FluentCart records; a
     * simulated row names an ID no FluentCart record has ever had.
     *
     * @return array<object{wc_id: string, fc_id: int}>
     */
    public function getAllByEntityType(string $entityType, string|null $migrationId = null): array
    {
        global $wpdb;

        if ($migrationId !== null) {
            return $wpdb->get_results($wpdb->prepare(
                "SELECT wc_id, fc_id FROM {$this->table}
                 WHERE source_key = %s AND entity_type = %s AND migration_id = %s AND is_simulated = 0",
                $this->sourceKey,
                $entityType,
                $migrationId,
            ));
        }

        return $wpdb->get_results($wpdb->prepare(
            "SELECT wc_id, fc_id FROM {$this->table}
             WHERE source_key = %s AND entity_type = %s AND is_simulated = 0",
            $this->sourceKey,
            $entityType,
        ));
    }

    /**
     * Get only rows that were created by migration.
     *
     * Real realm only, for the reason given on getAllByEntityType().
     *
     * @return array<object{wc_id: string, fc_id: int}>
     */
    public function getCreatedByMigration(string $entityType, string $migrationId): array
    {
        global $wpdb;

        return $wpdb->get_results($wpdb->prepare(
            "SELECT wc_id, fc_id FROM {$this->table}
             WHERE source_key = %s AND entity_type = %s AND migration_id = %s
               AND created_by_migration = 1 AND is_simulated = 0",
            $this->sourceKey,
            $entityType,
            $migrationId,
        ));
    }

    /**
     * Delete all rows for a migration ID, optionally restricted to one realm.
     *
     * @param bool|null $simulated Null deletes both realms; true or false pins one.
     */
    public function deleteByMigration(string $migrationId, bool|null $simulated = null): void
    {
        $where   = ['migration_id' => $migrationId];
        $formats = ['%s'];

        if ($simulated !== null) {
            $where['is_simulated'] = $simulated ? 1 : 0;
            $formats[] = '%d';
        }

        $this->deleteWhere($where, $formats);
    }

    /**
     * Drop every simulated row, whichever dry run left it behind.
     *
     * Called when a dry run starts (a rehearsal begins from a clean slate, not
     * from whatever an abandoned earlier one left), when a dry run finishes, and
     * on reset. Real rows are never touched: reset forgets a run, it does not
     * unpick one.
     */
    public function purgeSimulated(): void
    {
        $this->deleteWhere(['is_simulated' => 1], ['%d']);
    }

    /**
     * Delete only rows where created_by_migration = 1 for a migration ID.
     */
    public function deleteCreatedByMigration(string $migrationId): void
    {
        global $wpdb;

        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$this->table}
             WHERE source_key = %s AND migration_id = %s AND created_by_migration = 1",
            $this->sourceKey,
            $migrationId,
        ));

        $this->flushMemo();
    }

    /**
     * Shared delete + memo invalidation.
     *
     * @param array<string, string|int> $where
     * @param list<string>              $formats
     */
    private function deleteWhere(array $where, array $formats): void
    {
        global $wpdb;

        $where['source_key'] = $this->sourceKey;
        $formats[]           = '%s';

        $wpdb->delete($this->table, $where, $formats);

        $this->flushMemo();
    }

    /**
     * Drop every mapping this source owns.
     *
     * A DELETE rather than the TRUNCATE it used to be. Once the table is shared
     * between source namespaces, truncating it to clear one source's mappings
     * would take every other source's with it — and those are facts a different
     * run resolves against.
     */
    public function truncate(): void
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$this->table} WHERE source_key = %s",
            $this->sourceKey,
        ));

        $this->flushMemo();
    }
}

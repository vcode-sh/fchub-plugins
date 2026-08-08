<?php

declare(strict_types=1);

namespace CartShift\Storage;

defined('ABSPATH') || exit;

final class IdMapRepository
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

    /** True while this repository is serving a dry run. */
    private bool $simulating = false;

    public function __construct()
    {
        global $wpdb;
        $this->table = $wpdb->prefix . 'cartshift_id_map';
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
    public function store(
        string $entityType,
        string $wcId,
        int $fcId,
        string $migrationId = '',
        bool $createdByMigration = true,
    ): void {
        // isset() is false for a memoised miss, so a miss is replaced while an
        // earlier hit is kept — matching the "first match wins" read semantics.
        if (!isset($this->memo[$entityType][$wcId])) {
            $this->memo[$entityType][$wcId] = $fcId;
        }

        global $wpdb;

        $wpdb->insert(
            $this->table,
            [
                'entity_type'          => $entityType,
                'wc_id'                => $wcId,
                'fc_id'                => $fcId,
                'migration_id'         => $migrationId,
                'created_by_migration' => $createdByMigration ? 1 : 0,
                'is_simulated'         => $this->simulating ? 1 : 0,
                'created_at'           => gmdate('Y-m-d H:i:s'),
            ],
            ['%s', '%s', '%d', '%s', '%d', '%d', '%s'],
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
        (new MigrationLogRepository())->recordWriteFailure(
            $migrationId,
            $entityType,
            $wcId,
            'the ID map entry (rollback will not be able to remove this record)',
        );
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
             WHERE entity_type = %s AND wc_id = %s" . $this->realmPredicate() . "
             ORDER BY is_simulated ASC, id ASC
             LIMIT 1",
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
             WHERE entity_type = %s" . $this->realmPredicate() . "
             ORDER BY is_simulated ASC, id ASC",
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
                 WHERE entity_type = %s AND migration_id = %s AND is_simulated = 0",
                $entityType,
                $migrationId,
            ));
        }

        return $wpdb->get_results($wpdb->prepare(
            "SELECT wc_id, fc_id FROM {$this->table} WHERE entity_type = %s AND is_simulated = 0",
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
             WHERE entity_type = %s AND migration_id = %s
               AND created_by_migration = 1 AND is_simulated = 0",
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
            "DELETE FROM {$this->table} WHERE migration_id = %s AND created_by_migration = 1",
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

        $wpdb->delete($this->table, $where, $formats);

        $this->flushMemo();
    }

    /**
     * Truncate the entire table.
     */
    public function truncate(): void
    {
        global $wpdb;

        $wpdb->query("TRUNCATE TABLE {$this->table}");

        $this->flushMemo();
    }
}

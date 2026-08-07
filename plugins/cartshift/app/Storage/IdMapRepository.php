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
     * single-row SELECTs on a large store. The memo lives for one request only.
     *
     * @var array<string, array<string, int|null>>
     */
    private array $memo = [];

    /** True while the dry run is resolving references without persisting them. */
    private bool $simulating = false;

    public function __construct()
    {
        global $wpdb;
        $this->table = $wpdb->prefix . 'cartshift_id_map';
    }

    /**
     * Resolve references in memory only.
     *
     * The dry run must answer "would this order find its customer?" without writing
     * anything. Before this existed the memo stayed empty, every lookup missed, and the
     * dry run over-reported every dependency-driven outcome it was supposed to predict.
     */
    public function enableSimulation(): void
    {
        $this->simulating = true;
    }

    public function isSimulating(): bool
    {
        return $this->simulating;
    }

    /**
     * Store a WC-to-FC ID mapping.
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

        if ($this->simulating) {
            return;
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
                'created_at'           => gmdate('Y-m-d H:i:s'),
            ],
            ['%s', '%s', '%d', '%s', '%d', '%s'],
        );
    }

    /**
     * Get the FluentCart ID for a given WC entity (first match).
     */
    public function getFcId(string $entityType, string $wcId): int|null
    {
        if (array_key_exists($wcId, $this->memo[$entityType] ?? [])) {
            return $this->memo[$entityType][$wcId];
        }

        global $wpdb;

        $result = $wpdb->get_var($wpdb->prepare(
            "SELECT fc_id FROM {$this->table} WHERE entity_type = %s AND wc_id = %s LIMIT 1",
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
            "SELECT wc_id, fc_id FROM {$this->table} WHERE entity_type = %s",
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
     * @return array<object{wc_id: string, fc_id: int}>
     */
    public function getAllByEntityType(string $entityType, string|null $migrationId = null): array
    {
        global $wpdb;

        if ($migrationId !== null) {
            return $wpdb->get_results($wpdb->prepare(
                "SELECT wc_id, fc_id FROM {$this->table} WHERE entity_type = %s AND migration_id = %s",
                $entityType,
                $migrationId,
            ));
        }

        return $wpdb->get_results($wpdb->prepare(
            "SELECT wc_id, fc_id FROM {$this->table} WHERE entity_type = %s",
            $entityType,
        ));
    }

    /**
     * Get only rows that were created by migration.
     *
     * @return array<object{wc_id: string, fc_id: int}>
     */
    public function getCreatedByMigration(string $entityType, string $migrationId): array
    {
        global $wpdb;

        return $wpdb->get_results($wpdb->prepare(
            "SELECT wc_id, fc_id FROM {$this->table}
             WHERE entity_type = %s AND migration_id = %s AND created_by_migration = 1",
            $entityType,
            $migrationId,
        ));
    }

    /**
     * Delete all rows for a migration ID.
     */
    public function deleteByMigration(string $migrationId): void
    {
        global $wpdb;

        $wpdb->delete(
            $this->table,
            ['migration_id' => $migrationId],
            ['%s'],
        );

        $this->flushMemo();
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
     * Truncate the entire table.
     */
    public function truncate(): void
    {
        global $wpdb;

        $wpdb->query("TRUNCATE TABLE {$this->table}");

        $this->flushMemo();
    }
}

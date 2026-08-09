<?php

declare(strict_types=1);

namespace CartShift\Support;

use CartShift\Storage\MigrationLogRepository;

defined('ABSPATH') || exit;

final class Migrations
{
    private const string DB_VERSION_OPTION = 'cartshift_db_version';
    private const string CURRENT_VERSION = '6';

    /** Unique index guaranteeing one id-map row per (entity_type, wc_id). Superseded by v5. */
    private const string ID_MAP_UNIQUE_INDEX = 'entity_wc_unique';

    /** Unique index guaranteeing one id-map row per (entity_type, wc_id) per realm. */
    private const string ID_MAP_REALM_UNIQUE_INDEX = 'entity_wc_realm_unique';

    /** Index backing per-code log filtering and grouping. */
    private const string LOG_ERROR_CODE_INDEX = 'migration_error_code';

    /** @var array<string, callable> */
    private const array VERSIONS = [
        '1' => 'v1',
        '2' => 'v2',
        '3' => 'v3',
        '4' => 'v4',
        '5' => 'v5',
        '6' => 'v6',
    ];

    public static function run(): void
    {
        $installed = get_option(self::DB_VERSION_OPTION, '0');

        foreach (self::VERSIONS as $version => $method) {
            $version = (string) $version;
            if (version_compare($installed, $version, '>=')) {
                continue;
            }

            self::$method();
            update_option(self::DB_VERSION_OPTION, $version);
        }
    }

    public static function needsUpgrade(): bool
    {
        $installed = get_option(self::DB_VERSION_OPTION, '0');

        return version_compare($installed, self::CURRENT_VERSION, '<');
    }

    public static function currentVersion(): string
    {
        return self::CURRENT_VERSION;
    }

    public static function dropAll(): void
    {
        global $wpdb;

        $idMapTable       = $wpdb->prefix . 'cartshift_id_map';
        $logTable         = $wpdb->prefix . 'cartshift_migration_log';
        $productMapTable  = $wpdb->prefix . 'cartshift_product_map';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->query("DROP TABLE IF EXISTS {$idMapTable}");
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->query("DROP TABLE IF EXISTS {$logTable}");
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->query("DROP TABLE IF EXISTS {$productMapTable}");

        delete_option(self::DB_VERSION_OPTION);
        delete_option('cartshift_migration_state');
    }

    private static function v1(): void
    {
        global $wpdb;

        $charset    = $wpdb->get_charset_collate();
        $idMapTable = $wpdb->prefix . 'cartshift_id_map';
        $logTable   = $wpdb->prefix . 'cartshift_migration_log';

        $sql = "CREATE TABLE {$idMapTable} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            entity_type VARCHAR(50) NOT NULL,
            wc_id VARCHAR(100) NOT NULL,
            fc_id BIGINT UNSIGNED NOT NULL,
            migration_id VARCHAR(36) NOT NULL DEFAULT '',
            created_by_migration TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY entity_lookup (entity_type, wc_id),
            KEY migration_lookup (migration_id, entity_type)
        ) {$charset};

        CREATE TABLE {$logTable} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            migration_id VARCHAR(36) NOT NULL,
            entity_type VARCHAR(50) NOT NULL,
            wc_id VARCHAR(100) NOT NULL,
            status VARCHAR(20) NOT NULL,
            message TEXT,
            details LONGTEXT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY migration_entity (migration_id, entity_type),
            KEY status_lookup (migration_id, status)
        ) {$charset};";

        // dbDelta() lives in an admin include that is not loaded on every request.
        if (!function_exists('dbDelta')) {
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        }

        dbDelta($sql);
    }

    /**
     * v2: enforce one id-map row per (entity_type, wc_id) in the schema.
     *
     * Idempotency was enforced only in PHP, which is no help at all when two batch
     * requests overlap: both read "not migrated", both insert, and the entity ends up
     * duplicated in FluentCart with the id-map pointing at whichever row won. A UNIQUE
     * index makes the database refuse the second insert.
     *
     * Existing installs are assumed dirty, so duplicates are collapsed first —
     * ALTER TABLE would otherwise fail outright on a table that already has them.
     * Lowest id wins, matching getFcId()'s "first match" read semantics, so nothing
     * that already resolved changes its answer.
     */
    private static function v2(): void
    {
        global $wpdb;

        $idMapTable = $wpdb->prefix . 'cartshift_id_map';

        // Collapse duplicates, keeping the lowest id per (entity_type, wc_id).
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $wpdb->query(
            "DELETE dupe FROM {$idMapTable} AS dupe
             INNER JOIN (
                 SELECT entity_type, wc_id, MIN(id) AS keep_id
                 FROM {$idMapTable}
                 GROUP BY entity_type, wc_id
                 HAVING COUNT(*) > 1
             ) AS keeper
                 ON dupe.entity_type = keeper.entity_type
                AND dupe.wc_id = keeper.wc_id
             WHERE dupe.id > keeper.keep_id",
        );

        // Tolerate a partially applied upgrade — the index may already be there.
        if (!self::indexExists($idMapTable, self::ID_MAP_UNIQUE_INDEX)) {
            $indexName = self::ID_MAP_UNIQUE_INDEX;

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $wpdb->query(
                "ALTER TABLE {$idMapTable}
                 ADD UNIQUE INDEX {$indexName} (entity_type, wc_id)",
            );
        }

        // v1's entity_lookup covers the same columns in the same order, so the new
        // UNIQUE index serves every query it served. Two indexes on a table that
        // takes millions of inserts during a migration is a cost with no return.
        if (
            self::indexExists($idMapTable, self::ID_MAP_UNIQUE_INDEX)
            && self::indexExists($idMapTable, 'entity_lookup')
        ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $wpdb->query("ALTER TABLE {$idMapTable} DROP INDEX entity_lookup");
        }
    }

    /**
     * v3: give the migration log a first-class error_code column.
     *
     * The codes were originally tucked inside the details JSON, which meant grouping
     * failures by cause required a LIKE scan over LONGTEXT — tolerable for one run's
     * log, wasteful forever. A real column plus (migration_id, error_code) turns both
     * the breakdown and the per-code filter into index reads.
     *
     * Nullable because a log row genuinely need not have a code. The backfill for
     * rows that do — everything written while the code lived only in the details
     * JSON — is v4; it could not live here because nothing populated the column
     * until the repository was taught to.
     */
    private static function v3(): void
    {
        global $wpdb;

        $logTable = $wpdb->prefix . 'cartshift_migration_log';

        $hasColumn = self::columnExists($logTable, 'error_code');

        if (!$hasColumn) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $added = $wpdb->query(
                "ALTER TABLE {$logTable}
                 ADD COLUMN error_code VARCHAR(64) NULL DEFAULT NULL AFTER status",
            );

            // Trust the ALTER rather than re-issuing SHOW COLUMNS: false means the
            // statement failed, and indexing a column that does not exist would fail too.
            $hasColumn = $added !== false;
        }

        // Tolerate a partially applied upgrade — the index may already be there.
        if ($hasColumn && !self::indexExists($logTable, self::LOG_ERROR_CODE_INDEX)) {
            $indexName = self::LOG_ERROR_CODE_INDEX;

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $wpdb->query(
                "ALTER TABLE {$logTable}
                 ADD INDEX {$indexName} (migration_id, error_code)",
            );
        }
    }

    /**
     * v4: backfill error_code from the details JSON.
     *
     * v3 added the column and its index but nothing ever wrote to it — the code was
     * still going only into the details JSON, so the column sat permanently NULL and
     * the index was dead weight. Now that MigrationLogRepository::write() populates
     * it, every row written before that change still needs filling in, or those rows
     * drop out of filtered views and per-code counts while still showing up in the
     * unfiltered list. Same log, two different answers.
     *
     * String functions rather than JSON_EXTRACT on purpose: `details` is LONGTEXT and
     * is not guaranteed to hold valid JSON, and JSON_EXTRACT errors on malformed
     * input under MySQL 5.7 instead of returning NULL. SUBSTRING_INDEX just returns
     * nothing useful, which is the failure mode we want.
     *
     * Only plausible codes are written. A value that is empty, longer than the column,
     * or carries characters no code ever uses did not come from a real write() call —
     * it came from a malformed row — and storing it would put a value in the column
     * that nothing can resolve, which is strictly worse than leaving NULL for
     * hydrate()'s JSON fallback to handle. The shape test is deliberately looser than
     * the current enum: a code from an older build is real data and still belongs in
     * the `other` bucket, so it is kept, while junk is not.
     */
    private static function v4(): void
    {
        global $wpdb;

        $logTable = $wpdb->prefix . 'cartshift_migration_log';

        // Nothing to backfill into if v3 could not add the column.
        if (!self::columnExists($logTable, 'error_code')) {
            return;
        }

        $marker = '"' . MigrationLogRepository::DETAILS_CODE_KEY . '":"';

        // The extracted value, repeated because UPDATE cannot reference a select alias.
        $extract = "SUBSTRING_INDEX(SUBSTRING_INDEX(details, %s, -1), '\"', 1)";

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $wpdb->query($wpdb->prepare(
            "UPDATE {$logTable}
             SET error_code = {$extract}
             WHERE error_code IS NULL
               AND details LIKE %s
               AND CHAR_LENGTH({$extract}) BETWEEN 1 AND 64
               AND {$extract} REGEXP '^[A-Za-z0-9_]+$'",
            $marker,
            '%' . self::escLike($marker) . '%',
            $marker,
            $marker,
        ));
    }

    /**
     * v5: let a dry run persist its ID map without ever being mistaken for a real one.
     *
     * Simulation used to live in a per-request memo, which is fine under WP-CLI —
     * one process, one memo — and useless everywhere else. The REST batch loop and
     * Action Scheduler each run ONE entity type per request, so products were
     * validated in an earlier request than the coupons and orders that reference
     * them: by the time a dependency was resolved the memo was empty and every
     * lookup missed. The dry run then over-reported exactly the outcomes it exists
     * to predict. A dry run's mappings have to outlive the request, which means
     * they have to be rows.
     *
     * `is_simulated` is the whole safety story. Every read a real migration makes
     * is scoped to `is_simulated = 0`, so a rehearsal's leftovers can never resolve
     * a real reference; simulated rows are purged when a dry run starts, when it
     * finishes, and on reset.
     *
     * The UNIQUE key has to grow with it. v2's `(entity_type, wc_id)` would make a
     * real row and a simulated row for the same entity mutually exclusive, so a dry
     * run followed by a real migration would collide on the very first insert. The
     * key is extended to `(entity_type, wc_id, is_simulated)` rather than scoped:
     * MySQL has no partial or filtered index, so "unique among real rows only" is
     * not expressible as an index at all. Extending it keeps the guarantee v2
     * actually wanted — the database still refuses a duplicate within each realm,
     * which is what stops two overlapping batch requests double-inserting — while
     * letting the two realms coexist. It also keeps serving every point lookup,
     * because `(entity_type, wc_id)` is still a leading prefix of it.
     *
     * No second index for the realm filter: `getMapForEntityType()` runs a handful
     * of times per request, whereas inserts run millions of times during a
     * migration. v2 dropped a redundant index for that exact reason.
     */
    private static function v5(): void
    {
        global $wpdb;

        $idMapTable = $wpdb->prefix . 'cartshift_id_map';

        if (!self::columnExists($idMapTable, 'is_simulated')) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $added = $wpdb->query(
                "ALTER TABLE {$idMapTable}
                 ADD COLUMN is_simulated TINYINT(1) NOT NULL DEFAULT 0 AFTER created_by_migration",
            );

            // Indexing a column the ALTER failed to add would fail too.
            if ($added === false) {
                return;
            }
        }

        // Tolerate a partially applied upgrade — the index may already be there.
        if (!self::indexExists($idMapTable, self::ID_MAP_REALM_UNIQUE_INDEX)) {
            $indexName = self::ID_MAP_REALM_UNIQUE_INDEX;

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $wpdb->query(
                "ALTER TABLE {$idMapTable}
                 ADD UNIQUE INDEX {$indexName} (entity_type, wc_id, is_simulated)",
            );
        }

        // Only once the replacement is definitely in place. Dropping first would
        // leave a partially upgraded install with no uniqueness guarantee at all.
        if (
            self::indexExists($idMapTable, self::ID_MAP_REALM_UNIQUE_INDEX)
            && self::indexExists($idMapTable, self::ID_MAP_UNIQUE_INDEX)
        ) {
            $legacyIndex = self::ID_MAP_UNIQUE_INDEX;

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $wpdb->query("ALTER TABLE {$idMapTable} DROP INDEX {$legacyIndex}");
        }
    }

    /**
     * v6: the product mapping staging table.
     *
     * Deliberately not the ID map. A row in the ID map is a fact the migration
     * resolves against; a row here is an intention the owner is still free to
     * change. Keeping them apart is what stops a half-finished mapping session
     * altering the next run, and what stops `reset` — which clears run state —
     * from destroying decisions that were never part of a run.
     */
    private static function v6(): void
    {
        global $wpdb;

        $charset = $wpdb->get_charset_collate();
        $table   = $wpdb->prefix . 'cartshift_product_map';

        $sql = "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            wc_id BIGINT UNSIGNED NOT NULL,
            wc_type VARCHAR(20) NOT NULL DEFAULT '',
            decision VARCHAR(10) NOT NULL,
            fc_post_id BIGINT UNSIGNED NULL,
            band VARCHAR(10) NOT NULL DEFAULT 'none',
            variant_map LONGTEXT NULL,
            decided_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY wc_product_unique (wc_id),
            KEY decision_lookup (decision)
        ) {$charset};";

        if (!function_exists('dbDelta')) {
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        }

        dbDelta($sql);
    }

    /**
     * Escape a literal for use inside a LIKE pattern.
     *
     * The marker contains an underscore, which LIKE reads as "any single
     * character", so escaping is load-bearing rather than ceremonial. Guarded
     * because $wpdb is swappable and not every replacement carries esc_like().
     */
    private static function escLike(string $literal): string
    {
        global $wpdb;

        return method_exists($wpdb, 'esc_like')
            ? $wpdb->esc_like($literal)
            : addcslashes($literal, '_%\\');
    }

    /**
     * Whether a named column exists on a table.
     */
    private static function columnExists(string $table, string $column): bool
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $rows = $wpdb->get_results($wpdb->prepare(
            "SHOW COLUMNS FROM {$table} LIKE %s",
            $column,
        ));

        return !empty($rows);
    }

    /**
     * Whether a named index exists on a table.
     */
    private static function indexExists(string $table, string $indexName): bool
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $rows = $wpdb->get_results($wpdb->prepare(
            "SHOW INDEX FROM {$table} WHERE Key_name = %s",
            $indexName,
        ));

        return !empty($rows);
    }
}

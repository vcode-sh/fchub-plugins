<?php

declare(strict_types=1);

namespace CartShift\Support;

use CartShift\Storage\MigrationLogRepository;

defined('ABSPATH') || exit;

final class Migrations
{
    private const string DB_VERSION_OPTION = 'cartshift_db_version';
    private const string CURRENT_VERSION = '8';
    private const string AUTOMATIC_VERSION = '7';

    /** Unique index guaranteeing one id-map row per (entity_type, wc_id). Superseded by v5. */
    private const string ID_MAP_UNIQUE_INDEX = 'entity_wc_unique';

    /** Unique index guaranteeing one id-map row per (entity_type, wc_id) per realm. Superseded by v7. */
    private const string ID_MAP_REALM_UNIQUE_INDEX = 'entity_wc_realm_unique';

    /** Unique index guaranteeing one id-map row per (source, entity_type, wc_id) per realm. */
    private const string ID_MAP_SOURCE_UNIQUE_INDEX = 'source_entity_wc_realm_unique';

    /** Unique index guaranteeing one product-map decision per Woo product. Superseded by v7. */
    private const string PRODUCT_MAP_UNIQUE_INDEX = 'wc_product_unique';

    /** Unique index guaranteeing one product-map decision per (source, Woo product). */
    private const string PRODUCT_MAP_SOURCE_UNIQUE_INDEX = 'source_wc_product_unique';

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
        '7' => 'v7',
        '8' => 'v8',
    ];

    /**
     * Apply every outstanding step, and STAMP ONLY WHAT ACTUALLY LANDED.
     *
     * The runner used to call the step and then write the version regardless.
     * Every step returned `void` and discarded every `$wpdb->query()` result, so
     * a DDL statement that failed without killing the process — and MySQL has
     * plenty of those — got stamped as applied, `needsUpgrade()` answered false
     * for ever, and the step never replayed.
     *
     * v7 is the first step whose silent failure LOSES DATA rather than merely
     * leaving a schema behind. If its column lands and `ADD UNIQUE INDEX` fails
     * — a `COMPACT` row format puts the id-map key over the 767-byte prefix
     * limit, and a lock wait does the same — the superseded key is correctly
     * kept and the source-scoped one never arrives. After that,
     * `ProductMapRepository::save()`'s `$wpdb->replace()` matches the surviving
     * `wc_product_unique (wc_id)`, and source B's decision about product 42
     * silently DELETES source A's.
     *
     * A failed step stops the chain rather than merely skipping its own stamp:
     * every later step is written against the schema the earlier ones leave
     * behind, and running v7 over a table v5 could not alter is how one broken
     * statement becomes several.
     */
    public static function run(): void
    {
        $installed = get_option(self::DB_VERSION_OPTION, '0');

        foreach (self::VERSIONS as $version => $method) {
            $version = (string) $version;

            if (version_compare($version, self::AUTOMATIC_VERSION, '>')) {
                break;
            }

            if (version_compare($installed, $version, '>=')) {
                continue;
            }

            if (self::$method() !== true) {
                return;
            }

            update_option(self::DB_VERSION_OPTION, $version);
        }
    }

    public static function needsUpgrade(): bool
    {
        $installed = get_option(self::DB_VERSION_OPTION, '0');

        return version_compare($installed, self::CURRENT_VERSION, '<');
    }

    public static function needsAutomaticUpgrade(): bool
    {
        $installed = get_option(self::DB_VERSION_OPTION, '0');

        return version_compare($installed, self::AUTOMATIC_VERSION, '<');
    }

    public static function upgradeExplicit(string $from, string $to): bool
    {
        if ($from !== self::AUTOMATIC_VERSION || $to !== self::CURRENT_VERSION) {
            return false;
        }

        if ((string) get_option(self::DB_VERSION_OPTION, '0') !== $from) {
            return false;
        }

        if (self::v8() !== true) {
            return false;
        }

        if (!update_option(self::DB_VERSION_OPTION, $to)) {
            return false;
        }

        return (string) get_option(self::DB_VERSION_OPTION, '0') === $to;
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

    /**
     * `dbDelta()` reports what it did, not whether it worked — it answers a list
     * of human-readable strings and swallows the error. There is nothing here to
     * return but success; the steps that issue their own statements check them.
     */
    private static function v1(): bool
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

        return true;
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
    private static function v2(): bool
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
            $added = $wpdb->query(
                "ALTER TABLE {$idMapTable}
                 ADD UNIQUE INDEX {$indexName} (entity_type, wc_id)",
            );

            // The whole point of the step. Stamping v2 without it would leave
            // idempotency enforced in PHP alone, which is what v2 exists to stop.
            if ($added === false) {
                return false;
            }
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

        // A redundant index left behind is survivable; the guarantee is in place.
        return true;
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
    private static function v3(): bool
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

        if (!$hasColumn) {
            return false;
        }

        // Tolerate a partially applied upgrade — the index may already be there.
        if (!self::indexExists($logTable, self::LOG_ERROR_CODE_INDEX)) {
            $indexName = self::LOG_ERROR_CODE_INDEX;

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $indexed = $wpdb->query(
                "ALTER TABLE {$logTable}
                 ADD INDEX {$indexName} (migration_id, error_code)",
            );

            if ($indexed === false) {
                return false;
            }
        }

        return true;
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
    private static function v4(): bool
    {
        global $wpdb;

        $logTable = $wpdb->prefix . 'cartshift_migration_log';

        // Nothing to backfill into. Reported as success rather than failure:
        // whether the column exists is v3's question and v3 answered it, and a
        // backfill with no target has not itself gone wrong.
        if (!self::columnExists($logTable, 'error_code')) {
            return true;
        }

        $marker = '"' . MigrationLogRepository::DETAILS_CODE_KEY . '":"';

        // The extracted value, repeated because UPDATE cannot reference a select alias.
        $extract = "SUBSTRING_INDEX(SUBSTRING_INDEX(details, %s, -1), '\"', 1)";

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $backfilled = $wpdb->query($wpdb->prepare(
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

        return $backfilled !== false;
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
    private static function v5(): bool
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
                return false;
            }
        }

        // Tolerate a partially applied upgrade — the index may already be there.
        if (!self::indexExists($idMapTable, self::ID_MAP_REALM_UNIQUE_INDEX)) {
            $indexName = self::ID_MAP_REALM_UNIQUE_INDEX;

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $indexed = $wpdb->query(
                "ALTER TABLE {$idMapTable}
                 ADD UNIQUE INDEX {$indexName} (entity_type, wc_id, is_simulated)",
            );

            if ($indexed === false) {
                return false;
            }
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

        return true;
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
    private static function v6(): bool
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

        return true;
    }

    /**
     * v7: the source namespace.
     *
     * Cross-site migration breaks an assumption both mapping tables were built
     * on — that a Woo ID identifies a thing. It does not: it identifies a thing
     * *within one WooCommerce install*, and two installs hand out the same small
     * integers. Product 42 on the club site and product 42 on the shop site
     * would collide on the unique key, or, considerably worse, resolve to each
     * other and point a migrated order at somebody else's product.
     *
     * `source_key` is a different question from `is_simulated`, and the two
     * deliberately coexist in the id-map's key rather than being merged.
     * `is_simulated` asks "is this a rehearsal"; `source_key` asks "whose data
     * is this". A dry run of the club import must be invisible to a real local
     * run for the first reason and invisible to a real club run for the second.
     *
     * Order matters. The column is added with a `local` default, then every row
     * that predates it is backfilled — those rows all came from the site the
     * plugin is installed on, which is what `local` means — and only then does
     * the new unique key name the column. Building the index first would index
     * whatever the ALTER's default happened to leave behind, which is the same
     * value on MySQL but not a guarantee worth relying on across engines.
     *
     * The superseded keys are dropped only once their replacements are
     * confirmed present, for the reason v2 and v5 give: an install left with no
     * uniqueness guarantee at all is worse than one carrying a redundant index.
     * Both replacements keep the old key's columns as a leading prefix in
     * neither case — `source_key` goes first — so the old indexes genuinely are
     * superseded rather than merely duplicated, and every lookup this plugin
     * makes now carries a source key.
     */
    private static function v7(): bool
    {
        global $wpdb;

        $idMapTable      = $wpdb->prefix . 'cartshift_id_map';
        $productMapTable = $wpdb->prefix . 'cartshift_product_map';

        $ok = self::addSourceKeyColumn($idMapTable, 'entity_type')
            && self::replaceUniqueIndex(
                $idMapTable,
                self::ID_MAP_SOURCE_UNIQUE_INDEX,
                '(source_key, entity_type, wc_id, is_simulated)',
                self::ID_MAP_REALM_UNIQUE_INDEX,
            );

        // NOT short-circuited across the two tables. Both halves are attempted
        // so a re-run has less to do, and the verdict is the conjunction: a
        // product map left on `wc_product_unique (wc_id)` while the id map is
        // source-scoped is exactly the half-applied state that must replay.
        $productMapOk = self::addSourceKeyColumn($productMapTable, 'wc_id')
            && self::replaceUniqueIndex(
                $productMapTable,
                self::PRODUCT_MAP_SOURCE_UNIQUE_INDEX,
                '(source_key, wc_id)',
                self::PRODUCT_MAP_UNIQUE_INDEX,
            );

        return $ok && $productMapOk;
    }

    /**
     * V8 is explicit-only. run() deliberately stops at v7; callers must pass
     * through upgradeExplicit() after the backup, maintenance and mutex gates.
     */
    private static function v8(): bool
    {
        global $wpdb;

        $idMap = $wpdb->prefix . 'cartshift_id_map';
        $idMapColumns = [
            'source_fingerprint' => 'CHAR(64) NULL DEFAULT NULL',
            'target_fingerprint' => 'CHAR(64) NULL DEFAULT NULL',
            'record_state' => "VARCHAR(24) NOT NULL DEFAULT 'legacy'",
            'updated_at' => 'DATETIME NULL DEFAULT NULL',
        ];

        foreach ($idMapColumns as $column => $definition) {
            if (self::columnExists($idMap, $column)) {
                continue;
            }

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            if ($wpdb->query("ALTER TABLE {$idMap} ADD COLUMN {$column} {$definition}") === false) {
                return false;
            }
        }

        foreach (self::v8Tables() as $suffix => $contract) {
            $table = $wpdb->prefix . $suffix;

            if (!self::ensureV8Table($table, $contract['columns'], $contract['indexes'])) {
                return false;
            }
        }

        return self::verifyV8Postconditions();
    }

    /**
     * @return array<string, array{
     *   columns: array<string, string>,
     *   indexes: array<string, array{unique: bool, columns: list<string>}>
     * }>
     */
    private static function v8Tables(): array
    {
        return [
            'cartshift_target_claims' => [
                'columns' => [
                    'id' => 'BIGINT UNSIGNED NOT NULL AUTO_INCREMENT',
                    'entity_type' => 'VARCHAR(32) NOT NULL',
                    'target_id' => 'BIGINT UNSIGNED NOT NULL',
                    'source_key' => 'VARCHAR(64) NOT NULL',
                    'source_id' => 'VARCHAR(191) NOT NULL',
                    'run_id' => 'VARCHAR(36) NOT NULL',
                    'source_fingerprint' => 'CHAR(64) NOT NULL',
                    'target_fingerprint' => 'CHAR(64) NOT NULL',
                    'claim_state' => 'VARCHAR(24) NOT NULL',
                    'created_at' => 'DATETIME NOT NULL',
                    'updated_at' => 'DATETIME NULL DEFAULT NULL',
                ],
                'indexes' => [
                    'PRIMARY' => ['unique' => true, 'columns' => ['id']],
                    'target_exclusive' => ['unique' => true, 'columns' => ['entity_type', 'target_id']],
                    'source_claim' => ['unique' => false, 'columns' => ['source_key', 'entity_type', 'source_id']],
                ],
            ],
            'cartshift_shared_links' => [
                'columns' => [
                    'id' => 'BIGINT UNSIGNED NOT NULL AUTO_INCREMENT',
                    'source_key' => 'VARCHAR(64) NOT NULL',
                    'entity_type' => 'VARCHAR(32) NOT NULL',
                    'source_id' => 'VARCHAR(191) NOT NULL',
                    'target_id' => 'BIGINT UNSIGNED NOT NULL',
                    'target_fingerprint' => 'CHAR(64) NOT NULL',
                    'decision_fingerprint' => 'CHAR(64) NOT NULL',
                    'created_at' => 'DATETIME NOT NULL',
                    'updated_at' => 'DATETIME NULL DEFAULT NULL',
                ],
                'indexes' => [
                    'PRIMARY' => ['unique' => true, 'columns' => ['id']],
                    'source_shared' => ['unique' => true, 'columns' => ['source_key', 'entity_type', 'source_id']],
                ],
            ],
            'cartshift_transfer_leases' => [
                'columns' => [
                    'target_fingerprint' => 'CHAR(64) NOT NULL',
                    'holder_id' => 'VARCHAR(128) NOT NULL',
                    'descriptor_hash' => 'CHAR(64) NOT NULL',
                    'expires_at' => 'DATETIME NOT NULL',
                    'heartbeat_at' => 'DATETIME NOT NULL',
                ],
                'indexes' => [
                    'PRIMARY' => ['unique' => true, 'columns' => ['target_fingerprint']],
                ],
            ],
            'cartshift_transfer_runs' => [
                'columns' => [
                    'run_id' => 'VARCHAR(36) NOT NULL',
                    'descriptor_hash' => 'CHAR(64) NOT NULL',
                    'package_hash' => 'CHAR(64) NOT NULL',
                    'decision_hash' => 'CHAR(64) NOT NULL',
                    'runtime_hash' => 'CHAR(64) NOT NULL',
                    'settings_hash' => 'CHAR(64) NOT NULL',
                    'target_hash' => 'CHAR(64) NOT NULL',
                    'state' => 'VARCHAR(32) NOT NULL',
                    'resume_state' => 'VARCHAR(32) NULL DEFAULT NULL',
                    'attempt' => 'INT UNSIGNED NOT NULL DEFAULT 0',
                    'generation' => 'INT UNSIGNED NOT NULL DEFAULT 1',
                    'created_at' => 'DATETIME NOT NULL',
                    'updated_at' => 'DATETIME NULL DEFAULT NULL',
                ],
                'indexes' => [
                    'PRIMARY' => ['unique' => true, 'columns' => ['run_id']],
                ],
            ],
            'cartshift_transfer_records' => [
                'columns' => [
                    'id' => 'BIGINT UNSIGNED NOT NULL AUTO_INCREMENT',
                    'run_id' => 'VARCHAR(36) NOT NULL',
                    'record_kind' => 'VARCHAR(32) NOT NULL',
                    'source_identity' => 'VARCHAR(255) NOT NULL',
                    'generation' => 'INT UNSIGNED NOT NULL',
                    'source_fingerprint' => 'CHAR(64) NOT NULL',
                    'target_fingerprint' => 'CHAR(64) NULL DEFAULT NULL',
                    'action' => 'VARCHAR(32) NOT NULL',
                    'state' => 'VARCHAR(24) NOT NULL',
                    'target_ids' => 'LONGTEXT NULL',
                    'before_hash' => 'CHAR(64) NULL DEFAULT NULL',
                    'after_hash' => 'CHAR(64) NULL DEFAULT NULL',
                    'error_code' => 'VARCHAR(64) NULL DEFAULT NULL',
                    'created_at' => 'DATETIME NOT NULL',
                    'updated_at' => 'DATETIME NULL DEFAULT NULL',
                ],
                'indexes' => [
                    'PRIMARY' => ['unique' => true, 'columns' => ['id']],
                    'run_record_generation' => [
                        'unique' => true,
                        'columns' => ['run_id', 'record_kind', 'source_identity', 'generation'],
                    ],
                    'run_record_state' => ['unique' => false, 'columns' => ['run_id', 'state']],
                ],
            ],
            'cartshift_transfer_outbox' => [
                'columns' => [
                    'id' => 'BIGINT UNSIGNED NOT NULL AUTO_INCREMENT',
                    'run_id' => 'VARCHAR(36) NOT NULL',
                    'record_kind' => 'VARCHAR(32) NOT NULL',
                    'source_identity' => 'VARCHAR(255) NOT NULL',
                    'generation' => 'INT UNSIGNED NOT NULL',
                    'payload' => 'LONGTEXT NOT NULL',
                    'payload_hash' => 'CHAR(64) NOT NULL',
                    'exported_at' => 'DATETIME NULL DEFAULT NULL',
                    'created_at' => 'DATETIME NOT NULL',
                ],
                'indexes' => [
                    'PRIMARY' => ['unique' => true, 'columns' => ['id']],
                    'run_outbox_generation' => [
                        'unique' => true,
                        'columns' => ['run_id', 'record_kind', 'source_identity', 'generation'],
                    ],
                ],
            ],
        ];
    }

    /**
     * @param array<string, string> $columns
     * @param array<string, array{unique: bool, columns: list<string>}> $indexes
     */
    private static function ensureV8Table(string $table, array $columns, array $indexes): bool
    {
        global $wpdb;

        $columnSql = [];

        foreach ($columns as $name => $definition) {
            $columnSql[] = "{$name} {$definition}";
        }

        foreach ($indexes as $name => $index) {
            $joined = implode(', ', $index['columns']);
            $columnSql[] = $name === 'PRIMARY'
                ? "PRIMARY KEY ({$joined})"
                : sprintf('%s KEY %s (%s)', $index['unique'] ? 'UNIQUE' : '', $name, $joined);
        }

        $collate = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE IF NOT EXISTS {$table} (\n" . implode(",\n", $columnSql) . "\n) ENGINE=InnoDB {$collate}";

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        if ($wpdb->query($sql) === false) {
            return false;
        }

        // Heal a partially applied table instead of stamping a permanent trap.
        foreach ($columns as $name => $definition) {
            if (!self::columnExists($table, $name)) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                if ($wpdb->query("ALTER TABLE {$table} ADD COLUMN {$name} {$definition}") === false) {
                    return false;
                }
            }
        }

        foreach ($indexes as $name => $index) {
            if (self::indexExists($table, $name)) {
                continue;
            }

            $joined = implode(', ', $index['columns']);
            $definition = $name === 'PRIMARY'
                ? "PRIMARY KEY ({$joined})"
                : sprintf('%s INDEX %s (%s)', $index['unique'] ? 'UNIQUE' : '', $name, $joined);

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            if ($wpdb->query("ALTER TABLE {$table} ADD {$definition}") === false) {
                return false;
            }
        }

        return true;
    }

    private static function verifyV8Postconditions(): bool
    {
        global $wpdb;

        $idMap = $wpdb->prefix . 'cartshift_id_map';
        $idMapExpected = [
            'source_fingerprint' => 'char(64)',
            'target_fingerprint' => 'char(64)',
            'record_state' => 'varchar(24)',
            'updated_at' => 'datetime',
        ];

        if (!self::verifyColumns($idMap, $idMapExpected)) {
            return false;
        }

        $idRows = self::fullColumns($idMap);

        if (
            ($idRows['source_fingerprint']['null'] ?? '') !== 'YES'
            || ($idRows['target_fingerprint']['null'] ?? '') !== 'YES'
            || ($idRows['record_state']['null'] ?? '') !== 'NO'
            || ($idRows['record_state']['default'] ?? null) !== 'legacy'
            || ($idRows['updated_at']['null'] ?? '') !== 'YES'
            || !self::verifyIndex(
                $idMap,
                self::ID_MAP_SOURCE_UNIQUE_INDEX,
                ['source_key', 'entity_type', 'wc_id', 'is_simulated'],
                true,
            )
            || !self::verifyEngine($idMap)
        ) {
            return false;
        }

        foreach (self::v8Tables() as $suffix => $contract) {
            $table = $wpdb->prefix . $suffix;
            $expected = [];

            foreach ($contract['columns'] as $column => $definition) {
                $expected[$column] = self::definitionType($definition);
            }

            if (!self::verifyColumns($table, $expected) || !self::verifyEngine($table)) {
                return false;
            }

            $actualColumns = self::fullColumns($table);

            foreach ($contract['columns'] as $column => $definition) {
                $expectedNull = str_contains($definition, 'NOT NULL') ? 'NO' : 'YES';

                if (($actualColumns[$column]['null'] ?? '') !== $expectedNull) {
                    return false;
                }

                if (preg_match("/DEFAULT\\s+'?([^'\\s]+)'?/i", $definition, $match) === 1) {
                    $expectedDefault = strtoupper($match[1]) === 'NULL' ? null : $match[1];

                    if ((string) ($actualColumns[$column]['default'] ?? '') !== (string) ($expectedDefault ?? '')) {
                        return false;
                    }
                }
            }

            foreach ($contract['indexes'] as $name => $index) {
                if (!self::verifyIndex($table, $name, $index['columns'], $index['unique'])) {
                    return false;
                }
            }
        }

        return true;
    }

    /** @param array<string, string> $expected */
    private static function verifyColumns(string $table, array $expected): bool
    {
        $actual = self::fullColumns($table);

        foreach ($expected as $column => $type) {
            if (!isset($actual[$column]) || self::normaliseType($actual[$column]['type']) !== self::normaliseType($type)) {
                return false;
            }
        }

        return true;
    }

    /** @return array<string, array{type: string, null: string, default: mixed}> */
    private static function fullColumns(string $table): array
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $rows = $wpdb->get_results("SHOW FULL COLUMNS FROM {$table}");
        $columns = [];

        foreach ($rows as $row) {
            $field = (string) ($row->Field ?? '');

            if ($field === '') {
                continue;
            }

            $columns[$field] = [
                'type' => (string) ($row->Type ?? ''),
                'null' => (string) ($row->Null ?? ''),
                'default' => $row->Default ?? null,
            ];
        }

        return $columns;
    }

    private static function definitionType(string $definition): string
    {
        if (preg_match('/\A([A-Z]+(?:\(\d+\))?(?:\s+UNSIGNED)?)/i', $definition, $match) !== 1) {
            return '';
        }

        return strtolower($match[1]);
    }

    private static function normaliseType(string $type): string
    {
        $type = strtolower(trim($type));

        return preg_replace('/\b(tinyint|smallint|mediumint|int|bigint)\(\d+\)/', '$1', $type) ?? $type;
    }

    /** @param list<string> $columns */
    private static function verifyIndex(string $table, string $name, array $columns, bool $unique): bool
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $rows = $wpdb->get_results("SHOW INDEX FROM {$table}");
        $actual = [];
        $nonUnique = null;

        foreach ($rows as $row) {
            if ((string) ($row->Key_name ?? '') !== $name) {
                continue;
            }

            $actual[(int) ($row->Seq_in_index ?? 0)] = (string) ($row->Column_name ?? '');
            $nonUnique = (int) ($row->Non_unique ?? 1);
        }

        ksort($actual);

        return array_values($actual) === $columns && $nonUnique === ($unique ? 0 : 1);
    }

    private static function verifyEngine(string $table): bool
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $rows = $wpdb->get_results($wpdb->prepare('SHOW TABLE STATUS LIKE %s', $table));

        return isset($rows[0]) && strcasecmp((string) ($rows[0]->Engine ?? ''), 'InnoDB') === 0;
    }

    /**
     * Add `source_key` to a mapping table and backfill it, idempotently.
     *
     * The backfill runs whether or not this call added the column: a half
     * applied upgrade may have added it and stopped, and re-running must finish
     * the job rather than assume it was finished. Its predicate makes it a
     * no-op on a table that is already correct.
     *
     * @return bool Whether the column is now there to be indexed.
     */
    private static function addSourceKeyColumn(string $table, string $after): bool
    {
        global $wpdb;

        if (!self::columnExists($table, 'source_key')) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $added = $wpdb->query(
                "ALTER TABLE {$table}
                 ADD COLUMN source_key VARCHAR(64) NOT NULL DEFAULT 'local' AFTER {$after}",
            );

            // Trust the ALTER rather than re-issuing SHOW COLUMNS, as v3 does:
            // false means the statement failed, and backfilling or indexing a
            // column that does not exist would fail too.
            if ($added === false) {
                return false;
            }
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $backfilled = $wpdb->query(
            "UPDATE {$table}
             SET source_key = 'local'
             WHERE source_key IS NULL OR source_key = ''",
        );

        // A backfill that failed leaves rows the new unique key would index as
        // an empty namespace, which is not `local` and is not any other source.
        return $backfilled !== false;
    }

    /**
     * Add a unique index, then drop the one it supersedes — in that order.
     *
     * @return bool Whether the replacement key is the one now guarding the table.
     */
    private static function replaceUniqueIndex(
        string $table,
        string $indexName,
        string $columns,
        string $supersededIndex,
    ): bool {
        global $wpdb;

        // Tolerate a partially applied upgrade — the index may already be there.
        if (!self::indexExists($table, $indexName)) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $added = $wpdb->query("ALTER TABLE {$table} ADD UNIQUE INDEX {$indexName} {$columns}");

            // THE STATEMENT THAT LOSES DATA WHEN IT FAILS QUIETLY. Without the
            // source-scoped key the superseded one survives — correctly, since
            // this method drops it only once the replacement is confirmed — and
            // `ProductMapRepository::save()`'s REPLACE then matches
            // `wc_product_unique (wc_id)`, so one source's decision about
            // product 42 deletes another's.
            if ($added === false) {
                return false;
            }
        }

        if (self::indexExists($table, $indexName) && self::indexExists($table, $supersededIndex)) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $wpdb->query("ALTER TABLE {$table} DROP INDEX {$supersededIndex}");
        }

        // A superseded index that would not drop is redundant, not dangerous.
        return true;
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

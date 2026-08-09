<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\Support;

use CartShift\Support\Migrations;
use CartShift\Tests\Unit\PluginTestCase;

final class MigrationsTest extends PluginTestCase
{
    protected function tearDown(): void
    {
        unset($GLOBALS['_cartshift_test_get_results_callback']);

        parent::tearDown();
    }

    public function testNeedsUpgradeWhenNoVersionStored(): void
    {
        // No option stored means version '0', which is < CURRENT_VERSION.
        unset($GLOBALS['_cartshift_test_options']['cartshift_db_version']);

        $this->assertTrue(Migrations::needsUpgrade());
    }

    public function testNeedsUpgradeWhenVersionIsOld(): void
    {
        // Explicitly stored '0' should still need upgrade.
        $GLOBALS['_cartshift_test_options']['cartshift_db_version'] = '0';

        $this->assertTrue(Migrations::needsUpgrade());
    }

    /**
     * v1 installs predate the id-map unique index, so they are not current.
     */
    public function testV1InstallStillNeedsUpgrade(): void
    {
        $GLOBALS['_cartshift_test_options']['cartshift_db_version'] = '1';

        $this->assertTrue(Migrations::needsUpgrade());
    }

    /**
     * v2 installs predate the log's error_code column, so they are not current either.
     */
    public function testV2InstallStillNeedsUpgrade(): void
    {
        $GLOBALS['_cartshift_test_options']['cartshift_db_version'] = '2';

        $this->assertTrue(Migrations::needsUpgrade());
    }

    /**
     * v3 added the error_code column but nothing populated it, so a v3 install has a
     * permanently NULL column and needs the v4 backfill.
     */
    public function testV3InstallStillNeedsUpgrade(): void
    {
        $GLOBALS['_cartshift_test_options']['cartshift_db_version'] = '3';

        $this->assertTrue(Migrations::needsUpgrade());
    }

    /**
     * v4 installs predate the id-map's is_simulated column, so a dry run there
     * still has nowhere to persist its mappings.
     */
    public function testV4InstallStillNeedsUpgrade(): void
    {
        $GLOBALS['_cartshift_test_options']['cartshift_db_version'] = '4';

        $this->assertTrue(Migrations::needsUpgrade());
    }

    /**
     * v6 installs predate the source namespace, so their mapping tables cannot
     * tell a `local` row from a `lapka-klub` one and would collide on the first
     * cross-site import.
     */
    public function testV6InstallStillNeedsUpgrade(): void
    {
        $GLOBALS['_cartshift_test_options']['cartshift_db_version'] = '6';

        $this->assertTrue(Migrations::needsUpgrade());
    }

    public function testNoUpgradeNeededWhenCurrent(): void
    {
        $GLOBALS['_cartshift_test_options']['cartshift_db_version'] = '7';

        $this->assertFalse(Migrations::needsUpgrade());
    }

    // ──────────────────────────────────────────────
    // v2: id-map unique index
    // ──────────────────────────────────────────────

    /**
     * Running from scratch applies every version and lands on the latest.
     */
    public function testRunAppliesAllVersionsAndRecordsTheLatest(): void
    {
        unset($GLOBALS['_cartshift_test_options']['cartshift_db_version']);
        $GLOBALS['_cartshift_test_get_results_callback'] = fn (): array => [];

        Migrations::run();

        $this->assertSame('7', $GLOBALS['_cartshift_test_options']['cartshift_db_version']);
        $this->assertFalse(Migrations::needsUpgrade());
    }

    /**
     * An install already on the latest version must not re-run anything.
     */
    public function testRunIsANoOpWhenAlreadyCurrent(): void
    {
        $GLOBALS['_cartshift_test_options']['cartshift_db_version'] = '7';
        $GLOBALS['_cartshift_test_get_results_callback'] = fn (): array => [];

        Migrations::run();

        $this->assertSame([], $GLOBALS['_cartshift_test_queries']);
    }

    /**
     * De-duplication must happen BEFORE the ALTER, or the ALTER fails outright on any
     * install that already collected duplicate (entity_type, wc_id) rows — which is
     * every install, since idempotency was only ever enforced in PHP.
     */
    public function testV2DeduplicatesBeforeAddingTheUniqueIndex(): void
    {
        $sqls = $this->runV2();

        $dedupeIndex = $this->indexOfQueryContaining($sqls, 'DELETE dupe');
        $alterIndex = $this->indexOfQueryContaining($sqls, 'ADD UNIQUE INDEX');

        $this->assertNotNull($dedupeIndex, 'v2 must de-duplicate the id-map.');
        $this->assertNotNull($alterIndex, 'v2 must add the unique index.');
        $this->assertLessThan($alterIndex, $dedupeIndex, 'De-dupe must precede the ALTER.');
    }

    /**
     * The de-dupe keeps the lowest id per (entity_type, wc_id), matching
     * IdMapRepository::getFcId()'s "first match wins" read semantics — so no mapping
     * that already resolved changes its answer.
     */
    public function testV2DedupeKeepsTheLowestIdPerPair(): void
    {
        $sqls = $this->runV2();
        $dedupe = $this->firstQueryContaining($sqls, 'DELETE dupe');

        $this->assertStringContainsString('MIN(id) AS keep_id', $dedupe);
        $this->assertStringContainsString('GROUP BY entity_type, wc_id', $dedupe);
        $this->assertStringContainsString('HAVING COUNT(*) > 1', $dedupe);
        $this->assertStringContainsString('WHERE dupe.id > keeper.keep_id', $dedupe);
    }

    /**
     * The unique index covers exactly the pair that must not repeat.
     */
    public function testV2AddsUniqueIndexOnEntityTypeAndWcId(): void
    {
        $sqls = $this->runV2();
        $alter = $this->firstQueryContaining($sqls, 'ADD UNIQUE INDEX');

        $this->assertStringContainsString('cartshift_id_map', $alter);
        $this->assertStringContainsString('(entity_type, wc_id)', $alter);
    }

    /**
     * A partially applied upgrade must not blow up on a second attempt — MySQL
     * rejects a duplicate index name.
     */
    public function testV2SkipsTheAlterWhenTheIndexAlreadyExists(): void
    {
        // SHOW INDEX reports entity_wc_unique as present, entity_lookup as absent.
        $sqls = $this->runV2(function (string $query): array {
            if (str_contains($query, "Key_name = 'entity_wc_unique'")) {
                return [(object) ['Key_name' => 'entity_wc_unique']];
            }

            return [];
        });

        $this->assertNull(
            $this->indexOfQueryContaining($sqls, 'ADD UNIQUE INDEX entity_wc_unique'),
            'The ALTER must be skipped when the index is already there.',
        );

        // De-duplication still runs — it is harmless and the table may be dirty.
        $this->assertNotNull($this->indexOfQueryContaining($sqls, 'DELETE dupe'));
    }

    /**
     * v1's entity_lookup covers the same two columns in the same order, so the new
     * UNIQUE index replaces it. Two indexes on a table taking millions of inserts is
     * a cost with no return — but only drop it once the replacement is confirmed.
     */
    public function testV2DropsTheNowRedundantEntityLookupIndex(): void
    {
        $sqls = $this->runV2(fn (): array => [(object) ['Key_name' => 'whatever']]);

        $drop = $this->firstQueryContaining($sqls, 'DROP INDEX');
        $this->assertStringContainsString('entity_lookup', $drop);
    }

    /**
     * If the unique index could not be created, entity_lookup must stay — dropping it
     * would leave the lookup with no index at all.
     */
    public function testV2KeepsEntityLookupWhenTheUniqueIndexIsMissing(): void
    {
        $sqls = $this->runV2(fn (): array => []);

        $this->assertNull(
            $this->indexOfQueryContaining($sqls, 'DROP INDEX'),
            'entity_lookup must survive when entity_wc_unique is not confirmed present.',
        );
    }

    // ──────────────────────────────────────────────
    // v3: log error_code column
    // ──────────────────────────────────────────────

    /**
     * Grouping failures by cause previously meant a LIKE scan over the details LONGTEXT.
     * A real column makes it an index read.
     */
    public function testV3AddsTheErrorCodeColumn(): void
    {
        $sqls = $this->runV3();
        $alter = $this->firstQueryContaining($sqls, 'ADD COLUMN error_code');

        $this->assertStringContainsString('cartshift_migration_log', $alter);
        $this->assertStringContainsString('VARCHAR(64) NULL', $alter);
    }

    /**
     * The index must lead with migration_id: every read is already scoped to one run,
     * and a bare error_code index would be useless for that access pattern.
     */
    public function testV3IndexesMigrationIdBeforeErrorCode(): void
    {
        $sqls = $this->runV3();
        $index = $this->firstQueryContaining($sqls, 'ADD INDEX migration_error_code');

        $this->assertStringContainsString('(migration_id, error_code)', $index);
    }

    /**
     * The column must exist before the index references it.
     */
    public function testV3AddsTheColumnBeforeTheIndex(): void
    {
        $sqls = $this->runV3();

        $columnIndex = $this->indexOfQueryContaining($sqls, 'ADD COLUMN error_code');
        $indexIndex = $this->indexOfQueryContaining($sqls, 'ADD INDEX migration_error_code');

        $this->assertNotNull($columnIndex);
        $this->assertNotNull($indexIndex);
        $this->assertLessThan($indexIndex, $columnIndex);
    }

    /**
     * A partially applied upgrade must not blow up on a second attempt — MySQL rejects
     * both a duplicate column and a duplicate index name.
     */
    public function testV3IsIdempotent(): void
    {
        $sqls = $this->runV3(function (string $query): array {
            if (str_contains($query, "SHOW COLUMNS") && str_contains($query, 'error_code')) {
                return [(object) ['Field' => 'error_code']];
            }

            if (str_contains($query, "Key_name = 'migration_error_code'")) {
                return [(object) ['Key_name' => 'migration_error_code']];
            }

            return [];
        });

        $this->assertNull(
            $this->indexOfQueryContaining($sqls, 'ADD COLUMN error_code'),
            'The column must not be added twice.',
        );
        $this->assertNull(
            $this->indexOfQueryContaining($sqls, 'ADD INDEX migration_error_code'),
            'The index must not be added twice.',
        );
    }

    // ──────────────────────────────────────────────
    // v4: error_code backfill
    // ──────────────────────────────────────────────

    /**
     * v3 shipped the column and its index, but nothing wrote to it — the code was
     * still going only into the details JSON. Without a backfill the rows written in
     * that window drop out of filtered views and per-code counts while still showing
     * up in the unfiltered list: the same log giving two different answers.
     */
    public function testV4BackfillsErrorCodeFromTheDetailsJson(): void
    {
        $sql = $this->firstQueryContaining($this->runV4(), 'UPDATE');

        $this->assertStringContainsString('cartshift_migration_log', $sql);
        $this->assertStringContainsString('SET error_code =', $sql);
        $this->assertStringContainsString('SUBSTRING_INDEX', $sql);
        $this->assertStringContainsString('"error_code":"', $sql);
    }

    /**
     * Only rows that still need it. Re-running must not rewrite rows write() already
     * populated, and must not touch rows that legitimately have no code.
     */
    public function testV4OnlyTouchesUnpopulatedRowsThatCarryACode(): void
    {
        $sql = $this->firstQueryContaining($this->runV4(), 'UPDATE');

        $this->assertStringContainsString('error_code IS NULL', $sql);
        $this->assertStringContainsString('details LIKE', $sql);
    }

    /**
     * JSON_EXTRACT would error on malformed input under MySQL 5.7, and `details` is
     * LONGTEXT with no guarantee of valid JSON. String functions degrade quietly,
     * which is the failure mode we want.
     */
    public function testV4AvoidsJsonFunctionsOnUntrustedLongtext(): void
    {
        $sql = $this->firstQueryContaining($this->runV4(), 'UPDATE');

        $this->assertStringNotContainsString('JSON_EXTRACT', $sql);
        $this->assertStringNotContainsString('JSON_UNQUOTE', $sql);
    }

    /**
     * A value that is empty, oversized or shaped like nothing the plugin ever wrote
     * came from a malformed row. Writing it would put a code in the column that
     * nothing can resolve and no filter can find — strictly worse than leaving NULL.
     */
    public function testV4RefusesToBackfillImplausibleValues(): void
    {
        $sql = $this->firstQueryContaining($this->runV4(), 'UPDATE');

        $this->assertStringContainsString('CHAR_LENGTH', $sql);
        $this->assertStringContainsString('BETWEEN 1 AND 64', $sql);
        $this->assertStringContainsString('REGEXP', $sql);
    }

    /**
     * The shape test must be looser than the current enum. A code from an older build
     * is real data that still belongs in the `other` bucket; only junk is rejected.
     */
    public function testV4KeepsCodesTheCurrentEnumDoesNotKnow(): void
    {
        $sql = $this->firstQueryContaining($this->runV4(), 'UPDATE');

        foreach (['already_migrated', 'customer_not_found'] as $knownCode) {
            $this->assertStringNotContainsString(
                $knownCode,
                $sql,
                'The backfill must not be an allowlist of the current enum cases.',
            );
        }
    }

    /**
     * If v3 could not add the column there is nothing to back-fill into, and the
     * UPDATE would be a hard error rather than a no-op.
     */
    public function testV4DoesNothingWhenTheColumnIsMissing(): void
    {
        // SHOW COLUMNS finds nothing, and the ALTER that would have added it reports
        // failure, so v3 leaves no column behind.
        $GLOBALS['_cartshift_test_options']['cartshift_db_version'] = '3';
        $GLOBALS['_cartshift_test_queries'] = [];
        $GLOBALS['_cartshift_test_get_results_callback'] = fn (): array => [];

        Migrations::run();

        // Scoped to the log table: v7 backfills the mapping tables in the same
        // run, and this test is about v4 declining to backfill the log.
        $updates = array_filter(
            $GLOBALS['_cartshift_test_queries'],
            static fn (array $e): bool => $e[0] === 'query'
                && str_contains($e[1], 'UPDATE')
                && str_contains($e[1], 'cartshift_migration_log'),
        );

        $this->assertSame([], $updates, 'No column means no backfill.');
    }

    // ──────────────────────────────────────────────
    // v5: id-map simulated realm
    // ──────────────────────────────────────────────

    /**
     * A dry run's mappings have to outlive the request that made them — the
     * orchestrator handles one entity type per request, so a memo-only simulation
     * has forgotten every product by the time coupons are validated.
     */
    public function testV5AddsTheIsSimulatedColumn(): void
    {
        $alter = $this->firstQueryContaining($this->runV5(), 'ADD COLUMN is_simulated');

        $this->assertStringContainsString('cartshift_id_map', $alter);
        $this->assertStringContainsString('TINYINT(1) NOT NULL DEFAULT 0', $alter);
    }

    /**
     * v2's `(entity_type, wc_id)` key would make a simulated row and a real row
     * for the same entity mutually exclusive, so a dry run followed by a real
     * migration would collide on the first insert. MySQL has no partial index, so
     * "unique among real rows only" is not expressible — the key grows instead,
     * keeping the guarantee within each realm.
     */
    public function testV5WidensTheUniqueKeyToIncludeTheRealm(): void
    {
        $alter = $this->firstQueryContaining($this->runV5(), 'ADD UNIQUE INDEX entity_wc_realm_unique');

        $this->assertStringContainsString('(entity_type, wc_id, is_simulated)', $alter);
    }

    /**
     * The column has to exist before the index names it.
     */
    public function testV5AddsTheColumnBeforeTheIndex(): void
    {
        $sqls = $this->runV5();

        $columnIndex = $this->indexOfQueryContaining($sqls, 'ADD COLUMN is_simulated');
        $indexIndex  = $this->indexOfQueryContaining($sqls, 'ADD UNIQUE INDEX entity_wc_realm_unique');

        $this->assertNotNull($columnIndex);
        $this->assertNotNull($indexIndex);
        $this->assertLessThan($indexIndex, $columnIndex);
    }

    /**
     * `(entity_type, wc_id)` is a leading prefix of the new key, so the old one is
     * dead weight on a table that takes millions of inserts during a migration —
     * but only once the replacement is confirmed present.
     */
    public function testV5DropsTheSupersededUniqueIndexOnceTheNewOneExists(): void
    {
        $sqls = $this->runV5(static function (string $query): array {
            if (str_contains($query, 'SHOW INDEX')) {
                return [(object) ['Key_name' => 'present']];
            }

            return [];
        });

        $drop = $this->firstQueryContaining($sqls, 'DROP INDEX entity_wc_unique');

        $this->assertStringContainsString('cartshift_id_map', $drop);
    }

    public function testV5KeepsTheOldIndexWhenTheNewOneIsNotConfirmed(): void
    {
        $this->assertNull(
            $this->indexOfQueryContaining($this->runV5(), 'DROP INDEX entity_wc_unique'),
            'Dropping the old key before the new one exists would leave no uniqueness guarantee at all.',
        );
    }

    /**
     * A partially applied upgrade must not blow up on a second attempt — MySQL
     * rejects both a duplicate column and a duplicate index name.
     */
    public function testV5IsIdempotent(): void
    {
        $sqls = $this->runV5(static function (string $query): array {
            if (str_contains($query, 'SHOW COLUMNS') && str_contains($query, 'is_simulated')) {
                return [(object) ['Field' => 'is_simulated']];
            }

            if (str_contains($query, "Key_name = 'entity_wc_realm_unique'")) {
                return [(object) ['Key_name' => 'entity_wc_realm_unique']];
            }

            return [];
        });

        $this->assertNull($this->indexOfQueryContaining($sqls, 'ADD COLUMN is_simulated'));
        $this->assertNull($this->indexOfQueryContaining($sqls, 'ADD UNIQUE INDEX entity_wc_realm_unique'));
    }

    // ──────────────────────────────────────────────
    // v6: product mapping staging table
    // ──────────────────────────────────────────────

    public function testV6CreatesTheProductMapTable(): void
    {
        $statements = [];

        $GLOBALS['_cartshift_test_dbdelta_callback'] = static function (string $sql) use (&$statements): array {
            $statements[] = $sql;
            return [];
        };

        update_option('cartshift_db_version', '5');

        Migrations::run();

        $joined = implode("\n", $statements);

        $this->assertStringContainsString('cartshift_product_map', $joined);
        $this->assertStringContainsString('wc_id BIGINT UNSIGNED NOT NULL', $joined);
        $this->assertStringContainsString('decision VARCHAR(10) NOT NULL', $joined);
        $this->assertStringContainsString('variant_map LONGTEXT NULL', $joined);
        $this->assertStringContainsString('UNIQUE KEY wc_product_unique (wc_id)', $joined);
        $this->assertSame('7', get_option('cartshift_db_version'));
    }

    public function testV6IsNotReRunWhenAlreadyAtSix(): void
    {
        update_option('cartshift_db_version', '6');

        $ran = false;
        $GLOBALS['_cartshift_test_dbdelta_callback'] = static function () use (&$ran): array {
            $ran = true;
            return [];
        };

        Migrations::run();

        $this->assertFalse($ran, 'A table that already exists must not be re-created.');
    }

    // ──────────────────────────────────────────────
    // v7: source namespace
    // ──────────────────────────────────────────────

    /**
     * Cross-site migration means two sources can hand over the same numeric Woo
     * IDs. Without a namespace column the second import either collides with
     * the first on the unique key or, worse, silently resolves to it.
     */
    public function testV7AddsTheSourceKeyColumnToBothMappingTables(): void
    {
        $sqls = $this->runV7();

        $idMap = $this->firstQueryContaining($sqls, 'ADD COLUMN source_key');
        $this->assertStringContainsString('cartshift_id_map', $idMap);
        $this->assertStringContainsString("VARCHAR(64) NOT NULL DEFAULT 'local'", $idMap);

        $productMap = $this->firstQueryContaining(
            array_filter($sqls, static fn (string $sql): bool => str_contains($sql, 'cartshift_product_map')),
            'ADD COLUMN source_key',
        );
        $this->assertStringContainsString("VARCHAR(64) NOT NULL DEFAULT 'local'", $productMap);
    }

    /**
     * Every row that predates the namespace came from the site the plugin is
     * installed on. Backfilling them to `local` is what keeps an existing
     * migration resolvable — and it has to happen before the unique key names
     * the column, or the index is built over whatever the ALTER's default
     * happened to leave behind.
     */
    public function testV7BackfillsExistingRowsToLocalBeforeReplacingTheIndexes(): void
    {
        $sqls = $this->runV7();

        $backfill = $this->indexOfQueryContaining($sqls, "SET source_key = 'local'");
        $newIndex = $this->indexOfQueryContaining($sqls, 'ADD UNIQUE INDEX source_entity_wc_realm_unique');

        $this->assertNotNull($backfill, 'v7 must backfill existing rows.');
        $this->assertNotNull($newIndex);
        $this->assertLessThan($newIndex, $backfill, 'Backfill must precede the index.');
    }

    public function testV7BackfillOnlyTouchesRowsThatHaveNoSourceKey(): void
    {
        $backfill = $this->firstQueryContaining($this->runV7(), "SET source_key = 'local'");

        $this->assertStringContainsString('WHERE source_key IS NULL', $backfill);
        $this->assertStringContainsString("source_key = ''", $backfill);
    }

    public function testV7AddsSourceScopedUniqueIndexes(): void
    {
        $sqls = $this->runV7();

        $idMap = $this->firstQueryContaining($sqls, 'ADD UNIQUE INDEX source_entity_wc_realm_unique');
        $this->assertStringContainsString('(source_key, entity_type, wc_id, is_simulated)', $idMap);

        $productMap = $this->firstQueryContaining($sqls, 'ADD UNIQUE INDEX source_wc_product_unique');
        $this->assertStringContainsString('(source_key, wc_id)', $productMap);
    }

    /**
     * The source namespace and the simulated realm answer different questions —
     * "whose data is this" and "is this a rehearsal" — and the id-map key needs
     * both. Merging them would make a dry run of one source invisible to the
     * other, or worse, resolvable by it.
     */
    public function testV7KeepsTheSourceNamespaceSeparateFromTheSimulatedRealm(): void
    {
        $idMap = $this->firstQueryContaining($this->runV7(), 'ADD UNIQUE INDEX source_entity_wc_realm_unique');

        $this->assertStringContainsString('source_key', $idMap);
        $this->assertStringContainsString('is_simulated', $idMap);
    }

    public function testV7DropsTheSupersededIndexesOnceTheNewOnesExist(): void
    {
        $sqls = $this->runV7(static function (string $query): array {
            if (str_contains($query, 'SHOW INDEX')) {
                return [(object) ['Key_name' => 'present']];
            }

            return [];
        });

        $this->assertNotNull($this->indexOfQueryContaining($sqls, 'DROP INDEX entity_wc_realm_unique'));
        $this->assertNotNull($this->indexOfQueryContaining($sqls, 'DROP INDEX wc_product_unique'));
    }

    public function testV7KeepsTheOldIndexesWhenTheNewOnesAreNotConfirmed(): void
    {
        $sqls = $this->runV7();

        $this->assertNull(
            $this->indexOfQueryContaining($sqls, 'DROP INDEX entity_wc_realm_unique'),
            'Dropping the old key before the new one exists would leave no uniqueness guarantee at all.',
        );
        $this->assertNull($this->indexOfQueryContaining($sqls, 'DROP INDEX wc_product_unique'));
    }

    /**
     * Running it twice must be a no-op — MySQL rejects both a duplicate column
     * and a duplicate index name, and a half-applied upgrade is the normal way
     * an install arrives here.
     */
    public function testV7IsIdempotent(): void
    {
        $sqls = $this->runV7(static function (string $query): array {
            if (str_contains($query, 'SHOW COLUMNS') && str_contains($query, 'source_key')) {
                return [(object) ['Field' => 'source_key']];
            }

            if (
                str_contains($query, "Key_name = 'source_entity_wc_realm_unique'")
                || str_contains($query, "Key_name = 'source_wc_product_unique'")
            ) {
                return [(object) ['Key_name' => 'already-there']];
            }

            return [];
        });

        $this->assertNull($this->indexOfQueryContaining($sqls, 'ADD COLUMN source_key'));
        $this->assertNull($this->indexOfQueryContaining($sqls, 'ADD UNIQUE INDEX source_entity_wc_realm_unique'));
        $this->assertNull($this->indexOfQueryContaining($sqls, 'ADD UNIQUE INDEX source_wc_product_unique'));
    }

    // ──────────────────────────────────────────────
    // The runner: a version is stamped only when the step landed
    // ──────────────────────────────────────────────

    /**
     * The quiet stranding, and the one that loses data.
     *
     * v7's column lands and `ADD UNIQUE INDEX` does not — a `COMPACT` row format
     * puts the id-map key over the 767-byte prefix limit, and a lock wait does
     * the same. The superseded `wc_product_unique (wc_id)` is then correctly
     * kept, because `replaceUniqueIndex()` drops it only once the replacement is
     * confirmed. The runner used to stamp 7 anyway, `needsUpgrade()` answered
     * false for ever, and the step never replayed — after which
     * `ProductMapRepository::save()`'s REPLACE matches the surviving key and
     * source B's decision about product 42 silently deletes source A's.
     */
    public function testAFailedIndexStatementLeavesTheVersionUnstampedSoTheStepReplays(): void
    {
        $GLOBALS['_cartshift_test_options']['cartshift_db_version'] = '6';
        $GLOBALS['_cartshift_test_get_results_callback'] = fn (): array => [];
        $GLOBALS['_cartshift_test_db_error_callback'] = static fn (string $query): string
            => str_contains($query, 'ADD UNIQUE INDEX') ? 'Specified key was too long' : '';

        Migrations::run();

        unset($GLOBALS['_cartshift_test_db_error_callback']);

        $this->assertSame('6', get_option('cartshift_db_version'));
        $this->assertTrue(Migrations::needsUpgrade(), 'A step that did not land must replay.');
    }

    /**
     * A failed step stops the chain rather than letting later ones run against a
     * schema that was never altered.
     */
    public function testAFailedStepStopsTheChainAndStampsNothingAfterIt(): void
    {
        unset($GLOBALS['_cartshift_test_options']['cartshift_db_version']);
        $GLOBALS['_cartshift_test_get_results_callback'] = fn (): array => [];
        $GLOBALS['_cartshift_test_db_error_callback'] = static fn (string $query): string
            => str_contains($query, 'ADD UNIQUE INDEX entity_wc_unique') ? 'Lock wait timeout exceeded' : '';

        Migrations::run();

        unset($GLOBALS['_cartshift_test_db_error_callback']);

        // v1 landed; v2 did not, and v3 onwards never ran.
        $this->assertSame('1', get_option('cartshift_db_version'));
        $this->assertNull(
            $this->indexOfQueryContaining($this->issuedStatements(), 'ADD COLUMN error_code'),
            'v3 must not run over a schema v2 could not finish altering.',
        );
    }

    /**
     * Every statement the run issued, in order.
     *
     * @return string[]
     */
    private function issuedStatements(): array
    {
        return array_values(array_map(
            static fn (array $entry): string => (string) $entry[1],
            array_filter(
                $GLOBALS['_cartshift_test_queries'] ?? [],
                static fn (array $entry): bool => $entry[0] === 'query',
            ),
        ));
    }

    // ──────────────────────────────────────────────
    // Cross-cutting: version constants
    // ──────────────────────────────────────────────

    /**
     * cartshift.php and Migrations declare the DB version independently, and nothing
     * enforces their agreement — this is the guard.
     *
     * It cannot compare against the ambient CARTSHIFT_DB_VERSION constant: phpunit.xml's
     * bootstrap is tests/stubs/test-bootstrap.php, and nothing under tests/ ever requires
     * the real cartshift.php — CARTSHIFT_PLUGIN_FILE holds its path but nothing loads it.
     * So that constant would permanently resolve to the stub's own independent define(),
     * making the assertion "does the stub agree with Migrations" — a third value, neither
     * of the two it is meant to compare — which reproduces the exact drift this test
     * exists to catch, just one hop removed: bump the real file and Migrations but forget
     * the stub, and this would still show green while a genuinely broken pair of files
     * looked fine.
     *
     * So it reads cartshift.php's source text directly and pulls the literal out with a
     * regex, rather than loading the file — loading it would run the real plugin bootstrap
     * (activation hook registration, the GitHub updater, its own autoloader), all of it
     * unwanted side effect in a unit test.
     */
    public function testTheTwoVersionConstantsAgree(): void
    {
        $source = file_get_contents(CARTSHIFT_PLUGIN_FILE);

        if ($source === false) {
            $this->fail('Could not read cartshift.php at ' . CARTSHIFT_PLUGIN_FILE . ' to check its DB version constant.');
        }

        $matched = preg_match("/define\\('CARTSHIFT_DB_VERSION',\\s*'([^']+)'\\)/", $source, $matches);

        if ($matched !== 1) {
            $this->fail('Could not find a CARTSHIFT_DB_VERSION define() in cartshift.php — the regex may be stale.');
        }

        $this->assertSame(
            $matches[1],
            Migrations::currentVersion(),
            'cartshift.php and Migrations declare the DB version independently; they must match.',
        );
    }

    // ──────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────

    /**
     * Run only the v7 step and return the SQL it issued.
     *
     * @param (callable(string): array)|null $schemaCallback Fakes SHOW COLUMNS / SHOW INDEX.
     * @return string[]
     */
    private function runV7(callable|null $schemaCallback = null): array
    {
        $GLOBALS['_cartshift_test_options']['cartshift_db_version'] = '6';
        $GLOBALS['_cartshift_test_queries'] = [];
        $GLOBALS['_cartshift_test_get_results_callback'] = $schemaCallback ?? fn (): array => [];

        Migrations::run();

        return array_values(array_map(
            static fn (array $entry): string => $entry[1],
            array_filter(
                $GLOBALS['_cartshift_test_queries'],
                static fn (array $entry): bool => $entry[0] === 'query',
            ),
        ));
    }

    /**
     * Run only the v5 step and return the SQL it issued.
     *
     * @param (callable(string): array)|null $schemaCallback Fakes SHOW COLUMNS / SHOW INDEX.
     * @return string[]
     */
    private function runV5(callable|null $schemaCallback = null): array
    {
        $GLOBALS['_cartshift_test_options']['cartshift_db_version'] = '4';
        $GLOBALS['_cartshift_test_queries'] = [];
        $GLOBALS['_cartshift_test_get_results_callback'] = $schemaCallback ?? fn (): array => [];

        Migrations::run();

        return array_values(array_map(
            static fn (array $entry): string => $entry[1],
            array_filter(
                $GLOBALS['_cartshift_test_queries'],
                static fn (array $entry): bool => $entry[0] === 'query',
            ),
        ));
    }

    /**
     * Run only the v4 step, with the log's error_code column reported as present.
     *
     * @return string[]
     */
    private function runV4(): array
    {
        $GLOBALS['_cartshift_test_options']['cartshift_db_version'] = '3';
        $GLOBALS['_cartshift_test_queries'] = [];
        $GLOBALS['_cartshift_test_get_results_callback'] = static function (string $query): array {
            if (str_contains($query, 'SHOW COLUMNS') && str_contains($query, 'error_code')) {
                return [(object) ['Field' => 'error_code']];
            }

            return [];
        };

        Migrations::run();

        return array_values(array_map(
            static fn (array $entry): string => $entry[1],
            array_filter(
                $GLOBALS['_cartshift_test_queries'],
                static fn (array $entry): bool => $entry[0] === 'query',
            ),
        ));
    }

    /**
     * Run only the v2 step and return the SQL it issued.
     *
     * @param (callable(string): array)|null $showIndexCallback Fakes SHOW INDEX results.
     * @return string[]
     */
    private function runV2(callable|null $showIndexCallback = null): array
    {
        $GLOBALS['_cartshift_test_options']['cartshift_db_version'] = '1';
        $GLOBALS['_cartshift_test_queries'] = [];
        $GLOBALS['_cartshift_test_get_results_callback'] = $showIndexCallback ?? fn (): array => [];

        Migrations::run();

        return array_values(array_map(
            static fn (array $entry): string => $entry[1],
            array_filter(
                $GLOBALS['_cartshift_test_queries'],
                static fn (array $entry): bool => $entry[0] === 'query',
            ),
        ));
    }

    /**
     * Run only the v3 step and return the SQL it issued.
     *
     * @param (callable(string): array)|null $schemaCallback Fakes SHOW COLUMNS / SHOW INDEX.
     * @return string[]
     */
    private function runV3(callable|null $schemaCallback = null): array
    {
        $GLOBALS['_cartshift_test_options']['cartshift_db_version'] = '2';
        $GLOBALS['_cartshift_test_queries'] = [];
        $GLOBALS['_cartshift_test_get_results_callback'] = $schemaCallback ?? fn (): array => [];

        Migrations::run();

        return array_values(array_map(
            static fn (array $entry): string => $entry[1],
            array_filter(
                $GLOBALS['_cartshift_test_queries'],
                static fn (array $entry): bool => $entry[0] === 'query',
            ),
        ));
    }

    /**
     * @param string[] $sqls
     */
    private function indexOfQueryContaining(array $sqls, string $needle): int|null
    {
        foreach ($sqls as $i => $sql) {
            if (str_contains($sql, $needle)) {
                return $i;
            }
        }

        return null;
    }

    /**
     * @param string[] $sqls
     */
    private function firstQueryContaining(array $sqls, string $needle): string
    {
        $index = $this->indexOfQueryContaining($sqls, $needle);

        if ($index === null) {
            $this->fail("No query recorded containing: {$needle}");
        }

        return $sqls[$index];
    }
}

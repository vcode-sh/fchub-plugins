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

    public function testNoUpgradeNeededWhenCurrent(): void
    {
        $GLOBALS['_cartshift_test_options']['cartshift_db_version'] = '4';

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

        $this->assertSame('4', $GLOBALS['_cartshift_test_options']['cartshift_db_version']);
        $this->assertFalse(Migrations::needsUpgrade());
    }

    /**
     * An install already on the latest version must not re-run anything.
     */
    public function testRunIsANoOpWhenAlreadyCurrent(): void
    {
        $GLOBALS['_cartshift_test_options']['cartshift_db_version'] = '4';
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
            $this->indexOfQueryContaining($sqls, 'ADD UNIQUE INDEX'),
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

        $updates = array_filter(
            $GLOBALS['_cartshift_test_queries'],
            static fn (array $e): bool => $e[0] === 'query' && str_contains($e[1], 'UPDATE'),
        );

        $this->assertSame([], $updates, 'No column means no backfill.');
    }

    // ──────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────

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

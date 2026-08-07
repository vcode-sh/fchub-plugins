<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\Storage;

use CartShift\Storage\MigrationLogRepository;
use CartShift\Support\Enums\MigrationErrorCode;
use CartShift\Tests\Unit\PluginTestCase;

final class MigrationLogRepositoryTest extends PluginTestCase
{
    private MigrationLogRepository $log;

    protected function setUp(): void
    {
        parent::setUp();

        $this->log = new MigrationLogRepository();
    }

    protected function tearDown(): void
    {
        unset(
            $GLOBALS['_cartshift_test_get_results_callback'],
            $GLOBALS['_cartshift_test_get_var_callback'],
        );

        parent::tearDown();
    }

    /**
     * Pagination must order by the autoincrement id, not created_at.
     *
     * created_at is a one-second-resolution DATETIME and the migration writes
     * thousands of rows a second. Ordering by it leaves MySQL free to break ties
     * differently on every query, so rows appear on two pages and others are never
     * shown at all. The same instability breaks the CSV export, which pages through
     * the identical method at per_page=100.
     */
    public function testPaginationOrdersByIdNotCreatedAt(): void
    {
        $GLOBALS['_cartshift_test_get_results_callback'] = fn (): array => [];

        $this->log->getPaginated('migration-1', 1, 50);

        $sql = $this->findSelectWithLimit();

        $this->assertStringContainsString('ORDER BY id DESC', $sql);
        $this->assertStringNotContainsString('ORDER BY created_at', $sql);
    }

    /**
     * Newest-first is the intended direction, and it must survive filtering.
     */
    public function testOrderingIsStableWithStatusFilter(): void
    {
        $GLOBALS['_cartshift_test_get_results_callback'] = fn (): array => [];

        $this->log->getPaginated('migration-2', 3, 100, 'error');

        $sql = $this->findSelectWithLimit();

        $this->assertStringContainsString('ORDER BY id DESC', $sql);
        $this->assertStringContainsString("status = 'error'", $sql);
    }

    /**
     * Every page of a stable ordering must be disjoint. Simulated over a fixed
     * id-ordered corpus: paging through it must yield each row exactly once.
     */
    public function testPagingCoversEveryRowExactlyOnce(): void
    {
        // 25 rows, ids 1..25, all sharing one created_at second.
        $corpus = [];
        for ($id = 1; $id <= 25; $id++) {
            $corpus[] = [
                'id'           => $id,
                'migration_id' => 'm',
                'entity_type'  => 'order',
                'wc_id'        => (string) $id,
                'status'       => 'success',
                'message'      => '',
                'details'      => null,
                'created_at'   => '2026-01-01 00:00:00',
            ];
        }

        // Serve slices the way MySQL would, given ORDER BY id DESC.
        $GLOBALS['_cartshift_test_get_results_callback'] =
            function (string $query) use ($corpus): array {
                if (!str_contains($query, 'LIMIT')) {
                    return [];
                }

                $this->assertStringContainsString('ORDER BY id DESC', $query);

                preg_match('/LIMIT (\d+) OFFSET (\d+)/', $query, $m);

                $sorted = $corpus;
                usort($sorted, static fn (array $a, array $b): int => $b['id'] <=> $a['id']);

                return array_slice($sorted, (int) $m[2], (int) $m[1]);
            };

        $seen = [];
        for ($page = 1; $page <= 3; $page++) {
            foreach ($this->log->getPaginated('m', $page, 10)['data'] as $row) {
                $seen[] = $row['id'];
            }
        }

        $this->assertCount(25, $seen, 'Every row should be returned exactly once.');
        $this->assertSame($seen, array_values(array_unique($seen)), 'No row may repeat across pages.');
        $this->assertSame(range(25, 1), $seen, 'Newest first, no gaps.');
    }

    /**
     * getStats() zero-fills the four keys the UI reads unconditionally, but must also
     * report the statuses the migration actually writes — 'warning', 'dry-run' and
     * 'rollback' all reach write() elsewhere in the plugin. total sums all of them.
     */
    public function testStatsReportsEveryStatusNotJustTheNamedFour(): void
    {
        $GLOBALS['_cartshift_test_get_results_callback'] = fn (): array => [
            ['status' => 'success', 'count' => '10'],
            ['status' => 'error', 'count' => '2'],
            ['status' => 'warning', 'count' => '3'],
            ['status' => 'dry-run', 'count' => '5'],
            ['status' => 'rollback', 'count' => '1'],
        ];

        $stats = $this->log->getStats('migration-3');

        $this->assertSame(10, $stats['success']);
        $this->assertSame(2, $stats['error']);
        $this->assertSame(0, $stats['skipped'], 'Named keys are zero-filled.');
        $this->assertSame(3, $stats['warning']);
        $this->assertSame(5, $stats['dry-run']);
        $this->assertSame(1, $stats['rollback']);
        $this->assertSame(21, $stats['total'], 'total sums every status, not just the named four.');
    }

    /**
     * A status literally called "total" must not clobber the sum.
     */
    public function testStatsTotalKeyIsNotOverwrittenByAStatusNamedTotal(): void
    {
        $GLOBALS['_cartshift_test_get_results_callback'] = fn (): array => [
            ['status' => 'success', 'count' => '4'],
            ['status' => 'total', 'count' => '99'],
        ];

        $stats = $this->log->getStats('migration-4');

        $this->assertSame(103, $stats['total']);
    }

    public function testStatsOnAnEmptyMigrationIsAllZeroes(): void
    {
        $GLOBALS['_cartshift_test_get_results_callback'] = fn (): array => [];

        $stats = $this->log->getStats('migration-5');

        $this->assertSame(0, $stats['success']);
        $this->assertSame(0, $stats['skipped']);
        $this->assertSame(0, $stats['error']);
        $this->assertSame(0, $stats['total']);
        $this->assertSame([], $stats['codes'], 'No rows means no reasons, not a roll call of every code.');
        $this->assertSame([], $stats['code_breakdown']);
    }

    /**
     * The code vocabulary is additive: the status keys the UI and the CLI already
     * read must come back exactly as before, whether or not any row carries a code.
     */
    public function testStatsKeepsItsExistingStatusKeysAlongsideTheCodeBreakdown(): void
    {
        $GLOBALS['_cartshift_test_get_results_callback'] =
            function (string $query): array {
                if (str_contains($query, 'GROUP BY status')) {
                    return [
                        ['status' => 'success', 'count' => '7'],
                        ['status' => 'skipped', 'count' => '4'],
                    ];
                }

                return [
                    ['error_code' => 'customer_not_found', 'count' => '3'],
                    ['error_code' => 'sku_collision', 'count' => '1'],
                ];
            };

        $stats = $this->log->getStats('migration-6');

        $this->assertSame(7, $stats['success']);
        $this->assertSame(4, $stats['skipped']);
        $this->assertSame(0, $stats['error']);
        $this->assertSame(11, $stats['total']);
        $this->assertSame(['customer_not_found' => 3, 'sku_collision' => 1], $stats['codes']);
    }

    /**
     * Counting is the whole point: 4,000 orders skipped for one reason must read as
     * one line saying 4,000, and the biggest cause must come first.
     */
    public function testCodeCountsAreOrderedMostFrequentFirst(): void
    {
        // Grouped rows in the arbitrary order the database returns them: GROUP BY
        // guarantees no ordering of its own, so the sort has to be ours.
        $GLOBALS['_cartshift_test_get_results_callback'] = fn (): array => [
            ['error_code' => 'sku_collision', 'count' => '12'],
            ['error_code' => 'customer_not_found', 'count' => '4000'],
            ['error_code' => 'already_migrated', 'count' => '350'],
        ];

        $counts = $this->log->getCodeCounts('migration-7');

        $this->assertSame(
            ['customer_not_found' => 4000, 'already_migrated' => 350, 'sku_collision' => 12],
            $counts,
        );
        $this->assertSame('customer_not_found', array_key_first($counts));
    }

    /**
     * A code the user cannot act on is noise. GROUP BY already omits codes no row
     * carries, so what is left to guard is the degenerate row: an empty-string
     * code, which the column can hold and which the v4 backfill leaves behind on a
     * row it could not read. Unguarded, that becomes a breakdown line with a blank
     * heading and a filter that selects nothing.
     */
    public function testCodeCountsOmitEmptyAndZeroCountRows(): void
    {
        $GLOBALS['_cartshift_test_get_results_callback'] = fn (): array => [
            ['error_code' => 'customer_not_found', 'count' => '5'],
            ['error_code' => '', 'count' => '3'],
            ['error_code' => 'sku_collision', 'count' => '0'],
        ];

        $this->assertSame(['customer_not_found' => 5], $this->log->getCodeCounts('migration-8'));
    }

    /**
     * A code written by an older build, or by third-party code hooking the
     * migration, is not in this build's enum. It must be reported under its real
     * value — not summed into a synthetic 'other'.
     *
     * Two things are at stake and only one is obvious. The obvious one is that the
     * breakdown must add up to the log the user is looking at. The other is that
     * every line of the breakdown is a filter: whatever getCodeCounts() returns
     * gets handed straight back to getPaginated()'s `code` filter and must find
     * exactly that many rows. 'other' satisfies the first and quietly breaks the
     * second — a line reading "7 x Unrecognised reason" that selects nothing when
     * clicked, because there is no single code called 'other' to filter by.
     */
    public function testUnrecognisedCodesAreReportedUnderTheirRealValue(): void
    {
        $GLOBALS['_cartshift_test_get_results_callback'] = fn (): array => [
            ['error_code' => 'customer_not_found', 'count' => '2'],
            ['error_code' => 'a_retired_legacy_code', 'count' => '4'],
            ['error_code' => 'another_retired_code', 'count' => '3'],
        ];

        $counts = $this->log->getCodeCounts('migration-9');

        $this->assertSame(
            [
                'a_retired_legacy_code' => 4,
                'another_retired_code'  => 3,
                'customer_not_found'    => 2,
            ],
            $counts,
        );
        $this->assertArrayNotHasKey('other', $counts, 'A bucket nothing can filter by is a dead end.');
    }

    /**
     * ...and the breakdown labels an unrecognised code honestly rather than
     * pretending to know what it means.
     */
    public function testUnrecognisedCodesAreLabelledHonestlyInTheBreakdown(): void
    {
        $GLOBALS['_cartshift_test_get_results_callback'] =
            fn (string $query): array => str_contains($query, 'GROUP BY status')
                ? []
                : [['error_code' => 'a_retired_legacy_code', 'count' => '7']];

        $breakdown = $this->log->getStats('migration-9b')['code_breakdown'];

        $this->assertCount(1, $breakdown);
        $this->assertSame('a_retired_legacy_code', $breakdown[0]['code']);
        $this->assertSame(7, $breakdown[0]['count']);
        $this->assertSame('Unrecognised reason', $breakdown[0]['label']);
    }

    /**
     * The breakdown carries the explanation with the count, so the UI does not
     * need its own copy of the vocabulary to say what the user should do.
     */
    public function testCodeBreakdownCarriesLabelHintAndSeverity(): void
    {
        $GLOBALS['_cartshift_test_get_results_callback'] =
            function (string $query): array {
                if (str_contains($query, 'GROUP BY status')) {
                    return [];
                }

                return [['error_code' => 'customer_not_found', 'count' => '4000']];
            };

        $breakdown = $this->log->getStats('migration-10')['code_breakdown'];

        $this->assertCount(1, $breakdown);
        $this->assertSame('customer_not_found', $breakdown[0]['code']);
        $this->assertSame(4000, $breakdown[0]['count']);
        $this->assertNotSame('', $breakdown[0]['label']);
        $this->assertStringContainsString('Migrate customers', $breakdown[0]['hint']);
        $this->assertSame('error', $breakdown[0]['severity']);
        $this->assertSame('customer', $breakdown[0]['category']);
    }

    /**
     * Grouping is per-migration, and (migration_id, error_code) is indexed, so the
     * grouping stays inside that fence rather than sweeping every run the install
     * has ever done on each load of the log screen.
     */
    public function testCodeCountsAreScopedToOneMigration(): void
    {
        $GLOBALS['_cartshift_test_get_results_callback'] = fn (): array => [];

        $this->log->getCodeCounts('migration-11');

        $sql = $this->findQueryContaining('GROUP BY error_code');

        $this->assertStringContainsString("migration_id = 'migration-11'", $sql);
    }

    /**
     * The grouping reads the indexed column, not a LIKE scan over the details
     * LONGTEXT. That was the whole reason the v3 schema added the column.
     */
    public function testCodeCountsGroupOnTheColumnNotTheDetailsJson(): void
    {
        $GLOBALS['_cartshift_test_get_results_callback'] = fn (): array => [];

        $this->log->getCodeCounts('migration-12');

        $sql = $this->findQueryContaining('GROUP BY error_code');

        $this->assertStringNotContainsString('details LIKE', $sql);
        $this->assertStringNotContainsString('SUM(CASE WHEN', $sql);
    }

    /**
     * Old-style positional calls — the signature every migrator uses today — must
     * keep working untouched, with and without the details argument.
     */
    public function testWriteStillAcceptsTheOriginalPositionalSignature(): void
    {
        $this->log->write('m', 'order', 42, 'skipped', 'Already migrated.');
        $this->log->write('m', 'order', '43', 'error', 'Boom.', ['trace' => 'x']);

        $inserts = $this->recordedInserts();

        $this->assertCount(2, $inserts);

        $this->assertSame('order', $inserts[0]['entity_type']);
        $this->assertSame('42', $inserts[0]['wc_id']);
        $this->assertSame('Already migrated.', $inserts[0]['message']);
        $this->assertNull($inserts[0]['details'], 'No code and no details means no JSON blob.');

        $this->assertSame('{"trace":"x"}', $inserts[1]['details']);
        $this->assertStringNotContainsString('error_code', (string) $inserts[1]['details']);
    }

    /**
     * The code is metadata attached to the message, not a replacement for it. The
     * message keeps the specifics a fixed vocabulary cannot carry.
     */
    public function testWriteStoresTheCodeWithoutTouchingTheMessage(): void
    {
        $this->log->write(
            'm',
            'order',
            42,
            'warning',
            'Customer ID 7 not found in ID map. Skipping order.',
            null,
            MigrationErrorCode::CustomerNotFound,
        );

        $insert = $this->recordedInserts()[0];

        $this->assertSame('Customer ID 7 not found in ID map. Skipping order.', $insert['message']);
        $this->assertSame('customer_not_found', $insert['error_code']);
        $this->assertSame('{"error_code":"customer_not_found"}', $insert['details']);
    }

    /**
     * The column is what every read resolves through, so a write that filled only
     * the JSON would produce a row that is visible in the list and invisible to
     * both the filter and the counts. One writer, one statement, both destinations.
     */
    public function testWritePopulatesTheColumnAndTheJsonFromOneResolution(): void
    {
        $this->log->write('m', 'order', 42, 'warning', 'Skipping.', null, MigrationErrorCode::CustomerNotFound);

        $insert = $this->recordedInserts()[0];
        $details = json_decode((string) $insert['details'], true);

        $this->assertSame($insert['error_code'], $details['error_code'], 'The two copies cannot disagree.');
    }

    public function testWriteAcceptsTheCodeAsAPlainString(): void
    {
        $this->log->write('m', 'product', 9, 'skipped', 'Unsupported product type: bundle', null, 'unsupported_product_type');

        $insert = $this->recordedInserts()[0];

        $this->assertSame('unsupported_product_type', $insert['error_code']);
        $this->assertSame('{"error_code":"unsupported_product_type"}', $insert['details']);
    }

    /**
     * write() is the one place a code enters the system, so it is the one place
     * that can keep the vocabulary closed. An unrecognised string is dropped
     * rather than stored, otherwise the breakdown ends up offering a filter it has
     * no label, hint or severity for.
     *
     * Note the asymmetry with the read path, which does pass unknown codes
     * through: the column can legitimately hold a code this build does not know —
     * one retired in a later release, or lifted out of an old row by the v4
     * backfill — but nothing running now should be adding more of them.
     */
    public function testWriteDiscardsAnUnrecognisedCode(): void
    {
        $this->log->write('m', 'product', 9, 'skipped', 'Something.', null, 'made_up_code');

        $insert = $this->recordedInserts()[0];

        $this->assertNull($insert['error_code']);
        $this->assertNull($insert['details']);
    }

    /**
     * A caller that tucked a code into $details rather than passing it as $code
     * still gets it lifted into the column — otherwise the row would be filterable
     * by nothing while looking perfectly well-formed in the raw table.
     */
    public function testACodeHidingInDetailsIsLiftedIntoTheColumn(): void
    {
        $this->log->write('m', 'product', 9, 'skipped', 'Something.', ['error_code' => 'sku_collision']);

        $this->assertSame('sku_collision', $this->recordedInserts()[0]['error_code']);
    }

    public function testWriteKeepsExistingDetailsAlongsideTheCode(): void
    {
        $this->log->write(
            'm',
            'product',
            9,
            'skipped',
            'SKU "abc" already exists in FluentCart. Using "abc-wc9" instead.',
            ['old_sku' => 'abc', 'new_sku' => 'abc-wc9'],
            MigrationErrorCode::SkuCollision,
        );

        $details = json_decode((string) $this->recordedInserts()[0]['details'], true);

        $this->assertSame('abc', $details['old_sku']);
        $this->assertSame('abc-wc9', $details['new_sku']);
        $this->assertSame('sku_collision', $details['error_code']);
    }

    /**
     * A code passed explicitly is the caller's stated intent, and beats whatever a
     * details array happened to carry under the same key.
     */
    public function testAnExplicitCodeWinsOverOneAlreadyInDetails(): void
    {
        $this->log->write(
            'm',
            'product',
            9,
            'skipped',
            'Something.',
            ['error_code' => 'already_migrated'],
            MigrationErrorCode::SkuCollision,
        );

        $this->assertSame(
            '{"error_code":"sku_collision"}',
            $this->recordedInserts()[0]['details'],
        );
    }

    /**
     * Round trip: what write() puts in comes back out of getPaginated(), lifted to
     * the top level so the UI never has to know it lives inside a JSON blob.
     */
    public function testCodeSurvivesTheRoundTripAndIsExposedAtTheTopLevel(): void
    {
        $this->log->write('m', 'order', 42, 'warning', 'Skipping order.', null, MigrationErrorCode::CustomerNotFound);

        $stored = $this->recordedInserts()[0];

        $GLOBALS['_cartshift_test_get_results_callback'] =
            fn (string $query): array => str_contains($query, 'LIMIT')
                ? [[
                    'id'           => 1,
                    'migration_id' => 'm',
                    'entity_type'  => 'order',
                    'wc_id'        => '42',
                    'status'       => 'warning',
                    // Both representations, exactly as write() recorded them.
                    'error_code'   => $stored['error_code'],
                    'message'      => 'Skipping order.',
                    'details'      => $stored['details'],
                    'created_at'   => '2026-01-01 00:00:00',
                ]]
                : [];

        $row = $this->log->getPaginated('m', 1, 50)['data'][0];

        $this->assertSame('customer_not_found', $row['error_code']);
        $this->assertSame('customer_not_found', $row['details']['error_code'], 'details stays intact.');
    }

    /**
     * Rows written before the vocabulary existed have no code. The key is still
     * present so the UI can branch on null rather than on a missing array key.
     */
    public function testRowsWithoutACodeHydrateToANullCode(): void
    {
        $GLOBALS['_cartshift_test_get_results_callback'] =
            fn (string $query): array => str_contains($query, 'LIMIT')
                ? [[
                    'id'           => 1,
                    'migration_id' => 'm',
                    'entity_type'  => 'order',
                    'wc_id'        => '42',
                    'status'       => 'error',
                    'message'      => 'Boom.',
                    'details'      => null,
                    'created_at'   => '2026-01-01 00:00:00',
                ]]
                : [];

        $row = $this->log->getPaginated('m', 1, 50)['data'][0];

        $this->assertArrayHasKey('error_code', $row);
        $this->assertNull($row['error_code']);
    }

    /**
     * hydrate() reads the column and only the column — no JSON fallback.
     *
     * This is the one case where showing less is right. A fallback would fire only
     * for a row the v4 backfill refused as malformed, and it would fire only here:
     * the filter and the counts both read the column, so that row would appear in
     * the list under a reason, be absent when the user clicks that reason, and be
     * missing from the number above it. Twelve rows under a heading that says
     * 2,481, no error, no clue what to trust. Better to show no reason than a
     * reason nothing else can corroborate.
     */
    public function testHydrateDoesNotFallBackToTheDetailsJson(): void
    {
        $GLOBALS['_cartshift_test_get_results_callback'] =
            fn (string $query): array => str_contains($query, 'LIMIT')
                ? [[
                    'id'           => 1,
                    'migration_id' => 'm',
                    'entity_type'  => 'order',
                    'wc_id'        => '42',
                    'status'       => 'warning',
                    // The shape v4 leaves behind when it cannot read a row's JSON:
                    // a code in the blob, nothing in the column.
                    'error_code'   => null,
                    'message'      => 'Skipping order.',
                    'details'      => '{"error_code":"customer_not_found"}',
                    'created_at'   => '2026-01-01 00:00:00',
                ]]
                : [];

        $row = $this->log->getPaginated('m', 1, 50)['data'][0];

        $this->assertNull($row['error_code'], 'A code the filter and the counts cannot see must not be shown.');
    }

    /**
     * Filtering by code is what turns the breakdown into navigation: click 4,000 x
     * "Customer not migrated", get exactly those rows — still newest-first by id.
     */
    public function testPaginatedFiltersByCodeAndKeepsTheStableOrdering(): void
    {
        $GLOBALS['_cartshift_test_get_results_callback'] = fn (): array => [];

        $this->log->getPaginated('m', 1, 50, null, MigrationErrorCode::CustomerNotFound);

        $sql = $this->findSelectWithLimit();

        // Indexed equality on the column, not a LIKE scan over the details
        // LONGTEXT. Same column getCodeCounts() groups by, which is what makes
        // every line of the breakdown clickable and exact.
        $this->assertStringContainsString("error_code = 'customer_not_found'", $sql);
        $this->assertStringNotContainsString('details LIKE', $sql);
        $this->assertStringContainsString('ORDER BY id DESC', $sql);
    }

    public function testCodeFilterComposesWithTheStatusFilter(): void
    {
        $GLOBALS['_cartshift_test_get_results_callback'] = fn (): array => [];

        $this->log->getPaginated('m', 1, 50, 'warning', 'customer_not_found');

        $sql = $this->findSelectWithLimit();

        $this->assertStringContainsString("status = 'warning'", $sql);
        $this->assertStringContainsString("error_code = 'customer_not_found'", $sql);
    }

    /**
     * An unknown code must match nothing. Falling back to an unfiltered result set
     * would hand the user every row in the migration and call it a filter.
     *
     * It reaches the database rather than short-circuiting, because the column can
     * legitimately hold a code this build's enum has never heard of — one retired
     * from the vocabulary in a later release, or lifted out of an old row's JSON by
     * the v4 backfill. Short-circuiting would make those permanently unfindable.
     * Equality on a value no row carries returns nothing, which is the same honest
     * answer arrived at without pretending to know the whole vocabulary.
     */
    public function testAnUnknownCodeFilterMatchesNothingRatherThanEverything(): void
    {
        $GLOBALS['_cartshift_test_get_results_callback'] = fn (): array => [];

        $result = $this->log->getPaginated('m', 1, 50, null, 'made_up_code');

        $this->assertSame([], $result['data']);
        $this->assertSame(0, $result['total']);

        // The filter must actually be in the SQL. An unfiltered query returning
        // nothing only because the stub is empty would pass this test while
        // shipping the bug it exists to catch.
        $this->assertStringContainsString("error_code = 'made_up_code'", $this->findSelectWithLimit());
    }

    // ── The invariant ──────────────────────────────────────

    /**
     * What the summary counts, the filter must find. Nothing else in this file
     * matters as much as this.
     *
     * The failure it guards against is genuinely horrible to diagnose in
     * production: a user filters by "customer not found", sees a dozen rows, and
     * the card directly above the list says 2,481. No error, no warning, just two
     * numbers that flatly disagree. It happens the instant a code is written to
     * one place and read from another — precisely the state this class was in
     * while the code lived only in the details JSON and the column sat NULL
     * beside it.
     *
     * The fixture is deliberately mixed: rows whose code the enum recognises,
     * rows carrying one it does not, and uncoded rows that must appear in neither
     * the counts nor any filtered view.
     */
    public function testEveryCountedCodeIsReachableByFilteringForIt(): void
    {
        $rows = [
            ['error_code' => 'customer_not_found', 'status' => 'warning'],
            ['error_code' => 'customer_not_found', 'status' => 'warning'],
            ['error_code' => 'customer_not_found', 'status' => 'error'],
            ['error_code' => 'sku_collision', 'status' => 'skipped'],
            // A code this build's enum has never heard of: retired from the
            // vocabulary, or lifted out of an old row's JSON by the v4 backfill.
            ['error_code' => 'a_retired_legacy_code', 'status' => 'error'],
            ['error_code' => 'a_retired_legacy_code', 'status' => 'error'],
            // Uncoded: pre-taxonomy rows, and plain successes.
            ['error_code' => null, 'status' => 'success'],
            ['error_code' => null, 'status' => 'success'],
            ['error_code' => '', 'status' => 'skipped'],
        ];

        $GLOBALS['_cartshift_test_get_results_callback'] =
            static function (string $query) use ($rows): array {
                // The grouping query — GROUP BY error_code over the fixture.
                if (str_contains($query, 'GROUP BY error_code')) {
                    $grouped = [];

                    foreach ($rows as $row) {
                        $code = (string) ($row['error_code'] ?? '');

                        if ($code !== '') {
                            $grouped[$code] = ($grouped[$code] ?? 0) + 1;
                        }
                    }

                    return array_map(
                        static fn (string $code, int $count): array => [
                            'error_code' => $code,
                            'count'      => (string) $count,
                        ],
                        array_keys($grouped),
                        $grouped,
                    );
                }

                // The paginated query — the indexed equality the filter emits.
                if (!str_contains($query, 'LIMIT') || !preg_match("/error_code = '([^']*)'/", $query, $m)) {
                    return [];
                }

                $matched = array_values(array_filter(
                    $rows,
                    static fn (array $row): bool => ($row['error_code'] ?? null) === $m[1],
                ));

                return array_map(
                    static fn (array $row, int $i): array => [
                        'id'           => $i + 1,
                        'migration_id' => 'm',
                        'entity_type'  => 'order',
                        'wc_id'        => (string) ($i + 1),
                        'status'       => $row['status'],
                        'error_code'   => $row['error_code'],
                        'message'      => '',
                        'details'      => null,
                        'created_at'   => '2026-01-01 00:00:00',
                    ],
                    $matched,
                    array_keys($matched),
                );
            };

        $counts = $this->log->getCodeCounts('m');

        $this->assertSame(
            ['customer_not_found' => 3, 'a_retired_legacy_code' => 2, 'sku_collision' => 1],
            $counts,
            'Uncoded rows are not a reason and must not be counted as one.',
        );

        $reached = 0;

        foreach ($counts as $code => $count) {
            $filtered = $this->log->getPaginated('m', 1, 100, null, (string) $code)['data'];

            $this->assertCount(
                $count,
                $filtered,
                sprintf('The breakdown says %d x %s; filtering for it must return %d rows.', $count, $code, $count),
            );

            foreach ($filtered as $row) {
                $this->assertSame(
                    $code,
                    $row['error_code'],
                    'A filtered row must display the reason it was filtered by.',
                );
            }

            $reached += count($filtered);
        }

        $this->assertSame(
            array_sum($counts),
            $reached,
            'Every row the breakdown counts must be reachable through the filter, and no others.',
        );
    }

    /**
     * The same invariant from the other side: one resolution helper, so an enum
     * case and its string value produce the identical query. Two spellings that
     * disagreed would reintroduce exactly the divergence the column removed.
     */
    public function testTheCodeFilterResolvesEnumCasesAndStringsIdentically(): void
    {
        $GLOBALS['_cartshift_test_get_results_callback'] = fn (): array => [];

        $this->log->getPaginated('m', 1, 50, null, MigrationErrorCode::CustomerNotFound);
        $fromCase = $this->findSelectWithLimit();

        $GLOBALS['_cartshift_test_queries'] = [];

        $this->log->getPaginated('m', 1, 50, null, 'customer_not_found');

        $this->assertSame($fromCase, $this->findSelectWithLimit());
    }

    // ── warning as a first-class status ────────────────────

    /**
     * 'warning' is not decoration. SubscriptionMigrator writes it for a
     * subscription whose product could not be mapped — a real gap in the migrated
     * data — but nothing zero-filled it, so a run with warnings and no errors
     * reported four tidy zeroes and looked clean.
     */
    public function testStatsZeroFillsWarningAlongsideTheOtherNamedStatuses(): void
    {
        $GLOBALS['_cartshift_test_get_results_callback'] = fn (): array => [
            ['status' => 'success', 'count' => '3'],
        ];

        $stats = $this->log->getStats('migration-warning');

        $this->assertArrayHasKey('warning', $stats);
        $this->assertSame(0, $stats['warning']);
    }

    public function testWarningIsOfferedAsAKnownAndRetryableStatus(): void
    {
        $this->assertContains('warning', MigrationLogRepository::KNOWN_STATUSES);
        $this->assertContains('warning', MigrationLogRepository::RETRYABLE_STATUSES);

        // Retrying a success is how you get duplicates.
        $this->assertNotContains('success', MigrationLogRepository::RETRYABLE_STATUSES);
    }

    // ── getRetryableIds ────────────────────────────────────

    /**
     * The plain case: distinct ids, ascending, for one entity of one run.
     */
    public function testRetryableIdsAreDistinctAndAscending(): void
    {
        $this->stubRetryRows([
            ['wc_id' => '11', 'status' => 'error'],
            ['wc_id' => '2', 'status' => 'error'],
            ['wc_id' => '11', 'status' => 'error'],
        ]);

        $this->assertSame(['11', '2'], $this->log->getRetryableIds('m', 'order'));
    }

    /**
     * The one that matters. A record can be logged as an error on one pass and
     * succeed on a later one — an order whose customer was missing, then
     * migrated, then the order re-run by hand. Its error row is still sitting in
     * the log. Retrying it would create a second copy of something that already
     * migrated, which is worse than the failure it is trying to fix.
     */
    public function testAnIdThatLaterSucceededIsNotRetried(): void
    {
        $this->stubRetryRows([
            ['wc_id' => '7', 'status' => 'error'],
            ['wc_id' => '7', 'status' => 'success'],
            ['wc_id' => '8', 'status' => 'error'],
        ]);

        $this->assertSame(
            ['8'],
            $this->log->getRetryableIds('m', 'order'),
            'A record that eventually succeeded has nothing left to retry.',
        );
    }

    /**
     * The veto is per (migration, entity, id) and comes from the SQL, not from
     * post-filtering in PHP — otherwise the exclusion silently stops working the
     * moment the result set is paginated.
     */
    public function testTheSucceededExclusionIsExpressedInSql(): void
    {
        $this->stubRetryRows([]);

        $this->log->getRetryableIds('m', 'order');

        $sql = $this->findQueryContaining('GROUP BY wc_id');

        $this->assertStringContainsString("SUM(CASE WHEN status = 'success' THEN 1 ELSE 0 END) = 0", $sql);
        $this->assertStringContainsString('ORDER BY wc_id ASC', $sql);
    }

    public function testRetryableIdsDefaultsToErrorsOnly(): void
    {
        $this->stubRetryRows([]);

        $this->log->getRetryableIds('m', 'order');

        $sql = $this->findQueryContaining('GROUP BY wc_id');

        $this->assertStringContainsString("status IN ('error')", $sql);
        $this->assertStringNotContainsString("'warning'", $sql);
    }

    /**
     * Callers pass ['error', 'warning'] because warnings are frequently the
     * interesting ones.
     */
    public function testRetryableIdsAcceptsSeveralStatuses(): void
    {
        $this->stubRetryRows([
            ['wc_id' => '4', 'status' => 'warning'],
            ['wc_id' => '5', 'status' => 'error'],
        ]);

        $this->assertSame(['4', '5'], $this->log->getRetryableIds('m', 'subscription', ['error', 'warning']));

        $this->assertStringContainsString(
            "status IN ('error', 'warning')",
            $this->findQueryContaining('GROUP BY wc_id'),
        );
    }

    /**
     * Asking to retry successes contradicts the veto, so it could only ever
     * return nothing. Dropping it beats leaving the caller to wonder why.
     */
    public function testSuccessIsStrippedFromTheRequestedStatuses(): void
    {
        $this->stubRetryRows([]);

        $this->log->getRetryableIds('m', 'order', ['error', 'success']);

        $sql = $this->findQueryContaining('GROUP BY wc_id');

        $this->assertStringContainsString("status IN ('error')", $sql);
    }

    public function testAskingToRetryNothingRunsNoQueryAtAll(): void
    {
        $this->stubRetryRows([]);

        $this->assertSame([], $this->log->getRetryableIds('m', 'order', []));
        $this->assertSame([], $this->log->getRetryableIds('m', 'order', ['success']));
        $this->assertSame([], $GLOBALS['_cartshift_test_queries']);
    }

    /**
     * The query is scoped by (migration_id, entity_type) — both equalities, which
     * is the `migration_entity` index exactly. A retry of one run's orders must
     * not read another run's rows.
     */
    public function testRetryableIdsAreScopedToOneRunAndOneEntity(): void
    {
        $this->stubRetryRows([]);

        $this->log->getRetryableIds('migration-42', 'coupon');

        $sql = $this->findQueryContaining('GROUP BY wc_id');

        $this->assertStringContainsString("migration_id = 'migration-42'", $sql);
        $this->assertStringContainsString("entity_type = 'coupon'", $sql);
    }

    /**
     * Scoping a retry by reason resolves through the same column as every other
     * read — and must not let the code filter reach the success rows, or the veto
     * quietly disappears and already-migrated ids come back.
     */
    public function testScopingARetryByCodeKeepsTheSucceededVeto(): void
    {
        $this->stubRetryRows([]);

        $this->log->getRetryableIds('m', 'order', ['error'], MigrationErrorCode::CustomerNotFound);

        $sql = $this->findQueryContaining('GROUP BY wc_id');

        $this->assertStringContainsString("error_code = 'customer_not_found'", $sql);
        $this->assertStringContainsString("(error_code = 'customer_not_found' OR status = 'success')", $sql);
    }

    // ── hasEntries ─────────────────────────────────────────

    public function testHasEntriesIsFalseForAnUnknownMigration(): void
    {
        $GLOBALS['_cartshift_test_get_var_callback'] = static fn (): ?string => null;

        $this->assertFalse($this->log->hasEntries('never-happened'));
    }

    public function testHasEntriesIsTrueWhenTheRunLoggedAnything(): void
    {
        $GLOBALS['_cartshift_test_get_var_callback'] = static fn (): string => '17';

        $this->assertTrue($this->log->hasEntries('m'));
    }

    public function testHasEntriesRefusesAnEmptyIdWithoutQuerying(): void
    {
        $this->assertFalse($this->log->hasEntries(''));
        $this->assertSame([], $GLOBALS['_cartshift_test_queries']);
    }

    /**
     * Serve a fixture through the retryable-ids query, emulating the GROUP BY and
     * the success veto the database would apply.
     *
     * @param list<array{wc_id: string, status: string}> $rows
     */
    private function stubRetryRows(array $rows): void
    {
        $GLOBALS['_cartshift_test_get_results_callback'] =
            static function (string $query) use ($rows): array {
                if (!str_contains($query, 'GROUP BY wc_id')) {
                    return [];
                }

                preg_match_all("/'([^']*)'/", $query, $literals);
                $wanted = array_values(array_filter(
                    $literals[1],
                    static fn (string $literal): bool => in_array(
                        $literal,
                        MigrationLogRepository::RETRYABLE_STATUSES,
                        true,
                    ),
                ));

                $byId = [];

                foreach ($rows as $row) {
                    $byId[$row['wc_id']][] = $row['status'];
                }

                $out = [];

                foreach ($byId as $id => $statuses) {
                    if (in_array('success', $statuses, true)) {
                        continue;
                    }

                    if (array_intersect($statuses, $wanted) === []) {
                        continue;
                    }

                    $out[] = ['wc_id' => (string) $id];
                }

                usort($out, static fn (array $a, array $b): int => strcmp($a['wc_id'], $b['wc_id']));

                return $out;
            };
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function recordedInserts(): array
    {
        $out = [];

        foreach ($GLOBALS['_cartshift_test_queries'] ?? [] as $entry) {
            if ($entry[0] === 'insert') {
                $out[] = $entry[2];
            }
        }

        return $out;
    }

    private function findQueryContaining(string $needle): string
    {
        foreach ($GLOBALS['_cartshift_test_queries'] ?? [] as $entry) {
            if ($entry[0] === 'get_results' && str_contains($entry[1], $needle)) {
                return $entry[1];
            }
        }

        $this->fail(sprintf('No query containing "%s" was recorded.', $needle));
    }

    private function findSelectWithLimit(): string
    {
        foreach ($GLOBALS['_cartshift_test_queries'] ?? [] as $entry) {
            if ($entry[0] === 'get_results' && str_contains($entry[1], 'LIMIT')) {
                return $entry[1];
            }
        }

        $this->fail('No paginated SELECT recorded.');
    }
}

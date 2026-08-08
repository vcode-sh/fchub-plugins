<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\CLI;

use CartShift\CLI\MigrateCommand;
use CartShift\Domain\Scope\ScopeResolver;
use CartShift\State\MigrationState;
use CartShift\Tests\Unit\PluginTestCase;

require_once dirname(__DIR__, 2) . '/stubs/HttpCliStubs.php';

/**
 * Covers `wp cartshift reset`.
 *
 * The WP-CLI stubs are no-ops, so flag parsing and WP_CLI::error()'s exit
 * cannot be exercised here. What can be verified is the part that matters:
 * what the command does to the stored state.
 */
final class MigrateCommandTest extends PluginTestCase
{
    private MigrationState $state;
    private ?\wpdb $originalWpdb = null;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $GLOBALS['_cartshift_test_as_pending'] = [];
        $this->state = new MigrationState();

        // Lock is free unless a test says otherwise.
        $GLOBALS['_cartshift_test_get_var_callback'] = static fn (string $query): string => '1';
    }

    /**
     * Clear the query callbacks — PluginTestCase::setUp() does not, so one left
     * installed leaks into every class that runs after this one.
     */
    #[\Override]
    protected function tearDown(): void
    {
        if ($this->originalWpdb !== null) {
            $GLOBALS['wpdb'] = $this->originalWpdb;
            $this->originalWpdb = null;
        }

        unset(
            $GLOBALS['_cartshift_test_get_var_callback'],
            $GLOBALS['_cartshift_test_get_results_callback'],
        );

        parent::tearDown();
    }

    // ── migrate: scope ─────────────────────────────────────

    public function testMigrateStoresTheScopeItWasGiven(): void
    {
        MigrateCommand::migrate([], ['entities' => 'order', 'since' => '2024-03-01']);

        $this->assertSame('since', $this->freshState()->getScope()->mode());
    }

    public function testMigrateWithNoScopeFlagsMigratesEverything(): void
    {
        MigrateCommand::migrate([], ['entities' => 'order']);

        $this->assertTrue($this->freshState()->getScope()->isEverything());
    }

    /**
     * Same refusal as POST /migrate, and for the same reason: truncating a
     * closure would migrate a subset of what the operator confirmed on the
     * command line.
     *
     * Picked explicitly rather than closed into: MAX_CLOSURE_IDS + 1 customer
     * IDs on --customers overflows noteSize() on the picked set itself, with
     * no need to stub a closure query.
     */
    public function testAnOversizedClosureIsRefusedBeforeAnythingIsWritten(): void
    {
        $customerIds = implode(',', range(1, ScopeResolver::MAX_CLOSURE_IDS + 1));

        MigrateCommand::migrate([], [
            'entities'  => 'order',
            'customers' => $customerIds,
        ]);

        $this->assertSame('idle', $this->freshState()->getProgress()['status']);
    }

    /**
     * `--since` combined with `--products` or `--customers` is a contradiction,
     * not a preference to silently resolve — refused rather than guessed.
     */
    public function testSinceCombinedWithProductsIsRefused(): void
    {
        MigrateCommand::migrate([], [
            'entities' => 'order',
            'since'    => '2024-01-01',
            'products' => '12',
        ]);

        $this->assertSame('idle', $this->freshState()->getProgress()['status']);
    }

    /**
     * MigrationScope::fromArray() fails *open* on a malformed date — it falls
     * back to "everything" — which is correct on the REST path, where a
     * preview and an explicit confirmation sit between the value and a
     * running migration. There is no preview on the command line: a typo
     * would otherwise migrate the entire shop with no indication the scope
     * was ever discarded. The CLI refuses instead.
     *
     * @return list<string>
     */
    public static function malformedSinceDates(): array
    {
        return [
            'single-digit month/day' => ['2024-3-1'],
            'not a date at all'      => ['yesterday'],
            'empty string'           => [''],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('malformedSinceDates')]
    public function testAMalformedSinceDateIsRefusedRatherThanMigratingEverything(string $since): void
    {
        MigrateCommand::migrate([], [
            'entities' => 'order',
            'since'    => $since,
        ]);

        $this->assertSame('idle', $this->freshState()->getProgress()['status']);
    }

    public function testResetIsANoopWhenThereIsNoMigrationState(): void
    {
        MigrateCommand::reset([], []);

        $this->assertNull($this->freshState()->getCurrent());
    }

    public function testResetClearsAnAbandonedRun(): void
    {
        $this->state->start(['product']);
        $migrationId = $this->state->getMigrationId();

        MigrateCommand::reset([], []);

        $this->assertNull($this->freshState()->getCurrent(), 'Reset must clear the stored state');
        $this->assertFalse($this->freshState()->isRunning());

        $unscheduled = $GLOBALS['_cartshift_test_as_unscheduled'];
        $this->assertCount(1, $unscheduled, 'Pending background batches must be cancelled');
        $this->assertSame([$migrationId], $unscheduled[0]['args']);
    }

    /**
     * Reset is not rollback. It clears the run's state and discards the simulated
     * id-map rows a dry run leaves behind — those exist only to carry references
     * between that run's own batches — and touches nothing else.
     */
    public function testResetDiscardsOnlyTheSimulatedIdMapRows(): void
    {
        $this->state->start(['product']);

        MigrateCommand::reset([], []);

        $deletes = array_values(array_filter(
            $GLOBALS['_cartshift_test_queries'],
            static fn (array $entry): bool => ($entry[0] ?? '') === 'delete',
        ));

        $this->assertCount(1, $deletes, 'Reset must issue exactly one delete against the id map.');
        $this->assertSame('wp_cartshift_id_map', $deletes[0][1]);
        $this->assertSame(
            ['is_simulated' => 1],
            $deletes[0][2],
            'Real mappings survive a reset — deleting those is rollback\'s job.',
        );

        $this->assertSame([], $GLOBALS['_cartshift_test_deleted_posts']);
    }

    /**
     * MigrationState memoises its reads per instance, and the command builds its
     * own. Read through a fresh instance so assertions see the option, not this
     * test's stale cache.
     */
    private function freshState(): MigrationState
    {
        return new MigrationState();
    }

    // ── retry: argument parsing ────────────────────────────

    public function testRetryIsRegisteredAsACommand(): void
    {
        $this->assertTrue(
            method_exists(MigrateCommand::class, 'retry'),
            '`wp cartshift retry` needs a callable or add_command registers nothing',
        );
    }

    /**
     * `--migration` is the documented spelling, but `wp cartshift log` and
     * `wp cartshift finalize` both use `--migration-id`. Accepting either beats
     * making people remember which command wants which.
     */
    public function testRetrySourceAcceptsEitherSpelling(): void
    {
        $this->assertSame('abc-123', $this->parseRetrySource(['migration' => 'abc-123']));
        $this->assertSame('abc-123', $this->parseRetrySource(['migration-id' => 'abc-123']));
        $this->assertSame('abc-123', $this->parseRetrySource(['migration' => '  abc-123  ']));
        $this->assertSame('', $this->parseRetrySource([]));
    }

    /**
     * `--migration` wins when both are given: it is the one this command
     * documents, so it is the one the user meant.
     */
    public function testTheDocumentedSpellingWinsWhenBothAreGiven(): void
    {
        $this->assertSame(
            'wanted',
            $this->parseRetrySource(['migration' => 'wanted', 'migration-id' => 'ignored']),
        );
    }

    public function testStatusesDefaultToErrorsOnly(): void
    {
        $this->assertSame(['error'], $this->parseStatuses([]));
        $this->assertSame(['error'], $this->parseStatuses(['statuses' => 'error']));
    }

    /**
     * Warnings are frequently the interesting ones — a subscription warned about
     * an unmapped product is a real gap in the migrated data.
     */
    public function testStatusesParseACommaSeparatedList(): void
    {
        $this->assertSame(['error', 'warning'], $this->parseStatuses(['statuses' => 'error,warning']));
        $this->assertSame(['error', 'warning'], $this->parseStatuses(['statuses' => ' error , warning ']));
        $this->assertSame(['warning'], $this->parseStatuses(['statuses' => 'warning,warning']));
    }

    /**
     * Retrying a success is how you get duplicates, so it is dropped rather than
     * quietly honoured.
     */
    public function testNonRetryableStatusesAreDropped(): void
    {
        $this->assertSame(['error'], $this->parseStatuses(['statuses' => 'error,success']));
        $this->assertSame([], $this->parseStatuses(['statuses' => 'success,made-up']));
    }

    /**
     * `--entities` uses the same parser `migrate` does, so a retry cannot reach
     * an entity type a migration could not.
     */
    public function testRetryEntitiesUseTheSameWhitelistAsMigrate(): void
    {
        $this->assertSame(
            ['product', 'order'],
            $this->parseEntityTypes(['entities' => 'product,wp_users,order']),
        );
        $this->assertSame([], $this->parseEntityTypes(['entities' => 'wp_users']));
        $this->assertCount(5, $this->parseEntityTypes([]), 'No --entities means every type');
    }

    /**
     * @param array<string, mixed> $assocArgs
     */
    private function parseRetrySource(array $assocArgs): string
    {
        return $this->callPrivate('resolveRetrySource', $assocArgs);
    }

    /**
     * @param array<string, mixed> $assocArgs
     *
     * @return list<string>
     */
    private function parseStatuses(array $assocArgs): array
    {
        return $this->callPrivate('resolveStatuses', $assocArgs);
    }

    /**
     * @param array<string, mixed> $assocArgs
     *
     * @return list<string>
     */
    private function parseEntityTypes(array $assocArgs): array
    {
        return $this->callPrivate('resolveEntityTypes', $assocArgs);
    }

    /**
     * Reach a private parser directly.
     *
     * These are private because nothing outside the command should call them,
     * but they are where every argument decision is made, and WP-CLI's own
     * dispatch cannot be driven from a unit test. Reflection is the honest way
     * to test the logic without widening the class's surface to suit the tests.
     *
     * @param array<string, mixed> $assocArgs
     */
    private function callPrivate(string $method, array $assocArgs): mixed
    {
        return (new \ReflectionMethod(MigrateCommand::class, $method))->invoke(null, $assocArgs);
    }

    // ── the closing line ───────────────────────────────────
    //
    // `Success: Migration complete. 25 migrated, 2 skipped in 0.23s.` is what a
    // live run printed while writing ten `Unknown column 'item_count'` errors.
    // A shop owner told their migration succeeded does not then go and read a
    // PHP error log, which is how that bug shipped for as long as it did. The
    // branch itself was always here; what was wrong was the count feeding it.
    //
    // Reached by reflection for the reason callPrivate() gives above.

    public function testARunWithErrorsDoesNotCloseOnSuccess(): void
    {
        [$level, $message] = $this->closingLine('Migration', 25, 2, 10, 0.23);

        $this->assertSame('warning', $level, 'WP_CLI::success() prints "Success:", which this run did not earn.');
        $this->assertStringContainsString('10 error(s)', $message);
    }

    /**
     * The counts sit in the table directly above it, so the closing line has to
     * agree with them in both directions. It used to report the errors alone,
     * and a run that migrated 4,000 records and lost three columns is not a run
     * with nothing to show for itself.
     */
    public function testTheWarningStillCarriesWhatDidWork(): void
    {
        [, $message] = $this->closingLine('Migration', 25, 2, 10, 0.23);

        $this->assertStringContainsString('25 migrated', $message);
        $this->assertStringContainsString('2 skipped', $message);
        $this->assertStringContainsString('0.23s', $message);
    }

    public function testACleanRunStillClosesOnSuccess(): void
    {
        [$level, $message] = $this->closingLine('Migration', 25, 2, 0, 0.23);

        $this->assertSame('success', $level, 'A run with nothing wrong must still be allowed to say so.');
        $this->assertStringContainsString('25 migrated, 2 skipped', $message);
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function closingLine(string $label, int $migrated, int $skipped, int $errors, float $elapsed): array
    {
        return (new \ReflectionMethod(MigrateCommand::class, 'closingLine'))
            ->invoke(null, $label, $migrated, $skipped, $errors, $elapsed);
    }

    public function testResetClearsAFinishedRun(): void
    {
        $this->state->start(['product']);
        $this->state->complete();

        MigrateCommand::reset([], []);

        $this->assertNull($this->freshState()->getCurrent());
    }
}

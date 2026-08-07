<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\CLI;

use CartShift\CLI\MigrateCommand;
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
        unset(
            $GLOBALS['_cartshift_test_get_var_callback'],
            $GLOBALS['_cartshift_test_get_results_callback'],
        );

        parent::tearDown();
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

    public function testResetLeavesTheIdMapAlone(): void
    {
        $this->state->start(['product']);

        MigrateCommand::reset([], []);

        // Reset is not rollback: it must not issue any delete against the id map.
        $deletes = array_filter(
            $GLOBALS['_cartshift_test_queries'],
            static fn (array $entry): bool => ($entry[0] ?? '') === 'delete',
        );

        $this->assertSame([], $deletes);
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

    public function testResetClearsAFinishedRun(): void
    {
        $this->state->start(['product']);
        $this->state->complete();

        MigrateCommand::reset([], []);

        $this->assertNull($this->freshState()->getCurrent());
    }
}

<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\Domain\Migration;

use CartShift\Domain\Migration\BatchProcessor;
use CartShift\Domain\Migration\MigrationOrchestrator;
use CartShift\State\MigrationState;
use CartShift\Tests\Unit\PluginTestCase;

final class BatchProcessorTest extends PluginTestCase
{
    private MigrationState $state;

    protected function setUp(): void
    {
        parent::setUp();
        $this->state = new MigrationState();
    }

    public function testIsAvailableChecksAsFunction(): void
    {
        // as_schedule_single_action is defined in our test-bootstrap.php stubs.
        $this->assertTrue(
            BatchProcessor::isAvailable(),
            'isAvailable() should return true when as_schedule_single_action exists',
        );
    }

    public function testLegacyScheduleFirstCannotQueueWork(): void
    {
        $processor = new BatchProcessor(
            fn () => $this->createMock(MigrationOrchestrator::class),
            $this->state,
        );

        $processor->scheduleFirst('test-migration-id-123');

        $this->assertSame([], $GLOBALS['_cartshift_test_as_scheduled']);
    }

    public function testCancelCallsUnschedule(): void
    {
        $processor = new BatchProcessor(
            fn () => $this->createMock(MigrationOrchestrator::class),
            $this->state,
        );

        $processor->cancel('test-migration-id-456');

        $unscheduled = $GLOBALS['_cartshift_test_as_unscheduled'];

        $this->assertCount(1, $unscheduled, 'Exactly one unschedule call should be made');
        $this->assertSame('cartshift/migration/process_batch', $unscheduled[0]['hook']);
        $this->assertSame(['test-migration-id-456'], $unscheduled[0]['args']);
        $this->assertSame('cartshift', $unscheduled[0]['group']);
    }

    public function testHandleBatchIgnoresWhenNotRunning(): void
    {
        $orchestratorCalled = false;

        $processor = new BatchProcessor(
            function () use (&$orchestratorCalled) {
                $orchestratorCalled = true;
                return $this->createMock(MigrationOrchestrator::class);
            },
            $this->state,
        );

        // State is idle — no running migration.
        $processor->handleBatch('some-id');

        $this->assertFalse($orchestratorCalled, 'Orchestrator must not be created when migration is not running');
    }

    public function testHandleBatchIgnoresMismatchedMigrationId(): void
    {
        $orchestratorCalled = false;

        // Start a migration so state is running.
        $this->state->start(['product']);
        $realId = $this->state->getMigrationId();

        $processor = new BatchProcessor(
            function () use (&$orchestratorCalled) {
                $orchestratorCalled = true;
                return $this->createMock(MigrationOrchestrator::class);
            },
            $this->state,
        );

        // Pass a different migration ID — should be ignored.
        $processor->handleBatch('wrong-migration-id');

        $this->assertFalse($orchestratorCalled, 'Orchestrator must not be created for mismatched migration ID');
    }

    public function testLegacyBatchCannotAcquireALockOrReachTheOrchestrator(): void
    {
        $this->state->start(['product']);
        $migrationId = (string) $this->state->getMigrationId();
        $GLOBALS['_cartshift_test_get_var_callback'] = static fn (string $query): string =>
            str_contains($query, 'RELEASE_LOCK') ? '1' : '1';
        $called = false;
        $orchestrator = new class {
            public function processBatch(): array
            {
                return ['continue' => false];
            }
        };
        $processor = new BatchProcessor(static function () use (&$called, $orchestrator): object {
            $called = true;
            return $orchestrator;
        }, $this->state);

        $processor->handleBatch($migrationId);
        $processor->handleBatch($migrationId);

        $getLocks = array_values(array_filter(
            $GLOBALS['_cartshift_test_queries'] ?? [],
            static fn (array $query): bool => ($query[0] ?? '') === 'get_var'
                && str_contains((string) ($query[1] ?? ''), 'GET_LOCK'),
        ));
        self::assertFalse($called);
        self::assertSame([], $getLocks);
    }
}

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

    public function testScheduleFirstCallsAs(): void
    {
        $processor = new BatchProcessor(
            fn () => $this->createMock(MigrationOrchestrator::class),
            $this->state,
        );

        $processor->scheduleFirst('test-migration-id-123');

        $scheduled = $GLOBALS['_cartshift_test_as_scheduled'];

        $this->assertCount(1, $scheduled, 'Exactly one action should be scheduled');
        $this->assertSame('cartshift/migration/process_batch', $scheduled[0]['hook']);
        $this->assertSame(['test-migration-id-123'], $scheduled[0]['args']);
        $this->assertSame('cartshift', $scheduled[0]['group']);
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
}

<?php

declare(strict_types=1);

namespace CartShift\Tests\Integration\Failure;

use CartShift\Domain\Transfer\Execution\TransferCoordinator;
use CartShift\Domain\Transfer\Execution\TransferRunState;
use PHPUnit\Framework\Attributes\DataProvider;

final class DatabaseFailureTest extends FailureTestCase
{
    /** @return array<string, array{string, ?string}> */
    public static function transactionalBoundaries(): array
    {
        return [
            'before target row creation' => ['before_target_row', null],
            'after target row creation' => ['after_target_row', null],
            'before ID-map write' => ['before_map_write', null],
            'after ID-map write' => ['after_map_write', null],
            'before receipt write' => [null, 'before_receipt_write'],
            'after receipt write' => [null, 'after_receipt_write'],
        ];
    }

    #[DataProvider('transactionalBoundaries')]
    public function testEveryPreCommitDatabaseFailureLeavesNoGraphMapOrReceipt(
        ?string $writerFailure,
        ?string $journalFailure,
    ): void {
        $prepared = $this->prepared();
        $record = $this->record();
        $graph = new FailureGraph();
        $journal = new FailureJournal($journalFailure);
        $coordinator = new TransferCoordinator(
            $journal,
            new FailureExporter(),
            new FailureBoundary(),
            new FixedFailureTargetState($prepared->targetState),
            ['product' => new FailurePointWriter($graph, $writerFailure)],
            ['product' => new FailureReconciler()],
        );

        try {
            $coordinator->stage($this->plan($prepared, [$record]), $this->context(), 'worker-a', 300);
            self::fail('The injected database boundary failure was ignored.');
        } catch (\RuntimeException $exception) {
            self::assertStringStartsWith('injected_failure:', $exception->getMessage());
        }

        self::assertSame(0, $graph->targetRows, 'A target row escaped its record transaction.');
        self::assertSame(0, $graph->maps, 'An ID map escaped its record transaction.');
        self::assertSame([], $journal->receipts($prepared->runId), 'A receipt escaped its record transaction.');
        self::assertSame(TransferRunState::Failed, $journal->state($prepared->runId));

        try {
            $coordinator->stage($this->plan($prepared, [$record]), $this->context(), 'worker-a', 300);
            self::fail('A terminal failed generation was silently retried.');
        } catch (\RuntimeException $exception) {
            self::assertSame('transfer_run_failed_terminal', $exception->getMessage());
        }
        self::assertSame(1, $graph->writerCalls, 'The blocked retry reached the target writer.');
    }
}


<?php

declare(strict_types=1);

namespace CartShift\Tests\Integration\Failure;

use CartShift\Domain\Transfer\Execution\TransferCoordinator;
use CartShift\Domain\Transfer\Execution\TransferRunState;
use PHPUnit\Framework\Attributes\DataProvider;

final class CrashResumeTest extends FailureTestCase
{
    /** @return array<string, array{string}> */
    public static function receiptCrashBoundaries(): array
    {
        return [
            'before immutable receipt export' => ['before_receipt_export'],
            'after immutable receipt export before outbox acknowledgement' => ['after_receipt_export'],
        ];
    }

    #[DataProvider('receiptCrashBoundaries')]
    public function testReceiptExportCrashResumesWithoutDuplicatingAnyRecord(string $failurePoint): void
    {
        $prepared = $this->prepared();
        $records = [$this->record('product', '41'), $this->record('product', '42')];
        $graph = new FailureGraph();
        $journal = new FailureJournal();
        $exporter = new FailureExporter($failurePoint);
        $reconciler = new FailureReconciler();
        $coordinator = new TransferCoordinator(
            $journal,
            $exporter,
            new FailureBoundary(),
            new FixedFailureTargetState($prepared->targetState),
            ['product' => new FailurePointWriter($graph)],
            ['product' => $reconciler],
        );

        try {
            $coordinator->stage($this->plan($prepared, $records), $this->context(), 'worker-a', 300);
            self::fail('The injected receipt-export crash was ignored.');
        } catch (\RuntimeException $exception) {
            self::assertSame('receipt_export_interrupted', $exception->getMessage());
        }

        self::assertSame(1, $graph->writerCalls);
        self::assertSame(1, $graph->targetRows);
        self::assertSame(1, $graph->maps);
        self::assertCount(1, $journal->receipts($prepared->runId));
        self::assertSame(TransferRunState::Interrupted, $journal->state($prepared->runId));

        $coordinator->stage($this->plan($prepared, $records), $this->context(), 'worker-a', 300);

        self::assertSame(2, $graph->writerCalls, 'Resume did not write exactly the one record that lacked a durable receipt.');
        self::assertSame(2, $graph->targetRows);
        self::assertSame(2, $graph->maps);
        self::assertCount(2, $journal->receipts($prepared->runId));
        self::assertSame(2, $exporter->files, 'Immutable export was duplicated or omitted.');
        self::assertSame(1, $reconciler->calls, 'Resume did not re-read the already committed target record.');
        self::assertSame(TransferRunState::Staged, $journal->state($prepared->runId));
        self::assertSame(2, $journal->attempt($prepared->runId));
    }

    public function testCrashBeforeReconciliationRequiresRecoveryEvidenceForTheSameDescriptor(): void
    {
        $prepared = $this->prepared();
        $record = $this->record();
        $journal = new FailureJournal();
        $boundary = new FailureBoundary();
        $reconciler = new FailureReconciler();
        $coordinator = new TransferCoordinator(
            $journal,
            new FailureExporter(),
            $boundary,
            new FixedFailureTargetState($prepared->targetState),
            ['product' => new FailurePointWriter(new FailureGraph())],
            ['product' => $reconciler],
        );
        $plan = $this->plan($prepared, [$record]);
        $coordinator->stage($plan, $this->context(), 'worker-a', 300);

        // The durable state transition happened, then the process disappeared
        // before the first target read.
        $journal->transition($prepared->runId, TransferRunState::Staged, TransferRunState::Reconciling);

        try {
            $coordinator->reconcile($plan, 'worker-b', 300);
            self::fail('A crashed reconciling lease was resumed without recovery evidence.');
        } catch (\RuntimeException $exception) {
            self::assertSame('transfer_run_not_reconcilable:reconciling', $exception->getMessage());
        }

        $evidence = str_repeat('e', 64);
        $coordinator->reconcile($plan, 'worker-b', 300, $evidence);

        self::assertSame(1, $reconciler->calls);
        self::assertSame(TransferRunState::Reconciled, $journal->state($prepared->runId));
        self::assertContains('recover:' . $evidence, $boundary->events);
    }

    public function testFailureImmediatelyBeforePromotionLeavesReconciledStateAndRetryTransitionsOnce(): void
    {
        $prepared = $this->prepared();
        $record = $this->record();
        $journal = new FailureJournal();
        $graph = new FailureGraph();
        $plan = $this->plan($prepared, [$record]);
        $base = [
            $journal,
            new FailureExporter(),
            new FixedFailureTargetState($prepared->targetState),
            ['product' => new FailurePointWriter($graph)],
            ['product' => new FailureReconciler()],
        ];
        $coordinator = new TransferCoordinator($base[0], $base[1], new FailureBoundary(), $base[2], $base[3], $base[4]);
        $coordinator->stage($plan, $this->context(), 'worker-a', 300);
        $coordinator->reconcile($plan, 'worker-a', 300);

        $failing = new TransferCoordinator($base[0], $base[1], new FailureBoundary('lock_renew'), $base[2], $base[3], $base[4]);
        try {
            $failing->promote($prepared, 'worker-a', 300);
            self::fail('Promotion continued after lease renewal failed.');
        } catch (\RuntimeException $exception) {
            self::assertSame('injected_failure:lock_renew', $exception->getMessage());
        }
        self::assertSame(TransferRunState::Reconciled, $journal->state($prepared->runId));

        $coordinator->promote($prepared, 'worker-a', 300);
        self::assertSame(TransferRunState::Promoted, $journal->state($prepared->runId));
        self::assertSame(1, $graph->writerCalls);
    }
}


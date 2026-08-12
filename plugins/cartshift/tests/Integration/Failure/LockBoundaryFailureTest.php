<?php

declare(strict_types=1);

namespace CartShift\Tests\Integration\Failure;

use CartShift\Domain\Transfer\Execution\TransferCoordinator;
use CartShift\Domain\Transfer\Execution\TransferRunState;

final class LockBoundaryFailureTest extends FailureTestCase
{
    public function testLeaseAcquisitionFailureLeavesPreparedRunUntouchedAndRetryable(): void
    {
        $prepared = $this->prepared();
        $record = $this->record();
        $graph = new FailureGraph();
        $journal = new FailureJournal();
        $targetState = new FixedFailureTargetState($prepared->targetState);
        $writers = ['product' => new FailurePointWriter($graph)];
        $reconcilers = ['product' => new FailureReconciler()];
        $failing = new TransferCoordinator(
            $journal,
            new FailureExporter(),
            new FailureBoundary('lock_acquire'),
            $targetState,
            $writers,
            $reconcilers,
        );

        try {
            $failing->stage($this->plan($prepared, [$record]), $this->context(), 'worker-a', 300);
            self::fail('A failed lease acquisition reached target staging.');
        } catch (\RuntimeException $exception) {
            self::assertSame('injected_failure:lock_acquire', $exception->getMessage());
        }

        self::assertSame(TransferRunState::Prepared, $journal->state($prepared->runId));
        self::assertSame(0, $journal->attempt($prepared->runId));
        self::assertSame(0, $graph->writerCalls);
        self::assertSame(0, $graph->targetRows);
        self::assertSame(0, $graph->maps);
        self::assertSame([], $journal->receipts($prepared->runId));

        $healthy = new TransferCoordinator(
            $journal,
            new FailureExporter(),
            new FailureBoundary(),
            $targetState,
            $writers,
            $reconcilers,
        );
        $healthy->stage($this->plan($prepared, [$record]), $this->context(), 'worker-a', 300);

        self::assertSame(TransferRunState::Staged, $journal->state($prepared->runId));
        self::assertSame(1, $journal->attempt($prepared->runId));
        self::assertSame(1, $graph->writerCalls);
        self::assertCount(1, $journal->receipts($prepared->runId));
    }

    public function testLeaseRenewalFailureLeavesStagedRunUnchangedAndRetryable(): void
    {
        $prepared = $this->prepared();
        $record = $this->record();
        $graph = new FailureGraph();
        $journal = new FailureJournal();
        $targetState = new FixedFailureTargetState($prepared->targetState);
        $writers = ['product' => new FailurePointWriter($graph)];
        $reconciler = new FailureReconciler();
        $reconcilers = ['product' => $reconciler];
        $healthy = new TransferCoordinator(
            $journal,
            new FailureExporter(),
            new FailureBoundary(),
            $targetState,
            $writers,
            $reconcilers,
        );
        $plan = $this->plan($prepared, [$record]);
        $healthy->stage($plan, $this->context(), 'worker-a', 300);

        $failing = new TransferCoordinator(
            $journal,
            new FailureExporter(),
            new FailureBoundary('lock_renew'),
            $targetState,
            $writers,
            $reconcilers,
        );
        try {
            $failing->reconcile($plan, 'worker-a', 300);
            self::fail('A failed lease renewal entered reconciliation.');
        } catch (\RuntimeException $exception) {
            self::assertSame('injected_failure:lock_renew', $exception->getMessage());
        }

        self::assertSame(TransferRunState::Staged, $journal->state($prepared->runId));
        self::assertSame(1, $journal->attempt($prepared->runId));
        self::assertSame(0, $reconciler->calls);
        self::assertSame(1, $graph->writerCalls);
        self::assertCount(1, $journal->receipts($prepared->runId));

        $healthy->reconcile($plan, 'worker-a', 300);

        self::assertSame(TransferRunState::Reconciled, $journal->state($prepared->runId));
        self::assertSame(1, $reconciler->calls);
        self::assertSame(1, $graph->writerCalls);
    }
}

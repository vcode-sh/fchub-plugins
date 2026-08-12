<?php

declare(strict_types=1);

namespace CartShift\Tests\Integration\Failure;

use CartShift\Domain\Transfer\Decision\TransferDecisionSet;
use CartShift\Domain\Transfer\Execution\TargetStateFingerprint;
use CartShift\Domain\Transfer\Execution\TransferCoordinator;
use CartShift\Domain\Transfer\Execution\TransferPlan;
use CartShift\Domain\Transfer\Execution\TransferRunState;
use CartShift\Domain\Transfer\RecordEnvelope;
use CartShift\Domain\Transfer\SourceIdentity;
use PHPUnit\Framework\Attributes\DataProvider;

final class TargetDriftTest extends FailureTestCase
{
    /** @return array<string, array{string}> */
    public static function sealedInputs(): array
    {
        return [
            'one source package byte' => ['package'],
            'one decision' => ['decision'],
            'installed compatibility contract' => ['compatibility'],
            'one CartShift setting' => ['settings'],
            'one target gateway registration' => ['gateway'],
            'one selected record or dependency' => ['selection'],
            'one mapped target row' => ['target'],
        ];
    }

    #[DataProvider('sealedInputs')]
    public function testEachSealedFingerprintStopsBeforeWritesAndRestorationRevalidates(string $field): void
    {
        $prepared = $this->prepared();
        $current = $prepared->targetState->toArray();
        $current[$field] = str_repeat('f', 64);
        $graph = new FailureGraph();
        $journal = new FailureJournal();
        $record = $this->record();
        $writer = new FailurePointWriter($graph);
        $reconciler = new FailureReconciler();
        $drifted = new TransferCoordinator(
            $journal,
            new FailureExporter(),
            new FailureBoundary(),
            new FixedFailureTargetState(TargetStateFingerprint::fromArray($current)),
            ['product' => $writer],
            ['product' => $reconciler],
        );

        try {
            $drifted->stage($this->plan($prepared, [$record]), $this->context(), 'worker-a', 300);
            self::fail('Changed ' . $field . ' evidence reached the writer.');
        } catch (\RuntimeException $exception) {
            self::assertSame('prepared_transfer_fingerprint_changed:' . $field, $exception->getMessage());
        }
        self::assertSame(0, $graph->writerCalls);
        self::assertSame(0, $graph->targetRows);
        self::assertSame(0, $graph->maps);
        self::assertSame([], $journal->receipts($prepared->runId));
        self::assertSame(TransferRunState::Prepared, $journal->state($prepared->runId));

        $restored = new TransferCoordinator(
            $journal,
            new FailureExporter(),
            new FailureBoundary(),
            new FixedFailureTargetState($prepared->targetState),
            ['product' => $writer],
            ['product' => $reconciler],
        );
        $restored->stage($this->plan($prepared, [$record]), $this->context(), 'worker-a', 300);

        self::assertSame(1, $graph->writerCalls);
        self::assertSame(1, $graph->targetRows);
        self::assertSame(1, $graph->maps);
        self::assertCount(1, $journal->receipts($prepared->runId));
        self::assertSame(TransferRunState::Staged, $journal->state($prepared->runId));
    }

    public function testDependencyMutationCannotBeHiddenBehindAnUnchangedPreparedDescriptor(): void
    {
        $prepared = $this->prepared();
        $missing = 'contract-source:customer:99';
        $order = RecordEnvelope::forPayload(
            1,
            new SourceIdentity('contract-source', 'order', '77'),
            ['dependencies' => [$missing]],
        );

        try {
            TransferPlan::build($prepared, [$order], TransferDecisionSet::empty());
            self::fail('A selected order with a removed dependency produced an executable plan.');
        } catch (\RuntimeException $exception) {
            self::assertSame('transfer_dependency_graph_blocked:dependency_missing', $exception->getMessage());
        }
    }
}

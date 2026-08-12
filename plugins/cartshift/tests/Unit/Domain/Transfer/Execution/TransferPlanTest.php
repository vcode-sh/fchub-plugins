<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\Domain\Transfer\Execution;

use CartShift\Domain\Transfer\Decision\TransferDecisionSet;
use CartShift\Domain\Transfer\Execution\PreparedTransfer;
use CartShift\Domain\Transfer\Execution\TargetStateFingerprint;
use CartShift\Domain\Transfer\Execution\TransferPlan;
use CartShift\Domain\Transfer\RecordEnvelope;
use CartShift\Domain\Transfer\SourceIdentity;
use CartShift\Tests\Unit\PluginTestCase;

final class TransferPlanTest extends PluginTestCase
{
    public function testGraphIsValidatedAndReorderedBeforeAnyWriterCanSeeIt(): void
    {
        $decisions = TransferDecisionSet::empty();
        $product = $this->record('product', '4', []);
        $order = $this->record('order', '9', [$product->identity->canonical()]);
        $prepared = $this->prepared($decisions->fingerprint());

        $plan = TransferPlan::build($prepared, [$order, $product], $decisions);

        self::assertSame(
            [$product->identity->canonical(), $order->identity->canonical()],
            array_map(static fn (RecordEnvelope $record): string => $record->identity->canonical(), $plan->records),
        );
    }

    public function testMissingDependencyAndDecisionFingerprintDriftBothStopPlanConstruction(): void
    {
        $decisions = TransferDecisionSet::empty();
        $order = $this->record('order', '9', ['shop-alpha:product:404']);

        foreach ([
            [$this->prepared($decisions->fingerprint()), [$order], 'transfer_dependency_graph_blocked:dependency_missing'],
            [$this->prepared(str_repeat('f', 64)), [], 'prepared_decision_fingerprint_changed'],
        ] as [$prepared, $records, $message]) {
            try {
                TransferPlan::build($prepared, $records, $decisions);
                self::fail($message . ' was accepted.');
            } catch (\RuntimeException $exception) {
                self::assertSame($message, $exception->getMessage());
            }
        }
    }

    public function testEmbeddedClosureRecordsAreValidatedButOwnedByTheirRootWriter(): void
    {
        $decisions = TransferDecisionSet::empty();
        $media = $this->record('media_asset', '4', []);
        $term = $this->record('taxonomy_term', '5', []);
        $product = $this->record('product', '9', [$media->identity->canonical(), $term->identity->canonical()]);

        $plan = TransferPlan::build($this->prepared($decisions->fingerprint()), [$product, $media, $term], $decisions);

        self::assertSame([$product->identity->canonical()], array_map(
            static fn (RecordEnvelope $record): string => $record->identity->canonical(),
            $plan->records,
        ));

        $this->expectExceptionMessage('transfer_dependency_graph_blocked:dependency_missing');
        TransferPlan::build($this->prepared($decisions->fingerprint()), [$product, $media], $decisions);
    }

    /** @param list<string> $dependencies */
    private function record(string $kind, string $id, array $dependencies): RecordEnvelope
    {
        return RecordEnvelope::forPayload(1, new SourceIdentity('shop-alpha', $kind, $id), [
            'dependencies' => $dependencies,
        ]);
    }

    private function prepared(string $decisionHash): PreparedTransfer
    {
        return new PreparedTransfer(
            'run-plan-22',
            '/srv/private/package',
            str_repeat('1', 64),
            new TargetStateFingerprint(
                str_repeat('1', 64), $decisionHash, str_repeat('3', 64),
                str_repeat('4', 64), str_repeat('5', 64), str_repeat('6', 64), str_repeat('7', 64),
            ),
            'rehearsal', [], false, '2026-08-10T12:00:00Z', 'shop-alpha',
        );
    }
}

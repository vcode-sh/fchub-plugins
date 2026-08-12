<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\Domain\Transfer\Graph;

use CartShift\Domain\Transfer\Decision\TransferDecisionSet;
use CartShift\Domain\Transfer\Graph\TransferDependencyGraph;
use CartShift\Domain\Transfer\RecordEnvelope;
use CartShift\Domain\Transfer\SourceIdentity;
use CartShift\Tests\Unit\PluginTestCase;

final class TransferDependencyGraphTest extends PluginTestCase
{
    public function testValidatesShippedClosureAndReturnsCanonicalTopologicalOrder(): void
    {
        $product = $this->record('product', '9');
        $customer = $this->record('customer', '7');
        $order = $this->record('order', '41', [$product->identity, $customer->identity]);
        $subscription = $this->record('subscription', '5', [$order->identity]);

        $result = (new TransferDependencyGraph())->validate([$subscription, $order, $customer, $product], TransferDecisionSet::empty());

        self::assertTrue($result->closed);
        self::assertSame(['product', 'customer', 'order', 'subscription'], array_map(static fn (RecordEnvelope $r): string => $r->identity->entityType, $result->orderedRecords));
    }

    public function testMissingNodesAndCyclesAreExplicitStops(): void
    {
        $missing = $this->record('order', '41', [new SourceIdentity('shop-alpha', 'customer', '7')]);
        $missingResult = (new TransferDependencyGraph())->validate([$missing], TransferDecisionSet::empty());
        self::assertFalse($missingResult->closed);
        self::assertContains('dependency_missing', $missingResult->reasonCodes);

        $leftIdentity = new SourceIdentity('shop-alpha', 'order', '41');
        $rightIdentity = new SourceIdentity('shop-alpha', 'order', '42');
        $cycle = (new TransferDependencyGraph())->validate([
            $this->record('order', '41', [$rightIdentity]), $this->record('order', '42', [$leftIdentity]),
        ], TransferDecisionSet::empty());
        self::assertFalse($cycle->closed);
        self::assertContains('dependency_cycle', $cycle->reasonCodes);
    }

    public function testDecisionSetRejectsUnknownAndStaleRecordFingerprints(): void
    {
        $record = $this->record('customer', '7');
        $stale = TransferDecisionSet::fromArray([[
            'identity' => $record->identity->canonical(), 'action' => 'excluded_by_policy',
            'source_fingerprint' => str_repeat('0', 64), 'operator' => 'owner', 'reason' => 'reviewed', 'decided_at' => '2026-08-10T12:00:00Z',
        ]]);
        $result = (new TransferDependencyGraph())->validate([$record], $stale);

        self::assertFalse($result->closed);
        self::assertContains('decision_stale', $result->reasonCodes);
        self::assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', $stale->fingerprint());
    }

    public function testDeliberatelyExcludedRootMayBeAbsentWithoutPermittingOtherUnknownDecisions(): void
    {
        $excluded = $this->record('product', '9');
        $excludedDecision = TransferDecisionSet::fromArray([[
            'identity' => $excluded->identity->canonical(),
            'action' => 'excluded_by_policy',
            'source_fingerprint' => $excluded->sourceContentDigest,
            'operator' => 'owner',
            'reason' => 'The source product is trashed and must not enter the package.',
            'decided_at' => '2026-08-11T12:00:00Z',
        ]]);

        $excludedResult = (new TransferDependencyGraph())->validate([], $excludedDecision);

        self::assertTrue($excludedResult->closed);
        self::assertSame([], $excludedResult->orderedRecords);

        $unknownDecision = TransferDecisionSet::fromArray([[
            'identity' => $excluded->identity->canonical(),
            'action' => 'approve_mapping',
            'source_fingerprint' => $excluded->sourceContentDigest,
            'operator' => 'owner',
            'reason' => 'An approved record cannot disappear from the materialised closure.',
            'decided_at' => '2026-08-11T12:00:00Z',
        ]]);

        $unknownResult = (new TransferDependencyGraph())->validate([], $unknownDecision);

        self::assertFalse($unknownResult->closed);
        self::assertContains('decision_unknown_record', $unknownResult->reasonCodes);
    }

    public function testOrderDomainReferencesCannotHideBehindAnEmptyGenericDependencyList(): void
    {
        $order = RecordEnvelope::forPayload(2, new SourceIdentity('shop-alpha', 'order', '41'), [
            'dependencies' => [], 'customer' => 'shop-alpha:customer:7', 'parent_order' => null,
            'product_lines' => [['product' => 'shop-alpha:product:9', 'variation' => 'shop-alpha:product:9:variation:91']],
        ]);
        $result = (new TransferDependencyGraph())->validate([$order], TransferDecisionSet::empty());

        self::assertFalse($result->closed);
        self::assertContains('dependency_missing', $result->reasonCodes);
    }

    public function testReciprocalProductRecommendationsAreClosureEdgesButNotWriteOrderCycles(): void
    {
        $left = RecordEnvelope::forPayload(2, new SourceIdentity('shop-alpha', 'product', '9'), [
            'dependencies' => [],
            'upsell_products' => ['shop-alpha:product:10'],
            'cross_sell_products' => [],
            'taxonomies' => [], 'media' => [], 'downloads' => [],
        ]);
        $right = RecordEnvelope::forPayload(2, new SourceIdentity('shop-alpha', 'product', '10'), [
            'dependencies' => [],
            'upsell_products' => [],
            'cross_sell_products' => ['shop-alpha:product:9'],
            'taxonomies' => [], 'media' => [], 'downloads' => [],
        ]);

        $result = (new TransferDependencyGraph())->validate([$right, $left], TransferDecisionSet::empty());

        self::assertTrue($result->closed);
        self::assertSame(['9', '10'], array_map(static fn (RecordEnvelope $record): string => $record->identity->sourceId, $result->orderedRecords));
    }

    /** @param list<SourceIdentity> $dependencies */
    private function record(string $kind, string $id, array $dependencies = []): RecordEnvelope
    {
        return RecordEnvelope::forPayload(2, new SourceIdentity('shop-alpha', $kind, $id), [
            'dependencies' => array_map(static fn (SourceIdentity $identity): string => $identity->canonical(), $dependencies),
        ]);
    }
}

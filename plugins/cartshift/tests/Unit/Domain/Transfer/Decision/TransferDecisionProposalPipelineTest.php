<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\Domain\Transfer\Decision;

use CartShift\Domain\Transfer\Audit\TransferAuditReport;
use CartShift\Domain\Transfer\Decision\TransferDecisionProposalPipeline;
use CartShift\Domain\Transfer\Decision\TransferDecisionSet;
use CartShift\Domain\Transfer\RecordEnvelope;
use CartShift\Domain\Transfer\SelectionClause;
use CartShift\Domain\Transfer\SourceIdentity;
use CartShift\Domain\Transfer\TransferSelection;
use CartShift\Tests\Unit\PluginTestCase;

final class TransferDecisionProposalPipelineTest extends PluginTestCase
{
    public function testProposalMustPassSecondAuditAndStillRequiresOwnerReview(): void
    {
        $selection = new TransferSelection(
            'shop-alpha', SelectionClause::all(), SelectionClause::none(), SelectionClause::none(), SelectionClause::none(),
        );
        $evidence = str_repeat('e', 64);
        $audit = static function (TransferSelection $selected, TransferDecisionSet $decisions) use ($evidence): TransferAuditReport {
            $decision = $decisions->forAuditFinding('shop-alpha:product:10', 'product_relation_loss_decision_required');
            $ready = ($decision['source_fingerprint'] ?? null) === $evidence;
            return TransferAuditReport::create(
                'shop-alpha', $selected->fingerprint(), str_repeat('r', 64), $ready,
                [], [], $ready ? [] : [[
                    'code' => 'product_relation_loss_decision_required',
                    'identity' => 'shop-alpha:product:10',
                    'context' => [
                        'relation_policy' => 'preserve_provenance',
                        'upsell_count' => 1,
                        'cross_sell_count' => 0,
                        'evidence_fingerprint' => $evidence,
                    ],
                ]],
                $decisions->fingerprint(),
            );
        };
        $identity = new SourceIdentity('shop-alpha', 'product', '10');
        $record = RecordEnvelope::forPayload(1, $identity, [
            'identity' => $identity->canonical(),
            'status' => 'publish',
            'upsell_products' => ['shop-alpha:product:11'],
            'cross_sell_products' => [],
            'password_protected' => false,
            'dependencies' => ['shop-alpha:product:11'],
        ]);
        $pipeline = new TransferDecisionProposalPipeline($audit, static fn (): iterable => [$record]);
        $result = $pipeline->propose($selection, TransferDecisionSet::empty(), 'owner', '2026-08-11T01:00:00Z');

        self::assertSame('owner_review_required', $result['status']);
        self::assertSame(['wordpress' => false, 'filesystem' => false], $result['writes']);
        self::assertSame(1, $result['proposal_counts']['audit_findings']);
        self::assertSame(1, $result['proposal_counts']['records']);
        self::assertSame([], $result['blockers']);
        self::assertCount(2, $result['decision_set']['decisions']);
        TransferDecisionSet::fromArray($result['decision_set']['decisions']);
    }

    public function testUnsupportedAuditFindingStopsBeforeRecordHydration(): void
    {
        $selection = new TransferSelection(
            'shop-alpha', SelectionClause::all(), SelectionClause::none(), SelectionClause::none(), SelectionClause::none(),
        );
        $audit = static fn (TransferSelection $selected, TransferDecisionSet $decisions): TransferAuditReport => TransferAuditReport::create(
            'shop-alpha', $selected->fingerprint(), str_repeat('r', 64), false, [], [], [[
                'code' => 'target_schema_unrepresentable',
                'identity' => 'shop-alpha:product:10',
                'context' => ['field' => 'sku', 'evidence_fingerprint' => str_repeat('e', 64)],
            ]], $decisions->fingerprint(),
        );
        $hydrated = false;
        $pipeline = new TransferDecisionProposalPipeline($audit, static function () use (&$hydrated): iterable {
            $hydrated = true;
            return [];
        });
        $result = $pipeline->propose($selection, TransferDecisionSet::empty(), 'owner', '2026-08-11T01:00:00Z');

        self::assertSame('blocked', $result['status']);
        self::assertFalse($hydrated);
        self::assertNotEmpty(array_filter(
            $result['blockers'],
            static fn (array $blocker): bool => str_contains($blocker['code'], 'requires_manual_resolution'),
        ));
    }
}

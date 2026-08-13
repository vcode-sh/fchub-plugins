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
        $materialisations = 0;
        $pipeline = new TransferDecisionProposalPipeline($audit, static function () use ($record, &$materialisations): iterable {
            $materialisations++;
            return [$record];
        });
        $result = $pipeline->propose($selection, TransferDecisionSet::empty(), 'owner', '2026-08-11T01:00:00Z');

        self::assertSame('owner_review_required', $result['status']);
        self::assertSame(1, $materialisations);
        self::assertSame(['wordpress' => false, 'filesystem' => false], $result['writes']);
        self::assertSame(1, $result['proposal_counts']['audit_findings']);
        self::assertSame(1, $result['proposal_counts']['records']);
        self::assertSame([], $result['blockers']);
        self::assertSame(TransferDecisionSet::empty()->fingerprint(), $result['base_decision_fingerprint']);
        self::assertCount(2, $result['proposal_decisions']);
        self::assertCount(2, $result['decision_set']['decisions']);
        TransferDecisionSet::fromArray($result['decision_set']['decisions']);
    }

    public function testUnrepresentableRecordIsProposedAsAnExplicitSkipBeforeHydration(): void
    {
        $selection = new TransferSelection(
            'shop-alpha', SelectionClause::all(), SelectionClause::none(), SelectionClause::none(), SelectionClause::none(),
        );
        $audit = static function (TransferSelection $selected, TransferDecisionSet $decisions): TransferAuditReport {
            $resolved = $decisions->forAuditFinding(
                'shop-alpha:product:10',
                'target_schema_unrepresentable',
            ) !== null;
            return TransferAuditReport::create(
                'shop-alpha',
                $selected->fingerprint(),
                str_repeat('r', 64),
                $resolved,
                [],
                [],
                $resolved ? [] : [[
                    'code' => 'target_schema_unrepresentable',
                    'identity' => 'shop-alpha:product:10',
                    'context' => ['field' => 'sku', 'evidence_fingerprint' => str_repeat('e', 64)],
                ]],
                $decisions->fingerprint(),
            );
        };
        $hydrated = false;
        $pipeline = new TransferDecisionProposalPipeline($audit, static function () use (&$hydrated): iterable {
            $hydrated = true;
            return [];
        });
        $result = $pipeline->propose($selection, TransferDecisionSet::empty(), 'owner', '2026-08-11T01:00:00Z');

        self::assertSame('owner_review_required', $result['status']);
        self::assertTrue($hydrated);
        self::assertSame([], $result['blockers']);
        self::assertSame('excluded_by_policy', $result['proposal_decisions'][0]['action']);
    }

    public function testStaleAuditApprovalIsReproposedFromTheCurrentUnderlyingFinding(): void
    {
        $selection = new TransferSelection(
            'shop-alpha',
            SelectionClause::none(),
            SelectionClause::none(),
            SelectionClause::all(),
            SelectionClause::none(),
        );
        $oldEvidence = str_repeat('a', 64);
        $currentEvidence = str_repeat('b', 64);
        $existing = TransferDecisionSet::fromArray([
            [
                'identity' => 'shop-alpha:order:42',
                'scope' => 'audit_finding',
                'finding_code' => 'order_note_visibility_decision_required',
                'action' => 'approve_mapping',
                'note_policy' => 'preserve_history_select_canonical',
                'note_count' => 2,
                'customer_visible_note_count' => 0,
                'source_fingerprint' => $oldEvidence,
                'operator' => 'owner',
                'reason' => 'Approved before a runtime-only upgrade.',
                'decided_at' => '2026-08-11T01:00:00Z',
            ],
            [
                'identity' => 'shop-alpha:product:9',
                'scope' => 'audit_finding',
                'finding_code' => 'product_lookup_missing',
                'action' => 'excluded_by_policy',
                'source_fingerprint' => str_repeat('c', 64),
                'operator' => 'owner',
                'reason' => 'Keep this unrelated audit decision.',
                'decided_at' => '2026-08-10T01:00:00Z',
            ],
            [
                'identity' => 'shop-alpha:product:10',
                'action' => 'activate_catalogue',
                'target_status' => 'publish',
                'source_fingerprint' => str_repeat('d', 64),
                'operator' => 'owner',
                'reason' => 'Keep this record decision.',
                'decided_at' => '2026-08-10T01:00:00Z',
            ],
            [
                'identity' => 'shop-alpha:order:91',
                'scope' => 'target_finding',
                'finding_code' => 'source_identity_conflict',
                'action' => 'approve_mapping',
                'target_disposition' => 'create_distinct',
                'candidate_target_id' => 501,
                'target_fingerprint' => str_repeat('e', 64),
                'source_fingerprint' => str_repeat('f', 64),
                'operator' => 'owner',
                'reason' => 'Keep this target decision.',
                'decided_at' => '2026-08-10T01:00:00Z',
            ],
        ]);
        $untouched = array_values(array_filter(
            $existing->rows(),
            static fn (array $row): bool => ($row['finding_code'] ?? null) !== 'order_note_visibility_decision_required',
        ));
        $audit = static function (
            TransferSelection $selected,
            TransferDecisionSet $decisions,
        ) use ($currentEvidence): TransferAuditReport {
            $decision = $decisions->forAuditFinding(
                'shop-alpha:order:42',
                'order_note_visibility_decision_required',
            );
            if (($decision['source_fingerprint'] ?? null) === $currentEvidence) {
                return TransferAuditReport::create(
                    'shop-alpha',
                    $selected->fingerprint(),
                    str_repeat('r', 64),
                    true,
                    [],
                    [],
                    [],
                    $decisions->fingerprint(),
                );
            }
            $finding = $decision === null
                ? [
                    'code' => 'order_note_visibility_decision_required',
                    'identity' => 'shop-alpha:order:42',
                    'context' => [
                        'note_policy' => 'preserve_history_select_canonical',
                        'note_count' => 2,
                        'customer_visible_note_count' => 0,
                        'evidence_fingerprint' => $currentEvidence,
                    ],
                ]
                : [
                    'code' => 'audit_decision_stale',
                    'identity' => 'shop-alpha:order:42',
                    'context' => [
                        'finding_code' => 'order_note_visibility_decision_required',
                        'evidence_fingerprint' => $currentEvidence,
                    ],
                ];

            return TransferAuditReport::create(
                'shop-alpha',
                $selected->fingerprint(),
                str_repeat('r', 64),
                false,
                [],
                [],
                [$finding],
                $decisions->fingerprint(),
            );
        };
        $pipeline = new TransferDecisionProposalPipeline($audit, static fn (): iterable => []);

        $result = $pipeline->propose($selection, $existing, 'owner', '2026-08-12T01:00:00Z');
        $resultSet = TransferDecisionSet::fromArray($result['decision_set']['decisions']);
        $renewed = $resultSet->forAuditFinding(
            'shop-alpha:order:42',
            'order_note_visibility_decision_required',
        );

        self::assertSame('owner_review_required', $result['status']);
        self::assertSame([], $result['blockers']);
        self::assertSame(1, $result['proposal_counts']['renewed_audit_findings']);
        self::assertSame(3, $result['proposal_counts']['retained']);
        self::assertSame(4, $result['proposal_counts']['total']);
        self::assertSame($existing->fingerprint(), $result['base_decision_fingerprint']);
        self::assertSame(
            ['shop-alpha:order:42|order_note_visibility_decision_required'],
            $result['renewed_audit_decisions'],
        );
        self::assertEquals([$renewed], $result['proposal_decisions']);
        self::assertSame($currentEvidence, $renewed['source_fingerprint']);
        self::assertSame('2026-08-12T01:00:00Z', $renewed['decided_at']);
        self::assertSame('order_note_visibility_decision_required', $renewed['finding_code']);
        foreach ($untouched as $row) {
            self::assertContains($row, $resultSet->rows(), 'An unrelated decision changed during audit renewal.');
        }
    }

    public function testStaleRecordDecisionIsRebuiltForReviewWithoutChangingUnrelatedRows(): void
    {
        $selection = new TransferSelection(
            'shop-alpha',
            SelectionClause::none(),
            SelectionClause::none(),
            SelectionClause::all(),
            SelectionClause::none(),
        );
        $identity = new SourceIdentity('shop-alpha', 'order', '42');
        $record = RecordEnvelope::forPayload(2, $identity, [
            'identity' => $identity->canonical(),
            'status' => 'completed',
            'notes' => [[
                'identity' => 'shop-alpha:order_note:7',
                'customer_visible' => false,
                'public_identifier' => 'note-7',
            ]],
            'dependencies' => [],
        ]);
        $existing = TransferDecisionSet::fromArray([
            [
                'identity' => $identity->canonical(),
                'scope' => 'record',
                'action' => 'approve_mapping',
                'canonical_customer_note' => null,
                'note_decision_fingerprint' => str_repeat('a', 64),
                'source_fingerprint' => str_repeat('b', 64),
                'operator' => 'old-owner',
                'reason' => 'Old record evidence.',
                'decided_at' => '2026-08-10T01:00:00Z',
            ],
            [
                'identity' => 'shop-alpha:product:10',
                'scope' => 'record',
                'action' => 'activate_catalogue',
                'target_status' => 'publish',
                'source_fingerprint' => str_repeat('c', 64),
                'operator' => 'old-owner',
                'reason' => 'Keep this record decision.',
                'decided_at' => '2026-08-10T01:00:00Z',
            ],
            [
                'identity' => 'shop-alpha:product:9',
                'scope' => 'audit_finding',
                'finding_code' => 'product_lookup_missing',
                'action' => 'excluded_by_policy',
                'source_fingerprint' => str_repeat('d', 64),
                'operator' => 'old-owner',
                'reason' => 'Keep this audit decision.',
                'decided_at' => '2026-08-10T01:00:00Z',
            ],
            [
                'identity' => 'shop-alpha:order:91',
                'scope' => 'target_finding',
                'finding_code' => 'source_identity_conflict',
                'action' => 'approve_mapping',
                'target_disposition' => 'create_distinct',
                'candidate_target_id' => 501,
                'target_fingerprint' => str_repeat('e', 64),
                'source_fingerprint' => str_repeat('f', 64),
                'operator' => 'old-owner',
                'reason' => 'Keep this target decision.',
                'decided_at' => '2026-08-10T01:00:00Z',
            ],
        ]);
        $untouched = array_values(array_filter(
            $existing->rows(),
            static fn (array $row): bool => $row['identity'] !== $identity->canonical()
                || ($row['scope'] ?? 'record') !== 'record',
        ));
        $audit = static fn (TransferSelection $selected, TransferDecisionSet $decisions): TransferAuditReport =>
            TransferAuditReport::create(
                'shop-alpha',
                $selected->fingerprint(),
                str_repeat('r', 64),
                true,
                [],
                [],
                [],
                $decisions->fingerprint(),
            );
        $materialisations = 0;
        $pipeline = new TransferDecisionProposalPipeline($audit, static function () use ($record, &$materialisations): iterable {
            $materialisations++;
            return [$record];
        });

        $result = $pipeline->propose($selection, $existing, 'new-owner', '2026-08-12T02:00:00Z');
        $resultSet = TransferDecisionSet::fromArray($result['decision_set']['decisions']);
        $renewed = $resultSet->for($identity);

        self::assertSame('owner_review_required', $result['status']);
        self::assertSame(1, $materialisations, 'A renewal must bind every row to one coherent source snapshot.');
        self::assertSame([], $result['blockers']);
        self::assertSame([$identity->canonical()], $result['renewed_record_decisions']);
        self::assertSame(1, $result['proposal_counts']['renewed_records']);
        self::assertSame(3, $result['proposal_counts']['retained']);
        self::assertSame(4, $result['proposal_counts']['total']);
        self::assertEquals([$renewed], $result['proposal_decisions']);
        self::assertSame($record->sourceContentDigest, $renewed['source_fingerprint']);
        self::assertSame('new-owner', $renewed['operator']);
        self::assertSame('2026-08-12T02:00:00Z', $renewed['decided_at']);
        foreach ($untouched as $row) {
            self::assertContains($row, $resultSet->rows(), 'An unrelated decision changed during record renewal.');
        }
    }
}

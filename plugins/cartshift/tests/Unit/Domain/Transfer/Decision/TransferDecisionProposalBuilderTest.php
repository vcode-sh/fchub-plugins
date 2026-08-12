<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\Domain\Transfer\Decision;

use CartShift\Domain\Transfer\Audit\TransferAuditReport;
use CartShift\Domain\Transfer\Decision\TransferDecisionProposalBuilder;
use CartShift\Domain\Transfer\Decision\TransferDecisionSet;
use CartShift\Domain\Transfer\RecordEnvelope;
use CartShift\Domain\Transfer\SourceIdentity;
use CartShift\Tests\Unit\PluginTestCase;

final class TransferDecisionProposalBuilderTest extends PluginTestCase
{
    public function testAuditProposalCopiesExactEvidenceAndRelationCounts(): void
    {
        $builder = new TransferDecisionProposalBuilder();
        $first = $builder->auditFindings($this->report(2, 1, str_repeat('1', 64)), 'owner', '2026-08-11T01:00:00Z');
        $changed = $builder->auditFindings($this->report(3, 1, str_repeat('2', 64)), 'owner', '2026-08-11T01:00:00Z');

        self::assertSame([], $first['blockers']);
        self::assertSame(2, $first['rows'][0]['upsell_count']);
        self::assertSame('preserve_provenance', $first['rows'][0]['relation_policy']);
        self::assertNotSame($first['rows'][0]['source_fingerprint'], $changed['rows'][0]['source_fingerprint']);
        TransferDecisionSet::fromArray($first['rows']);
    }

    public function testMultipleVisibleNotesStopInsteadOfChoosingConveniently(): void
    {
        $order = $this->record('order', '42', [
            'notes' => [
                $this->note('1', true),
                $this->note('2', true),
            ],
        ]);
        $proposal = (new TransferDecisionProposalBuilder())->records(
            [$order],
            TransferDecisionSet::empty(),
            'owner',
            '2026-08-11T01:00:00Z',
        );

        self::assertSame([], $proposal['rows']);
        self::assertSame('record_decision_requires_manual_resolution', $proposal['blockers'][0]['code']);
    }

    public function testOrderWithNoVisibleNoteSelectsNullAndBindsEveryVisibilityFact(): void
    {
        $order = $this->record('order', '42', ['notes' => [$this->note('1', false)]]);
        $proposal = (new TransferDecisionProposalBuilder())->records(
            [$order], TransferDecisionSet::empty(), 'owner', '2026-08-11T01:00:00Z',
        );
        $row = $proposal['rows'][0];

        self::assertNull($row['canonical_customer_note']);
        self::assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/D', $row['note_decision_fingerprint']);
        $changed = $this->record('order', '42', ['notes' => [$this->note('1', true)]]);
        $other = (new TransferDecisionProposalBuilder())->records(
            [$changed], TransferDecisionSet::empty(), 'owner', '2026-08-11T01:00:00Z',
        );
        self::assertNotSame($row['note_decision_fingerprint'], $other['rows'][0]['note_decision_fingerprint']);
    }

    public function testManualAndTerminalSubscriptionsNeverProposeSourceRelease(): void
    {
        $manual = $this->subscription('11', 'active', true);
        $terminal = $this->subscription('12', 'cancelled', false);
        $automatic = $this->subscription('13', 'active', false);
        $proposal = (new TransferDecisionProposalBuilder())->records(
            [$manual, $terminal, $automatic], TransferDecisionSet::empty(), 'owner', '2026-08-11T01:00:00Z',
        );
        $rows = array_column($proposal['rows'], null, 'identity');

        self::assertFalse($rows['shop-alpha:subscription:11']['source_auto_renewal_release_required']);
        self::assertFalse($rows['shop-alpha:subscription:12']['source_auto_renewal_release_required']);
        self::assertTrue($rows['shop-alpha:subscription:13']['source_auto_renewal_release_required']);
    }

    public function testExistingCustomerOwnershipIsRetainedAndStaleDecisionBlocks(): void
    {
        $customer = $this->record('customer', '7', ['dependencies' => []]);
        $existing = TransferDecisionSet::fromArray([[
            'identity' => $customer->identity->canonical(),
            'scope' => 'record',
            'action' => 'attach_exact_same_site_user',
            'user_id' => 7,
            'source_fingerprint' => str_repeat('f', 64),
            'operator' => 'owner',
            'reason' => 'Reviewed exact same-site user.',
            'decided_at' => '2026-08-10T01:00:00Z',
        ]]);
        $proposal = (new TransferDecisionProposalBuilder())->records(
            [$customer], $existing, 'owner', '2026-08-11T01:00:00Z',
        );

        self::assertSame([], $proposal['rows']);
        self::assertSame('existing_record_decision_stale', $proposal['blockers'][0]['code']);
    }

    public function testTrashedProductIsExcludedInsteadOfBeingProposedAsAReusableDraft(): void
    {
        $trash = $this->record('product', '12097', [
            'status' => 'trash',
            'upsell_products' => ['shop-alpha:product:8221'],
            'cross_sell_products' => [],
        ]);
        $draft = $this->record('product', '9467', [
            'status' => 'draft',
            'upsell_products' => [],
            'cross_sell_products' => [],
        ]);
        $proposal = (new TransferDecisionProposalBuilder())->records(
            [$trash, $draft],
            TransferDecisionSet::empty(),
            'owner',
            '2026-08-11T01:00:00Z',
        );
        $rows = array_column($proposal['rows'], null, 'identity');

        self::assertSame('excluded_by_policy', $rows['shop-alpha:product:12097']['action']);
        self::assertArrayNotHasKey('target_status', $rows['shop-alpha:product:12097']);
        self::assertArrayNotHasKey('relation_policy', $rows['shop-alpha:product:12097']);
        self::assertSame('leave_catalogue_draft', $rows['shop-alpha:product:9467']['action']);
        self::assertSame('draft', $rows['shop-alpha:product:9467']['target_status']);
        TransferDecisionSet::fromArray($proposal['rows']);
    }

    private function report(int $upsells, int $crossSells, string $evidence): TransferAuditReport
    {
        return TransferAuditReport::create(
            'shop-alpha', str_repeat('a', 64), str_repeat('b', 64), false, [], [], [[
                'code' => 'product_relation_loss_decision_required',
                'identity' => 'shop-alpha:product:10',
                'context' => [
                    'relation_policy' => 'preserve_provenance',
                    'upsell_count' => $upsells,
                    'cross_sell_count' => $crossSells,
                    'evidence_fingerprint' => $evidence,
                ],
            ]],
        );
    }

    /** @param array<string,mixed> $payload */
    private function record(string $kind, string $id, array $payload): RecordEnvelope
    {
        $identity = new SourceIdentity('shop-alpha', $kind, $id);
        return RecordEnvelope::forPayload(1, $identity, array_merge([
            'identity' => $identity->canonical(),
            'dependencies' => [],
        ], $payload));
    }

    /** @return array<string,mixed> */
    private function note(string $id, bool $visible): array
    {
        return [
            'identity' => 'shop-alpha:order_note:' . $id,
            'source_note_id' => (int) $id,
            'content' => 'Private history ' . $id,
            'created_utc' => '2026-01-01T00:00:00Z',
            'customer_visible' => $visible,
            'author_kind' => 'system',
            'public_identifier' => 'note-' . $id,
        ];
    }

    private function subscription(string $id, string $status, bool $manual): RecordEnvelope
    {
        return $this->record('subscription', $id, [
            'status' => $status,
            'payment_ownership' => [
                'payment_reference_digest' => hash('sha256', 'payment-' . $id),
                'source_gateway' => 'stripe',
                'source_requires_manual_renewal' => $manual,
            ],
        ]);
    }
}

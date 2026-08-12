<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\Domain\Transfer\Decision;

use CartShift\Domain\Transfer\Decision\TransferDecisionSet;
use CartShift\Domain\Transfer\Graph\TransferDependencyGraph;
use CartShift\Domain\Transfer\RecordEnvelope;
use CartShift\Domain\Transfer\SourceIdentity;
use CartShift\Tests\Unit\PluginTestCase;

final class TransferDecisionSetTest extends PluginTestCase
{
    public function testPrivateCanonicalDecisionFileRoundTripsAndChangedOrderingIsRejected(): void
    {
        $path = sys_get_temp_dir() . '/cartshift-decisions-' . bin2hex(random_bytes(8)) . '.json';
        $decisions = TransferDecisionSet::fromArray([[
            'identity' => 'shop-alpha:product:9',
            'action' => 'activate_catalogue',
            'source_fingerprint' => str_repeat('a', 64),
            'target_status' => 'publish',
            'operator' => 'owner',
            'reason' => 'Approved.',
            'decided_at' => '2026-08-10T12:00:00Z',
        ]]);
        file_put_contents($path, $decisions->canonicalJson());
        chmod($path, 0600);
        try {
            self::assertSame($decisions->fingerprint(), TransferDecisionSet::fromFile($path)->fingerprint());
            file_put_contents($path, json_encode(['decisions' => array_values($decisions->decisions)], JSON_THROW_ON_ERROR));
            $this->expectExceptionMessage('Transfer decision file is not canonically serialized.');
            TransferDecisionSet::fromFile($path);
        } finally {
            unlink($path);
        }
    }

    public function testAuditFindingsCanShareARecordIdentityAndDoNotBecomePackageRecordDecisions(): void
    {
        $decisions = TransferDecisionSet::fromArray([
            [
                'scope' => 'audit_finding',
                'identity' => 'shop-alpha:product:9',
                'finding_code' => 'product_lookup_missing',
                'action' => 'excluded_by_policy',
                'source_fingerprint' => str_repeat('a', 64),
                'operator' => 'owner',
                'reason' => 'Reviewed missing lookup evidence.',
                'decided_at' => '2026-08-10T12:00:00Z',
            ],
            [
                'scope' => 'audit_finding',
                'identity' => 'shop-alpha:product:9',
                'finding_code' => 'product_lookup_stale',
                'action' => 'excluded_by_policy',
                'source_fingerprint' => str_repeat('b', 64),
                'operator' => 'owner',
                'reason' => 'Reviewed stale lookup evidence.',
                'decided_at' => '2026-08-10T12:00:00Z',
            ],
        ]);
        $record = RecordEnvelope::forPayload(
            2,
            new SourceIdentity('shop-alpha', 'product', '9'),
            ['dependencies' => []],
        );
        $closure = (new TransferDependencyGraph())->validate([$record], $decisions);

        self::assertCount(2, $decisions->auditFindings());
        self::assertNull($decisions->for($record->identity));
        self::assertTrue($closure->closed);
    }

    public function testAuditFindingRequiresExplicitScopeCodeAndExclusionAction(): void
    {
        $this->expectExceptionMessage('Transfer audit finding decision is incomplete or unsupported.');

        TransferDecisionSet::fromArray([[
            'scope' => 'audit_finding',
            'identity' => 'shop-alpha:product:9',
            'finding_code' => 'product_lookup_stale',
            'action' => 'approve_mapping',
            'source_fingerprint' => str_repeat('a', 64),
            'operator' => 'owner',
            'reason' => 'An audit finding cannot approve a target mapping.',
            'decided_at' => '2026-08-10T12:00:00Z',
        ]]);
    }

    public function testHistoricalScheduleAndSkuFindingDecisionsRequireTypedEvidence(): void
    {
        $decisions = TransferDecisionSet::fromArray([
            [
                'scope' => 'audit_finding',
                'identity' => 'shop-alpha:order:41:item:7',
                'finding_code' => 'historical_product_missing',
                'action' => 'approve_mapping',
                'source_fingerprint' => str_repeat('a', 64),
                'placeholder_identity' => 'shop-alpha:product:99',
                'placeholder_fingerprint' => str_repeat('b', 64),
                'operator' => 'owner',
                'reason' => 'Approved inert historical placeholder.',
                'decided_at' => '2026-08-10T12:00:00Z',
            ],
            [
                'scope' => 'audit_finding',
                'identity' => 'shop-alpha:subscription:51',
                'finding_code' => 'subscription_schedule_absence',
                'action' => 'approve_mapping',
                'source_fingerprint' => str_repeat('c', 64),
                'schedule_policy' => 'preserve_absence',
                'operator' => 'owner',
                'reason' => 'Preserve source-null schedule fields.',
                'decided_at' => '2026-08-10T12:00:00Z',
            ],
            [
                'scope' => 'audit_finding',
                'identity' => 'shop-alpha:product:61',
                'finding_code' => 'target_schema_unrepresentable',
                'action' => 'approve_mapping',
                'source_fingerprint' => str_repeat('d', 64),
                'field' => 'sku',
                'target_sku' => 'REVIEWED-SKU',
                'operator' => 'owner',
                'reason' => 'Approved exact SKU replacement.',
                'decided_at' => '2026-08-10T12:00:00Z',
            ],
        ]);

        self::assertCount(3, $decisions->auditFindings());
    }

    public function testCustomerLinkageDecisionsCarryTheirExactIdentityEvidence(): void
    {
        $decisions = TransferDecisionSet::fromArray([
            [
                'identity' => 'shop-alpha:customer:7',
                'action' => 'attach_exact_same_site_user',
                'user_id' => 7,
                'source_fingerprint' => str_repeat('a', 64),
                'operator' => 'owner',
                'reason' => 'The source and target share this exact WordPress user row.',
                'decided_at' => '2026-08-10T12:00:00Z',
            ],
            [
                'identity' => 'shop-alpha:customer:91:guest',
                'action' => 'allow_unlinked_downloads',
                'affected_orders' => ['shop-alpha:order:91'],
                'downloadable_orders' => [],
                'downloadable_order_count' => 0,
                'source_fingerprint' => str_repeat('b', 64),
                'operator' => 'owner',
                'reason' => 'The guest order remains intentionally unlinked.',
                'decided_at' => '2026-08-10T12:00:00Z',
            ],
            [
                'identity' => 'shop-alpha:customer:8',
                'action' => 'reuse_explicit_target_customer',
                'target_id' => 81,
                'target_fingerprint' => str_repeat('c', 64),
                'source_fingerprint' => str_repeat('d', 64),
                'operator' => 'owner',
                'reason' => 'The exact target customer already owns the same WordPress user.',
                'decided_at' => '2026-08-10T12:00:00Z',
            ],
        ]);

        self::assertCount(3, $decisions->decisions);
    }

    public function testLeaveDraftAcceptanceIsAnExactFingerprintBoundProductDecision(): void
    {
        $decisions = TransferDecisionSet::fromArray([[
            'identity' => 'shop-alpha:product:9',
            'action' => 'leave_catalogue_draft',
            'target_status' => 'draft',
            'source_fingerprint' => str_repeat('a', 64),
            'operator' => 'owner',
            'reason' => 'Keep the reviewed catalogue inert after promotion.',
            'decided_at' => '2026-08-11T10:00:00Z',
        ]]);

        self::assertSame('leave_catalogue_draft', $decisions->for(new SourceIdentity('shop-alpha', 'product', '9'))['action']);

        $this->expectExceptionMessage('Leave-draft decision must approve a product remaining draft exactly.');
        TransferDecisionSet::fromArray([[
            'identity' => 'shop-alpha:order:9',
            'action' => 'leave_catalogue_draft',
            'target_status' => 'draft',
            'source_fingerprint' => str_repeat('b', 64),
            'operator' => 'owner',
            'reason' => 'Wrong kind.',
            'decided_at' => '2026-08-11T10:00:00Z',
        ]]);
    }

    public function testCustomerLinkageDecisionCannotNameAnotherUserOrForeignOrder(): void
    {
        foreach ([
            [
                'identity' => 'shop-alpha:customer:7',
                'action' => 'attach_exact_same_site_user',
                'user_id' => 8,
            ],
            [
                'identity' => 'shop-alpha:customer:91:guest',
                'action' => 'allow_unlinked_downloads',
                'affected_orders' => ['other-shop:order:91'],
                'downloadable_orders' => [],
                'downloadable_order_count' => 0,
            ],
            [
                'identity' => 'shop-alpha:customer:8',
                'action' => 'reuse_explicit_target_customer',
                'target_id' => 81,
            ],
        ] as $invalid) {
            try {
                TransferDecisionSet::fromArray([[
                    ...$invalid,
                    'source_fingerprint' => str_repeat('a', 64),
                    'operator' => 'owner',
                    'reason' => 'Invalid on purpose.',
                    'decided_at' => '2026-08-10T12:00:00Z',
                ]]);
                self::fail('Invalid customer linkage evidence was accepted.');
            } catch (\InvalidArgumentException $exception) {
                self::assertStringContainsString('incomplete', $exception->getMessage());
            }
        }
    }

    public function testTargetFindingDecisionIsFingerprintBoundButNotAPackageRecordDecision(): void
    {
        $decisions = TransferDecisionSet::fromArray([[
            'scope' => 'target_finding',
            'identity' => 'shop-alpha:order:91',
            'finding_code' => 'source_identity_conflict',
            'action' => 'approve_mapping',
            'target_disposition' => 'create_distinct',
            'candidate_target_id' => 501,
            'target_fingerprint' => str_repeat('c', 64),
            'source_fingerprint' => str_repeat('d', 64),
            'operator' => 'owner',
            'reason' => 'The invoice-only candidate is not source ownership.',
            'decided_at' => '2026-08-10T12:00:00Z',
        ]]);
        $record = RecordEnvelope::forPayload(
            2,
            new SourceIdentity('shop-alpha', 'order', '91'),
            ['dependencies' => []],
        );

        self::assertCount(1, $decisions->targetFindings());
        self::assertNull($decisions->for($record->identity));
        self::assertTrue((new TransferDependencyGraph())->validate([$record], $decisions)->closed);
    }
}

<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\Domain\Transfer\Execution;

use CartShift\Domain\Transfer\Decision\TransferDecisionSet;
use CartShift\Domain\Transfer\Execution\LoadedPreparedTargetBaselineProbe;
use CartShift\Domain\Transfer\Identity\TargetOwnershipReport;
use CartShift\Domain\Transfer\RecordEnvelope;
use CartShift\Domain\Transfer\Product\ProductTargetFingerprint;
use CartShift\Domain\Transfer\SourceIdentity;
use CartShift\Support\CanonicalJson;
use CartShift\Tests\Unit\PluginTestCase;

final class LoadedPreparedTargetBaselineProbeTest extends PluginTestCase
{
    public function testExactCollisionDecisionAndExplicitCustomerTargetAreRevalidatedAndSealed(): void
    {
        $order = $this->record('order', '9');
        $customer = $this->record('customer', '7');
        $finding = [
            'code' => 'source_identity_conflict',
            'identity' => $order->identity->canonical(),
            'context' => ['entity_type' => 'order', 'target_id' => 91, 'match_reason' => 'invoice_only'],
        ];
        $report = $this->report([$finding]);
        $orderSnapshot = ['order' => ['id' => 91, 'invoice_no' => 'WC-9']];
        $customerSnapshot = ['customer' => ['id' => 71], 'addresses' => []];
        $decisions = TransferDecisionSet::fromArray([
            [
                'scope' => 'target_finding',
                'identity' => $order->identity->canonical(),
                'finding_code' => 'source_identity_conflict',
                'action' => 'approve_mapping',
                'target_disposition' => 'create_distinct',
                'candidate_target_id' => 91,
                'target_fingerprint' => CanonicalJson::fingerprint($orderSnapshot),
                'source_fingerprint' => CanonicalJson::fingerprint([
                    'target_report_fingerprint' => $report->fingerprint,
                    'finding' => $finding,
                ]),
                'operator' => 'owner',
                'reason' => 'The invoice-only row is unrelated, so create a distinct identity.',
                'decided_at' => '2026-08-11T10:00:00Z',
            ],
            [
                'identity' => $customer->identity->canonical(),
                'action' => 'reuse_explicit_target_customer',
                'target_id' => 71,
                'target_fingerprint' => CanonicalJson::fingerprint($customerSnapshot),
                'source_fingerprint' => $customer->privateContentDigest,
                'operator' => 'owner',
                'reason' => 'Exact existing customer.',
                'decided_at' => '2026-08-11T10:00:00Z',
            ],
        ]);
        $probe = new LoadedPreparedTargetBaselineProbe(
            static fn (string $sourceKey): TargetOwnershipReport => $report,
            static fn (string $sourceKey, string $runId): array => [
                'maps' => [['identity' => 'shop-alpha:product:2', 'target_id' => 22]],
                'claims' => [],
                'shared_links' => [],
            ],
            static fn (string $kind, int $targetId): array => match ([$kind, $targetId]) {
                ['order', 91] => $orderSnapshot,
                ['customer', 71] => $customerSnapshot,
                default => [],
            },
        );

        $baseline = $probe->capture('shop-alpha', [$customer, $order], $decisions, 'run-target-22');

        self::assertSame([], $baseline->blockingFindings);
        self::assertSame(91, $baseline->snapshot['protected_targets']['shop-alpha:order:9|source_identity_conflict']['target_id']);
        self::assertSame(71, $baseline->snapshot['protected_targets']['shop-alpha:customer:7|reuse_explicit_target_customer']['target_id']);
        $probe->verify($baseline, 'run-target-22');
        self::assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', $baseline->fingerprint());
    }

    public function testUnresolvedLegacyFindingAndStaleTargetFingerprintRemainHardBlocks(): void
    {
        $order = $this->record('order', '9');
        $legacy = ['code' => 'legacy_mapping_requires_audit', 'identity' => $order->identity->canonical(), 'context' => ['entity_type' => 'order']];
        $report = $this->report([$legacy]);
        $probe = new LoadedPreparedTargetBaselineProbe(
            static fn (string $sourceKey): TargetOwnershipReport => $report,
            static fn (string $sourceKey, string $runId): array => ['maps' => [], 'claims' => [], 'shared_links' => []],
            static fn (string $kind, int $targetId): array => [],
        );

        $baseline = $probe->capture('shop-alpha', [$order], TransferDecisionSet::empty(), 'run-target-22');

        self::assertSame(['legacy_mapping_requires_audit:shop-alpha:order:9'], $baseline->blockingFindings);
    }

    public function testTargetFindingsOutsideTheSealedSelectionDoNotBlockSelectedRecords(): void
    {
        $selected = $this->record('order', '9');
        $selectedFinding = [
            'code' => 'source_identity_conflict',
            'identity' => $selected->identity->canonical(),
            'context' => ['entity_type' => 'order', 'target_id' => 91, 'match_reason' => 'invoice_only'],
        ];
        $excludedFinding = [
            'code' => 'source_identity_conflict',
            'identity' => 'shop-alpha:order:10',
            'context' => ['entity_type' => 'order', 'target_id' => 92, 'match_reason' => 'invoice_only'],
        ];
        $report = $this->report([$selectedFinding, $excludedFinding]);
        $selectedSnapshot = ['order' => ['id' => 91, 'invoice_no' => 'WC-9']];
        $decisions = TransferDecisionSet::fromArray([[
            'scope' => 'target_finding',
            'identity' => $selected->identity->canonical(),
            'finding_code' => 'source_identity_conflict',
            'action' => 'approve_mapping',
            'target_disposition' => 'create_distinct',
            'candidate_target_id' => 91,
            'target_fingerprint' => CanonicalJson::fingerprint($selectedSnapshot),
            'source_fingerprint' => CanonicalJson::fingerprint([
                'target_report_fingerprint' => $report->fingerprint,
                'finding' => $selectedFinding,
            ]),
            'operator' => 'owner',
            'reason' => 'Preserve the selected collision and create a distinct target order.',
            'decided_at' => '2026-08-12T01:00:00Z',
        ]]);
        $probe = new LoadedPreparedTargetBaselineProbe(
            static fn (string $sourceKey): TargetOwnershipReport => $report,
            static fn (string $sourceKey, string $runId): array => ['maps' => [], 'claims' => [], 'shared_links' => []],
            static fn (string $kind, int $targetId): array => $targetId === 91 ? $selectedSnapshot : [],
        );

        $baseline = $probe->capture('shop-alpha', [$selected], $decisions, 'run-target-22');

        self::assertSame([], $baseline->blockingFindings);
        self::assertSame(
            ['shop-alpha:order:9|source_identity_conflict'],
            array_keys($baseline->snapshot['protected_targets']),
        );
    }

    public function testVerifyRejectsPreexistingRowOrProtectedTargetDriftButIgnoresRunOwnedRowsAtReaderBoundary(): void
    {
        $customer = $this->record('customer', '7');
        $target = ['customer' => ['id' => 71, 'status' => 'active'], 'addresses' => []];
        $decisions = TransferDecisionSet::fromArray([[
            'identity' => $customer->identity->canonical(),
            'action' => 'reuse_explicit_target_customer',
            'target_id' => 71,
            'target_fingerprint' => CanonicalJson::fingerprint($target),
            'source_fingerprint' => $customer->privateContentDigest,
            'operator' => 'owner',
            'reason' => 'Exact existing customer.',
            'decided_at' => '2026-08-11T10:00:00Z',
        ]]);
        $rows = ['maps' => [], 'claims' => [], 'shared_links' => []];
        $currentTarget = $target;
        $probe = new LoadedPreparedTargetBaselineProbe(
            fn (string $sourceKey): TargetOwnershipReport => $this->report([]),
            static function (string $sourceKey, string $runId) use (&$rows): array { return $rows; },
            static function (string $kind, int $targetId) use (&$currentTarget): array { return $currentTarget; },
        );
        $baseline = $probe->capture('shop-alpha', [$customer], $decisions, 'run-target-22');

        $rows['maps'][] = ['identity' => 'shop-alpha:order:8', 'target_id' => 80];
        try {
            $probe->verify($baseline, 'run-target-22');
            self::fail('A new pre-existing mapping was ignored.');
        } catch (\RuntimeException $exception) {
            self::assertSame('target_baseline_preexisting_rows_changed', $exception->getMessage());
        }
        $rows = ['maps' => [], 'claims' => [], 'shared_links' => []];
        $currentTarget['customer']['status'] = 'inactive';
        $this->expectExceptionMessage('target_baseline_protected_target_changed:shop-alpha:customer:7|reuse_explicit_target_customer');
        $probe->verify($baseline, 'run-target-22');
    }

    public function testApprovedExistingProductIsProtectedFromReviewToExecution(): void
    {
        $product = $this->record('product', '9');
        $variation = 'shop-alpha:product:9:variation:1';
        $target = [
            'product' => ['ID' => 501, 'post_title' => 'Existing product'],
            'detail' => ['post_id' => 501],
            'variations' => [['id' => 901, 'post_id' => 501, 'sku' => 'EXISTING-1']],
            'taxonomies' => [],
            'taxonomy_rows' => [],
            'media' => [],
            'downloads' => [],
        ];
        $sourceMap = [$product->identity->canonical() => 501, $variation => 901];
        $decisions = TransferDecisionSet::fromArray([[
            'identity' => $product->identity->canonical(),
            'action' => 'link_existing_product',
            'target_product_id' => 501,
            'target_fingerprint' => (new ProductTargetFingerprint())->fingerprint($target, $sourceMap),
            'variation_links' => [[
                'source_variation' => $variation,
                'target_variation_id' => 901,
                'source_fingerprint' => str_repeat('b', 64),
                'target_fingerprint' => CanonicalJson::fingerprint($target['variations'][0]),
            ]],
            'source_fingerprint' => $product->sourceContentDigest,
            'operator' => 'owner',
            'reason' => 'Use the reviewed existing product.',
            'decided_at' => '2026-08-12T21:00:00Z',
        ]]);
        $currentTarget = $target;
        $probe = new LoadedPreparedTargetBaselineProbe(
            fn (string $sourceKey): TargetOwnershipReport => $this->report([]),
            static fn (): array => ['maps' => [], 'claims' => [], 'shared_links' => []],
            static function (string $kind, int $targetId) use (&$currentTarget): array { return $currentTarget; },
        );

        $baseline = $probe->capture('shop-alpha', [$product], $decisions, 'run-target-22');

        self::assertSame(501, $baseline->snapshot['protected_targets']['shop-alpha:product:9|link_existing_product']['target_id']);
        $currentTarget['product']['post_title'] = 'Changed after review';
        $this->expectExceptionMessage('target_baseline_protected_target_changed:shop-alpha:product:9|link_existing_product');
        $probe->verify($baseline, 'run-target-22');
    }

    private function record(string $kind, string $id): RecordEnvelope
    {
        $identity = new SourceIdentity('shop-alpha', $kind, $id);
        return RecordEnvelope::forPayload(1, $identity, ['identity' => $identity->canonical(), 'dependencies' => []]);
    }

    /** @param list<array{code:string,identity:string,context:array<string,mixed>}> $blockers */
    private function report(array $blockers): TargetOwnershipReport
    {
        $document = [
            'source_key' => 'shop-alpha',
            'mapping_counts_by_entity' => [],
            'legacy_mapping_counts' => [],
            'missing_target_counts' => [],
            'duplicate_target_ownership_counts' => [],
            'invoice_collision_count' => count(array_filter($blockers, static fn (array $item): bool => $item['code'] === 'source_identity_conflict')),
            'unfingerprinted_mapping_count' => 0,
            'receipt_coverage_count' => 0,
            'blockers' => $blockers,
        ];
        return new TargetOwnershipReport(
            'shop-alpha', [], [], [], [], $document['invoice_collision_count'], 0, 0, 0,
            $blockers, TargetOwnershipReport::fingerprint($document),
        );
    }
}

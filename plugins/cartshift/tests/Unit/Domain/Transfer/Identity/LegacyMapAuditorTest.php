<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\Domain\Transfer\Identity;

use CartShift\Domain\Transfer\Identity\LegacyMapAuditor;
use CartShift\Domain\Transfer\Identity\LinkDecision;
use CartShift\Domain\Transfer\SourceIdentity;
use CartShift\Tests\Unit\PluginTestCase;

final class LegacyMapAuditorTest extends PluginTestCase
{
    public function testWebInvoiceCollisionsDoNotBecomeWebOwnership(): void
    {
        $report = $this->auditor([
            'mappings' => [$this->mapping('lapka-klub', 'order', '42', 900)],
            'source_order_ids' => ['42'],
            'invoice_orders' => [['source_id' => '42', 'target_id' => 900]],
        ])->inspect('lapka-web');

        self::assertSame(1, $report->invoiceCollisionCount);
        self::assertSame(0, $report->ownedOrderCount);
        self::assertContains('source_identity_conflict', $report->reasonCodes());
        self::assertSame([], $report->mappingCountsByEntity);
    }

    public function testLegacyAndMissingTargetRowsAreReportedWithoutMutation(): void
    {
        $snapshot = [
            'mappings' => [$this->mapping('lapka-web', 'product', '7', 70, state: 'legacy', targetExists: false)],
        ];
        $before = serialize($snapshot);
        $report = $this->auditor($snapshot)->inspect('lapka-web');

        self::assertSame(['product' => 1], $report->legacyMappingCounts);
        self::assertSame(['product' => 1], $report->missingTargetCounts);
        self::assertSame(1, $report->unfingerprintedMappingCount);
        self::assertContains('legacy_mapping_requires_audit', $report->reasonCodes());
        self::assertContains('mapped_target_missing', $report->reasonCodes());
        self::assertSame($before, serialize($snapshot));
        self::assertSame([], array_filter(
            $GLOBALS['_cartshift_test_queries'] ?? [],
            static fn (array $query): bool => in_array($query[0] ?? '', ['insert', 'update', 'delete', 'query'], true),
        ));
    }

    public function testExclusiveOrderSharingIsAlwaysBlockedEvenWithSharedLinkRows(): void
    {
        $target = str_repeat('b', 64);
        $report = $this->auditor([
            'mappings' => [
                $this->mapping('lapka-web', 'order', '42', 900),
                $this->mapping('lapka-klub', 'order', '84', 900),
            ],
            'shared_links' => [
                $this->sharedLink('lapka-web', 'order', '42', 900, $target),
                $this->sharedLink('lapka-klub', 'order', '84', 900, $target),
            ],
        ])->inspect('lapka-web');

        self::assertSame(['order' => 1], $report->duplicateTargetOwnershipCounts);
        self::assertContains('duplicate_target_ownership', $report->reasonCodes());
    }

    public function testReviewedProductLinksPermitIntentionalSharingButOneInvalidLinkDoesNot(): void
    {
        $target = str_repeat('b', 64);
        $mappings = [
            $this->mapping('lapka-web', 'product', '42', 900),
            $this->mapping('lapka-klub', 'product', '84', 900),
        ];
        $validLinks = [
            $this->sharedLink('lapka-web', 'product', '42', 900, $target),
            $this->sharedLink('lapka-klub', 'product', '84', 900, $target),
        ];

        $approved = $this->auditor(['mappings' => $mappings, 'shared_links' => $validLinks])->inspect('lapka-web');
        self::assertSame([], $approved->duplicateTargetOwnershipCounts);

        $validLinks[1]['decision_fingerprint'] = 'invalid';
        $blocked = $this->auditor(['mappings' => $mappings, 'shared_links' => $validLinks])->inspect('lapka-web');
        self::assertSame(['product' => 1], $blocked->duplicateTargetOwnershipCounts);
    }

    public function testReceiptCoverageRequiresEveryIdentityTargetStateAndFingerprintToMatch(): void
    {
        $mapping = $this->mapping('lapka-web', 'subscription', '55', 505);
        $claim = $this->claimFor($mapping);
        $receipt = [
            'source_key' => 'lapka-web',
            'source_id' => '55',
            'target_id' => 505,
            'state' => 'promoted',
            'source_fingerprint' => str_repeat('a', 64),
            'target_fingerprint' => str_repeat('b', 64),
        ];
        $covered = $this->auditor([
            'mappings' => [$mapping],
            'claims' => [$claim],
            'receipts' => [$receipt],
        ])->inspect('lapka-web');
        self::assertSame(1, $covered->receiptCoverageCount);
        self::assertNotContains('subscription_receipt_coverage_missing', $covered->reasonCodes());

        $receipt['target_fingerprint'] = str_repeat('c', 64);
        $missing = $this->auditor([
            'mappings' => [$mapping],
            'claims' => [$claim],
            'receipts' => [$receipt],
        ])->inspect('lapka-web');
        self::assertSame(0, $missing->receiptCoverageCount);
        self::assertContains('subscription_receipt_coverage_missing', $missing->reasonCodes());
    }

    public function testLinkDecisionIsCanonicalAndRejectsOrdersOrChangedFacts(): void
    {
        $source = new SourceIdentity('lapka-web', 'product', '42');
        $sourceFingerprint = str_repeat('a', 64);
        $targetFingerprint = str_repeat('b', 64);
        $approvedAt = '2026-08-10T12:00:00Z';
        $fingerprint = LinkDecision::fingerprint($source, $sourceFingerprint, 900, $targetFingerprint, $approvedAt);
        $decision = new LinkDecision(
            $source,
            $sourceFingerprint,
            900,
            $targetFingerprint,
            $fingerprint,
            $approvedAt,
        );
        self::assertSame($fingerprint, $decision->decisionFingerprint);

        foreach ([
            fn () => new LinkDecision(new SourceIdentity('lapka-web', 'order', '42'), $sourceFingerprint, 900, $targetFingerprint, $fingerprint, $approvedAt),
            fn () => new LinkDecision($source, $sourceFingerprint, 901, $targetFingerprint, $fingerprint, $approvedAt),
        ] as $invalid) {
            try {
                $invalid();
                self::fail('Invalid link decision was accepted.');
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testDuplicateGroupsOwnedOnlyByOtherSourcesDoNotPolluteThisReport(): void
    {
        $report = $this->auditor(['mappings' => [
            $this->mapping('lapka-klub', 'order', '10', 900),
            $this->mapping('another-source', 'order', '20', 900),
        ]])->inspect('lapka-web');

        self::assertSame([], $report->duplicateTargetOwnershipCounts);
        self::assertNotContains('duplicate_target_ownership', $report->reasonCodes());
    }

    public function testAnyLoadedInspectionReadErrorStopsImmediately(): void
    {
        $GLOBALS['_cartshift_test_db_error_callback'] = static fn (string $query): string =>
            str_contains($query, 'FROM wp_cartshift_id_map') ? 'injected read failure' : '';
        $this->expectExceptionMessage('Target ownership inspection failed.');

        (new LegacyMapAuditor())->inspect('lapka-web');
    }

    public function testLoadedInspectionDoesNotQueryAnAbsentHposTableWhenCptStorageIsAuthoritative(): void
    {
        $GLOBALS['_cartshift_test_options']['woocommerce_custom_orders_table_enabled'] = 'no';
        $GLOBALS['_cartshift_test_get_col_callback'] = static fn (string $query): array =>
            str_contains($query, 'wp_posts') ? [42] : [];

        (new LegacyMapAuditor())->inspect('lapka-web');

        $columnQueries = array_values(array_map(
            static fn (array $query): string => (string) ($query[1] ?? ''),
            array_filter(
                $GLOBALS['_cartshift_test_queries'],
                static fn (array $query): bool => ($query[0] ?? null) === 'get_col',
            ),
        ));
        self::assertContains(
            "SELECT ID FROM wp_posts WHERE post_type = 'shop_order' ORDER BY ID",
            $columnQueries,
        );
        self::assertSame([], array_values(array_filter(
            $columnQueries,
            static fn (string $query): bool => str_contains($query, 'wp_wc_orders'),
        )));
    }

    public function testLoadedV7InspectionDefaultsMapsToLegacyWithoutReadingV8TablesOrColumns(): void
    {
        $GLOBALS['_cartshift_test_options']['woocommerce_custom_orders_table_enabled'] = 'yes';
        $GLOBALS['_cartshift_test_get_var_callback'] = static fn (string $query): string|int =>
            str_contains($query, 'FROM wp_posts') ? 900 : 0;
        $GLOBALS['_cartshift_test_get_results_callback'] = static fn (string $query): array =>
            str_contains($query, 'FROM wp_cartshift_id_map')
                ? [[
                    'source_key' => 'lapka-web',
                    'entity_type' => 'product',
                    'wc_id' => '42',
                    'fc_id' => 900,
                    'migration_id' => 'legacy-run',
                    'created_by_migration' => 1,
                    'is_simulated' => 0,
                ]]
                : [];

        $report = (new LegacyMapAuditor())->inspect('lapka-web');
        $queries = array_map(static fn (array $entry): string => (string) ($entry[1] ?? ''), $GLOBALS['_cartshift_test_queries']);

        self::assertSame(['product' => 1], $report->legacyMappingCounts);
        self::assertSame(1, $report->unfingerprintedMappingCount);
        self::assertContains('legacy_mapping_requires_audit', $report->reasonCodes());
        self::assertSame([], $report->missingTargetCounts);
        self::assertNotEmpty(array_filter(
            $queries,
            static fn (string $query): bool => str_contains($query, 'FROM wp_posts')
                && str_contains($query, "post_type = 'fluent-products'"),
        ));
        self::assertSame([], array_values(array_filter(
            $queries,
            static fn (string $query): bool => str_contains($query, 'cartshift_shared_links')
                || str_contains($query, 'cartshift_target_claims')
                || str_contains($query, 'fct_products')
                || (str_contains($query, 'FROM wp_cartshift_id_map') && str_contains($query, 'source_fingerprint')),
        )));
    }

    /** @param array<string, mixed> $snapshot */
    private function auditor(array $snapshot): LegacyMapAuditor
    {
        return new LegacyMapAuditor(static fn (): array => $snapshot + [
            'mappings' => [],
            'claims' => [],
            'shared_links' => [],
            'source_order_ids' => [],
            'invoice_orders' => [],
            'receipts' => [],
        ]);
    }

    /** @return array<string, mixed> */
    private function mapping(
        string $sourceKey,
        string $entity,
        string $sourceId,
        int $targetId,
        string $state = 'promoted',
        ?bool $targetExists = true,
    ): array {
        return [
            'source_key' => $sourceKey,
            'entity_type' => $entity,
            'wc_id' => $sourceId,
            'fc_id' => $targetId,
            'is_simulated' => 0,
            'source_fingerprint' => $state === 'legacy' ? null : str_repeat('a', 64),
            'target_fingerprint' => $state === 'legacy' ? null : str_repeat('b', 64),
            'record_state' => $state,
            'target_exists' => $targetExists,
        ];
    }

    /** @return array<string, mixed> */
    private function sharedLink(string $sourceKey, string $entity, string $sourceId, int $targetId, string $targetFingerprint): array
    {
        return [
            'source_key' => $sourceKey,
            'entity_type' => $entity,
            'source_id' => $sourceId,
            'target_id' => $targetId,
            'target_fingerprint' => $targetFingerprint,
            'decision_fingerprint' => str_repeat('d', 64),
        ];
    }

    /** @return array<string, mixed> */
    private function claimFor(array $mapping): array
    {
        return [
            'source_key' => $mapping['source_key'],
            'entity_type' => $mapping['entity_type'],
            'source_id' => $mapping['wc_id'],
            'target_id' => $mapping['fc_id'],
            'source_fingerprint' => $mapping['source_fingerprint'],
            'target_fingerprint' => $mapping['target_fingerprint'],
            'claim_state' => $mapping['record_state'],
        ];
    }
}

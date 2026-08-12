<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\Domain\Transfer\Audit;

use CartShift\Domain\Transfer\Audit\AuditRenderer;
use CartShift\Domain\Transfer\Audit\SourceInventoryInspector;
use CartShift\Domain\Transfer\Audit\SourceInventoryReport;
use CartShift\Domain\Transfer\Audit\SourceRecordContractInspector;
use CartShift\Domain\Transfer\Audit\SourceRecordContractReport;
use CartShift\Domain\Transfer\Audit\TransferAuditor;
use CartShift\Domain\Transfer\Decision\TransferDecisionSet;
use CartShift\Domain\Transfer\Runtime\TransferRuntimeInspector;
use CartShift\Domain\Transfer\Runtime\TransferRuntimeReport;
use CartShift\Domain\Transfer\SelectionClause;
use CartShift\Domain\Transfer\TransferSelection;
use CartShift\Tests\Unit\PluginTestCase;

final class TransferAuditorTest extends PluginTestCase
{
    public function testAuditNeverConstructsMigrationStateOrIdMapRepository(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 5) . '/app/Domain/Transfer/Audit/TransferAuditor.php',
        );

        self::assertIsString($source);
        self::assertStringNotContainsString('MigrationState', $source);
        self::assertStringNotContainsString('IdMapRepository', $source);
        self::assertStringNotContainsString('PreflightCheck', $source);
    }

    public function testRuntimeFailureStopsBeforeSourceInventoryReads(): void
    {
        $runtime = new FakeRuntimeInspector(['source_woocommerce_api_missing']);
        $inventory = new FakeSourceInventoryInspector($this->inventoryReport());
        $report = (new TransferAuditor($runtime, $inventory))->audit($this->selection());

        self::assertFalse($report->ready);
        self::assertSame(0, $inventory->calls);
        self::assertSame(['runtime_contract_mismatch'], array_column($report->blockers, 'code'));
    }

    public function testReadyAuditBindsRuntimeSelectionAndInventoryFingerprints(): void
    {
        $inventory = new FakeSourceInventoryInspector($this->inventoryReport());
        $auditor = new TransferAuditor(new FakeRuntimeInspector(), $inventory);
        $left = $auditor->audit($this->selection());
        $same = $auditor->audit($this->selection());

        self::assertTrue($left->ready);
        self::assertSame($left->auditFingerprint, $same->auditFingerprint);
        self::assertSame($this->selection()->fingerprint(), $left->selectionFingerprint);
        self::assertSame('runtime-fingerprint', $left->runtimeFingerprint);
        self::assertSame(2, $inventory->calls);
    }

    public function testBlockedInventoryRendersEveryStableReasonAndNoSensitiveContext(): void
    {
        $inventory = SourceInventoryReport::create(
            'lapka-web',
            $this->selection()->fingerprint(),
            'runtime-fingerprint',
            ['product_duplicates' => 0, 'products_unaccounted' => 0],
            ['product_types' => ['course' => 1]],
            [
                [
                    'code' => 'unsupported_product_type',
                    'identity' => 'lapka-web:product:7',
                    'context' => [
                        'source_id' => 7,
                        'type' => 'course',
                        'email' => 'must-not-render@example.test',
                        'file_url' => 'https://private.example.test/file',
                    ],
                ],
                [
                    'code' => 'asset_missing',
                    'identity' => 'lapka-web:media_asset:7:featured',
                    'context' => ['source_id' => 7],
                ],
            ],
        );
        $report = (new TransferAuditor(
            new FakeRuntimeInspector(),
            new FakeSourceInventoryInspector($inventory),
        ))->audit($this->selection());
        $json = AuditRenderer::json($report);

        self::assertFalse($report->ready);
        self::assertSame(
            ['asset_missing', 'unsupported_product_type'],
            array_column($report->blockers, 'code'),
        );
        self::assertStringNotContainsString('must-not-render', $json);
        self::assertStringNotContainsString('private.example', $json);
        self::assertStringContainsString('unsupported_product_type', $json);
        self::assertStringContainsString('asset_missing', $json);
    }

    public function testExactAuditFindingDecisionResolvesOnlyItsBoundFinding(): void
    {
        $inventory = SourceInventoryReport::create(
            'lapka-web',
            $this->selection()->fingerprint(),
            'runtime-fingerprint',
            ['product_duplicates' => 0, 'products_unaccounted' => 0],
            ['lookup_integrity' => ['stale' => 6]],
            [[
                'code' => 'product_lookup_stale',
                'identity' => 'lapka-web:product:1:lookup:stale',
                'context' => ['count' => 6],
            ]],
        );
        $auditor = new TransferAuditor(
            new FakeRuntimeInspector(),
            new FakeSourceInventoryInspector($inventory),
        );
        $first = $auditor->audit($this->selection());
        $fingerprint = $first->blockers[0]['context']['evidence_fingerprint'] ?? null;

        self::assertIsString($fingerprint);
        self::assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', $fingerprint);

        $decision = TransferDecisionSet::fromArray([[
            'scope' => 'audit_finding',
            'identity' => 'lapka-web:product:1:lookup:stale',
            'finding_code' => 'product_lookup_stale',
            'action' => 'excluded_by_policy',
            'source_fingerprint' => $fingerprint,
            'operator' => 'owner',
            'reason' => 'Stale lookup-only rows are outside the source catalogue.',
            'decided_at' => '2026-08-10T12:00:00Z',
        ]]);
        $resolved = $auditor->audit($this->selection(), $decision);

        self::assertTrue($resolved->ready);
        self::assertSame([], $resolved->blockers);
    }

    public function testStaleAndInventedAuditFindingDecisionsRemainBlocking(): void
    {
        $inventory = SourceInventoryReport::create(
            'lapka-web',
            $this->selection()->fingerprint(),
            'runtime-fingerprint',
            ['product_duplicates' => 0, 'products_unaccounted' => 0],
            ['lookup_integrity' => ['stale' => 6]],
            [[
                'code' => 'product_lookup_stale',
                'identity' => 'lapka-web:product:1:lookup:stale',
                'context' => ['count' => 6],
            ]],
        );
        $decisions = TransferDecisionSet::fromArray([
            [
                'scope' => 'audit_finding',
                'identity' => 'lapka-web:product:1:lookup:stale',
                'finding_code' => 'product_lookup_stale',
                'action' => 'excluded_by_policy',
                'source_fingerprint' => str_repeat('0', 64),
                'operator' => 'owner',
                'reason' => 'Stale evidence must fail.',
                'decided_at' => '2026-08-10T12:00:00Z',
            ],
            [
                'scope' => 'audit_finding',
                'identity' => 'lapka-web:order:999:item:501',
                'finding_code' => 'order_item_parent_missing',
                'action' => 'excluded_by_policy',
                'source_fingerprint' => str_repeat('1', 64),
                'operator' => 'owner',
                'reason' => 'This finding is not present.',
                'decided_at' => '2026-08-10T12:00:00Z',
            ],
        ]);
        $report = (new TransferAuditor(
            new FakeRuntimeInspector(),
            new FakeSourceInventoryInspector($inventory),
        ))->audit($this->selection(), $decisions);

        self::assertFalse($report->ready);
        self::assertSame(
            ['audit_decision_stale', 'audit_decision_unknown_finding'],
            array_column($report->blockers, 'code'),
        );
    }

    public function testResolvedRecordFindingsReconcileBlockedExportedAndExcludedCounts(): void
    {
        $inventory = SourceInventoryReport::create(
            'lapka-web',
            $this->selection()->fingerprint(),
            'runtime-fingerprint',
            [
                'product_duplicates' => 0,
                'products_unaccounted' => 0,
                'products_exported' => 1,
                'products_excluded' => 0,
                'products_blocked' => 2,
                'orders_exported' => 8,
                'orders_excluded' => 0,
                'orders_blocked' => 2,
            ],
            ['product_types' => ['course' => 1]],
            [
                ['code' => 'unsupported_product_type', 'identity' => 'lapka-web:product:7', 'context' => ['type' => 'course']],
                ['code' => 'target_schema_unrepresentable', 'identity' => 'lapka-web:product:8', 'context' => ['field' => 'sku', 'source_sku_fingerprint' => str_repeat('8', 64), 'sku_length' => 31, 'target_limit' => 30]],
                ['code' => 'unsupported_product_dependency', 'identity' => 'lapka-web:order:17', 'context' => ['product_types' => 'course']],
                ['code' => 'historical_product_missing', 'identity' => 'lapka-web:order:18:item:2', 'context' => [
                    'product_id' => 99,
                    'placeholder_identity' => 'lapka-web:product:99',
                    'placeholder_fingerprint' => str_repeat('9', 64),
                    'placeholder_ready' => true,
                ]],
            ],
        );
        $first = (new TransferAuditor(
            new FakeRuntimeInspector(),
            new FakeSourceInventoryInspector($inventory),
        ))->audit($this->selection());
        $fingerprints = [];
        foreach ($first->blockers as $blocker) {
            $fingerprints[$blocker['identity'] . '|' . $blocker['code']] = $blocker['context']['evidence_fingerprint'];
        }
        $base = static fn (string $identity, string $code, string $action, string $fingerprint): array => [
            'scope' => 'audit_finding',
            'identity' => $identity,
            'finding_code' => $code,
            'action' => $action,
            'source_fingerprint' => $fingerprint,
            'operator' => 'owner',
            'reason' => 'Reviewed.',
            'decided_at' => '2026-08-10T12:00:00Z',
        ];
        $decisions = TransferDecisionSet::fromArray([
            $base('lapka-web:product:7', 'unsupported_product_type', 'excluded_by_policy', $fingerprints['lapka-web:product:7|unsupported_product_type']),
            $base('lapka-web:product:8', 'target_schema_unrepresentable', 'approve_mapping', $fingerprints['lapka-web:product:8|target_schema_unrepresentable']) + ['field' => 'sku', 'target_sku' => 'SHORT-SKU'],
            $base('lapka-web:order:17', 'unsupported_product_dependency', 'excluded_by_policy', $fingerprints['lapka-web:order:17|unsupported_product_dependency']),
            $base('lapka-web:order:18:item:2', 'historical_product_missing', 'approve_mapping', $fingerprints['lapka-web:order:18:item:2|historical_product_missing']) + [
                'placeholder_identity' => 'lapka-web:product:99',
                'placeholder_fingerprint' => str_repeat('9', 64),
            ],
        ]);
        $resolved = (new TransferAuditor(
            new FakeRuntimeInspector(),
            new FakeSourceInventoryInspector($inventory),
        ))->audit($this->selection(), $decisions);

        self::assertTrue($resolved->ready);
        self::assertSame(0, $resolved->counts['products_blocked']);
        self::assertSame(2, $resolved->counts['products_exported']);
        self::assertSame(1, $resolved->counts['products_excluded']);
        self::assertSame(0, $resolved->counts['orders_blocked']);
        self::assertSame(9, $resolved->counts['orders_exported']);
        self::assertSame(1, $resolved->counts['orders_excluded']);
    }

    public function testImmutableRecordContractFailureCannotHideBehindAReadyInventory(): void
    {
        $inventory = SourceInventoryReport::create(
            'lapka-web',
            $this->selection()->fingerprint(),
            'runtime-fingerprint',
            [
                'product_duplicates' => 0,
                'products_unaccounted' => 0,
                'orders_exported' => 2,
                'orders_blocked' => 0,
                'orders_excluded' => 0,
            ],
            [],
            [],
        );
        $contracts = new FakeSourceRecordContractInspector([[
            'code' => 'order_money_mismatch',
            'identity' => 'lapka-web:order:42',
            'context' => ['diagnostic_fingerprint' => str_repeat('4', 64)],
        ]]);
        $auditor = new TransferAuditor(
            new FakeRuntimeInspector(),
            new FakeSourceInventoryInspector($inventory),
            $contracts,
        );

        $blocked = $auditor->audit($this->selection());

        self::assertFalse($blocked->ready);
        self::assertSame(['order_money_mismatch'], array_column($blocked->blockers, 'code'));
        self::assertTrue($blocked->blockers[0]['context']['record_contract']);
        self::assertSame(1, $blocked->counts['orders_exported']);
        self::assertSame(1, $blocked->counts['orders_blocked']);

        $decision = TransferDecisionSet::fromArray([[
            'scope' => 'audit_finding',
            'identity' => 'lapka-web:order:42',
            'finding_code' => 'order_money_mismatch',
            'action' => 'excluded_by_policy',
            'source_fingerprint' => $blocked->blockers[0]['context']['evidence_fingerprint'],
            'operator' => 'owner',
            'reason' => 'The internally contradictory source ledger is excluded.',
            'decided_at' => '2026-08-10T12:00:00Z',
        ]]);
        $resolved = $auditor->audit($this->selection(), $decision);

        self::assertTrue($resolved->ready);
        self::assertSame(0, $resolved->counts['orders_blocked']);
        self::assertSame(1, $resolved->counts['orders_exported']);
        self::assertSame(1, $resolved->counts['orders_excluded']);
    }

    private function selection(): TransferSelection
    {
        return new TransferSelection(
            'lapka-web',
            SelectionClause::all(),
            SelectionClause::none(),
            SelectionClause::all(),
            SelectionClause::none(),
        );
    }

    private function inventoryReport(): SourceInventoryReport
    {
        return SourceInventoryReport::create(
            'lapka-web',
            $this->selection()->fingerprint(),
            'runtime-fingerprint',
            [
                'product_duplicates' => 0,
                'products_unaccounted' => 0,
                'products_considered' => 1,
                'products_exported' => 1,
            ],
            ['product_types' => ['simple' => 1]],
            [],
        );
    }
}

final class FakeRuntimeInspector implements TransferRuntimeInspector
{
    /** @param list<string> $errors */
    public function __construct(private readonly array $errors = []) {}

    public function inspect(string $role): TransferRuntimeReport
    {
        return new TransferRuntimeReport(
            $role,
            'runtime-fingerprint',
            ['cartshift' => '1.5.0'],
            [],
            $this->errors,
            [],
        );
    }
}

final class FakeSourceInventoryInspector implements SourceInventoryInspector
{
    public int $calls = 0;

    public function __construct(private readonly SourceInventoryReport $report) {}

    public function inspect(TransferSelection $selection): SourceInventoryReport
    {
        ++$this->calls;

        return $this->report;
    }
}

final class FakeSourceRecordContractInspector implements SourceRecordContractInspector
{
    /** @param list<array{code: string, identity: string, context: array<string, scalar|null>}> $findings */
    public function __construct(private readonly array $findings) {}

    public function inspect(TransferSelection $selection): SourceRecordContractReport
    {
        $counts = [];
        foreach ($this->findings as $finding) {
            $kind = \CartShift\Domain\Transfer\SourceIdentity::fromCanonical($finding['identity'])->entityType;
            $counts[$kind] ??= ['considered' => 0, 'ready' => 0, 'blocked' => 0];
            ++$counts[$kind]['considered'];
            ++$counts[$kind]['blocked'];
        }
        return new SourceRecordContractReport($counts, $this->findings);
    }
}

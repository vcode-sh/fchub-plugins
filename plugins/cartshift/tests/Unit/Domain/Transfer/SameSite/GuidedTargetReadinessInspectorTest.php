<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\Domain\Transfer\SameSite;

use CartShift\Domain\Transfer\Decision\TransferDecisionSet;
use CartShift\Domain\Transfer\Order\OrderRecord;
use CartShift\Domain\Transfer\Package\TransferPackageValidator;
use CartShift\Domain\Transfer\Package\TransferPackageWriter;
use CartShift\Domain\Transfer\Product\StockOwnership;
use CartShift\Domain\Transfer\Product\StockProfile;
use CartShift\Domain\Transfer\SameSite\GuidedTargetReadinessInspector;
use CartShift\Domain\Transfer\SelectionClause;
use CartShift\Domain\Transfer\SourceIdentity;
use CartShift\Domain\Transfer\TransferSelection;
use CartShift\Tests\Unit\Domain\Transfer\Product\ProductAssessmentFixture;
use CartShift\Tests\Unit\PluginTestCase;

final class GuidedTargetReadinessInspectorTest extends PluginTestCase
{
    /** @var list<string> */
    private array $roots = [];

    #[\Override]
    protected function tearDown(): void
    {
        unset($GLOBALS['_cartshift_test_get_col_callback'], $GLOBALS['_cartshift_test_get_results_callback']);
        foreach ($this->roots as $root) {
            $this->removeTree($root);
        }

        parent::tearDown();
    }

    public function testInjectedReadinessPreservesManifestPayloadAndExceptionOrder(): void
    {
        $first = ['kind' => 'shared_parent_stock', 'source_quantity' => 11];
        $second = ['kind' => 'shared_parent_stock', 'source_quantity' => 4];
        $inspector = new GuidedTargetReadinessInspector(
            packageValidator: static fn (): object => (object) [
                'sourceKey' => 'shop-alpha',
                'selectionFingerprint' => str_repeat('a', 64),
                'recordsSha256' => str_repeat('b', 64),
                'recordCounts' => ['product' => 2],
                'createdAtUtc' => '2026-08-12T12:00:00Z',
            ],
            targetReadiness: static fn (): array => [$first, $second],
        );

        $result = $inspector->inspect([
            'package' => '/srv/private/package',
            'decision_set' => '/srv/private/decisions.json',
        ]);

        self::assertSame([
            'status' => 'validated',
            'source_key' => 'shop-alpha',
            'selection_fingerprint' => str_repeat('a', 64),
            'records_sha256' => str_repeat('b', 64),
            'record_counts' => ['product' => 2],
            'migration_exceptions' => [$first, $second],
        ], $result);
    }

    public function testDependencyBoundRecordsValidateWithoutPreparingOrWritingTargetRecords(): void
    {
        $parent = ProductAssessmentFixture::identity('42');
        $product = ProductAssessmentFixture::product([
            'productType' => 'variable',
            'variations' => [ProductAssessmentFixture::variation($parent, [
                'identity' => ProductAssessmentFixture::identity('42:variation:101'),
                'stock' => new StockProfile(StockOwnership::Parent, $parent, 7, 'instock', 'no', false, null),
            ])],
        ]);
        $order = new OrderRecord(
            new SourceIdentity('lapka-web', 'order', '9'),
            null,
            null,
            'checkout',
            'completed',
            'USD',
            'USD',
            'USD',
            '1.0000',
            'same_currency:USD',
            false,
            0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0,
            '2026-01-01T00:00:00Z',
            null, null, null, null,
            [], [], [], [], [], [], [], [], [],
        );
        [$package, $decisionPath] = $this->package(
            [$product->envelope(), $order->envelope()],
            [$this->productDecision($product->identity, $product->envelope()->sourceContentDigest)],
        );

        $result = (new GuidedTargetReadinessInspector())->inspect([
            'package' => $package,
            'decision_set' => $decisionPath,
        ]);

        self::assertSame('validated', $result['status']);
        self::assertSame(['order' => 1, 'product' => 1], $result['record_counts']);
        self::assertSame('shared_parent_stock', $result['migration_exceptions'][0]['kind']);
        self::assertSame('lapka-web:product:42:variation:101', $result['migration_exceptions'][0]['source_variation']);
        self::assertSame(7, $result['migration_exceptions'][0]['source_quantity']);
        self::assertSame('parent', $result['migration_exceptions'][0]['source_stock']['ownership']);
    }

    /** @param list<\CartShift\Domain\Transfer\RecordEnvelope> $records @param list<array<string,mixed>> $decisions */
    private function package(array $records, array $decisions): array
    {
        $root = sys_get_temp_dir() . '/cartshift-guided-inspector-' . bin2hex(random_bytes(8));
        mkdir($root, 0700);
        $this->roots[] = $root;
        $selection = new TransferSelection(
            'lapka-web',
            SelectionClause::all(),
            SelectionClause::all(),
            SelectionClause::all(),
            SelectionClause::none(),
        );
        $package = (new TransferPackageWriter(new TransferPackageValidator()))->write(
            new SourceIdentity('lapka-web', 'product', '1'),
            $selection,
            $records,
            [],
            [
                'destination' => $root,
                'source_instance_fingerprint' => str_repeat('1', 64),
                'source_url_hash' => str_repeat('2', 64),
                'source_runtime_fingerprint' => str_repeat('3', 64),
                'source_settings_fingerprint' => str_repeat('4', 64),
                'source_capability_fingerprint' => str_repeat('5', 64),
                'cartshift_version' => '2.0.0',
                'woocommerce_version' => '11.0.0',
                'created_at_utc' => '2026-08-12T12:00:00Z',
            ],
        );
        $decisionPath = $root . '/decisions.json';
        file_put_contents($decisionPath, TransferDecisionSet::fromArray($decisions)->canonicalJson());
        chmod($decisionPath, 0600);
        $GLOBALS['_cartshift_test_get_col_callback'] = static fn (): array => ['standard'];
        $GLOBALS['_cartshift_test_get_results_callback'] = static fn (): array => [];

        return [$package, $decisionPath];
    }

    /** @return array<string,mixed> */
    private function productDecision(SourceIdentity $identity, string $fingerprint): array
    {
        return [
            'identity' => $identity->canonical(),
            'action' => 'activate_catalogue',
            'target_status' => 'publish',
            'source_fingerprint' => $fingerprint,
            'operator' => 'wp-user:1',
            'reason' => 'Owner reviewed the product.',
            'decided_at' => '2026-08-12T12:00:00Z',
        ];
    }

    private function removeTree(string $path): void
    {
        if (!file_exists($path) && !is_link($path)) {
            return;
        }
        if (is_file($path) || is_link($path)) {
            unlink($path);
            return;
        }
        foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $entry) {
            $this->removeTree($path . '/' . $entry);
        }
        rmdir($path);
    }
}

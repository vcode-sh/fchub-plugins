<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\Domain\Transfer\Product;

use CartShift\Domain\Transfer\Product\FluentCartProductWriter;
use CartShift\Domain\Transfer\Product\ProductAssessmentContext;
use CartShift\Domain\Transfer\Product\ProductFieldDecisionSet;
use CartShift\Domain\Transfer\Product\ProductFieldDisposition;
use CartShift\Domain\Transfer\Product\ProductReconciler;
use CartShift\Domain\Transfer\Product\ProductStagePlan;
use CartShift\Domain\Transfer\Product\LinkedProductPlan;
use CartShift\Domain\Transfer\Product\ProductTargetFingerprint;
use CartShift\Domain\Transfer\Product\StockOwnership;
use CartShift\Domain\Transfer\Product\StockProfile;
use CartShift\Domain\Transfer\StageContext;
use CartShift\Tests\Unit\PluginTestCase;

require_once __DIR__ . '/FluentCartProductWriterTest.php';

final class ProductReconcilerTest extends PluginTestCase
{
    private string $package;

    protected function setUp(): void
    {
        parent::setUp();
        $this->package = sys_get_temp_dir() . '/cartshift-reconciler-' . bin2hex(random_bytes(8));
        mkdir($this->package . '/assets', 0700, true);
    }

    protected function tearDown(): void
    {
        rmdir($this->package . '/assets');
        rmdir($this->package);
        parent::tearDown();
    }

    public function testIndependentReadbackMatchesCompleteStagedTarget(): void
    {
        [$gateway, $maps, $writer, $plan] = $this->system();
        $result = $writer->stage($plan, $this->context());

        $reconciled = (new ProductReconciler($gateway, $maps))->reconcile(
            $plan,
            $result->targetId,
            $result->targetFingerprint,
        );

        self::assertTrue($reconciled->matches);
        self::assertSame($result->targetFingerprint, $reconciled->actualFingerprint);
        self::assertSame([], $reconciled->failures);
    }

    public function testApprovedCataloguePublicationDoesNotInvalidateTheImmutableDraftReceipt(): void
    {
        [$gateway, $maps, $writer, $plan] = $this->system();
        $result = $writer->stage($plan, $this->context());
        $gateway->products[$result->targetId]['post_status'] = 'publish';

        $withoutApproval = (new ProductReconciler($gateway, $maps))->reconcile(
            $plan,
            $result->targetId,
            $result->targetFingerprint,
        );
        $withApproval = (new ProductReconciler($gateway, $maps))->reconcile(
            $plan,
            $result->targetId,
            $result->targetFingerprint,
            'publish',
        );

        self::assertFalse($withoutApproval->matches, 'An unapproved publication was normalised as legitimate drift.');
        self::assertContains('product_field_mismatch', $withoutApproval->failures);
        self::assertTrue($withApproval->matches);
        self::assertSame($result->targetFingerprint, $withApproval->actualFingerprint);
    }

    public function testOperationalVariationDriftChangesFingerprintAndFails(): void
    {
        [$gateway, $maps, $writer, $plan] = $this->system();
        $result = $writer->stage($plan, $this->context());
        $variationId = $result->variationIds[0];
        $gateway->variations[$variationId]['available'] = 999;

        $reconciled = (new ProductReconciler($gateway, $maps))->reconcile(
            $plan,
            $result->targetId,
            $result->targetFingerprint,
        );

        self::assertFalse($reconciled->matches);
        self::assertContains('target_fingerprint_mismatch', $reconciled->failures);
    }

    public function testLinkedProductDriftAfterApprovalFailsWithoutProjectingSourceFields(): void
    {
        $record = ProductAssessmentFixture::product();
        $envelope = $record->envelope();
        $variation = $record->variations[0];
        $gateway = new InMemoryProductTargetGateway();
        $gateway->products[501] = [
            'ID' => 501,
            'post_title' => 'Existing FluentCart product',
            'post_status' => 'publish',
        ];
        $gateway->details[501] = ['post_id' => 501, 'variation_type' => 'simple'];
        $gateway->variations[901] = [
            'id' => 901,
            'post_id' => 501,
            'variation_title' => 'Default',
            'sku' => 'EXISTING-1',
            'item_price' => 2500,
        ];
        $snapshot = $gateway->snapshot(501);
        $sourceMap = [
            $record->identity->canonical() => 501,
            $variation->identity->canonical() => 901,
        ];
        $plan = LinkedProductPlan::fromDecision($record, $envelope, [
            'target_product_id' => 501,
            'target_fingerprint' => (new ProductTargetFingerprint())->fingerprint($snapshot, $sourceMap),
            'variation_links' => [[
                'source_variation' => $variation->identity->canonical(),
                'target_variation_id' => 901,
                'source_fingerprint' => \CartShift\Support\CanonicalJson::fingerprint($envelope->payload['variations'][0]),
                'target_fingerprint' => \CartShift\Support\CanonicalJson::fingerprint($snapshot['variations'][0]),
            ]],
        ], $snapshot);
        $maps = new InMemoryCheckedMappingStore();
        $writer = new FluentCartProductWriter($gateway, $maps, new ProductReconciler($gateway, $maps));
        $result = $writer->stage($plan, $this->context());
        $gateway->products[501]['post_title'] = 'Changed after owner approval';

        $reconciled = (new ProductReconciler($gateway, $maps))->reconcile(
            $plan,
            $result->targetId,
            $result->targetFingerprint,
        );

        self::assertFalse($reconciled->matches);
        self::assertContains('target_fingerprint_mismatch', $reconciled->failures);
    }

    public function testMissingBuySectionAndUncartableVariationBothFail(): void
    {
        [$gateway, $maps, $writer, $plan] = $this->system();
        $result = $writer->stage($plan, $this->context());
        $gateway->buySectionRendered = false;
        $gateway->cartableOverride = [$result->variationIds[0]];

        $reconciled = (new ProductReconciler($gateway, $maps))->reconcile(
            $plan,
            $result->targetId,
            $result->targetFingerprint,
        );

        self::assertFalse($reconciled->matches);
        self::assertContains('buy_section_missing', $reconciled->failures);
        self::assertContains('variation_not_cartable_exactly_once', $reconciled->failures);
    }

    public function testTargetVariationSourceIdentityMustRemainOneToOne(): void
    {
        [$gateway, $maps, $writer, $plan] = $this->system();
        $result = $writer->stage($plan, $this->context());
        $ids = $result->variationIds;
        $gateway->variations[$ids[1]]['other_info']['source_identity'] =
            $gateway->variations[$ids[0]]['other_info']['source_identity'];

        $reconciled = (new ProductReconciler($gateway, $maps))->reconcile(
            $plan,
            $result->targetId,
            $result->targetFingerprint,
        );

        self::assertFalse($reconciled->matches);
        self::assertContains('variation_source_cardinality_mismatch', $reconciled->failures);
    }

    public function testParentStockVariationMustStayUncartableWhileItsOrdinarySiblingStillWorks(): void
    {
        $parent = ProductAssessmentFixture::identity('42');
        $shared = ProductAssessmentFixture::identity('42:variation:101');
        $ordinary = ProductAssessmentFixture::identity('42:variation:102');
        $record = ProductAssessmentFixture::product([
            'productType' => 'variable',
            'variations' => [
                ProductAssessmentFixture::variation($parent, [
                    'identity' => $shared,
                    'stock' => new StockProfile(StockOwnership::Parent, $parent, 11, 'instock', 'yes', false, 2),
                ]),
                ProductAssessmentFixture::variation($parent, [
                    'identity' => $ordinary,
                    'stock' => new StockProfile(StockOwnership::Self, $ordinary, 5, 'instock', 'no', false, 2),
                ]),
            ],
        ]);
        $plan = ProductStagePlan::build($record, new ProductAssessmentContext(
            ['standard', 'none'],
            [],
            ProductFieldDecisionSet::all(ProductFieldDisposition::Migrate),
            targetShippingClasses: ['none' => 0],
        ));
        $gateway = new InMemoryProductTargetGateway();
        $gateway->stockManagementActive = false;
        $maps = new InMemoryCheckedMappingStore();
        $writer = new FluentCartProductWriter($gateway, $maps, new ProductReconciler($gateway, $maps));

        $result = $writer->stage($plan, $this->context());
        $sharedId = $maps->get($shared)?->targetId;
        $ordinaryId = $maps->get($ordinary)?->targetId;

        self::assertIsInt($sharedId);
        self::assertIsInt($ordinaryId);
        self::assertSame(1, $plan->detailFields['manage_stock']);
        self::assertSame('inactive', $gateway->variations[$sharedId]['item_status']);
        self::assertSame('out-of-stock', $gateway->variations[$sharedId]['stock_status']);
        self::assertSame(0, $gateway->variations[$sharedId]['available']);
        self::assertArrayHasKey('stock_migration_exception', $gateway->variations[$sharedId]['other_info']);
        self::assertNotContains($sharedId, $gateway->behaviour($result->targetId, $result->variationIds)['cartable_variation_ids']);
        self::assertContains($ordinaryId, $gateway->behaviour($result->targetId, $result->variationIds)['cartable_variation_ids']);

        $gateway->cartableOverride = [$sharedId, $ordinaryId];
        $gateway->checkoutOverride = [$sharedId, $ordinaryId];
        $unsafe = (new ProductReconciler($gateway, $maps))->reconcile(
            $plan,
            $result->targetId,
            $result->targetFingerprint,
        );

        self::assertFalse($unsafe->matches);
        self::assertContains('variation_not_cartable_exactly_once', $unsafe->failures);

        $gateway->cartableOverride = null;
        $gateway->checkoutOverride = null;
        $gateway->details[$result->targetId]['manage_stock'] = 0;
        self::assertNotContains(
            $sharedId,
            $gateway->behaviour($result->targetId, $result->variationIds)['cartable_variation_ids'],
            'The inactive variation must remain unavailable when FluentCart stock management is disabled.',
        );
        $gateway->variations[$sharedId]['item_status'] = 'active';
        self::assertContains(
            $sharedId,
            $gateway->behaviour($result->targetId, $result->variationIds)['cartable_variation_ids'],
            'The test must prove that activating the variation without stock management would reintroduce overselling.',
        );
    }

    /** @return array{InMemoryProductTargetGateway, InMemoryCheckedMappingStore, FluentCartProductWriter, \CartShift\Domain\Transfer\Product\ProductStagePlan} */
    private function system(): array
    {
        $gateway = new InMemoryProductTargetGateway();
        $maps = new InMemoryCheckedMappingStore();
        $writer = new FluentCartProductWriter($gateway, $maps, new ProductReconciler($gateway, $maps));

        $method = new \ReflectionMethod(FluentCartProductWriterTest::class, 'plan');
        $plan = $method->invoke(new FluentCartProductWriterTest('testStageIsDraftFirstAndCreatesOneTargetVariationPerSource'));

        return [$gateway, $maps, $writer, $plan];
    }

    private function context(): StageContext
    {
        return new StageContext($this->package, 'reconcile-run', 'source-runtime');
    }
}

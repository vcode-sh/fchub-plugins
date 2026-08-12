<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\Domain\Transfer\Product;

use CartShift\Domain\Transfer\Product\FluentCartProductWriter;
use CartShift\Domain\Transfer\Product\ProductReconciler;
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

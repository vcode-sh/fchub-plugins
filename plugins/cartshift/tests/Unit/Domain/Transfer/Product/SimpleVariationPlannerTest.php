<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\Domain\Transfer\Product;

use CartShift\Domain\Transfer\Product\AttributeRecord;
use CartShift\Domain\Transfer\Product\FluentCartSimpleVariationContract;
use CartShift\Domain\Transfer\Product\PriceRecord;
use CartShift\Domain\Transfer\Product\ProductAssessmentContext;
use CartShift\Domain\Transfer\Product\ProductFieldDecisionSet;
use CartShift\Domain\Transfer\Product\ProductFieldDisposition;
use CartShift\Domain\Transfer\Product\SimpleVariationPlanner;
use CartShift\Domain\Transfer\Product\StockOwnership;
use CartShift\Domain\Transfer\Product\StockProfile;
use CartShift\Domain\Transfer\SourceRecordException;
use CartShift\Tests\Unit\PluginTestCase;

final class SimpleVariationPlannerTest extends PluginTestCase
{
    public function testEveryExplicitVariationProducesOneSimpleVariationWithSourceScopedIdentity(): void
    {
        $parent = ProductAssessmentFixture::identity('42');
        $first = ProductAssessmentFixture::identity('42:variation:101');
        $second = ProductAssessmentFixture::identity('42:variation:102');
        $attributes = [
            new AttributeRecord('harness-size', 'Harness size', 'custom', true, true, 0, 'Small', ['Small', 'Large'], [
                'Small' => 'Small', 'Large' => 'Large',
            ]),
        ];
        $product = ProductAssessmentFixture::product([
            'productType' => 'variable',
            'attributes' => $attributes,
            'variations' => [
                ProductAssessmentFixture::variation($parent, [
                    'identity' => $first,
                    'attributeAssignments' => [[
                        'attribute_key' => 'harness-size', 'value' => 'Small', 'kind' => 'custom', 'wildcard' => false,
                    ]],
                ]),
                ProductAssessmentFixture::variation($parent, [
                    'identity' => $second,
                    'attributeAssignments' => [[
                        'attribute_key' => 'harness-size', 'value' => 'Large', 'kind' => 'custom', 'wildcard' => false,
                    ]],
                ]),
            ],
        ]);

        $plans = (new SimpleVariationPlanner())->plan($product, $this->context());

        self::assertCount(2, $plans);
        self::assertSame([$first, $second], array_column($plans, 'sourceVariation'));
        self::assertSame(['Harness size: Small', 'Harness size: Large'], array_column($plans, 'targetTitle'));
        self::assertSame('simple_variations', $plans[0]->targetFields['variation_type']);
        self::assertNotSame($plans[0]->variationIdentifier, $plans[1]->variationIdentifier);
        self::assertLessThanOrEqual(100, strlen($plans[0]->variationIdentifier));
        self::assertSame('custom', $plans[0]->targetOtherInfo['source_attributes'][0]['kind']);
        self::assertSame('Harness size', $plans[0]->targetOtherInfo['source_attributes'][0]['label']);
    }

    public function testIdentifierDoesNotChangeWithMutableTitleAndDiffersBySourceNamespace(): void
    {
        $contract = new FluentCartSimpleVariationContract();
        $web = ProductAssessmentFixture::identity('42:variation:101');
        $club = new \CartShift\Domain\Transfer\SourceIdentity('lapka-klub', 'product', '42:variation:101');

        self::assertSame($contract->identifier($web), $contract->identifier($web));
        self::assertNotSame($contract->identifier($web), $contract->identifier($club));
        self::assertMatchesRegularExpression('/\Acs-[a-f0-9]{40}\z/D', $contract->identifier($web));
    }

    public function testCompleteOperationalBaselinePreservesZeroesAndExplicitFields(): void
    {
        $parent = ProductAssessmentFixture::identity('42');
        $variation = ProductAssessmentFixture::variation($parent, [
            'price' => new PriceRecord(0, 1000, 0, null, null, 'USD'),
            'stock' => new StockProfile(StockOwnership::Self, $parent, 0, 'outofstock', 'no', true, 2),
        ]);

        $baseline = (new FluentCartSimpleVariationContract())->baseline($variation, $this->context());

        self::assertSame(0, $baseline['item_price']);
        self::assertSame(1000, $baseline['compare_price']);
        self::assertSame(1, $baseline['manage_stock']);
        self::assertSame(0, $baseline['total_stock']);
        self::assertSame(0, $baseline['available']);
        self::assertSame('out-of-stock', $baseline['stock_status']);
        self::assertSame(1, $baseline['sold_individually']);
        self::assertSame('onetime', $baseline['payment_type']);
        self::assertSame('standard', $baseline['other_info']['tax_class']);
        self::assertSame('no', $baseline['other_info']['tax_exempt']);
        self::assertSame('excluded', $baseline['other_info']['tax_inclusion']);
        self::assertArrayHasKey('description', $baseline['other_info']);
        self::assertArrayHasKey('weight_unit', $baseline['other_info']);
        self::assertArrayHasKey('dimension_unit', $baseline['other_info']);
        self::assertArrayHasKey('source_price', $baseline['other_info']);
    }

    public function testSubscriptionBaselineUsesExactCadenceTerms(): void
    {
        $parent = ProductAssessmentFixture::identity('42');
        $variation = ProductAssessmentFixture::variation($parent, [
            'typeConfiguration' => [
                'subscription_period' => 'month',
                'subscription_period_interval' => 3,
                'subscription_length' => 12,
                'subscription_trial_length' => 2,
                'subscription_trial_period' => 'week',
                'subscription_sign_up_fee' => '12.34',
            ],
        ]);

        $baseline = (new FluentCartSimpleVariationContract())->baseline($variation, $this->context());

        self::assertSame('subscription', $baseline['payment_type']);
        self::assertSame('quarterly', $baseline['other_info']['repeat_interval']);
        self::assertSame(4, $baseline['other_info']['times']);
        self::assertSame(14, $baseline['other_info']['trial_days']);
        self::assertSame('yes', $baseline['other_info']['manage_setup_fee']);
        self::assertSame(1234, $baseline['other_info']['signup_fee']);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('parentStockProvider')]
    public function testParentStockUsesOneConservativeProjectionAndPreservesItsSourceEvidence(
        ?int $quantity,
        string $status,
        string $backorders,
    ): void {
        $parent = ProductAssessmentFixture::identity('42');
        $variation = ProductAssessmentFixture::variation($parent, [
            'identity' => ProductAssessmentFixture::identity('42:variation:101'),
            'stock' => new StockProfile(StockOwnership::Parent, $parent, $quantity, $status, $backorders, false, 2),
        ]);

        $baseline = (new FluentCartSimpleVariationContract())->baseline($variation, $this->context());
        $exception = $baseline['other_info']['stock_migration_exception'];

        self::assertSame(1, $baseline['manage_stock']);
        self::assertSame(0, $baseline['total_stock']);
        self::assertSame(0, $baseline['available']);
        self::assertSame(0, $baseline['committed']);
        self::assertSame(0, $baseline['on_hold']);
        self::assertSame('out-of-stock', $baseline['stock_status']);
        self::assertSame(0, $baseline['backorders']);
        self::assertSame($variation->stock->toArray(), $exception['source_stock']);
        self::assertSame('shared_parent_stock', $exception['type']);
        self::assertTrue($exception['requires_manual_resolution']);
    }

    /** @return iterable<string,array{?int,string,string}> */
    public static function parentStockProvider(): iterable
    {
        yield 'positive in stock' => [7, 'instock', 'no'];
        yield 'zero out of stock' => [0, 'outofstock', 'notify'];
        yield 'unknown backorder stock' => [null, 'onbackorder', 'yes'];
    }

    public function testReviewedSkuOverrideIsAppliedWithoutChangingTheSourceRecord(): void
    {
        $parent = ProductAssessmentFixture::identity('42');
        $variation = ProductAssessmentFixture::variation($parent, ['sku' => str_repeat('S', 31)]);
        $product = ProductAssessmentFixture::product(['variations' => [$variation]]);

        $plans = (new SimpleVariationPlanner())->plan($product, $this->context(), [
            $variation->identity->canonical() => 'REVIEWED-SKU',
        ]);

        self::assertSame('REVIEWED-SKU', $plans[0]->targetFields['sku']);
        self::assertSame(str_repeat('S', 31), $product->variations[0]->sku);
    }

    public function testUnknownOrInvalidSkuOverrideBlocks(): void
    {
        $product = ProductAssessmentFixture::product();

        foreach ([
            ['lapka-web:product:missing' => 'SAFE-SKU'],
            [$product->variations[0]->identity->canonical() => str_repeat('S', 31)],
        ] as $overrides) {
            try {
                (new SimpleVariationPlanner())->plan($product, $this->context(), $overrides);
                self::fail('Invalid SKU override was accepted.');
            } catch (SourceRecordException $exception) {
                self::assertSame('target_schema_unrepresentable', $exception->reasonCode);
            }
        }
    }

    /** @dataProvider lossyVariationProvider */
    #[\PHPUnit\Framework\Attributes\DataProvider('lossyVariationProvider')]
    public function testLossyVariationShapesBlock(array $productOverrides, string $reason): void
    {
        try {
            (new SimpleVariationPlanner())->plan(ProductAssessmentFixture::product($productOverrides), $this->context());
            self::fail('Lossy variation shape was accepted.');
        } catch (SourceRecordException $exception) {
            self::assertSame($reason, $exception->reasonCode);
        }
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function lossyVariationProvider(): iterable
    {
        $parent = ProductAssessmentFixture::identity('42');
        $child = ProductAssessmentFixture::identity('42:variation:101');

        yield 'wildcard' => [[
            'productType' => 'variable',
            'attributes' => [ProductAssessmentFixture::customAttribute()],
            'variations' => [ProductAssessmentFixture::variation($parent, [
                'identity' => $child,
                'attributeAssignments' => [[
                    'attribute_key' => 'colour', 'value' => '', 'kind' => 'custom', 'wildcard' => true,
                ]],
            ])],
        ], 'wildcard_variation_unrepresentable'];

        yield 'wrong parent' => [[
            'productType' => 'variable',
            'variations' => [ProductAssessmentFixture::variation(ProductAssessmentFixture::identity('99'), ['identity' => $child])],
        ], 'variation_parent_mismatch'];

        yield 'duplicate identity' => [[
            'productType' => 'variable',
            'variations' => [
                ProductAssessmentFixture::variation($parent, ['identity' => $child]),
                ProductAssessmentFixture::variation($parent, ['identity' => $child]),
            ],
        ], 'variation_source_duplicate'];

        yield 'long sku' => [[
            'productType' => 'variable',
            'variations' => [ProductAssessmentFixture::variation($parent, ['identity' => $child, 'sku' => str_repeat('x', 31)])],
        ], 'target_schema_unrepresentable'];

        yield 'notify backorders' => [[
            'productType' => 'variable',
            'variations' => [ProductAssessmentFixture::variation($parent, [
                'identity' => $child,
                'stock' => ProductAssessmentFixture::stock($child, 'notify'),
            ])],
        ], 'target_backorders_notify_unavailable'];
    }

    public function testUnknownShippingClassBlocksInsteadOfBecomingNull(): void
    {
        $parent = ProductAssessmentFixture::identity('42');
        $variation = ProductAssessmentFixture::variation($parent, ['shippingClassSlug' => 'oversized']);
        $product = ProductAssessmentFixture::product([
            'shippingClassSlug' => 'oversized',
            'variations' => [$variation],
        ]);

        $this->expectException(SourceRecordException::class);
        (new SimpleVariationPlanner())->plan($product, $this->context());
    }

    private function context(): ProductAssessmentContext
    {
        return new ProductAssessmentContext(
            ['standard', 'none'],
            [],
            ProductFieldDecisionSet::all(ProductFieldDisposition::Migrate),
            targetShippingClasses: ['none' => 0],
        );
    }
}

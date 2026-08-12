<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\Domain\Transfer\Product;

use CartShift\Domain\Transfer\AssessmentOutcome;
use CartShift\Domain\Transfer\Product\AssetReference;
use CartShift\Domain\Transfer\Product\LinkedVariationDecision;
use CartShift\Domain\Transfer\Product\ProductAssessmentContext;
use CartShift\Domain\Transfer\Product\ProductCapabilityAssessor;
use CartShift\Domain\Transfer\Product\ProductDecisionSet;
use CartShift\Domain\Transfer\Product\ProductFieldDecisionSet;
use CartShift\Domain\Transfer\Product\ProductFieldDisposition;
use CartShift\Domain\Transfer\Product\ProductTransferAction;
use CartShift\Domain\Transfer\Product\ProductTransferDecision;
use CartShift\Domain\Transfer\Product\StockOwnership;
use CartShift\Domain\Transfer\Product\StockProfile;
use CartShift\Domain\Transfer\RecordKind;
use CartShift\Domain\Transfer\SourceIdentity;
use CartShift\Support\CanonicalJson;
use CartShift\Tests\Unit\PluginTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class ProductCapabilityAssessorTest extends PluginTestCase
{
    private ProductCapabilityAssessor $assessor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assessor = new ProductCapabilityAssessor();
    }

    public function testUnknownCustomTypeBlocksWithDependentOrderCount(): void
    {
        $assessment = $this->assessor->assess(
            ProductAssessmentFixture::product(['productType' => 'course']),
            $this->context(dependentOrders: 41),
        );

        self::assertSame(AssessmentOutcome::Blocked, $assessment->outcome);
        self::assertSame('unsupported_product_type', $assessment->reasonCode);
        self::assertSame(41, $assessment->context['dependent_orders']);
    }

    public function testSimpleProductIsReadyOnlyWithExactBaselineCapabilities(): void
    {
        $assessment = $this->assessor->assess(ProductAssessmentFixture::product(), $this->context());

        self::assertSame(AssessmentOutcome::Ready, $assessment->outcome);
        self::assertSame('product_ready', $assessment->reasonCode);
        self::assertSame('draft', $assessment->context['stage_status']);
        self::assertSame('publish', $assessment->context['approved_promotion_status']);
        self::assertSame('onetime', $assessment->context['target_payment_type']);
    }

    public function testVariationOnlyAssetsCannotBypassTheAssetRoundTripCapability(): void
    {
        $parent = ProductAssessmentFixture::identity('42');
        $variation = ProductAssessmentFixture::identity('42:variation:101');
        $hash = hash('sha256', 'variation-only-image');
        $assessment = $this->assessor->assess(ProductAssessmentFixture::product([
            'productType' => 'variable',
            'variations' => [ProductAssessmentFixture::variation($parent, [
                'identity' => $variation,
                'media' => [new AssetReference(
                    new SourceIdentity('lapka-web', RecordKind::MediaAsset->value, '501'),
                    'https://example.com/wp-content/uploads/variation.jpg',
                    'variation',
                    'image/jpeg',
                    20,
                    $variation,
                    'own',
                    $hash,
                )],
            ])],
        ]), $this->context(capabilities: ['asset_hash_roundtrip' => false]));

        self::assertSame(AssessmentOutcome::Blocked, $assessment->outcome);
        self::assertSame('asset_roundtrip_unproved', $assessment->reasonCode);
    }

    #[DataProvider('unsupportedShapeProvider')]
    public function testLossyShapesBlockIndependently(array $productOverrides, array $capabilityOverrides, string $reason): void
    {
        $assessment = $this->assessor->assess(
            ProductAssessmentFixture::product($productOverrides),
            $this->context(capabilities: $capabilityOverrides),
        );

        self::assertSame(AssessmentOutcome::Blocked, $assessment->outcome);
        self::assertSame($reason, $assessment->reasonCode);
    }

    /** @return iterable<string, array{array<string, mixed>, array<string, bool>, string}> */
    public static function unsupportedShapeProvider(): iterable
    {
        $parent = ProductAssessmentFixture::identity('42');
        $child = ProductAssessmentFixture::identity('42:variation:101');

        yield 'wildcard variation' => [[
            'productType' => 'variable',
            'variations' => [ProductAssessmentFixture::variation($parent, [
                'identity' => $child,
                'attributeAssignments' => [[
                    'attribute_key' => 'colour', 'value' => '', 'kind' => 'custom', 'wildcard' => true,
                ]],
            ])],
        ], [], 'wildcard_variation_unrepresentable'];

        yield 'custom attribute without renderer proof' => [[
            'productType' => 'variable',
            'attributes' => [ProductAssessmentFixture::customAttribute()],
            'variations' => [ProductAssessmentFixture::variation($parent, ['identity' => $child])],
        ], ['custom_variation_attributes' => false], 'custom_attribute_renderer_unproved'];

        yield 'parent-managed stock without shared owner' => [[
            'productType' => 'variable',
            'variations' => [ProductAssessmentFixture::variation($parent, [
                'identity' => $child,
                'stock' => new StockProfile(StockOwnership::Parent, $parent, 7, 'instock', 'no', false, null),
            ])],
        ], ['shared_parent_stock' => false], 'parent_stock_owner_unrepresentable'];

        yield 'scheduled sale without exact scheduler' => [[
            'variations' => [ProductAssessmentFixture::variation($parent, [
                'price' => new \CartShift\Domain\Transfer\Product\PriceRecord(
                    800,
                    1000,
                    800,
                    '2026-01-01T00:00:00Z',
                    '2026-01-31T00:00:00Z',
                    'USD',
                ),
            ])],
        ], ['exact_sale_scheduler' => false], 'scheduled_sale_unrepresentable'];

        yield 'notify backorders are not yes backorders' => [[
            'stock' => ProductAssessmentFixture::stock($parent, 'notify'),
            'variations' => [ProductAssessmentFixture::variation($parent, [
                'stock' => ProductAssessmentFixture::stock($parent, 'notify'),
            ])],
        ], ['backorders_notify' => false, 'backorders_yes' => true], 'backorders_notify_unproved'];
    }

    public function testCustomAttributesPassOnlyWithRendererAndCartProof(): void
    {
        $parent = ProductAssessmentFixture::identity('42');
        $child = ProductAssessmentFixture::identity('42:variation:101');
        $product = ProductAssessmentFixture::product([
            'productType' => 'variable',
            'attributes' => [ProductAssessmentFixture::customAttribute()],
            'variations' => [ProductAssessmentFixture::variation($parent, [
                'identity' => $child,
                'attributeAssignments' => [[
                    'attribute_key' => 'colour', 'value' => 'Red', 'kind' => 'custom', 'wildcard' => false,
                ]],
            ])],
        ]);

        $assessment = $this->assessor->assess($product, $this->context());

        self::assertSame(AssessmentOutcome::Ready, $assessment->outcome);
    }

    #[DataProvider('statusProvider')]
    public function testStatusesRequireExplicitPromotionOrSelectionPolicy(string $status, array $approved, AssessmentOutcome $outcome, string $reason): void
    {
        $assessment = $this->assessor->assess(
            ProductAssessmentFixture::product(['status' => $status]),
            $this->context(approvedDraftStatuses: $approved),
        );

        self::assertSame($outcome, $assessment->outcome);
        self::assertSame($reason, $assessment->reasonCode);
    }

    /** @return iterable<string, array{string, list<string>, AssessmentOutcome, string}> */
    public static function statusProvider(): iterable
    {
        yield 'private' => ['private', [], AssessmentOutcome::Ready, 'product_ready'];
        yield 'draft' => ['draft', [], AssessmentOutcome::Ready, 'product_ready'];
        yield 'pending blocked' => ['pending', [], AssessmentOutcome::Blocked, 'product_status_policy_required'];
        yield 'pending approved as draft' => ['pending', ['pending'], AssessmentOutcome::Ready, 'product_ready'];
        yield 'trash needs selection exclusion' => ['trash', [], AssessmentOutcome::Blocked, 'trashed_product_selection_required'];
    }

    #[DataProvider('subscriptionCadenceProvider')]
    public function testSubscriptionCadenceIsExact(string $period, int $interval, AssessmentOutcome $outcome): void
    {
        $assessment = $this->assessor->assess(ProductAssessmentFixture::product([
            'productType' => 'subscription',
            'typeConfiguration' => [
                'subscription_period' => $period,
                'subscription_period_interval' => $interval,
                'subscription_length' => 0,
                'subscription_trial_length' => 0,
                'subscription_trial_period' => 'day',
                'subscription_sign_up_fee' => '0',
            ],
        ]), $this->context());

        self::assertSame($outcome, $assessment->outcome);
        self::assertSame($outcome === AssessmentOutcome::Ready ? 'product_ready' : 'unsupported_billing_cadence', $assessment->reasonCode);
    }

    public function testVariableSubscriptionRequiresExactCadenceOnEveryVariation(): void
    {
        $parent = ProductAssessmentFixture::identity('42');
        $first = ProductAssessmentFixture::identity('42:variation:101');
        $second = ProductAssessmentFixture::identity('42:variation:102');
        $base = [
            'subscription_period' => 'month',
            'subscription_period_interval' => 1,
            'subscription_length' => 0,
            'subscription_trial_length' => 0,
            'subscription_trial_period' => 'day',
            'subscription_sign_up_fee' => '0',
        ];
        $product = ProductAssessmentFixture::product([
            'productType' => 'variable-subscription',
            'typeConfiguration' => $base,
            'variations' => [
                ProductAssessmentFixture::variation($parent, ['identity' => $first, 'typeConfiguration' => $base]),
                ProductAssessmentFixture::variation($parent, ['identity' => $second, 'typeConfiguration' => [
                    ...$base,
                    'subscription_period_interval' => 2,
                ]]),
            ],
        ]);

        $blocked = $this->assessor->assess($product, $this->context());
        self::assertSame(AssessmentOutcome::Blocked, $blocked->outcome);
        self::assertSame('unsupported_billing_cadence', $blocked->reasonCode);
        self::assertSame($second->canonical(), $blocked->context['source_variation']);

        $product = ProductAssessmentFixture::product([
            'productType' => 'variable-subscription',
            'typeConfiguration' => $base,
            'variations' => [
                ProductAssessmentFixture::variation($parent, ['identity' => $first, 'typeConfiguration' => $base]),
                ProductAssessmentFixture::variation($parent, ['identity' => $second, 'typeConfiguration' => [...$base, 'subscription_period_interval' => 3]]),
            ],
        ]);
        self::assertSame(AssessmentOutcome::Ready, $this->assessor->assess($product, $this->context())->outcome);
    }

    public function testFiniteTermMustDivideByBillingIntervalAndCalendarTrialCannotBeRoundedToDays(): void
    {
        foreach ([
            ['subscription_period' => 'month', 'subscription_period_interval' => 3, 'subscription_length' => 10, 'subscription_trial_length' => 0, 'subscription_trial_period' => 'day', 'subscription_sign_up_fee' => '0'],
            ['subscription_period' => 'month', 'subscription_period_interval' => 1, 'subscription_length' => 0, 'subscription_trial_length' => 1, 'subscription_trial_period' => 'month', 'subscription_sign_up_fee' => '0'],
        ] as $configuration) {
            $assessment = $this->assessor->assess(ProductAssessmentFixture::product([
                'productType' => 'subscription',
                'typeConfiguration' => $configuration,
            ]), $this->context());
            self::assertSame(AssessmentOutcome::Blocked, $assessment->outcome);
            self::assertContains($assessment->reasonCode, ['subscription_length_unrepresentable', 'subscription_trial_unrepresentable']);
        }
    }

    /** @return iterable<string, array{string, int, AssessmentOutcome}> */
    public static function subscriptionCadenceProvider(): iterable
    {
        yield 'monthly' => ['month', 1, AssessmentOutcome::Ready];
        yield 'quarterly' => ['month', 3, AssessmentOutcome::Ready];
        yield 'two monthly is not invented' => ['month', 2, AssessmentOutcome::Blocked];
    }

    public function testFieldDecisionCannotClaimUnsupportedDataWasMigrated(): void
    {
        $decisions = $this->fieldDecisions(['global_unique_id' => ProductFieldDisposition::Migrate]);
        $assessment = $this->assessor->assess(
            ProductAssessmentFixture::product(['globalUniqueId' => 'GTIN-42']),
            $this->context(capabilities: ['global_unique_id_roundtrip' => false], fieldDecisions: $decisions),
        );

        self::assertSame(AssessmentOutcome::Blocked, $assessment->outcome);
        self::assertSame('global_unique_id_contract_unproved', $assessment->reasonCode);
    }

    public function testExplicitFieldExclusionIsReportedAsLoss(): void
    {
        $assessment = $this->assessor->assess(
            ProductAssessmentFixture::product(['globalUniqueId' => 'GTIN-42']),
            $this->context(fieldDecisions: $this->fieldDecisions([
                'global_unique_id' => ProductFieldDisposition::ExcludeByPolicy,
            ])),
        );

        self::assertSame(AssessmentOutcome::Ready, $assessment->outcome);
        self::assertContains('global_unique_id', $assessment->context['excluded_fields']);
    }

    public function testFieldDecisionSetRejectsAnyMissingInventoryField(): void
    {
        $decisions = ProductFieldDecisionSet::all(ProductFieldDisposition::Migrate)->decisions;
        unset($decisions['purchase_note']);

        $this->expectException(\InvalidArgumentException::class);
        new ProductFieldDecisionSet($decisions);
    }

    public function testExplicitExclusionCanSelectATrashedProductWithoutCreatingIt(): void
    {
        $product = ProductAssessmentFixture::product(['status' => 'trash']);
        $decision = new ProductTransferDecision(
            $product->identity,
            $product->envelope()->privateContentDigest,
            ProductTransferAction::Exclude,
            null,
            null,
            'owner_excluded_trash',
            [],
        );

        $assessment = $this->assessor->assess($product, $this->context(operatorDecision: $decision));
        self::assertSame(AssessmentOutcome::ExcludedByPolicy, $assessment->outcome);
    }

    public function testLinkDecisionRequiresCompleteOneToOneVariationMappingAndFreshFingerprints(): void
    {
        $parent = ProductAssessmentFixture::identity('42');
        $first = ProductAssessmentFixture::identity('42:variation:101');
        $second = ProductAssessmentFixture::identity('42:variation:102');
        $product = ProductAssessmentFixture::product([
            'productType' => 'variable',
            'variations' => [
                ProductAssessmentFixture::variation($parent, ['identity' => $first]),
                ProductAssessmentFixture::variation($parent, ['identity' => $second]),
            ],
        ]);
        $sourceFingerprint = $product->envelope()->privateContentDigest;
        $decision = new ProductTransferDecision(
            $parent,
            $sourceFingerprint,
            ProductTransferAction::Link,
            501,
            self::sha('target-parent'),
            'owner_approved_link',
            [
                new LinkedVariationDecision($first, 501, 601, CanonicalJson::fingerprint($product->variations[0]->toArray()), self::sha('target-1'), self::sha('operator')),
                new LinkedVariationDecision($second, 501, 602, CanonicalJson::fingerprint($product->variations[1]->toArray()), self::sha('target-2'), self::sha('operator')),
            ],
        );

        $assessment = $this->assessor->assess($product, $this->context(operatorDecision: $decision));
        self::assertSame(AssessmentOutcome::Linked, $assessment->outcome);

        $set = new ProductDecisionSet([$decision]);
        $set->assertCurrent([
            $parent->canonical() => $sourceFingerprint,
            $first->canonical() => CanonicalJson::fingerprint($product->variations[0]->toArray()),
            $second->canonical() => CanonicalJson::fingerprint($product->variations[1]->toArray()),
        ], [
            501 => self::sha('target-parent'),
            'variation:601' => self::sha('target-1'),
            'variation:602' => self::sha('target-2'),
        ]);
        self::addToAssertionCount(1);

        $this->expectException(\RuntimeException::class);
        $set->assertCurrent([
            $parent->canonical() => $sourceFingerprint,
            $first->canonical() => CanonicalJson::fingerprint($product->variations[0]->toArray()),
            $second->canonical() => CanonicalJson::fingerprint($product->variations[1]->toArray()),
        ], [
            501 => self::sha('target-parent'),
            'variation:601' => self::sha('target-1-drift'),
            'variation:602' => self::sha('target-2'),
        ]);
    }

    public function testMissingOrDuplicateLinkVariationBlocks(): void
    {
        $parent = ProductAssessmentFixture::identity('42');
        $first = ProductAssessmentFixture::identity('42:variation:101');
        $second = ProductAssessmentFixture::identity('42:variation:102');
        $product = ProductAssessmentFixture::product([
            'productType' => 'variable',
            'variations' => [
                ProductAssessmentFixture::variation($parent, ['identity' => $first]),
                ProductAssessmentFixture::variation($parent, ['identity' => $second]),
            ],
        ]);

        foreach ([
            [new LinkedVariationDecision($first, 501, 601, self::sha('s1'), self::sha('t1'), self::sha('op'))],
            [
                new LinkedVariationDecision($first, 501, 601, self::sha('s1'), self::sha('t1'), self::sha('op')),
                new LinkedVariationDecision($second, 501, 601, self::sha('s2'), self::sha('t2'), self::sha('op')),
            ],
            [
                new LinkedVariationDecision($first, 501, 601, CanonicalJson::fingerprint($product->variations[0]->toArray()), self::sha('t1'), self::sha('op-1')),
                new LinkedVariationDecision($second, 501, 602, CanonicalJson::fingerprint($product->variations[1]->toArray()), self::sha('t2'), self::sha('op-2')),
            ],
        ] as $links) {
            $decision = new ProductTransferDecision(
                $parent,
                $product->envelope()->privateContentDigest,
                ProductTransferAction::Link,
                501,
                self::sha('target-parent'),
                'owner_approved_link',
                $links,
            );
            $assessment = $this->assessor->assess($product, $this->context(operatorDecision: $decision));
            self::assertSame(AssessmentOutcome::Blocked, $assessment->outcome);
            self::assertSame('linked_variation_set_invalid', $assessment->reasonCode);
        }
    }

    /** @param array<string, bool> $capabilities */
    private function context(
        array $capabilities = [],
        int $dependentOrders = 0,
        array $approvedDraftStatuses = [],
        ?ProductFieldDecisionSet $fieldDecisions = null,
        ?ProductTransferDecision $operatorDecision = null,
    ): ProductAssessmentContext {
        return new ProductAssessmentContext(
            ['standard', 'reduced-rate', 'none'],
            $capabilities + [
                'exact_price_x100' => true,
                'stock_purchase_path' => true,
                'asset_hash_roundtrip' => true,
                'simple_variations' => true,
                'custom_variation_attributes' => true,
                'shared_parent_stock' => true,
                'backorders_yes' => true,
                'backorders_notify' => true,
                'exact_sale_scheduler' => true,
                'catalogue_fields_roundtrip' => true,
                'global_unique_id_roundtrip' => true,
                'product_relations_roundtrip' => true,
                'review_provenance_roundtrip' => true,
                'sales_provenance_roundtrip' => true,
                'extension_metadata_adapter' => true,
                'provenance_readback' => true,
                'subscription_finite_cycles' => true,
                'subscription_trial_days' => true,
                'subscription_setup_fee' => true,
            ],
            $fieldDecisions ?? ProductFieldDecisionSet::all(ProductFieldDisposition::Migrate),
            $dependentOrders,
            0,
            $approvedDraftStatuses,
            $operatorDecision,
        );
    }

    /** @param array<string, ProductFieldDisposition> $overrides */
    private function fieldDecisions(array $overrides): ProductFieldDecisionSet
    {
        $all = ProductFieldDecisionSet::all(ProductFieldDisposition::Migrate)->decisions;
        return new ProductFieldDecisionSet($overrides + $all);
    }

    private static function sha(string $value): string
    {
        return hash('sha256', $value);
    }
}

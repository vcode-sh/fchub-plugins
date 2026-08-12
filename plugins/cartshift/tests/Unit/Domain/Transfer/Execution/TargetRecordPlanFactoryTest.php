<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\Domain\Transfer\Execution;

use CartShift\Domain\Transfer\Customer\CustomerRecord;
use CartShift\Domain\Transfer\Decision\TransferDecisionSet;
use CartShift\Domain\Transfer\Execution\TargetRecordPlanFactory;
use CartShift\Domain\Transfer\Execution\LoadedTargetRecordPlanFactory;
use CartShift\Domain\Transfer\Identity\CheckedMappingStore;
use CartShift\Domain\Transfer\Identity\MapState;
use CartShift\Domain\Transfer\Identity\MappingRecord;
use CartShift\Domain\Transfer\Order\OrderRecord;
use CartShift\Domain\Transfer\Product\ProductStagePlan;
use CartShift\Domain\Transfer\SourceIdentity;
use CartShift\Tests\Unit\Domain\Transfer\Product\ProductAssessmentFixture;
use CartShift\Tests\Unit\PluginTestCase;

final class TargetRecordPlanFactoryTest extends PluginTestCase
{
    private string $package;

    protected function setUp(): void
    {
        parent::setUp();
        $this->package = sys_get_temp_dir() . '/cartshift-target-projector-' . bin2hex(random_bytes(6));
        mkdir($this->package, 0700, true);
        mkdir($this->package . '/assets', 0700);
    }

    protected function tearDown(): void
    {
        @rmdir($this->package . '/assets');
        @rmdir($this->package);
        parent::tearDown();
    }

    public function testProductPlanRequiresAnExactVisibilityDecisionAndPreservesSourceFingerprint(): void
    {
        $record = ProductAssessmentFixture::product();
        $envelope = $record->envelope();
        $factory = $this->factory([$envelope], $this->decisions([[
            'identity' => $record->identity->canonical(),
            'action' => 'activate_catalogue',
            'target_status' => 'publish',
            'source_fingerprint' => $envelope->privateContentDigest,
        ]]));

        $plan = $factory->product($envelope);

        self::assertInstanceOf(ProductStagePlan::class, $plan);
        self::assertSame($envelope->privateContentDigest, $plan->sourceFingerprint);
        self::assertSame('draft', $plan->productFields['post_status']);

        $stale = $this->factory([$envelope], $this->decisions([[
            'identity' => $record->identity->canonical(),
            'action' => 'activate_catalogue',
            'target_status' => 'publish',
            'source_fingerprint' => str_repeat('f', 64),
        ]]));
        $this->expectExceptionMessage('target_record_decision_missing_or_stale');
        $stale->product($envelope);
    }

    public function testLoadedTargetDoesNotClaimAQuantityBearingSharedStockCapability(): void
    {
        $method = new \ReflectionMethod(LoadedTargetRecordPlanFactory::class, 'capabilities');
        $capabilities = $method->invoke(null);

        self::assertIsArray($capabilities);
        self::assertArrayHasKey('shared_parent_stock', $capabilities);
        self::assertFalse($capabilities['shared_parent_stock']);
    }

    public function testLoadedShippingClassesUseTheInstalledFluentCartNameSchema(): void
    {
        $GLOBALS['_cartshift_test_get_results_callback'] = static function (string $query): array {
            self::assertStringContainsString('SELECT id, name FROM wp_fct_shipping_classes', $query);
            self::assertStringNotContainsString('slug', $query);

            return [['id' => 17, 'name' => 'bulky-items']];
        };

        $method = new \ReflectionMethod(LoadedTargetRecordPlanFactory::class, 'shippingClasses');

        self::assertSame(['bulky-items' => 17, 'none' => 0], $method->invoke(null));
    }

    public function testLoadedShippingClassesRefuseToGuessWooSlugsFromFriendlyNames(): void
    {
        $GLOBALS['_cartshift_test_get_results_callback'] = static fn (): array => [
            ['id' => 17, 'name' => 'Bulky Items'],
        ];

        $method = new \ReflectionMethod(LoadedTargetRecordPlanFactory::class, 'shippingClasses');

        $this->expectExceptionMessage('target_shipping_class_mapping_unavailable');
        $method->invoke(null);
    }

    public function testLoadedShippingClassesRejectDuplicateCanonicalNames(): void
    {
        $GLOBALS['_cartshift_test_get_results_callback'] = static fn (): array => [
            ['id' => 17, 'name' => 'bulky-items'],
            ['id' => 18, 'name' => 'bulky-items'],
        ];

        $method = new \ReflectionMethod(LoadedTargetRecordPlanFactory::class, 'shippingClasses');

        $this->expectExceptionMessage('target_shipping_class_state_invalid');
        $method->invoke(null);
    }

    public function testCustomerSameSiteDecisionIsReassessedFromPackageBytes(): void
    {
        $identity = new SourceIdentity('shop-alpha', 'customer', '7');
        $record = CustomerRecord::create($identity, 7, 'registered', 'Ada', 'Lovelace', 'ada@example.test', 'active', [], null, null, [], []);
        $envelope = $record->envelope();
        $factory = $this->factory([$envelope], $this->decisions([[
            'identity' => $identity->canonical(),
            'action' => 'attach_exact_same_site_user',
            'user_id' => 7,
            'source_fingerprint' => $envelope->privateContentDigest,
        ]]));

        $projection = $factory->customer($envelope);

        self::assertSame('attach_exact_same_site_user', $projection['assessment']->action);
        self::assertSame(7, $projection['assessment']->evidence['user_id']);
    }

    public function testProductRelationsCannotBeClaimedAsMigratedWithoutAnExactProvenanceDecision(): void
    {
        $related = ProductAssessmentFixture::identity('43');
        $record = ProductAssessmentFixture::product(['upsellProducts' => [$related]]);
        $envelope = $record->envelope();
        $base = [[
            'identity' => $record->identity->canonical(),
            'action' => 'activate_catalogue',
            'target_status' => 'publish',
            'source_fingerprint' => $envelope->privateContentDigest,
        ]];

        try {
            $this->factory([$envelope], $this->decisions($base))->product($envelope);
            self::fail('An unsupported functional upsell was silently claimed as migrated.');
        } catch (\RuntimeException $exception) {
            self::assertStringStartsWith('target_product_relation_decision_missing_or_stale', $exception->getMessage());
        }

        $relations = ['upsell_products' => [$related->canonical()], 'cross_sell_products' => []];
        $base[0]['relation_policy'] = 'preserve_provenance';
        $base[0]['relation_fingerprint'] = \CartShift\Support\CanonicalJson::fingerprint($relations);
        $plan = $this->factory([$envelope], $this->decisions($base))->product($envelope);

        self::assertSame($relations, $plan->detailFields['other_info']['relation_provenance']);
    }

    public function testUnsupportedPasswordProtectionRequiresAnExplicitLossDecision(): void
    {
        $record = ProductAssessmentFixture::product(['passwordProtected' => true]);
        $envelope = $record->envelope();
        $decision = [[
            'identity' => $record->identity->canonical(),
            'action' => 'leave_catalogue_draft',
            'target_status' => 'draft',
            'source_fingerprint' => $envelope->privateContentDigest,
        ]];

        $this->expectExceptionMessage('target_product_password_protection_decision_missing');
        $this->factory([$envelope], $this->decisions($decision))->product($envelope);
    }

    public function testOrderCannotProjectAnUnmappedCustomerDependency(): void
    {
        $customer = new SourceIdentity('shop-alpha', 'customer', '7');
        $identity = new SourceIdentity('shop-alpha', 'order', '9');
        $record = new OrderRecord(
            $identity, $customer, null, 'checkout', 'completed', 'USD', 'USD', 'USD', '1.0000', 'same_currency:USD',
            false, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, '2026-01-01T00:00:00Z', null, null, null, null,
            [], [], [], [], [], [], [], [], [],
        );

        $this->expectExceptionMessage('target_dependency_mapping_missing:customer');
        $this->factory([$record->envelope()], TransferDecisionSet::empty())->order($record->envelope());
    }

    /** @param list<array<string,mixed>> $rows */
    private function decisions(array $rows): TransferDecisionSet
    {
        return TransferDecisionSet::fromArray(array_map(static fn (array $row): array => $row + [
            'operator' => 'owner',
            'reason' => 'Reviewed target projection.',
            'decided_at' => '2026-08-10T12:00:00Z',
        ], $rows));
    }

    /** @param list<object> $records */
    private function factory(array $records, TransferDecisionSet $decisions): TargetRecordPlanFactory
    {
        return new TargetRecordPlanFactory(
            $decisions,
            new EmptyCheckedMappingStore(),
            $this->package,
            $records,
            [],
            ['standard', 'none'],
            [
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
            ['none' => 0],
            'live',
            false,
            static fn (): array => [],
        );
    }
}

final class EmptyCheckedMappingStore implements CheckedMappingStore
{
    public function get(SourceIdentity $identity): ?MappingRecord { return null; }
    public function storeOrThrow(SourceIdentity $identity, int $targetId, string $migrationId, string $sourceFingerprint, string $targetFingerprint, MapState $state, bool $createdByMigration, int $generation = 1): MappingRecord { throw new \LogicException('unused'); }
    public function transitionOrThrow(SourceIdentity $identity, MapState $expected, MapState $next, string $expectedTargetFingerprint, string $nextTargetFingerprint): MappingRecord { throw new \LogicException('unused'); }
}

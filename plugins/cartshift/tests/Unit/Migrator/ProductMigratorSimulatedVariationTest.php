<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\Migrator;

use CartShift\Migrator\ProductMigrator;
use CartShift\State\MigrationState;
use CartShift\Storage\IdMapRepository;
use CartShift\Storage\MigrationLogRepository;
use CartShift\Support\Constants;
use CartShift\Tests\Unit\PluginTestCase;

require_once dirname(__DIR__, 2) . '/stubs/ProductMigratorStubs.php';

/**
 * Order line items and subscriptions resolve Constants::ENTITY_VARIATION, which
 * MigrationOrchestrator cannot know about — it only registers the entity type its
 * own migrator declares. ProductMigrator::validateRecord() has to register a
 * simulated variation mapping itself, on the success path, so anything validated
 * later in the same dry run can resolve the variation it references.
 */
final class ProductMigratorSimulatedVariationTest extends PluginTestCase
{
    private IdMapRepository $idMap;
    private MigrationLogRepository $log;
    private MigrationState $state;

    protected function setUp(): void
    {
        parent::setUp();

        $this->idMap = new IdMapRepository();
        $this->log   = new MigrationLogRepository();
        $this->state = new MigrationState();

        // The stub wpdb::get_var() defaults to 0, not null, via `?? 0` — which
        // treats an explicitly-null value the same as "unset". getFcId() would
        // then read every miss as FC ID 0 instead of "not migrated".
        $GLOBALS['_cartshift_test_get_var_callback'] = static fn (string $query): null => null;
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['_cartshift_test_get_var_callback']);

        parent::tearDown();
    }

    public function testASimpleProductRegistersItsSimulatedVariationOnlyWhenSimulating(): void
    {
        $migrator = new ProductMigrator($this->idMap, $this->log, $this->state);

        $result = $migrator->validateRecord($this->simpleProduct(65, 'Half hidden'));

        $this->assertTrue($result);
        $this->assertNull(
            $this->idMap->getFcId(Constants::ENTITY_VARIATION, '65'),
            'Outside simulation, validateRecord() must not write anything to the id map.',
        );
    }

    public function testASimpleProductRegistersItsSimulatedVariationWhenSimulating(): void
    {
        $this->idMap->setSimulating(true);

        $migrator = new ProductMigrator($this->idMap, $this->log, $this->state);

        $result = $migrator->validateRecord($this->simpleProduct(65, 'Half hidden'));

        $this->assertTrue($result);

        $variationId = $this->idMap->getFcId(Constants::ENTITY_VARIATION, '65');
        $productId   = $this->idMap->getFcId(Constants::ENTITY_PRODUCT, '65');

        $this->assertNull(
            $productId,
            'validateRecord() registers the variation only — MigrationOrchestrator '
            . 'registers the product itself once validateRecord() returns.',
        );
        $this->assertNotNull($variationId);
        // Deliberately far from MigrationOrchestrator::$simulatedId (base 900,000,000)
        // so the two ranges can never collide, however large a run gets.
        $this->assertSame(950_000_000 + 65, $variationId);
    }

    /**
     * A failed validation (no name) must not leave a variation mapping behind —
     * only the success path registers one.
     */
    public function testAFailedValidationRegistersNothingEvenWhileSimulating(): void
    {
        $this->idMap->setSimulating(true);

        $migrator = new ProductMigrator($this->idMap, $this->log, $this->state);

        $product = new \WC_Product();
        (new \ReflectionProperty(\WC_Product::class, 'id'))->setValue($product, 77);
        // No name set — validateRecord() fails on the empty-name check.

        $result = $migrator->validateRecord($product);

        $this->assertFalse($result);
        $this->assertNull($this->idMap->getFcId(Constants::ENTITY_VARIATION, '77'));
    }

    /**
     * P1 regression: a variable-subscription product used to compare its type
     * against the bare literal 'variable' here too, so $wcVariationIds fell
     * back to `[$wcId]` — one entry, the parent's own id — regardless of how
     * many real children the product had. Every index past the first then
     * resolved through `?? $wcId`, so both of two variations registered a
     * simulated mapping under the *parent's* id instead of their own: the
     * second write silently overwrote the first (same key, same computed
     * value), and neither real WooCommerce variation id ever got an entry at
     * all. An order line item or subscription referencing either one would
     * fail to resolve on the very next tick of the same dry run.
     */
    public function testAVariableSubscriptionRegistersASimulatedVariationPerRealChildWhenSimulating(): void
    {
        $this->idMap->setSimulating(true);

        $migrator = new ProductMigrator($this->idMap, $this->log, $this->state);

        $product = $this->variableSubscriptionProduct(500, 'Yoga Pass', [201, 202]);

        $result = $migrator->validateRecord($product);

        $this->assertTrue($result);

        $this->assertSame(
            950_000_000 + 201,
            $this->idMap->getFcId(Constants::ENTITY_VARIATION, '201'),
            'Each real WooCommerce variation must get its own simulated FluentCart id.',
        );
        $this->assertSame(
            950_000_000 + 202,
            $this->idMap->getFcId(Constants::ENTITY_VARIATION, '202'),
        );
        $this->assertNull(
            $this->idMap->getFcId(Constants::ENTITY_VARIATION, '500'),
            "The parent product's own id must never stand in for one of its variations.",
        );
    }

    private function simpleProduct(int $id, string $name): \WC_Product
    {
        $product = new \WC_Product();

        foreach (['id' => $id, 'name' => $name, 'status' => 'publish'] as $property => $value) {
            (new \ReflectionProperty(\WC_Product::class, $property))->setValue($product, $value);
        }

        return $product;
    }

    /**
     * A variable-subscription parent with two real WC_Product_Variation
     * children, registered with the wc_get_product() stub so
     * ProductMigrator::loadVariations() can resolve them.
     *
     * @param list<int> $variationIds
     */
    private function variableSubscriptionProduct(int $id, string $name, array $variationIds): \WC_Product
    {
        $product = new \WC_Product();

        foreach ([
            'id' => $id,
            'name' => $name,
            'status' => 'publish',
            'type' => 'variable-subscription',
            'children' => $variationIds,
        ] as $property => $value) {
            (new \ReflectionProperty(\WC_Product::class, $property))->setValue($product, $value);
        }

        foreach ($variationIds as $variationId) {
            $variation = new \WC_Product_Variation();

            foreach (['id' => $variationId, 'status' => 'publish', 'price' => '9.99', 'regular_price' => '9.99'] as $property => $value) {
                (new \ReflectionProperty(\WC_Product_Variation::class, $property))->setValue($variation, $value);
            }

            $GLOBALS['_cartshift_test_wc_products'][$variationId] = $variation;
        }

        return $product;
    }
}

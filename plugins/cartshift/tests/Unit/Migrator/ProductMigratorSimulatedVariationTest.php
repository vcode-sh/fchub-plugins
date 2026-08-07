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

    private function simpleProduct(int $id, string $name): \WC_Product
    {
        $product = new \WC_Product();

        foreach (['id' => $id, 'name' => $name, 'status' => 'publish'] as $property => $value) {
            (new \ReflectionProperty(\WC_Product::class, $property))->setValue($product, $value);
        }

        return $product;
    }
}

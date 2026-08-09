<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\Migrator\Entity;

use CartShift\Migrator\SubscriptionMigrator;
use CartShift\State\MigrationState;
use CartShift\Storage\IdMapRepository;
use CartShift\Storage\MigrationLogRepository;
use CartShift\Tests\Unit\PluginTestCase;

require_once dirname(__DIR__, 3) . '/stubs/EntityMigratorStubs.php';

/**
 * missingProductReference() used to `break` after the first line item, so a
 * multi-product subscription could sail through validation while pointing at
 * products that were never migrated.
 *
 * Since 1.2.1 a missing reference no longer means "skip" — processRecord()
 * migrates the subscription paused instead. What this file holds is the
 * detection: every line item is examined, and the description names the item
 * that failed so the log says which one.
 */
final class SubscriptionProductReferenceTest extends PluginTestCase
{
    private SubscriptionMigrator $migrator;

    protected function setUp(): void
    {
        parent::setUp();

        unset($GLOBALS['_cartshift_test_get_var_callback']);
        $GLOBALS['_cartshift_test_id_map'] = [];

        $this->migrator = new SubscriptionMigrator(
            new IdMapRepository(),
            new MigrationLogRepository(),
            new MigrationState(),
        );
    }

    public function testAllItemsPassWhenEveryProductIsMapped(): void
    {
        $this->mapProducts([101, 202, 303]);
        $this->mapVariations([101, 202, 303]);

        $subscription = new \CartShiftTestSubscription(9001, [
            new \CartShiftTestOrderItem(101, 0, 'Monthly Coffee'),
            new \CartShiftTestOrderItem(202, 0, 'Monthly Tea'),
            new \CartShiftTestOrderItem(303, 0, 'Monthly Biscuits'),
        ]);

        $this->assertFalse(
            $this->hasMissingReference($subscription),
            'A fully mapped subscription has nothing missing',
        );
    }

    public function testSecondItemWithAnUnmappedProductIsCaught(): void
    {
        // Only the first product was migrated — and its variation with it,
        // keyed by the product ID, which is what ProductMigrator writes for a
        // simple product. Without the variation row the first item is what gets
        // caught, and this test would pass while proving nothing about the
        // second.
        $this->mapProducts([101]);
        $this->mapVariations([101]);

        $subscription = new \CartShiftTestSubscription(9002, [
            new \CartShiftTestOrderItem(101, 0, 'Monthly Coffee'),
            new \CartShiftTestOrderItem(202, 0, 'Monthly Tea'),
        ]);

        $this->assertTrue(
            $this->hasMissingReference($subscription),
            'The second line item references an unmigrated product; that must be detected',
        );
    }

    public function testThirdItemWithAnUnmappedProductIsCaught(): void
    {
        $this->mapProducts([101, 202]);
        $this->mapVariations([101, 202]);

        $subscription = new \CartShiftTestSubscription(9003, [
            new \CartShiftTestOrderItem(101, 0, 'Monthly Coffee'),
            new \CartShiftTestOrderItem(202, 0, 'Monthly Tea'),
            new \CartShiftTestOrderItem(303, 0, 'Monthly Biscuits'),
        ]);

        $this->assertTrue($this->hasMissingReference($subscription));
    }

    public function testUnmappedVariationOnALaterItemIsCaught(): void
    {
        $this->mapProducts([101, 202]);
        $this->mapVariations([501]);

        $subscription = new \CartShiftTestSubscription(9004, [
            new \CartShiftTestOrderItem(101, 501, 'Coffee — Large'),
            new \CartShiftTestOrderItem(202, 502, 'Tea — Large'),
        ]);

        $this->assertTrue($this->hasMissingReference($subscription));
    }

    public function testWarningNamesTheOffendingItem(): void
    {
        $this->mapProducts([101]);
        $this->mapVariations([101]);

        $subscription = new \CartShiftTestSubscription(9005, [
            new \CartShiftTestOrderItem(101, 0, 'Monthly Coffee'),
            new \CartShiftTestOrderItem(202, 0, 'Monthly Tea'),
        ]);

        $message = (string) $this->describeMissing($subscription);

        $this->assertStringContainsString('202', $message);
        $this->assertStringContainsString('Monthly Tea', $message);
        $this->assertStringContainsString('#2', $message, 'The item position must be 1-based, not the WC item ID');
    }

    public function testWarningFallsBackToThePositionWhenTheItemHasNoName(): void
    {
        $this->mapProducts([101]);
        $this->mapVariations([101]);

        $subscription = new \CartShiftTestSubscription(9006, [
            new \CartShiftTestOrderItem(101, 0, 'Monthly Coffee'),
            new \CartShiftTestOrderItem(202, 0, ''),
        ]);

        $this->assertStringContainsString('#2', (string) $this->describeMissing($subscription));
    }

    public function testAnEmptySubscriptionPasses(): void
    {
        $subscription = new \CartShiftTestSubscription(9007, []);

        $this->assertFalse($this->hasMissingReference($subscription));
    }

    /**
     * The hole product mapping put weight on.
     *
     * The variation check used to be gated on `$wcVariationId > 0`. A simple
     * product's line item carries no variation ID, so for every subscription to
     * a simple product the check never ran at all — and a mapped product whose
     * ENTITY_VARIATION row never landed sailed through into an *active*
     * subscription with variation_id = null.
     */
    public function testASimpleProductWithNoMigratedVariationIsCaught(): void
    {
        // The product resolves; the variation, keyed by the same ID, does not.
        $this->mapProducts([101]);

        $subscription = new \CartShiftTestSubscription(9008, [
            new \CartShiftTestOrderItem(101, 0, 'Monthly Coffee'),
        ]);

        $this->assertTrue(
            $this->hasMissingReference($subscription),
            'A simple product with no migrated variation has nothing to bill against.',
        );
    }

    /**
     * The message must name the product, because for a simple product there is
     * no variation ID to name — "Variation ID 0" is not something anybody can
     * go and look at.
     */
    public function testTheSimpleProductWarningNamesTheProductNotVariationZero(): void
    {
        $this->mapProducts([101]);

        $subscription = new \CartShiftTestSubscription(9009, [
            new \CartShiftTestOrderItem(101, 0, 'Monthly Coffee'),
        ]);

        $message = (string) $this->describeMissing($subscription);

        $this->assertStringContainsString('101', $message);
        $this->assertStringContainsString('Monthly Coffee', $message);
        $this->assertStringNotContainsString('Variation ID 0', $message);
    }

    /**
     * A mapped simple product resolves its variant under the *product* ID —
     * MappingPromoter writes exactly that row, and ProductMigrator writes the
     * same shape for an unmapped one. Detection has to honour the fallback or
     * it pauses every healthy subscription in the shop.
     */
    public function testASimpleProductResolvedThroughTheProductKeyPasses(): void
    {
        $this->mapProducts([101]);
        $this->mapVariations([101]);

        $subscription = new \CartShiftTestSubscription(9010, [
            new \CartShiftTestOrderItem(101, 0, 'Monthly Coffee'),
        ]);

        $this->assertFalse($this->hasMissingReference($subscription));
    }

    // ──────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────

    private function describeMissing(object $subscription): ?string
    {
        $method = new \ReflectionMethod($this->migrator, 'missingProductReference');

        $described = $method->invoke($this->migrator, $subscription);

        return is_string($described) ? $described : null;
    }

    /**
     * True when some product or variation on the subscription did not migrate.
     */
    private function hasMissingReference(object $subscription): bool
    {
        return $this->describeMissing($subscription) !== null;
    }

    /**
     * Teach the IdMapRepository stub which product IDs resolve.
     *
     * @param list<int> $wcIds
     */
    private function mapProducts(array $wcIds): void
    {
        $this->mapEntity('product', $wcIds);
    }

    /**
     * @param list<int> $wcIds
     */
    private function mapVariations(array $wcIds): void
    {
        $this->mapEntity('variation', $wcIds);
    }

    /**
     * @param list<int> $wcIds
     */
    private function mapEntity(string $entityType, array $wcIds): void
    {
        $existing = $GLOBALS['_cartshift_test_id_map'] ?? [];

        foreach ($wcIds as $wcId) {
            $existing[$entityType][(string) $wcId] = $wcId + 10_000;
        }

        $GLOBALS['_cartshift_test_id_map'] = $existing;

        $GLOBALS['_cartshift_test_get_var_callback'] = static function (string $query): int|null {
            if (
                preg_match("/entity_type = '([^']*)' AND wc_id = '([^']*)'/", $query, $matches) !== 1
            ) {
                return 0;
            }

            return $GLOBALS['_cartshift_test_id_map'][$matches[1]][$matches[2]] ?? null;
        };
    }
}

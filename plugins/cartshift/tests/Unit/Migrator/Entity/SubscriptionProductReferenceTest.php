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
        // Only the first product was migrated.
        $this->mapProducts([101]);

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

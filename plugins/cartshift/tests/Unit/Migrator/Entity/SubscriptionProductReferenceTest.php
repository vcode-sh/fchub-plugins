<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\Migrator\Entity;

use CartShift\Migrator\SubscriptionMigrator;
use CartShift\State\MigrationState;
use CartShift\Storage\IdMapRepository;
use CartShift\Storage\MigrationLogRepository;
use CartShift\Support\Enums\MigrationErrorCode;
use CartShift\Tests\Unit\PluginTestCase;

require_once dirname(__DIR__, 3) . '/stubs/EntityMigratorStubs.php';
require_once dirname(__DIR__, 3) . '/stubs/FluentCartModelStubs.php';

/**
 * Which product a subscription bills against, and what happens when the answer
 * is "none of the ones this migration produced".
 *
 * This file used to test one private method. `missingProductReference()`
 * scanned every line item and described the first that did not resolve, and it
 * existed because an earlier version `break`ed after the first item and let
 * multi-product subscriptions sail through pointing at products nobody had
 * migrated.
 *
 * The method is gone and so is the hazard it guarded, from a different
 * direction: a subscription with more than one line item is now refused
 * outright, before any per-item scan could matter, because a FluentCart
 * subscription row holds exactly one product/variation contract and "keep the
 * first item" is data loss with a log entry attached. What survives is the
 * diagnostic quality that made the scan worth having — the log names the
 * product, the item and its position, so the owner is given an instruction
 * rather than a column name — and it is now asserted through the migrator's own
 * public entry point rather than through reflection.
 */
final class SubscriptionProductReferenceTest extends PluginTestCase
{
    private SubscriptionMigrator $migrator;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        unset($GLOBALS['_cartshift_test_get_var_callback']);
        $GLOBALS['_cartshift_test_id_map'] = [];

        \CartShiftFcModelStore::install();

        $this->migrator = new SubscriptionMigrator(
            new IdMapRepository(),
            new MigrationLogRepository(),
            new MigrationState(),
        );
    }

    // ──────────────────────────────────────────────
    // One item, resolved
    // ──────────────────────────────────────────────

    /**
     * A mapped simple product resolves its variant under the *product* ID —
     * `MappingPromoter` writes exactly that row, and `ProductMigrator` writes
     * the same shape for an unmapped one. Resolution has to honour the fallback
     * or it refuses every healthy subscription in the shop.
     */
    public function testASimpleProductResolvedThroughTheProductKeyMigrates(): void
    {
        // Section 8.4: a record WooCommerce was renewing automatically is held
        // at `confirmation_required` until the operator accepts that FluentCart
        // will invoice its customer instead. This test is about the product
        // reference, so it accepts explicitly.
        cartshift_test_accept_manual_fallback();

        $this->mapEntity('customer', [1]);
        $this->mapEntity('order', [901]);
        $this->mapEntity('product', [101]);
        $this->mapEntity('variation', [101]);

        $this->assertNotFalse($this->migrator->processRecord($this->subscription(9010, [
            new \CartShiftTestOrderItem(101, 0, 'Monthly Coffee'),
        ], 901)));

        $this->assertCount(1, \CartShiftFcModelStore::all('Subscription'));
    }

    // ──────────────────────────────────────────────
    // One item, unresolved
    // ──────────────────────────────────────────────

    /**
     * The hole product mapping put weight on.
     *
     * The variation check used to be gated on the source variation ID being
     * greater than zero. A simple product's line item carries no variation ID,
     * so for every subscription to a simple product — which is every Lapka
     * subscription — the check never ran, and a mapped product whose variation
     * row never landed sailed through into an *active* subscription with
     * `variation_id = null`.
     */
    public function testASimpleProductWithNoMigratedVariationIsBlocked(): void
    {
        $this->mapEntity('customer', [1]);
        $this->mapEntity('order', [902]);
        $this->mapEntity('product', [101]);

        $this->assertFalse($this->migrator->processRecord($this->subscription(9008, [
            new \CartShiftTestOrderItem(101, 0, 'Monthly Coffee'),
        ], 902)));

        $this->assertSame([], \CartShiftFcModelStore::all('Subscription'));
        $this->assertLogged(MigrationErrorCode::SubscriptionRequiredReferenceMissing);
    }

    /**
     * The message must name the product, because for a simple product there is
     * no variation ID to name — "Variation ID 0" is not something anybody can
     * go and look at.
     */
    public function testTheSimpleProductMessageNamesTheProductNotVariationZero(): void
    {
        $this->mapEntity('customer', [1]);
        $this->mapEntity('order', [903]);
        $this->mapEntity('product', [101]);

        $this->migrator->processRecord($this->subscription(9009, [
            new \CartShiftTestOrderItem(101, 0, 'Monthly Coffee'),
        ], 903));

        $message = (string) $this->firstMessageFor(MigrationErrorCode::SubscriptionRequiredReferenceMissing);

        $this->assertStringContainsString('101', $message);
        $this->assertStringContainsString('Monthly Coffee', $message);
        $this->assertStringNotContainsString('Variation ID 0', $message);
    }

    public function testAnUnmigratedProductIsNamedAlongsideTheItemItSitsOn(): void
    {
        $this->mapEntity('customer', [1]);
        $this->mapEntity('order', [904]);

        $this->migrator->processRecord($this->subscription(9011, [
            new \CartShiftTestOrderItem(202, 0, 'Monthly Tea'),
        ], 904));

        $message = (string) $this->firstMessageFor(MigrationErrorCode::SubscriptionRequiredReferenceMissing);

        $this->assertStringContainsString('Product ID 202', $message);
        $this->assertStringContainsString('Monthly Tea', $message);
        $this->assertStringContainsString('#1', $message, 'The item position is 1-based, not the WC item ID.');
    }

    public function testAnUnmigratedVariationIsNamedByItsOwnId(): void
    {
        $this->mapEntity('customer', [1]);
        $this->mapEntity('order', [905]);
        $this->mapEntity('product', [101]);

        $this->migrator->processRecord($this->subscription(9012, [
            new \CartShiftTestOrderItem(101, 501, 'Coffee — Large'),
        ], 905));

        $this->assertStringContainsString(
            'Variation ID 501',
            (string) $this->firstMessageFor(MigrationErrorCode::SubscriptionRequiredReferenceMissing),
        );
    }

    // ──────────────────────────────────────────────
    // More than one item
    // ──────────────────────────────────────────────

    /**
     * The per-item scan's whole purpose was to notice the second and third
     * items. It is unnecessary now for the honest reason: there is no
     * destination for them. FluentCart's subscription row carries one
     * `product_id`, one `variation_id` and one `item_name`, so a multi-item
     * source is refused whether or not its products resolved.
     */
    public function testAMultiItemSubscriptionIsBlockedEvenWhenEveryProductResolves(): void
    {
        $this->mapEntity('customer', [1]);
        $this->mapEntity('order', [906]);
        $this->mapEntity('product', [101, 202, 303]);
        $this->mapEntity('variation', [101, 202, 303]);

        $this->assertFalse($this->migrator->processRecord($this->subscription(9001, [
            new \CartShiftTestOrderItem(101, 0, 'Monthly Coffee'),
            new \CartShiftTestOrderItem(202, 0, 'Monthly Tea'),
            new \CartShiftTestOrderItem(303, 0, 'Monthly Biscuits'),
        ], 906)));

        $this->assertSame([], \CartShiftFcModelStore::all('Subscription'));
        $this->assertLogged(MigrationErrorCode::MultiItemSubscription);
    }

    /**
     * Every item is named, not only the ones a truncating mapper would have
     * dropped: the owner has to split the source subscription, and for that
     * they need the whole list rather than the tail of it.
     */
    public function testTheMultiItemMessageNamesEveryItemAndItsPosition(): void
    {
        $this->mapEntity('customer', [1]);
        $this->mapEntity('order', [907]);
        $this->mapEntity('product', [101, 202]);
        $this->mapEntity('variation', [101, 202]);

        $this->migrator->processRecord($this->subscription(9002, [
            new \CartShiftTestOrderItem(101, 0, 'Monthly Coffee'),
            new \CartShiftTestOrderItem(202, 0, 'Monthly Tea'),
        ], 907));

        $message = (string) $this->firstMessageFor(MigrationErrorCode::MultiItemSubscription);

        $this->assertStringContainsString('#1 "Monthly Coffee"', $message);
        $this->assertStringContainsString('#2 "Monthly Tea"', $message);
    }

    /**
     * A line with no name is refused earlier still, and under a different code.
     *
     * `item_name` is `TEXT NOT NULL`, so a nameless line is not a record with a
     * cosmetic gap — it is a source row somebody has to go and repair, and it
     * never becomes a subscription record at all. The multi-item question never
     * arises, which is the correct order: there is no point telling an owner to
     * split a subscription whose items cannot be written either way.
     */
    public function testALineItemWithNoNameIsRefusedBeforeTheMultiItemQuestionArises(): void
    {
        $this->mapEntity('customer', [1]);
        $this->mapEntity('order', [908]);
        $this->mapEntity('product', [101, 202]);
        $this->mapEntity('variation', [101, 202]);

        $this->assertFalse($this->migrator->processRecord($this->subscription(9006, [
            new \CartShiftTestOrderItem(101, 0, 'Monthly Coffee'),
            new \CartShiftTestOrderItem(202, 0, ''),
        ], 908)));

        $this->assertSame([], \CartShiftFcModelStore::all('Subscription'));
        $this->assertLogged(MigrationErrorCode::SubscriptionRequiredReferenceMissing);
        $this->assertSame(0, $this->countLogged(MigrationErrorCode::MultiItemSubscription));
    }

    // ──────────────────────────────────────────────
    // No items at all
    // ──────────────────────────────────────────────

    /**
     * A subscription with no line items has no product to be missing, so the
     * per-item scan had nothing to say about it. That used to be the end of the
     * matter, and this file asserted it: `testAnEmptySubscriptionPasses`.
     *
     * It was true and useless. `fct_subscriptions` requires `product_id`,
     * `variation_id` and `item_name`, none of which an item-less subscription
     * can supply, so "nothing was found wrong" and "this may be written" were
     * never the same claim — and the code that conflated them wrote the
     * malformed Lapka record out with null references and a status of paused.
     */
    public function testAnEmptyLiveSubscriptionIsBlockedBeforeCreate(): void
    {
        $this->mapEntity('customer', [1]);
        $this->mapEntity('order', [909]);

        $this->assertFalse($this->migrator->processRecord($this->subscription(9007, [], 909)));

        $this->assertSame(
            [],
            \CartShiftFcModelStore::all('Subscription'),
            'Nothing may reach the writer with null required references.',
        );
        $this->assertLogged(MigrationErrorCode::SubscriptionRequiredReferenceMissing);
    }

    // ──────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────

    /**
     * @param list<\CartShiftTestOrderItem> $items
     */
    private function subscription(int $id, array $items, int $parentId): \CartShiftTestSubscription
    {
        return new \CartShiftTestSubscription(
            $id,
            $items,
            1,
            'active',
            '',
            null,
            $parentId,
            // Live records need a schedule somebody owns; see section 9.3.
            ['next_payment' => '2099-01-01 00:00:00'],
        );
    }

    private function assertLogged(MigrationErrorCode $code): void
    {
        $this->assertGreaterThan(
            0,
            $this->countLogged($code),
            sprintf('Expected a log row coded "%s".', $code->value),
        );
    }

    private function countLogged(MigrationErrorCode $code): int
    {
        $count = 0;

        foreach ($GLOBALS['_cartshift_test_queries'] ?? [] as $query) {
            if (($query[0] ?? '') !== 'insert') {
                continue;
            }

            if ((string) ($query[2][MigrationLogRepository::CODE_COLUMN] ?? '') === $code->value) {
                $count++;
            }
        }

        return $count;
    }

    private function firstMessageFor(MigrationErrorCode $code): ?string
    {
        foreach ($GLOBALS['_cartshift_test_queries'] ?? [] as $query) {
            if (($query[0] ?? '') !== 'insert') {
                continue;
            }

            if ((string) ($query[2][MigrationLogRepository::CODE_COLUMN] ?? '') === $code->value) {
                return (string) ($query[2]['message'] ?? '');
            }
        }

        return null;
    }

    /**
     * @param list<int> $wcIds
     */
    private function mapEntity(string $entityType, array $wcIds): void
    {
        $existing = $GLOBALS['_cartshift_test_id_map'] ?? [];

        foreach ($wcIds as $wcId) {
            $existing[$entityType][(string) $wcId] = $wcId + 10_000;

            // The target catalogue, stated rather than derived from the map.
            // A simple product's variant carries the product's own ID on both
            // sides; a mapping row is not a catalogue row, and the ownership
            // gate exists to stop treating them as one.
            if ($entityType === 'variation') {
                cartshift_test_own_variation($wcId + 10_000, $wcId + 10_000);
            }
        }

        $GLOBALS['_cartshift_test_id_map'] = $existing;

        $GLOBALS['_cartshift_test_get_var_callback'] = cartshift_test_id_map_reader();
    }
}

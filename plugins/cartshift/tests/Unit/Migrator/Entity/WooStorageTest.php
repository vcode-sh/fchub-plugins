<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\Migrator\Entity;

use CartShift\Support\WooStorage;
use CartShift\Tests\Unit\PluginTestCase;

require_once dirname(__DIR__, 3) . '/stubs/EntityMigratorStubs.php';

final class WooStorageTest extends PluginTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        unset(
            $GLOBALS['_cartshift_test_wc_order_statuses'],
            $GLOBALS['_cartshift_test_post_stati'],
            // Other suites drive the shared OrderUtil stub through these; clear
            // them so isHposEnabled() answers from the option, as it must when
            // WooCommerce is not loaded at all.
            $GLOBALS['_cartshift_test_hpos_enabled'],
            $GLOBALS['_cartshift_test_order_util_throws'],
        );
    }

    public function testMigratableOrderStatusesAreWcPrefixed(): void
    {
        foreach (WooStorage::migratableOrderStatuses() as $status) {
            $this->assertStringStartsWith(
                'wc-',
                $status,
                'wc_orders.status stores order statuses with the wc- prefix',
            );
        }
    }

    public function testMigratableOrderStatusesMatchWcGetOrderStatuses(): void
    {
        $expected = array_keys(wc_get_order_statuses());

        $this->assertSame(
            $expected,
            WooStorage::migratableOrderStatuses(),
            'The migratable set is exactly what wc_get_orders(status => any) resolves to',
        );
    }

    public function testMigratableOrderStatusesExcludeDraftsAndTrash(): void
    {
        $statuses = WooStorage::migratableOrderStatuses();

        $this->assertNotContains('wc-checkout-draft', $statuses, 'Abandoned carts are not orders');
        $this->assertNotContains('checkout-draft', $statuses);
        $this->assertNotContains('trash', $statuses);
        $this->assertNotContains('draft', $statuses);
        $this->assertNotContains('auto-draft', $statuses);
    }

    public function testMigratableOrderStatusesHonourExcludeFromSearch(): void
    {
        $GLOBALS['_cartshift_test_wc_order_statuses'] = [
            'wc-pending'        => 'Pending payment',
            'wc-completed'      => 'Completed',
            'wc-checkout-draft' => 'Draft',
        ];

        $this->assertSame(
            ['wc-pending', 'wc-completed'],
            WooStorage::migratableOrderStatuses(),
            'Statuses registered as exclude_from_search are dropped, mirroring OrdersTableQuery',
        );
    }

    public function testMigratableOrderStatusesFallBackWhenEverythingIsExcluded(): void
    {
        $GLOBALS['_cartshift_test_wc_order_statuses'] = [
            'wc-checkout-draft' => 'Draft',
        ];

        $this->assertNotSame(
            [],
            WooStorage::migratableOrderStatuses(),
            'An empty status set would silently migrate nothing',
        );
    }

    public function testMigratableSubscriptionStatusesAreDistinctFromOrderStatuses(): void
    {
        $subscriptionStatuses = WooStorage::migratableSubscriptionStatuses();

        $this->assertContains('wc-active', $subscriptionStatuses);
        $this->assertContains('wc-pending-cancel', $subscriptionStatuses);
        $this->assertContains('wc-expired', $subscriptionStatuses);
        $this->assertContains('wc-switched', $subscriptionStatuses);
        $this->assertNotContains('wc-completed', $subscriptionStatuses);
        $this->assertNotContains('trash', $subscriptionStatuses);
    }

    public function testNormalizeStatusAddsThePrefix(): void
    {
        $this->assertSame('wc-completed', WooStorage::normalizeStatus('completed'));
        $this->assertSame('wc-completed', WooStorage::normalizeStatus('wc-completed'));
        $this->assertSame('wc-completed', WooStorage::normalizeStatus('  completed  '));
    }

    public function testNormalizeStatusLeavesCoreUnprefixedStatusesAlone(): void
    {
        // WooCommerce deliberately stores these three without the prefix.
        $this->assertSame('trash', WooStorage::normalizeStatus('trash'));
        $this->assertSame('draft', WooStorage::normalizeStatus('draft'));
        $this->assertSame('auto-draft', WooStorage::normalizeStatus('auto-draft'));
        $this->assertSame('', WooStorage::normalizeStatus('   '));
    }






    public function testOrderScopeClauseCombinesTypeAndStatus(): void
    {
        $clause = WooStorage::orderScopeClause('shop_order', ['wc-pending']);

        $this->assertSame("type = 'shop_order' AND status IN ('wc-pending')", $clause);
    }

    public function testOrderScopeClauseWithNoStatusesMatchesNothing(): void
    {
        $this->assertSame(
            "type = 'shop_order' AND 1 = 0",
            WooStorage::orderScopeClause('shop_order', []),
        );
    }

    public function testOrdersTableUsesTheWpdbPrefix(): void
    {
        $this->assertSame('wp_wc_orders', WooStorage::ordersTable());
    }

    public function testIsHposEnabledFallsBackToTheOptionWithoutWooCommerce(): void
    {
        $this->assertFalse(WooStorage::isHposEnabled(), 'Unset option means HPOS is off');

        update_option('woocommerce_custom_orders_table_enabled', 'yes');
        $this->assertTrue(WooStorage::isHposEnabled());

        update_option('woocommerce_custom_orders_table_enabled', 'no');
        $this->assertFalse(WooStorage::isHposEnabled());
    }

    public function testIsHposEnabledSurvivesAHalfBootedWooCommerce(): void
    {
        if (!class_exists('\Automattic\WooCommerce\Utilities\OrderUtil')) {
            $this->markTestSkipped('No OrderUtil stub loaded in this run.');
        }

        // OrderUtil resolves through WooCommerce's DI container and throws when
        // that container is not up yet. The option has to answer instead.
        $GLOBALS['_cartshift_test_order_util_throws'] = true;

        update_option('woocommerce_custom_orders_table_enabled', 'yes');
        $this->assertTrue(WooStorage::isHposEnabled());

        update_option('woocommerce_custom_orders_table_enabled', 'no');
        $this->assertFalse(WooStorage::isHposEnabled());
    }

    // ──────────────────────────────────────────────
    // The boundary this class no longer crosses
    // ──────────────────────────────────────────────

    /**
     * WooStorage builds HPOS table scopes, and the subscription dataset path
     * must not use one.
     *
     * That path reads through WooCommerce's public data-store APIs precisely so
     * WooCommerce chooses its own configured backend — Lapka's is legacy CPT —
     * and the previous reader's defect was exactly this: `countTotal()` counted
     * rows in `{prefix}wc_orders` while `fetchBatch()` hydrated through WCS, so
     * on a legacy-CPT store the total was zero and the fetch was not.
     *
     * Asserted on the source text rather than on behaviour because the failure
     * it guards against is a re-introduction, and by the time a behavioural test
     * could see it the store would need to be on legacy CPT to notice. The
     * authority *report* (`isHposEnabled()`) is deliberately still allowed:
     * saying which backend WooCommerce chose is not the same as choosing one.
     */
    public function testTheSubscriptionDatasetPathDoesNotReadThroughWooStorageTables(): void
    {
        $root = dirname(__DIR__, 4) . '/app';

        // SubscriptionMigrator is named explicitly because that is where the
        // defect actually lived — `countTotal()` counting rows in
        // {prefix}wc_orders while `fetchBatch()` hydrated through WCS. A scan
        // covering only Domain/Subscription would have let it back in at the
        // exact address it came from.
        $paths = [$root . '/Domain/Subscription', $root . '/Migrator/SubscriptionMigrator.php'];

        $offenders = [];

        foreach ($paths as $path) {
            $files = is_dir($path)
                ? new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path))
                : [new \SplFileInfo($path)];

            foreach ($files as $file) {
                if (!$file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }

                $source = (string) file_get_contents($file->getPathname());

                foreach (['ordersTable', 'subscriptionScopeSql', 'orderScopeSql', 'orderScopeParts'] as $forbidden) {
                    if (str_contains($source, 'WooStorage::' . $forbidden)) {
                        $offenders[] = $file->getFilename() . ' -> ' . $forbidden;
                    }
                }
            }
        }

        $this->assertSame([], $offenders);
    }
}

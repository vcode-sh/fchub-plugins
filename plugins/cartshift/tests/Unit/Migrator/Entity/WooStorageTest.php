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

    public function testStatusInClauseQuotesEveryValue(): void
    {
        $clause = WooStorage::statusInClause(['wc-pending', 'wc-completed']);

        $this->assertSame("status IN ('wc-pending', 'wc-completed')", $clause);
    }

    public function testStatusInClauseNormalizesAndDeduplicates(): void
    {
        $clause = WooStorage::statusInClause(['pending', 'wc-pending', 'completed']);

        $this->assertSame("status IN ('wc-pending', 'wc-completed')", $clause);
    }

    public function testStatusInClauseMatchesNothingWhenEmpty(): void
    {
        $this->assertSame('1 = 0', WooStorage::statusInClause([]));
    }

    public function testStatusInClauseSanitizesTheColumnName(): void
    {
        $clause = WooStorage::statusInClause(['wc-pending'], 'o.status; DROP TABLE wp_posts');

        // This used to assert `o.statusDROPTABLEwp_posts`, the strip-list's
        // output: not an injection, but not a column either, so the query died
        // at the database naming something nobody wrote. The allow-list asks
        // the question that was actually being asked — is this a column
        // reference? — and answers no, falling back to a valid one.
        $this->assertStringNotContainsString(';', $clause);
        $this->assertStringNotContainsString('DROP', $clause);
        $this->assertSame("status IN ('wc-pending')", $clause);
    }

    public function testStatusInClauseKeepsAQualifiedColumn(): void
    {
        // The fallback must not swallow the legitimate case it exists to guard.
        $this->assertSame(
            "o.status IN ('wc-pending')",
            WooStorage::statusInClause(['wc-pending'], 'o.status'),
        );
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
}

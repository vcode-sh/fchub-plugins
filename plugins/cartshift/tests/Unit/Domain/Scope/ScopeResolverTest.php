<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\Domain\Scope;

use CartShift\Domain\Scope\MigrationScope;
use CartShift\Domain\Scope\ScopeResolver;
use CartShift\Tests\Unit\PluginTestCase;

final class ScopeResolverTest extends PluginTestCase
{
    private ?\wpdb $originalWpdb = null;

    protected function tearDown(): void
    {
        if ($this->originalWpdb !== null) {
            $GLOBALS['wpdb'] = $this->originalWpdb;
            $this->originalWpdb = null;
        }

        parent::tearDown();
    }

    public function testEverythingRestrictsNothing(): void
    {
        $resolver = new ScopeResolver(MigrationScope::everything());

        $this->assertTrue($resolver->orderPredicate()->isEmpty());
        $this->assertTrue($resolver->registeredCustomerPredicate()->isEmpty());
        $this->assertTrue($resolver->guestCustomerPredicate()->isEmpty());
        $this->assertTrue($resolver->productPredicate()->isEmpty());
        $this->assertTrue($resolver->couponPredicate()->isEmpty());
        $this->assertTrue($resolver->subscriptionPredicate()->isEmpty());
    }

    public function testADateBoundAppliesToOrdersCustomersAndSubscriptionsButNotProducts(): void
    {
        $resolver = new ScopeResolver(
            MigrationScope::fromArray(['mode' => 'since', 'since' => '2024-03-01']),
        );

        $this->assertSame('date_created_gmt >= %s', $resolver->orderPredicate()->sql());
        $this->assertSame(['2024-03-01 00:00:00'], $resolver->orderPredicate()->values());
        $this->assertSame('date_created_gmt >= %s', $resolver->registeredCustomerPredicate()->sql());
        $this->assertSame('date_created_gmt >= %s', $resolver->subscriptionPredicate()->sql());

        // The full catalogue always travels. A product costs little to migrate,
        // and an order pointing at a product that never arrived is a worse
        // outcome than an unused product sitting in the catalogue.
        $this->assertTrue($resolver->productPredicate()->isEmpty());
        $this->assertTrue($resolver->couponPredicate()->isEmpty());
    }

    public function testPickingCustomersBringsTheirOrdersAndThoseOrdersProducts(): void
    {
        $this->stubClosure(
            buyersOfScopedOrders: [7],
            guestsOfScopedOrders: [],
            productsInScopedOrders: [44, 91],
        );

        $resolver = new ScopeResolver(MigrationScope::fromArray([
            'mode'         => 'explicit',
            'customer_ids' => [7],
        ]));

        $this->assertSame([7], $resolver->closedCustomers()['registered']);
        // 44 and 91 were never picked. They come in because an order has to
        // arrive complete.
        $this->assertSame([44, 91], $resolver->closedProductIds());
    }

    public function testPickingAProductDoesNotDragOrdersInUnlessTheOfferWasAccepted(): void
    {
        $this->stubClosure(buyersOfScopedOrders: [7], guestsOfScopedOrders: [], productsInScopedOrders: [44]);

        $resolver = new ScopeResolver(MigrationScope::fromArray([
            'mode'        => 'explicit',
            'product_ids' => [12],
        ]));

        // No customers picked and the upward offer declined: nothing selects an
        // order, so the order predicate matches nothing.
        $this->assertSame('1 = 0', $resolver->orderPredicate()->sql());
        $this->assertSame([12], $resolver->closedProductIds());
        $this->assertSame([], $resolver->closedCustomers()['registered']);
    }

    public function testAProductOnlyScopeSelectsNoSubscriptionsRatherThanAllOfThem(): void
    {
        // ScopePredicate::any() drops matchesNothing() parts and returns none()
        // when nothing survives. Both closed customer sets are empty here, so an
        // unguarded any() would collapse to "no clause" and hand every
        // subscription in the shop to a scope that selected no customers.
        $this->stubClosure(buyersOfScopedOrders: [7], guestsOfScopedOrders: [], productsInScopedOrders: [44]);

        $resolver = new ScopeResolver(MigrationScope::fromArray([
            'mode'        => 'explicit',
            'product_ids' => [12],
        ]));

        $this->assertSame('1 = 0', $resolver->subscriptionPredicate()->sql());
        $this->assertFalse($resolver->subscriptionPredicate()->isEmpty());
    }

    public function testAcceptingTheUpwardOfferPullsOrdersTheirBuyersAndTheirOtherProducts(): void
    {
        $this->stubClosure(
            buyersOfScopedOrders: [7, 19],
            guestsOfScopedOrders: ['bob@example.com'],
            productsInScopedOrders: [12, 44],
        );

        $resolver = new ScopeResolver(MigrationScope::fromArray([
            'mode'                        => 'explicit',
            'product_ids'                 => [12],
            'include_orders_for_products' => true,
        ]));

        $this->assertSame([7, 19], $resolver->closedCustomers()['registered']);
        $this->assertSame(['bob@example.com'], $resolver->closedCustomers()['guests']);
        $this->assertSame([12, 44], $resolver->closedProductIds());
    }

    public function testAClosureBiggerThanTheLimitIsReportedRatherThanTruncated(): void
    {
        $this->stubClosure(
            buyersOfScopedOrders: range(1, ScopeResolver::MAX_CLOSURE_IDS + 1),
            guestsOfScopedOrders: [],
            productsInScopedOrders: [],
        );

        $resolver = new ScopeResolver(MigrationScope::fromArray([
            'mode'        => 'explicit',
            'product_ids' => [12],
            'include_orders_for_products' => true,
        ]));

        $this->assertTrue($resolver->exceedsClosureLimit());
    }

    /**
     * Stand in for the three closure queries: buyers of the seed orders, guest
     * emails of the seed orders, and products in the seed orders.
     *
     * @param list<int>    $buyersOfScopedOrders
     * @param list<string> $guestsOfScopedOrders
     * @param list<int>    $productsInScopedOrders
     */
    private function stubClosure(
        array $buyersOfScopedOrders,
        array $guestsOfScopedOrders,
        array $productsInScopedOrders,
    ): void {
        $this->originalWpdb ??= $GLOBALS['wpdb'];

        $GLOBALS['wpdb'] = new class ($buyersOfScopedOrders, $guestsOfScopedOrders, $productsInScopedOrders) extends \wpdb {
            /**
             * @param list<int>    $buyers
             * @param list<string> $guests
             * @param list<int>    $products
             */
            public function __construct(
                private readonly array $buyers,
                private readonly array $guests,
                private readonly array $products,
            ) {
            }

            #[\Override]
            public function get_col(string $query): array
            {
                $GLOBALS['_cartshift_test_queries'][] = ['get_col', $query];

                if (str_contains($query, 'DISTINCT customer_id')) {
                    return array_map(strval(...), $this->buyers);
                }

                if (str_contains($query, 'DISTINCT billing_email')) {
                    return $this->guests;
                }

                return array_map(strval(...), $this->products);
            }
        };
    }
}

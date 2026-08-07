<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\Domain\Scope;

use CartShift\Domain\Mapping\CouponMapper;
use CartShift\Domain\Scope\MigrationScope;
use CartShift\Domain\Scope\ScopeConsequences;
use CartShift\Domain\Scope\ScopeResolver;
use CartShift\Tests\Unit\PluginTestCase;

final class ScopeConsequencesTest extends PluginTestCase
{
    /**
     * Make the shop look like the real one: two LearnDash `course` products
     * among the simple ones, which ProductMigrator cannot source.
     */
    private function withUnsupportedProductType(string $slug = 'course'): void
    {
        $this->stubResults(static function (string $query) use ($slug): array {
            if (str_contains($query, "tt.taxonomy = 'product_type'") && str_contains($query, 'GROUP BY t.slug')) {
                return [
                    (object) ['slug' => 'simple', 'count' => 23],
                    (object) ['slug' => $slug, 'count' => 2],
                ];
            }

            return [];
        });
    }

    /**
     * Chain a get_results responder onto whatever is already installed, so a
     * test can describe the product-type histogram and its own rows separately.
     */
    private function stubResults(callable $responder): void
    {
        $previous = $GLOBALS['_cartshift_test_get_results_callback'] ?? null;

        $GLOBALS['_cartshift_test_get_results_callback'] = static function (string $query, string $output = OBJECT) use ($responder, $previous): array {
            $rows = $responder($query);

            if ($rows !== []) {
                return $rows;
            }

            return $previous === null ? [] : $previous($query, $output);
        };
    }

    /** @return list<string> Every query the stub was asked to run. */
    private function queries(): array
    {
        return array_map(
            static fn (array $entry): string => (string) ($entry[1] ?? ''),
            $GLOBALS['_cartshift_test_queries'] ?? [],
        );
    }

    private function consequence(array $consequences, string $code): ?array
    {
        foreach ($consequences as $consequence) {
            if ($consequence['code'] === $code) {
                return $consequence;
            }
        }

        return null;
    }

    public function testEverythingProducesNoScopeDrivenConsequences(): void
    {
        $consequences = (new ScopeConsequences(new ScopeResolver(MigrationScope::everything())))->all();

        foreach ($consequences as $consequence) {
            $this->assertSame(
                0,
                $consequence['count'],
                sprintf('%s must be zero when nothing is left behind.', $consequence['code']),
            );
        }
    }

    public function testEveryConsequenceCarriesTheFullDescriptorTheUiRendersFrom(): void
    {
        $consequences = (new ScopeConsequences(new ScopeResolver(MigrationScope::everything())))->all();

        $this->assertNotSame([], $consequences);

        foreach ($consequences as $consequence) {
            $this->assertArrayHasKey('code', $consequence);
            $this->assertArrayHasKey('label', $consequence);
            $this->assertArrayHasKey('hint', $consequence);
            $this->assertArrayHasKey('severity', $consequence);
            $this->assertArrayHasKey('category', $consequence);
            $this->assertArrayHasKey('count', $consequence);
            $this->assertArrayHasKey('remedy', $consequence);
            $this->assertArrayHasKey('is_minimum', $consequence);
            $this->assertIsBool($consequence['is_minimum']);
        }
    }

    /**
     * `is_minimum` is the wire-level fact the UI keys "at least N" off — this
     * pins it as a property of the descriptor, not a fact only true for one
     * hardcoded code today. product_link_missing carries it because
     * productLinkMissingCount() is a structurally narrower query than the
     * truth (see its method doc); every other consequence here is either
     * structurally zero-or-exact under the scope that produces it, so none
     * of the rest may claim to be a floor.
     */
    public function testOnlyProductLinkMissingIsFlaggedAsAMinimum(): void
    {
        $consequences = (new ScopeConsequences(new ScopeResolver(MigrationScope::everything())))->all();

        $flagged = array_values(array_filter(
            $consequences,
            static fn (array $consequence): bool => $consequence['is_minimum'] === true,
        ));

        $this->assertCount(1, $flagged, 'Exactly one consequence is a known structural floor today.');
        $this->assertSame('product_link_missing', $flagged[0]['code']);

        foreach ($consequences as $consequence) {
            if ($consequence['code'] === 'product_link_missing') {
                continue;
            }

            $this->assertFalse(
                $consequence['is_minimum'],
                sprintf('%s must not claim to be a lower bound.', $consequence['code']),
            );
        }
    }

    /**
     * A consequence with a one-click fix must carry the ids that fix needs. A
     * remedy the UI cannot apply is worse than no remedy: it promises.
     *
     * The brief's own version of this test ran only under
     * MigrationScope::everything(), where every remedy is structurally always
     * null — three counters are gated to MODE_EXPLICIT and return a null
     * remedy immediately, and product_link_missing's remedy is unconditionally
     * null. That version asserted nothing on every run (PHPUnit correctly
     * flagged it Risky). This exercises an explicit scope that actually
     * produces a non-null remedy, so the assertions below are reachable.
     *
     * Customer 7 and product 12 are picked; the subscription the stubbed query
     * returns belongs to customer 7 but sells product 99, which was never
     * picked and never arrives via the order closure either — exactly the
     * subscription_paused_missing_product shape.
     */
    public function testARemedyNamesTheProductsThatWouldCloseTheGap(): void
    {
        $GLOBALS['_cartshift_test_get_results_callback'] = static function (string $query): array {
            if (str_contains($query, 'AS subscription_id')) {
                return [(object) ['subscription_id' => 501, 'product_id' => 99]];
            }

            return [];
        };

        $scope = MigrationScope::fromArray([
            'mode'         => 'explicit',
            'customer_ids' => [7],
            'product_ids'  => [12],
        ]);

        $consequences = (new ScopeConsequences(new ScopeResolver($scope)))->all();

        $withRemedy = array_values(array_filter(
            $consequences,
            static fn (array $consequence): bool => $consequence['remedy'] !== null,
        ));

        $this->assertNotSame([], $withRemedy, 'This scope must produce at least one non-null remedy.');

        foreach ($withRemedy as $consequence) {
            $this->assertSame('add_products', $consequence['remedy']['action']);
            $this->assertIsArray($consequence['remedy']['product_ids']);
            $this->assertNotSame([], $consequence['remedy']['product_ids'], 'A remedy with no ids cannot close anything.');
        }

        $subscriptionRow = null;

        foreach ($consequences as $consequence) {
            if ($consequence['code'] === 'subscription_paused_missing_product') {
                $subscriptionRow = $consequence;
            }
        }

        $this->assertNotNull($subscriptionRow, 'subscription_paused_missing_product must be present in every result.');
        $this->assertSame(1, $subscriptionRow['count']);
        $this->assertSame([99], $subscriptionRow['remedy']['product_ids']);
    }

    // ──────────────────────────────────────────────────────────────
    // Losses the migrators cause under every scope, not only a narrow one.
    // ──────────────────────────────────────────────────────────────

    /**
     * "Everything" does not migrate everything — ProductMigrator sources only
     * the supported product types. A subscription selling a LearnDash `course`
     * migrates paused under a plain Everything run, and the receipt used to
     * report zero because the whole counter was gated on MODE_EXPLICIT.
     */
    public function testASubscriptionSellingAnUnmigratableProductCountsUnderEverything(): void
    {
        $this->withUnsupportedProductType();
        $this->stubResults(static fn (string $query): array => str_contains($query, 'AS subscription_id')
            ? [(object) ['subscription_id' => 501, 'product_id' => 10318]]
            : []);

        $consequences = (new ScopeConsequences(new ScopeResolver(MigrationScope::everything())))->all(['subscription']);

        $row = $this->consequence($consequences, 'subscription_paused_missing_product');

        $this->assertNotNull($row);
        $this->assertSame(1, $row['count'], 'An unmigratable product type is a loss in every mode.');
        $this->assertSame([10318], $row['remedy']['product_ids']);
    }

    /**
     * The other half of the same defect: closedProductIds() is not filtered by
     * product type, so under an explicit scope a course product sitting inside
     * the closure satisfied the NOT IN and counted as fine. The type test has
     * to be OR'd in, not left to the closure.
     */
    public function testTheExplicitQueryAsksAboutProductTypeAsWellAsTheClosure(): void
    {
        $this->withUnsupportedProductType();
        $this->stubResults(static fn (string $query): array => str_contains($query, 'AS subscription_id')
            ? [(object) ['subscription_id' => 501, 'product_id' => 10318]]
            : []);

        $scope = MigrationScope::fromArray([
            'mode'         => 'explicit',
            'customer_ids' => [7],
            'product_ids'  => [10318],
        ]);

        (new ScopeConsequences(new ScopeResolver($scope)))->all(['subscription']);

        $subscriptionQuery = null;

        foreach ($this->queries() as $query) {
            if (str_contains($query, 'AS subscription_id')) {
                $subscriptionQuery = $query;
            }
        }

        $this->assertNotNull($subscriptionQuery, 'The subscription consequence must actually ask.');
        $this->assertStringContainsString('NOT IN', $subscriptionQuery, 'The closure test must survive.');
        $this->assertStringContainsString("tt.taxonomy = 'product_type'", $subscriptionQuery, 'The type test must be there too.');
        $this->assertStringContainsString(' OR ', $subscriptionQuery, 'Either reason is a loss — one is enough.');
    }

    /**
     * The guard against the obvious over-correction: with nothing narrowing
     * the catalogue and no unsupported type in it, there is no way for a
     * subscription to lose its product, and asking the database at all risks
     * counting every subscription in the shop.
     */
    public function testNoSubscriptionQueryRunsWhenNothingCanBeMissing(): void
    {
        $consequences = (new ScopeConsequences(new ScopeResolver(MigrationScope::everything())))->all(['subscription']);

        $this->assertSame(0, $this->consequence($consequences, 'subscription_paused_missing_product')['count']);

        foreach ($this->queries() as $query) {
            $this->assertStringNotContainsString('AS subscription_id', $query);
        }
    }

    // ──────────────────────────────────────────────────────────────
    // The coupon consequence and the mapper that decides it.
    // ──────────────────────────────────────────────────────────────

    /**
     * CouponMapper::WIDENING_ON_TOTAL_LOSS is what actually decides whether a
     * lost restriction disables a coupon. This consequence must read that list,
     * not keep a second one — and it can only read it if every key on it maps
     * to a WooCommerce postmeta key here.
     */
    public function testEveryWideningRestrictionIsEvaluable(): void
    {
        $reflected = new \ReflectionClass(ScopeConsequences::class);
        /** @var array<string, string> $meta */
        $meta = $reflected->getConstant('RESTRICTION_META');

        foreach (CouponMapper::WIDENING_ON_TOTAL_LOSS as $restriction) {
            $this->assertArrayHasKey(
                $restriction,
                $meta,
                sprintf('%s widens a coupon on total loss but the preview cannot evaluate it.', $restriction),
            );
        }
    }

    /**
     * The two directions the old list got wrong, in one assertion each on the
     * query it builds: `_exclude_product_ids` is NOT a disabling loss (an
     * excluded product that never arrived has no cart item to have protected),
     * and the two category keys are.
     */
    public function testTheCouponQueryAsksAboutExactlyTheWideningRestrictions(): void
    {
        (new ScopeConsequences(new ScopeResolver(MigrationScope::everything())))->all(['coupon']);

        $bound = null;

        foreach ($GLOBALS['_cartshift_test_queries'] as $entry) {
            if ($entry[0] === 'prepare' && str_contains((string) $entry[1], "'shop_coupon'")) {
                $bound = $entry[2];
            }
        }

        $this->assertNotNull($bound, 'The coupon consequence must ask about coupon meta.');
        $this->assertContains('_product_ids', $bound);
        $this->assertContains('_product_categories', $bound);
        $this->assertContains('_exclude_product_categories', $bound);
        $this->assertNotContains(
            '_exclude_product_ids',
            $bound,
            'An excluded product that never arrived cannot be discounted — the coupon stays active.',
        );
    }

    /**
     * A coupon restricted to a product that is coming across is not affected,
     * whatever else it carries. Without a real answer from the survivor lookup
     * every coupon with any restriction would count, which is the false
     * positive that turned 2 into 20 on the real store.
     */
    public function testACouponKeepingOneRestrictedProductIsNotCounted(): void
    {
        $this->stubResults(static fn (string $query): array => str_contains($query, "'shop_coupon'")
            ? [(object) ['coupon_id' => 88, 'meta_key' => '_product_ids', 'meta_value' => '12,99']]
            : []);

        // Product 12 survives; 99 does not. One survivor is enough — the
        // coupon is narrower than it was, not wider, so it stays active.
        $GLOBALS['_cartshift_test_get_col_callback'] = static fn (string $query): array => str_contains($query, "'product'")
            ? [12]
            : [];

        $consequences = (new ScopeConsequences(new ScopeResolver(MigrationScope::everything())))->all(['coupon']);

        $this->assertSame(0, $this->consequence($consequences, 'coupon_disabled_missing_restrictions')['count']);
    }

    /**
     * The same coupon with no survivors at all: every restricted product is
     * unmigratable, so in FluentCart the restriction reads as "no restriction"
     * and the coupon would discount the shop. Counted, and the remedy names
     * the products.
     */
    public function testACouponLosingEveryRestrictedProductIsCountedUnderEverything(): void
    {
        $this->stubResults(static fn (string $query): array => str_contains($query, "'shop_coupon'")
            ? [(object) ['coupon_id' => 88, 'meta_key' => '_product_ids', 'meta_value' => '10318']]
            : []);

        $GLOBALS['_cartshift_test_get_col_callback'] = static fn (string $query): array => [];

        $consequences = (new ScopeConsequences(new ScopeResolver(MigrationScope::everything())))->all(['coupon']);
        $row = $this->consequence($consequences, 'coupon_disabled_missing_restrictions');

        $this->assertSame(1, $row['count'], 'No mode gate: a coupon can lose its products under Everything too.');
        $this->assertSame([10318], $row['remedy']['product_ids']);
    }

    /**
     * A category restriction that cannot resolve widens the coupon just as a
     * product one does — it is on CouponMapper's list — and the old counter
     * never looked at it. The remedy stays product-only: categories are not a
     * scope decision, migrateCategories() takes the taxonomy whole.
     */
    public function testACouponLosingEveryRestrictedCategoryIsCountedWithoutAProductRemedy(): void
    {
        $this->stubResults(static fn (string $query): array => str_contains($query, "'shop_coupon'")
            ? [(object) ['coupon_id' => 88, 'meta_key' => '_product_categories', 'meta_value' => '5']]
            : []);

        // Term 5 is `uncategorized` (or gone): migrateCategories() skips it,
        // so the restriction resolves to nothing.
        $GLOBALS['_cartshift_test_get_col_callback'] = static fn (string $query): array => [];

        $consequences = (new ScopeConsequences(new ScopeResolver(MigrationScope::everything())))->all(['coupon']);
        $row = $this->consequence($consequences, 'coupon_disabled_missing_restrictions');

        $this->assertSame(1, $row['count']);
        $this->assertNull($row['remedy'], 'Adding a category to the scope is not a thing the owner can do.');
    }

    // ──────────────────────────────────────────────────────────────
    // Consequences belong to the entities being migrated.
    // ──────────────────────────────────────────────────────────────

    /**
     * Untick Orders and "order items link to no product" is not a smaller
     * number, it is not a fact: no order is being migrated to lose a link.
     */
    public function testConsequencesAreFilteredByTheEntitiesBeingMigrated(): void
    {
        $consequences = (new ScopeConsequences(new ScopeResolver(MigrationScope::everything())))->all(['product', 'coupon']);

        $codes = array_map(static fn (array $row): string => $row['code'], $consequences);

        $this->assertSame(['coupon_disabled_missing_restrictions'], $codes);
    }

    public function testAnEmptyEntityListMeansNoFilteringAtAll(): void
    {
        $codes = array_map(
            static fn (array $row): string => $row['code'],
            (new ScopeConsequences(new ScopeResolver(MigrationScope::everything())))->all(),
        );

        $this->assertSame(
            [
                'product_link_missing',
                'customer_rebuilt_from_order',
                'subscription_paused_missing_product',
                'coupon_disabled_missing_restrictions',
            ],
            $codes,
        );
    }
}

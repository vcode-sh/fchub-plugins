<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\Domain\Scope;

use CartShift\Domain\Scope\MigrationScope;
use CartShift\Domain\Scope\ScopeConsequences;
use CartShift\Domain\Scope\ScopeResolver;
use CartShift\Tests\Unit\PluginTestCase;

final class ScopeConsequencesTest extends PluginTestCase
{
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
}

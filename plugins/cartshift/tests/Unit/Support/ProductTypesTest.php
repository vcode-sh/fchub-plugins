<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\Support;

use CartShift\Support\ProductTypes;
use CartShift\Tests\Unit\PluginTestCase;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

/**
 * The single definition of "can CartShift migrate this product?".
 *
 * There were four, and they disagreed on two separate axes — products with no
 * `product_type` term, and subscription types on a store without the add-on.
 * ProductTypePredicateAgreementTest pins that the four callers all read this
 * class; these tests pin what it says.
 */
final class ProductTypesTest extends PluginTestCase
{
    public function testTheSubscriptionTypesAreAbsentWithoutTheAddon(): void
    {
        $this->assertFalse(class_exists('WC_Subscriptions'), 'Precondition for this test file.');

        $this->assertSame(['simple', 'variable'], ProductTypes::supported());
    }

    /**
     * The other side of the gate, and the reason it is a gate rather than a
     * constant: with the add-on loaded the subscription types are migratable,
     * and every caller picks that up from the same method on the same call.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testTheSubscriptionTypesArrivedWithTheAddon(): void
    {
        require_once dirname(__DIR__, 2) . '/stubs/WcSubscriptionsStub.php';

        $this->assertSame(
            ['simple', 'variable', 'subscription', 'variable-subscription'],
            ProductTypes::supported(),
        );
    }

    /**
     * `grouped` and `external` are unmigratable at BOTH layers or the layers
     * disagree: ProductMapper::map() returns null for them, and a predicate
     * that admitted them would source a product the mapper then refuses, which
     * is a log row where there should have been no record at all.
     */
    public function testTheTypesTheMapperRefusesAreNeverSupported(): void
    {
        $supported = ProductTypes::supported();

        $this->assertNotContains('grouped', $supported);
        $this->assertNotContains('external', $supported);
    }

    /**
     * The heart of it. A plain `IN (supported slugs)` needs the term row to
     * exist; WooCommerce reads a missing `product_type` term as simple, so the
     * predicate has to carry a second branch for products that have no term at
     * all. Without it a perfectly ordinary product is dropped from the total,
     * from the batch and from the log at once.
     *
     * @see woocommerce/includes/data-stores/class-wc-product-data-store-cpt.php::get_product_type() (v11.0.0, line 2149)
     */
    public function testTheClauseAdmitsProductsWithNoTypeTermAtAll(): void
    {
        [$sql, $values] = ProductTypes::migratableClause('p.ID');

        $this->assertStringContainsString("t.slug IN (%s, %s)", $sql, 'The positive branch.');
        $this->assertMatchesRegularExpression(
            '/OR\s+p\.ID NOT IN \(\s*SELECT tr\.object_id/',
            $sql,
            'The no-type branch: "carries no product_type term at all" is spelled NOT IN, and its absence '
            . 'is exactly the defect this predicate exists to fix.',
        );
        $this->assertSame(ProductTypes::supported(), $values);
    }

    /**
     * The no-type branch must not be narrowed to the supported slugs. `NOT IN
     * (products whose type is supported)` and `NOT IN (products with any type
     * term)` differ on precisely the rows that matter: the second is "untyped",
     * the first would re-admit every `course` product in the shop.
     */
    public function testTheNoTypeBranchIgnoresSlugsEntirely(): void
    {
        [$sql] = ProductTypes::migratableClause('p.ID');

        $branches = explode('OR', $sql);

        $this->assertCount(2, $branches);
        $this->assertStringNotContainsString('t.slug', $branches[1]);
        $this->assertStringNotContainsString('wp_terms', $branches[1]);
    }

    /**
     * Complement by construction, not by a parallel list. Written
     * independently the two drift, and every row one of them is silent about
     * becomes a row the preview and the migrator disagree on.
     */
    public function testTheUnmigratableClauseIsTheExactNegationOfTheMigratableOne(): void
    {
        [$migratable, $migratableValues] = ProductTypes::migratableClause('p.ID');
        [$unmigratable, $unmigratableValues] = ProductTypes::unmigratableClause('p.ID');

        $this->assertSame('NOT ' . $migratable, $unmigratable);
        $this->assertSame($migratableValues, $unmigratableValues);
    }

    public function testTheClauseIsBuiltForWhicheverColumnTheCallerJoinsOn(): void
    {
        [$sql] = ProductTypes::migratableClause('pml.product_id');

        $this->assertStringContainsString('pml.product_id IN (', $sql);
        $this->assertStringContainsString('pml.product_id NOT IN (', $sql);
        $this->assertStringNotContainsString('p.ID', $sql);
    }

    /**
     * ScopeConsequences applies the predicate to a line-item meta value, which
     * has to be cast before it can be compared to a post ID. The parentheses
     * and spaces of that expression must survive sanitising.
     */
    public function testACastExpressionSurvivesAsAColumn(): void
    {
        [$sql] = ProductTypes::migratableClause('CAST(im.meta_value AS UNSIGNED)');

        $this->assertStringContainsString('CAST(im.meta_value AS UNSIGNED) IN (', $sql);
        $this->assertStringContainsString('CAST(im.meta_value AS UNSIGNED) NOT IN (', $sql);
    }

    /**
     * A column is never user input here, so this is about a programming error
     * staying legible rather than about injection. Stripping "dangerous"
     * characters would have turned this into `p.ID DROP TABLE wp_posts` — still
     * broken, and broken somewhere the caller cannot see.
     */
    public function testAnUnusableColumnFallsBackRatherThanEmittingBrokenSql(): void
    {
        [$sql] = ProductTypes::migratableClause('p.ID; DROP TABLE wp_posts; --');

        $this->assertStringNotContainsString('DROP', $sql);
        $this->assertStringNotContainsString(';', $sql);
        $this->assertStringContainsString('p.ID IN (', $sql);
    }

    public function testTheTypedProductSubqueryNamesOnlyTheProductTypeTaxonomy(): void
    {
        $subquery = ProductTypes::typedProductSubquery();

        $this->assertStringContainsString('SELECT tr.object_id', $subquery);
        $this->assertStringContainsString("tt.taxonomy = 'product_type'", $subquery);
        $this->assertStringNotContainsString('product_cat', $subquery);
    }
}

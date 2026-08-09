<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\Http\Controllers;

use CartShift\Core\Container;
use CartShift\Http\Controllers\MappingController;
use CartShift\Storage\ProductMapRepository;
use CartShift\Tests\Unit\PluginTestCase;
use WP_REST_Request;

/**
 * VariantResolver's last pass pairs the nth Woo variation with the nth
 * FluentCart variant, and "nth" has to mean the position the owner sees.
 *
 * It did not. fcVariants() ordered by `id ASC`, which is creation order, so an
 * owner who had ever dragged a variant up their list got the first Woo
 * variation attached to a variant that is no longer first — and every migrated
 * order line and subscription for it filed against the wrong one, with nothing
 * anywhere saying so. Blank SKUs and unmatched names are the normal case on a
 * hand-built FluentCart product, so the positional pass is not a rare fallback;
 * it is the pass that usually decides.
 *
 * FluentCart's answer is `serial_index ASC`, unanimously across the admin
 * editor, the storefront and every resource that loads the relation, with NULLs
 * leading because that is what MySQL does with a nullable column and FluentCart
 * never overrides it. `id ASC` survives only as the tie-break — see
 * MappingController::fcVariants().
 *
 * The $wpdb double here honours whatever ORDER BY the query carries rather than
 * handing back the fixture array, which is the whole point: a test that ignored
 * the clause would pass against creation order just as happily.
 */
final class FcVariantDisplayOrderTest extends PluginTestCase
{
    private ?\wpdb $originalWpdb = null;

    #[\Override]
    protected function tearDown(): void
    {
        if ($this->originalWpdb !== null) {
            $GLOBALS['wpdb'] = $this->originalWpdb;
            $this->originalWpdb = null;
        }

        parent::tearDown();
    }

    /**
     * The plain case: the owner reordered, so display order and creation order
     * disagree. 502 leads the list the owner sees, so the first Woo variation
     * belongs to 502.
     */
    public function testPositionalPairingFollowsSerialIndexNotCreationOrder(): void
    {
        $map = $this->variantMapFor([
            ['id' => 501, 'serial_index' => 2],
            ['id' => 502, 'serial_index' => 1],
        ]);

        $this->assertSame([421 => 502, 422 => 501], $map);
    }

    /**
     * NULL leads, and this is the shape live data actually has: 58 of the 76
     * variant rows on the live store have no serial_index at all, because
     * FluentCart only assigns one when the owner saves through the product
     * editor. Everything CartShift adds gets max + 1, so a hand-built variant
     * always sorts ahead of a migrated one — which is exactly what the orphan
     * creator's `serial_index = max + 1` was chosen to guarantee.
     */
    public function testANullSerialIndexLeadsTheListJustAsMysqlAndFluentCartHaveIt(): void
    {
        $map = $this->variantMapFor([
            ['id' => 501, 'serial_index' => 1],
            ['id' => 502, 'serial_index' => null],
        ]);

        $this->assertSame([421 => 502, 422 => 501], $map);
    }

    /**
     * Ties need a tie-break, and they are not hypothetical: the live store has
     * a product carrying two variants both at serial_index 1, and a product
     * whose variants are all NULL has nothing but ties. MySQL leaves the order
     * of tied rows unspecified, so the double returns them in fixture order
     * when the query names no second key — a positional map that cannot be
     * reproduced between two runs of the same query is worse than one that is
     * merely in the wrong order.
     */
    public function testTiedRowsAreBrokenByIdSoThePairingIsReproducible(): void
    {
        // Fixture order is deliberately the opposite of id order: without the
        // `id ASC` tie-break the double hands them back exactly like this.
        $map = $this->variantMapFor([
            ['id' => 502, 'serial_index' => 1],
            ['id' => 501, 'serial_index' => 1],
        ]);

        $this->assertSame([421 => 501, 422 => 502], $map);
    }

    /**
     * The unchanged case, kept because the fix must not reorder a catalogue
     * that was already right: a product nobody has reordered has its
     * serial_index in creation order, and the pairing is unchanged.
     */
    public function testAnUntouchedProductPairsExactlyAsItAlwaysDid(): void
    {
        $map = $this->variantMapFor([
            ['id' => 501, 'serial_index' => 1],
            ['id' => 502, 'serial_index' => 2],
        ]);

        $this->assertSame([421 => 501, 422 => 502], $map);
    }

    // ── Fixture ─────────────────────────────────────────────

    /**
     * One variable Woo product against one FluentCart product, resolved through
     * the real controller, matcher and resolver.
     *
     * Both sides carry blank SKUs and no attributes, so the SKU pass and the
     * name pass both find nothing and every pairing here is positional — which
     * is the only pass this file is about.
     *
     * @param list<array{id: int, serial_index: int|null}> $fcVariants in the order the table holds them
     *
     * @return array<int, int> Woo variation id => FluentCart variant id
     */
    private function variantMapFor(array $fcVariants): array
    {
        $GLOBALS['_cartshift_test_wc_products'][42] = $this->wooProduct([
            'id' => 42, 'name' => 'Widget', 'type' => 'variable', 'sku' => '', 'price' => '10.00',
            'children' => [421, 422],
        ]);
        $GLOBALS['_cartshift_test_wc_products'][421] = $this->wooVariation(['id' => 421]);
        $GLOBALS['_cartshift_test_wc_products'][422] = $this->wooVariation(['id' => 422]);

        $this->stubCatalogue($fcVariants);

        $rows = $this->controller()
            ->rows($this->request(['page' => 1, 'per_page' => 50]))
            ->get_data()['data']['rows'];

        $this->assertSame(42, $rows[0]['wc_id'] ?? null, 'Fixture check: the one Woo product came back.');
        $this->assertSame(900, $rows[0]['suggested'] ?? null, 'Fixture check: it matched the one FC product.');

        return $rows[0]['variant']['map'];
    }

    /**
     * @param list<array{id: int, serial_index: int|null}> $fcVariants
     */
    private function stubCatalogue(array $fcVariants): void
    {
        $GLOBALS['_cartshift_test_get_col_callback'] = static fn (string $query): array
            => str_contains($query, 'fct_product_downloads') ? [] : [42];

        $GLOBALS['_cartshift_test_get_var_callback'] = static fn (string $query): int
            => str_contains($query, 'woocommerce_order_itemmeta') ? 0 : 1;

        $GLOBALS['_cartshift_test_get_results_callback'] = static function (string $query) use ($fcVariants): array {
            if (str_contains($query, 'cartshift_product_map')) {
                return [];
            }

            if (str_contains($query, "post_type = 'fluent-products'")) {
                return [(object) ['ID' => 900, 'post_title' => 'Widget']];
            }

            if (!str_contains($query, 'fct_product_variations')) {
                return [];
            }

            return array_map(
                static fn (array $variant): object => (object) [
                    'id'              => $variant['id'],
                    'variation_title' => '',
                    'item_price'      => 1000,
                    'sku'             => '',
                ],
                self::applyOrderBy($query, $fcVariants),
            );
        };
    }

    /**
     * Sort the fixture the way MySQL would, given the query's own ORDER BY.
     *
     * Only the two column names this query can name are understood, which is
     * deliberate — this is a double for one statement, not a SQL engine. NULL
     * sorts first, matching MySQL's ASC. Rows equal on every named key keep
     * fixture order: PHP's sort has been stable since 8.0, and "whatever the
     * table felt like" is precisely what MySQL guarantees for a tie.
     *
     * @param list<array{id: int, serial_index: int|null}> $variants
     *
     * @return list<array{id: int, serial_index: int|null}>
     */
    private static function applyOrderBy(string $query, array $variants): array
    {
        preg_match('/ORDER BY (.+?)(?:\s+LIMIT|$)/s', $query, $matches);

        $keys = [];

        foreach (explode(',', $matches[1] ?? '') as $term) {
            $column = strtok(trim($term), ' ');

            if ($column === 'serial_index' || $column === 'id') {
                $keys[] = $column;
            }
        }

        usort($variants, static function (array $a, array $b) use ($keys): int {
            foreach ($keys as $key) {
                // NULL first, as MySQL orders a nullable column ascending.
                $ordering = ($a[$key] ?? -PHP_INT_MAX) <=> ($b[$key] ?? -PHP_INT_MAX);

                if ($ordering !== 0) {
                    return $ordering;
                }
            }

            return 0;
        });

        return $variants;
    }

    private function controller(): MappingController
    {
        $this->originalWpdb ??= $GLOBALS['wpdb'];

        $GLOBALS['_cartshift_test_product_map_rows'] = [];

        $container = new Container();
        $container->instance(ProductMapRepository::class, new ProductMapRepository());

        return new MappingController($container);
    }

    private function request(array $params): WP_REST_Request
    {
        $request = new WP_REST_Request();

        foreach ($params as $key => $value) {
            $request->set_param($key, $value);
        }

        return $request;
    }

    private function wooProduct(array $overrides): \WC_Product
    {
        return $this->hydrate(new \WC_Product(), $overrides + [
            'id' => 1, 'name' => 'Product', 'type' => 'simple', 'sku' => '', 'price' => '0', 'children' => [],
        ]);
    }

    private function wooVariation(array $overrides): \WC_Product_Variation
    {
        return $this->hydrate(new \WC_Product_Variation(), $overrides + [
            'id' => 1, 'name' => '', 'sku' => '', 'attributes' => [],
        ]);
    }

    /**
     * The WC_Product stub declares protected properties, no constructor and no
     * setters, so reflection is how every test file in this suite fills one.
     *
     * @template T of object
     * @param T $product
     * @return T
     */
    private function hydrate(object $product, array $data): object
    {
        $ref = new \ReflectionClass($product);

        foreach ($data as $key => $value) {
            if ($ref->hasProperty($key)) {
                $ref->getProperty($key)->setValue($product, $value);
            }
        }

        return $product;
    }
}

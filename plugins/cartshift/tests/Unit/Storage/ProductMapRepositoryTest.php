<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\Storage;

use CartShift\Domain\Mapping\ProductMapDecision;
use CartShift\Storage\ProductMapRepository;
use CartShift\Tests\Unit\PluginTestCase;

/**
 * The staging table is the owner's draft, not the migration's record. These
 * tests pin the two properties that follow from that: one decision per Woo
 * product (last write wins), and a variant map that survives the round trip
 * as integers rather than JSON's strings.
 */
final class ProductMapRepositoryTest extends PluginTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $GLOBALS['_cartshift_test_product_map_rows'] = [];

        // A fake honouring the UNIQUE(wc_id) the schema declares, so "last write
        // wins" is tested rather than assumed.
        $GLOBALS['_cartshift_test_insert_callback'] = static function (string $table, array $data): int {
            if (!str_contains($table, 'cartshift_product_map')) {
                return 1;
            }

            $GLOBALS['_cartshift_test_product_map_rows'][(int) $data['wc_id']] = $data;

            return 1;
        };

        $GLOBALS['_cartshift_test_get_results_callback'] = static function (string $query): array {
            if (!str_contains($query, 'cartshift_product_map')) {
                return [];
            }

            $rows = [];

            foreach ($GLOBALS['_cartshift_test_product_map_rows'] as $row) {
                if (str_contains($query, "decision = 'link'") && $row['decision'] !== 'link') {
                    continue;
                }

                if (str_contains($query, "decision = 'skip'") && $row['decision'] !== 'skip') {
                    continue;
                }

                $rows[] = (object) $row;
            }

            return $rows;
        };
    }

    public function testALinkDecisionRoundTrips(): void
    {
        $repo = new ProductMapRepository();

        $repo->save(ProductMapDecision::link(42, 'variable', 900, 'strong', [11 => 501, 12 => 502]));

        $all = $repo->all();

        $this->assertCount(1, $all);
        $this->assertSame(42, $all[0]->wcId());
        $this->assertSame('link', $all[0]->decision());
        $this->assertSame(900, $all[0]->fcPostId());
        $this->assertSame([11 => 501, 12 => 502], $all[0]->variantMap());
    }

    public function testTheVariantMapSurvivesAsIntegers(): void
    {
        $repo = new ProductMapRepository();

        $repo->save(ProductMapDecision::link(42, 'variable', 900, 'strong', [11 => 501]));

        $map = $repo->all()[0]->variantMap();

        // json_decode hands back string keys and would quietly poison every
        // getFcId() lookup built from them.
        $this->assertSame([11 => 501], $map);
        $this->assertIsInt(array_key_first($map));
        $this->assertIsInt($map[11]);
    }

    public function testTheLastDecisionForAProductWins(): void
    {
        $repo = new ProductMapRepository();

        $repo->save(ProductMapDecision::link(42, 'simple', 900, 'strong', []));
        $repo->save(ProductMapDecision::skip(42, 'simple', 'strong'));

        $all = $repo->all();

        $this->assertCount(1, $all);
        $this->assertSame('skip', $all[0]->decision());
        $this->assertNull($all[0]->fcPostId());
    }

    public function testSkippedProductIdsReturnsOnlySkips(): void
    {
        $repo = new ProductMapRepository();

        $repo->save(ProductMapDecision::skip(1, 'simple', 'none'));
        $repo->save(ProductMapDecision::create(2, 'simple', 'none'));
        $repo->save(ProductMapDecision::skip(3, 'simple', 'none'));

        $this->assertSame([1, 3], $repo->skippedProductIds());
    }

    public function testOrphanVariationsRoundTripAlongsideTheMap(): void
    {
        $repo = new ProductMapRepository();

        $repo->save(ProductMapDecision::link(
            42,
            'variable',
            900,
            'likely',
            [11 => 501],
            [[
                'id'               => 13,
                'sku'              => 'TS-XL',
                'name'             => 'XL',
                'price'            => 1999,
                'fulfillment_type' => 'digital',
                'downloadable'     => 'true',
            ]],
        ));

        $decision = $repo->all()[0];

        $this->assertSame([11 => 501], $decision->variantMap());
        $this->assertSame(
            [[
                'id'               => 13,
                'sku'              => 'TS-XL',
                'name'             => 'XL',
                'price'            => 1999,
                'fulfillment_type' => 'digital',
                'downloadable'     => 'true',
            ]],
            $decision->orphans(),
            'Promotion needs the orphan list to know which variants to add — and what to add them as. '
            . 'A price that does not survive the round trip is a free item in the owner catalogue.',
        );
    }

    /**
     * A decision saved before the descriptor carried price, fulfilment and
     * downloadability is still sitting in the staging table, and must not be
     * read as "this variation was free". Absent is null and empty, which the
     * creator resolves against the linked product instead.
     */
    public function testALegacyOrphanDescriptorReportsWhatItDoesNotKnow(): void
    {
        $repo = new ProductMapRepository();

        $GLOBALS['_cartshift_test_product_map_rows'][42] = [
            'wc_id'       => 42,
            'wc_type'     => 'variable',
            'decision'    => 'link',
            'fc_post_id'  => 900,
            'band'        => 'strong',
            'variant_map' => '{"map":{"11":501},"orphans":[{"id":13,"sku":"","name":"XL"}]}',
        ];

        $orphan = $repo->all()[0]->orphans()[0];

        $this->assertNull($orphan['price'], 'A missing price is not a price of zero.');
        $this->assertSame('', $orphan['fulfillment_type']);
        $this->assertSame('', $orphan['downloadable']);
    }

    /**
     * The descriptor makes a round trip through the browser, so what comes
     * back is client input aimed at a column FluentCart branches on when it
     * decides whether an order needs a shipping address.
     */
    public function testAnUnrecognisedFulfillmentTypeIsDiscardedRatherThanStored(): void
    {
        $repo = new ProductMapRepository();

        $repo->save(ProductMapDecision::link(42, 'variable', 900, 'strong', [], [[
            'id'               => 13,
            'sku'              => '',
            'name'             => 'XL',
            'fulfillment_type' => 'teleportation',
            'downloadable'     => 'perhaps',
        ]]));

        $orphan = $repo->all()[0]->orphans()[0];

        $this->assertSame('', $orphan['fulfillment_type']);
        $this->assertSame('', $orphan['downloadable']);
    }

    public function testALegacyBareVariantMapStillDecodes(): void
    {
        // Rows written before the envelope existed hold the bare map.
        $repo = new ProductMapRepository();

        $GLOBALS['_cartshift_test_product_map_rows'][42] = [
            'wc_id'       => 42,
            'wc_type'     => 'variable',
            'decision'    => 'link',
            'fc_post_id'  => 900,
            'band'        => 'strong',
            'variant_map' => '{"11":501}',
        ];

        $decision = $repo->all()[0];

        $this->assertSame([11 => 501], $decision->variantMap());
        $this->assertSame([], $decision->orphans());
    }

    public function testACreateDecisionCarriesNoFcProduct(): void
    {
        $repo = new ProductMapRepository();

        $repo->save(ProductMapDecision::create(7, 'simple', 'none'));

        $this->assertNull($repo->all()[0]->fcPostId());
        $this->assertSame([], $repo->all()[0]->variantMap());
    }

    /**
     * fromRow() is the single point translating a stored `link` row into a
     * decision object. A link with nowhere to point is not a link — it is
     * downgraded to `create`, or a consumer three steps removed
     * (MappingPromoter) would write an ID map row aimed at a null FluentCart
     * post. Checked through both read paths since get() and all() must agree.
     */
    public function testALinkRowWithNoFcPostIdDowngradesToCreate(): void
    {
        $repo = new ProductMapRepository();

        $GLOBALS['_cartshift_test_product_map_rows'][42] = [
            'wc_id'       => 42,
            'wc_type'     => 'variable',
            'decision'    => 'link',
            'fc_post_id'  => null,
            'band'        => 'strong',
            'variant_map' => null,
        ];

        $fromAll = $repo->all()[0];
        $fromGet = $repo->get(42);

        $this->assertSame(
            'create',
            $fromAll->decision(),
            'A link row with no fc_post_id must not be reported as a link pointing nowhere.',
        );
        $this->assertNull($fromAll->fcPostId());

        $this->assertNotNull($fromGet);
        $this->assertSame(
            'create',
            $fromGet->decision(),
            'get() shares fromRow() with all() — the downgrade must hold on both read paths.',
        );
        $this->assertNull($fromGet->fcPostId());
    }

    /**
     * fromRow()'s guard is `$fcPostId === null || $fcPostId <= 0` — a distinct
     * branch from the null case above. 0 is what a loose cast of an empty
     * string or a missing column produces, and it is exactly as much
     * "nowhere to point" as null is, so it must downgrade the same way.
     */
    public function testALinkRowWithZeroFcPostIdAlsoDowngradesToCreate(): void
    {
        $repo = new ProductMapRepository();

        $GLOBALS['_cartshift_test_product_map_rows'][42] = [
            'wc_id'       => 42,
            'wc_type'     => 'variable',
            'decision'    => 'link',
            'fc_post_id'  => 0,
            'band'        => 'strong',
            'variant_map' => null,
        ];

        $decision = $repo->all()[0];

        $this->assertSame(
            'create',
            $decision->decision(),
            'fc_post_id = 0 is as much "nowhere to point" as null — both must downgrade.',
        );
        $this->assertNull($decision->fcPostId());
    }

    /**
     * linked() queries `decision = 'link'` at the SQL level, but a row can
     * carry that column value while fromRow() still downgrades it in PHP (no
     * fc_post_id). The array_filter() after mapping is what actually keeps
     * such a row out of the result — this test is what stops a future
     * "simplification" from deleting that re-filter as apparently redundant
     * and letting MappingPromoter (Tasks 5/5b/8) write an ID map row pointing
     * at nothing. A genuine link is saved alongside the broken one so the
     * assertion also proves the filter does not over-exclude.
     */
    public function testADowngradedLinkIsExcludedFromLinked(): void
    {
        $repo = new ProductMapRepository();

        // Column says 'link' but there is nowhere to point — the row the
        // SQL-level filter alone would let through.
        $GLOBALS['_cartshift_test_product_map_rows'][42] = [
            'wc_id'       => 42,
            'wc_type'     => 'variable',
            'decision'    => 'link',
            'fc_post_id'  => null,
            'band'        => 'strong',
            'variant_map' => null,
        ];

        $repo->save(ProductMapDecision::link(43, 'simple', 901, 'strong', []));

        $linked = $repo->linked();

        $this->assertCount(
            1,
            $linked,
            'linked() must not trust the decision column alone — a link with no target is not linked.',
        );
        $this->assertSame(43, $linked[0]->wcId());
    }
}

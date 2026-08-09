<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\Domain\Migration;

use CartShift\Domain\Mapping\ProductMapDecision;
use CartShift\Domain\Migration\MappingPromoter;
use CartShift\Domain\Scope\MigrationScope;
use CartShift\Domain\Scope\ScopeResolver;
use CartShift\Storage\IdMapRepository;
use CartShift\Storage\ProductMapRepository;
use CartShift\Support\Constants;
use CartShift\Tests\Unit\PluginTestCase;

/**
 * Promotion has to cover exactly what the run covers — no more, and no less.
 *
 * The staging table outlives a run. An owner maps their catalogue once and then
 * runs "just these four products", or "everything since March", and Monday's
 * decisions are all still sitting there. Promoting them regardless meant a
 * narrowed run created variants inside FluentCart products it was never going
 * to migrate — writes into a live catalogue nobody asked for — and stamped them
 * with this run's migration id, so rolling the run back deleted variants it had
 * no business creating.
 *
 * Both directions are tested, and the *widening* one is the more dangerous
 * regression: a filter that is slightly too eager turns an "Everything" run
 * into a partial one, silently, on the mode almost every owner uses. Hence a
 * test per mode rather than one test for the interesting case.
 *
 * The harness is MappingPromoterTest's, trimmed: the same real repositories
 * driven through the same $wpdb stub globals, because IdMapRepository and
 * ProductMapRepository are both `final` and cannot be subclassed.
 */
final class MappingPromoterScopeTest extends PluginTestCase
{
    /** @var list<array{0: string, 1: string, 2: int, 3: string, 4: bool}> */
    private array $stored = [];

    /** @var list<int> WooCommerce variation ids the promoter was asked to create a variant for. */
    private array $created = [];

    /** @var list<int> FluentCart products promotion asked about the existence of. */
    private array $probed = [];

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->stored  = [];
        $this->created = [];
        $this->probed  = [];

        $GLOBALS['_cartshift_test_product_map_rows'] = [];

        $stored = &$this->stored;

        $GLOBALS['_cartshift_test_insert_callback'] = static function (string $table, array $data) use (&$stored): int {
            if (str_contains($table, 'cartshift_id_map')) {
                $stored[] = [
                    $data['entity_type'],
                    $data['wc_id'],
                    (int) $data['fc_id'],
                    $data['migration_id'],
                    (bool) $data['created_by_migration'],
                ];

                return 1;
            }

            if (str_contains($table, 'cartshift_product_map')) {
                $GLOBALS['_cartshift_test_product_map_rows'][(int) $data['wc_id']] = $data;

                return 1;
            }

            return 1;
        };

        $GLOBALS['_cartshift_test_get_var_callback'] = static function (string $query) use (&$stored): int|string|null {
            if (!str_contains($query, 'cartshift_id_map')) {
                return null;
            }

            preg_match("/entity_type = '([^']*)' AND wc_id = '([^']*)'/", $query, $matches);

            foreach ($stored as $row) {
                if ($row[0] === $matches[1] && $row[1] === $matches[2]) {
                    return $row[2];
                }
            }

            return null;
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

    #[\Override]
    protected function tearDown(): void
    {
        unset(
            $GLOBALS['_cartshift_test_insert_callback'],
            $GLOBALS['_cartshift_test_get_var_callback'],
            $GLOBALS['_cartshift_test_get_results_callback'],
            $GLOBALS['_cartshift_test_product_map_rows'],
        );

        parent::tearDown();
    }

    // ── The three modes ─────────────────────────────────────

    /**
     * The regression most likely to slip past: a scope filter that narrows the
     * one mode that asked for no narrowing at all. "Everything" is also
     * MigrationScope's fallback for any unusable input, so a bug here would hit
     * runs that never chose a scope in the first place.
     */
    public function testAnEverythingRunPromotesEveryStagedLink(): void
    {
        $result = $this->promoteUnder(MigrationScope::everything());

        $this->assertSame(3, $result['linked']);
        $this->assertSame([], $result['outOfScope']);
        $this->assertSame([42, 43, 44], $this->promotedProductIds());
    }

    /**
     * A date-limited run also promotes everything, and that is not an oversight.
     *
     * Products are not selected by the date and never were: ScopeResolver
     * ::productPredicate() is unrestricted in this mode by explicit design —
     * open question 1 in the design spec — because an in-scope order pointing at
     * a product that never arrived is worse than an unused product sitting in
     * the catalogue. So ProductMigrator would create a fresh FluentCart product
     * for every one of these, and declining to promote the link is exactly how
     * the owner's hand-built product ends up duplicated.
     */
    public function testASinceRunPromotesEveryStagedLinkBecauseItTakesTheWholeCatalogue(): void
    {
        $result = $this->promoteUnder(
            MigrationScope::fromArray(['mode' => 'since', 'since' => '2024-03-01']),
        );

        $this->assertSame(3, $result['linked']);
        $this->assertSame([], $result['outOfScope']);
        $this->assertSame([42, 43, 44], $this->promotedProductIds());
    }

    /**
     * The mode that bounds the catalogue is the only one promotion narrows for.
     */
    public function testAnExplicitRunPromotesOnlyTheProductsItSelected(): void
    {
        $result = $this->promoteUnder($this->explicitScope([42, 44]));

        $this->assertSame(2, $result['linked']);
        $this->assertSame([43], $result['outOfScope']);
        $this->assertSame([42, 44], $this->promotedProductIds());
    }

    // ── What "declined" has to mean ─────────────────────────

    /**
     * Declining is not "promote quietly". The whole point is the writes that do
     * not happen: no ID map row for the product, none for its variants, and — the
     * one that reaches the owner's live catalogue — no variant added to a
     * FluentCart product this run was never going to touch.
     */
    public function testAnOutOfScopeDecisionWritesNothingAnywhere(): void
    {
        $decision = ProductMapDecision::link(
            43,
            'variable',
            901,
            'strong',
            [11 => 501],
            [['id' => 12, 'sku' => 'XL', 'name' => 'XL', 'price' => 1000, 'fulfillment_type' => 'physical', 'downloadable' => 'false']],
        );

        $result = $this->promoter([$decision])->promote('run-1', $this->explicitScope([42]));

        $this->assertSame([43], $result['outOfScope']);
        $this->assertSame(0, $result['linked']);
        $this->assertSame(0, $result['variants']);
        $this->assertSame(0, $result['added']);
        $this->assertSame([], $this->stored, 'An out-of-scope decision must not reach the ID map at all.');
        $this->assertSame([], $this->created, 'A variant in a product this run never migrates is the whole bug.');
    }

    /**
     * Cheap as well as safe: the check comes before every read the decision
     * would otherwise cause, so a large stale staging table costs a narrowed run
     * nothing.
     */
    public function testAnOutOfScopeDecisionIsNotEvenLookedUp(): void
    {
        $this->promoteUnder($this->explicitScope([42]));

        $this->assertSame([900], $this->probed, 'Only the in-scope decision should have been resolved at all.');
    }

    /**
     * The spec's edge-case table: "Mapping exists for a product no longer in
     * scope → Ignored at promotion. Kept in the table — scope may change on the
     * next run." Ignored, never deleted; the owner's next run may be wider, and
     * a decision quietly dropped is a decision they would have to make twice.
     */
    public function testAnOutOfScopeDecisionSurvivesForTheNextRun(): void
    {
        $map = $this->mapRepo($this->threeLinks());

        $this->promoter($this->threeLinks(), $map)->promote('run-1', $this->explicitScope([42]));

        $this->assertCount(3, $map->linked(), 'Out-of-scope decisions are filtered, never deleted.');

        // A later, wider run finds it still there and promotes it. 42 is already
        // promoted by then, so only the two it declined the first time land.
        $second = $this->promoter($this->threeLinks(), $map)
            ->promote('run-2', new ScopeResolver(MigrationScope::everything()));

        $this->assertSame(2, $second['linked']);
        $this->assertSame([], $second['outOfScope']);
    }

    /**
     * The bound is the closure, not the owner's raw picks.
     *
     * closedProductIds() is what ProductMigrator migrates: the picked products
     * *plus* every product an in-scope order references, because an order
     * arrives complete or not at all. Promotion filtering on the raw picks
     * instead would decline links for products the run does migrate — and then
     * duplicate the owner's hand-built product.
     */
    public function testTheBoundIsTheResolvedClosureNotTheRawPicks(): void
    {
        $original = $GLOBALS['wpdb'];

        // 43 was never picked. It comes in because an in-scope order sells it.
        $GLOBALS['wpdb'] = new class extends \wpdb {
            #[\Override]
            public function get_col(string $query): array
            {
                return ['43'];
            }
        };

        try {
            $result = $this->promoteUnder(MigrationScope::fromArray([
                'mode'                        => 'explicit',
                'product_ids'                 => [42],
                'include_orders_for_products' => true,
            ]));
        } finally {
            $GLOBALS['wpdb'] = $original;
        }

        $this->assertSame([42, 43], $this->promotedProductIds());
        $this->assertSame([44], $result['outOfScope']);
    }

    // ── Fixtures ────────────────────────────────────────────

    /**
     * Three staged links against three separate FluentCart products.
     *
     * @return list<ProductMapDecision>
     */
    private function threeLinks(): array
    {
        return [
            ProductMapDecision::link(42, 'simple', 900, 'strong', [42 => 777]),
            ProductMapDecision::link(43, 'simple', 901, 'strong', [43 => 778]),
            ProductMapDecision::link(44, 'simple', 902, 'strong', [44 => 779]),
        ];
    }

    /**
     * @return array{linked: int, variants: int, added: int, skipped: list<int>, outOfScope: list<int>, dead: list<int>, failed: list<int>, foreign: list<int>, fileless: list<int>}
     */
    private function promoteUnder(MigrationScope|ScopeResolver $scope): array
    {
        $resolver = $scope instanceof ScopeResolver ? $scope : new ScopeResolver($scope);

        return $this->promoter($this->threeLinks())->promote('run-1', $resolver);
    }

    /** @param list<int> $productIds */
    private function explicitScope(array $productIds): ScopeResolver
    {
        // No customers and no upward offer, so ScopeResolver's seed predicate is
        // "no orders" and the closure needs no query — the picks are the whole
        // answer. testTheBoundIsTheResolvedClosureNotTheRawPicks covers the
        // branch that does query.
        return new ScopeResolver(MigrationScope::fromArray([
            'mode'        => 'explicit',
            'product_ids' => $productIds,
        ]));
    }

    /** @param list<ProductMapDecision> $decisions */
    private function promoter(array $decisions, ?ProductMapRepository $map = null): MappingPromoter
    {
        $probed = &$this->probed;
        $created = &$this->created;

        return new MappingPromoter(
            $map ?? $this->mapRepo($decisions),
            new IdMapRepository(),
            static function (int $fcPostId) use (&$probed): bool {
                $probed[] = $fcPostId;

                return true;
            },
            static function (int $fcPostId, array $orphan) use (&$created): ?int {
                $created[] = $orphan['id'];

                return 9000 + $orphan['id'];
            },
            static fn (int $fcPostId): array => self::everyMappedVariantId($decisions),
            static fn (int $wcId, int $fcPostId): bool => false,
        );
    }

    /** @param list<ProductMapDecision> $decisions */
    private function mapRepo(array $decisions): ProductMapRepository
    {
        $repo = new ProductMapRepository();
        $repo->saveMany($decisions);

        return $repo;
    }

    /**
     * @param list<ProductMapDecision> $decisions
     * @return list<int>
     */
    private static function everyMappedVariantId(array $decisions): array
    {
        $ids = [];

        foreach ($decisions as $decision) {
            foreach ($decision->variantMap() as $fcVariationId) {
                $ids[] = $fcVariationId;
            }
        }

        return $ids;
    }

    /**
     * The WooCommerce product ids promotion actually filed, in the order it
     * filed them.
     *
     * @return list<int>
     */
    private function promotedProductIds(): array
    {
        $ids = [];

        foreach ($this->stored as $row) {
            if ($row[0] === Constants::ENTITY_PRODUCT) {
                $ids[] = (int) $row[1];
            }
        }

        return $ids;
    }
}

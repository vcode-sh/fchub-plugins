<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\Domain\Migration;

use CartShift\Domain\Mapping\ProductMapDecision;
use CartShift\Domain\Migration\MappingPromoter;
use CartShift\Storage\IdMapRepository;
use CartShift\Storage\ProductMapRepository;
use CartShift\Support\Constants;
use CartShift\Tests\Unit\PluginTestCase;

/**
 * Promotion is the whole feature in one method: a `link` becomes an ID map row
 * with created_by_migration = 0, and every migrator downstream inherits it
 * without knowing this class exists.
 *
 * The flag is not cosmetic. Rollback deletes only created_by_migration = 1, so
 * a promotion that got it wrong would let a rollback delete a product the shop
 * owner built by hand.
 *
 * IdMapRepository and ProductMapRepository are both `final` (confirmed against
 * the real classes; tests/stubs/InMemoryIdMap.php independently notes the same
 * constraint), so an anonymous class cannot `extend` either one — PHP fatals
 * with "cannot extend final class" at the point of instantiation. Both are used
 * for real here instead, driven through the same $wpdb stub/global-callback
 * pattern IdMapRepositoryTest and ProductMapRepositoryTest already use. See
 * setUp() for the wiring; idMap() and mapRepo() are thin factories again.
 */
final class MappingPromoterTest extends PluginTestCase
{
    /** @var list<array{0: string, 1: string, 2: int, 3: string, 4: bool}> */
    private array $stored = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->stored = [];
        $GLOBALS['_cartshift_test_product_map_rows'] = [];

        $stored = &$this->stored;

        // One dispatcher for both tables: the wpdb stub exposes a single global
        // insert callback shared by every repository in the process, and
        // MappingPromoter drives two repositories (IdMapRepository::store() and
        // ProductMapRepository::save()) through the same stub instance. A
        // per-table callback here would silently clobber the other's.
        $GLOBALS['_cartshift_test_insert_callback'] = static function (string $table, array $data) use (&$stored): int {
            if (str_contains($table, 'cartshift_id_map')) {
                // Mirrors the shape the brief's fake stored directly, now
                // captured from the real IdMapRepository::store() call instead.
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

        // Backs IdMapRepository::getFcId()'s DB fallback for a wcId this test
        // never stored. In practice the in-request memo answers repeat lookups
        // within one promote() call before this ever runs, but a promotion that
        // consulted a stale answer here would be exactly the double-promotion
        // bug the idempotency test exists to catch, so this stays faithful to
        // the real query rather than stubbed to always return null.
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

        // Backs ProductMapRepository::linked() / skippedProductIds(), copied
        // from ProductMapRepositoryTest's own stub of the same two queries.
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

    private function idMap(): IdMapRepository
    {
        return new IdMapRepository();
    }

    /** @param list<ProductMapDecision> $decisions */
    private function mapRepo(array $decisions): ProductMapRepository
    {
        $repo = new ProductMapRepository();
        $repo->saveMany($decisions);

        return $repo;
    }

    /**
     * A promoter wired for whichever question the test is asking.
     *
     * The four injected collaborators default to "nothing interesting
     * happens": the target still exists, orphan creation is never reached, the
     * linked product owns every variant it was mapped to, and no downloads are
     * lost. Each has tests of its own further down; defaulting them open keeps
     * every other test about the thing its name says it is about.
     *
     * @param list<int>|null $ownVariantIds Variants on the linked FluentCart
     *                                      product. Null means "whatever the
     *                                      decisions mapped", so the membership
     *                                      check passes.
     */
    private function promoter(
        ProductMapRepository $map,
        bool $targetExists = true,
        ?array $ownVariantIds = null,
        bool $downloadsLost = false,
    ): MappingPromoter {
        $owned = $ownVariantIds ?? self::everyMappedVariantId($map);

        return new MappingPromoter(
            $map,
            $this->idMap(),
            static fn (int $id): bool => $targetExists,
            static fn (int $fcPostId, array $orphan): ?int => null,
            static fn (int $fcPostId): array => $owned,
            static fn (int $wcId, int $fcPostId): bool => $downloadsLost,
        );
    }

    /**
     * Every FluentCart variant ID the saved decisions point at.
     *
     * @return list<int>
     */
    private static function everyMappedVariantId(ProductMapRepository $map): array
    {
        $ids = [];

        foreach ($map->linked() as $decision) {
            foreach ($decision->variantMap() as $fcVariationId) {
                $ids[] = $fcVariationId;
            }
        }

        return $ids;
    }

    public function testALinkIsPromotedWithCreatedByMigrationFalse(): void
    {
        $promoter = $this->promoter(
            $this->mapRepo([ProductMapDecision::link(42, 'simple', 900, 'strong', [42 => 777])]),
        );

        $result = $promoter->promote('run-1');

        $this->assertSame(1, $result['linked']);
        $this->assertSame(1, $result['variants']);

        // Variant first, product last. The product row is the "this decision is
        // finished" marker promote() reads on re-entry, so it is written after
        // everything the decision implies — see the class docblock.
        $this->assertSame(
            [Constants::ENTITY_VARIATION, '42', 777, 'run-1', false],
            $this->stored[0],
        );
        $this->assertSame(
            [Constants::ENTITY_PRODUCT, '42', 900, 'run-1', false],
            $this->stored[1],
            'A hand-made FluentCart product was not created by this migration and rollback must not delete it.',
        );
    }

    public function testEveryVariantInTheMapIsPromoted(): void
    {
        $promoter = $this->promoter(
            $this->mapRepo([
                ProductMapDecision::link(42, 'variable', 900, 'strong', [11 => 501, 12 => 502, 13 => 503]),
            ]),
        );

        $result = $promoter->promote('run-1');

        $this->assertSame(3, $result['variants']);
        $this->assertCount(4, $this->stored);
    }

    public function testADeadLinkDegradesToCreateAndIsReported(): void
    {
        $promoter = $this->promoter(
            $this->mapRepo([ProductMapDecision::link(42, 'simple', 900, 'strong', [42 => 777])]),
            // The owner deleted the FluentCart product between mapping and running.
            targetExists: false,
        );

        $result = $promoter->promote('run-1');

        $this->assertSame(0, $result['linked']);
        $this->assertSame([900], $result['dead']);
        $this->assertSame([], $this->stored, 'An ID map row pointing at a deleted post is worse than no row.');
    }

    public function testPromotionIsIdempotent(): void
    {
        $map = $this->mapRepo([ProductMapDecision::link(42, 'simple', 900, 'strong', [42 => 777])]);

        $first = $this->promoter($map);
        $first->promote('run-1');

        // A resumed run is a fresh REST or Action Scheduler request, not a
        // continuation of the same process — IdMapRepository's own docblock
        // draws exactly this line: WP-CLI drives every batch in one process
        // and keeps the in-request memo warm across them, REST and Action
        // Scheduler do not. Reusing the first IdMapRepository here would let
        // its memo answer the already-promoted check directly, so this test
        // would keep passing even if getFcId()'s SQL fallback were broken.
        // A second, freshly-constructed IdMapRepository has an empty memo,
        // which forces that check through $wpdb->get_var() instead — the
        // shared $stored-backed globals from setUp() mean it still sees the
        // first promote()'s row, so it must answer "already promoted" from
        // the query itself, not from memory.
        $resumed = $this->promoter($map);
        $second  = $resumed->promote('run-1');

        $this->assertSame(0, $second['linked'], 'A resumed run must not double-promote.');
        $this->assertCount(2, $this->stored);
    }

    public function testSkipsAreReportedAndNeverStored(): void
    {
        $promoter = $this->promoter(
            $this->mapRepo([
                ProductMapDecision::skip(7, 'simple', 'none'),
                ProductMapDecision::skip(9, 'simple', 'none'),
            ]),
        );

        $result = $promoter->promote('run-1');

        $this->assertSame([7, 9], $result['skipped']);
        $this->assertSame([], $this->stored);
    }

    public function testACreateDecisionDoesNothingAtAll(): void
    {
        $promoter = $this->promoter(
            $this->mapRepo([ProductMapDecision::create(7, 'simple', 'none')]),
        );

        $result = $promoter->promote('run-1');

        $this->assertSame(0, $result['linked']);
        $this->assertSame([], $result['skipped']);
        $this->assertSame([], $this->stored, 'Create is CartShift default behaviour, not an instruction.');
    }

    // ──────────────────────────────────────────────
    // The mapped variant has to be on the mapped product
    // ──────────────────────────────────────────────

    /**
     * Nothing else in the chain checks it. MappingController::build() only
     * absint()s what the browser sent, the product check here validates only
     * the product, and OrderMapper resolves post_id and object_id as two
     * unrelated lookups. FluentCart has no foreign key to catch the difference,
     * so an unchecked mapping attaches this product's order lines to another
     * product's variant — permanently, once the orders are written.
     */
    public function testAVariantOnAnotherProductIsDroppedAndReported(): void
    {
        $promoter = $this->promoter(
            $this->mapRepo([ProductMapDecision::link(42, 'variable', 900, 'strong', [11 => 501, 12 => 502])]),
            // 502 belongs to some other FluentCart product.
            ownVariantIds: [501],
        );

        $result = $promoter->promote('run-1');

        $this->assertSame(1, $result['variants'], 'Only the variant that belongs to the product may be written.');
        $this->assertSame([12], $result['foreign'], 'Keyed on the WooCommerce variation — the row to go and remap.');

        $this->assertSame(
            [Constants::ENTITY_VARIATION, '11', 501, 'run-1', false],
            $this->stored[0],
        );
        $this->assertCount(2, $this->stored, 'One good variant plus the product marker, and nothing else.');
    }

    /**
     * The ordinary way in is time, not a hand-made POST:
     * `fct_product_variations.id` is a global auto-increment that is never
     * reused, so a decision made last week and run today points at a *deleted*
     * variant whenever the owner tidied their product in between.
     */
    public function testADeletedVariantIsDroppedRatherThanPromoted(): void
    {
        $promoter = $this->promoter(
            $this->mapRepo([ProductMapDecision::link(42, 'simple', 900, 'strong', [42 => 777])]),
            ownVariantIds: [],
        );

        $result = $promoter->promote('run-1');

        $this->assertSame(0, $result['variants']);
        $this->assertSame([42], $result['foreign']);
    }

    /**
     * Dropping the variant is not a reason to drop the link. The product row
     * still lands, so the order keeps its product page and its money, and the
     * owner gets a warning naming exactly what to fix.
     */
    public function testTheLinkItselfSurvivesADroppedVariant(): void
    {
        $promoter = $this->promoter(
            $this->mapRepo([ProductMapDecision::link(42, 'simple', 900, 'strong', [42 => 777])]),
            ownVariantIds: [],
        );

        $result = $promoter->promote('run-1');

        $this->assertSame(1, $result['linked']);
        $this->assertSame(
            [Constants::ENTITY_PRODUCT, '42', 900, 'run-1', false],
            $this->stored[0],
        );
    }

    public function testAMapThatBelongsToTheProductReportsNothing(): void
    {
        $promoter = $this->promoter(
            $this->mapRepo([ProductMapDecision::link(42, 'variable', 900, 'strong', [11 => 501, 12 => 502])]),
            ownVariantIds: [501, 502, 503],
        );

        $result = $promoter->promote('run-1');

        $this->assertSame(2, $result['variants']);
        $this->assertSame([], $result['foreign']);
    }

    // ──────────────────────────────────────────────
    // Downloads the linked product does not have
    // ──────────────────────────────────────────────

    /**
     * A mapped product is skipped by ProductMigrator before it ever reaches
     * migrateDownloadFiles(), so its files never arrive. Promotion warns and
     * writes nothing: which of the owner's variants each file should belong to
     * has no safe automatic answer, and guessing hands the wrong customer the
     * wrong file.
     */
    public function testALinkedProductWithNoFilesOfItsOwnIsReported(): void
    {
        $promoter = $this->promoter(
            $this->mapRepo([ProductMapDecision::link(99, 'simple', 900, 'strong', [99 => 777])]),
            downloadsLost: true,
        );

        $result = $promoter->promote('run-1');

        $this->assertSame([99], $result['fileless'], 'Keyed on the WooCommerce product, where the files are.');
        $this->assertSame(1, $result['linked'], 'A warning, not a refusal — the history still has to migrate.');
    }

    public function testNothingIsReportedWhenNoFilesAreLost(): void
    {
        $promoter = $this->promoter(
            $this->mapRepo([ProductMapDecision::link(99, 'simple', 900, 'strong', [99 => 777])]),
        );

        $this->assertSame([], $promoter->promote('run-1')['fileless']);
    }

    /**
     * Both reports are per-run, not per-tick: a decision already promoted is
     * skipped whole, so an already-finished link cannot keep re-reporting its
     * missing files on every batch. (logIdsOnce() de-dups the rest — see
     * MigrationOrchestratorFactory.)
     */
    public function testAnAlreadyPromotedDecisionReportsNothingASecondTime(): void
    {
        $map = $this->mapRepo([ProductMapDecision::link(99, 'simple', 900, 'strong', [99 => 777])]);

        $this->promoter($map, downloadsLost: true)->promote('run-1');
        $second = $this->promoter($map, downloadsLost: true)->promote('run-1');

        $this->assertSame([], $second['fileless']);
        $this->assertSame([], $second['foreign']);
    }
}

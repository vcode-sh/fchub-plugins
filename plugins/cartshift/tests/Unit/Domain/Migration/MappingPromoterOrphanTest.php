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
 * A Woo variation with no FluentCart counterpart gets one created inside the
 * owner's product, flagged created_by_migration = 1 so rollback takes it back
 * out. The flag is the difference between "CartShift added a variant" and
 * "CartShift deleted the owner catalogue", so it is asserted explicitly.
 *
 * IdMapRepository and ProductMapRepository are both `final`, so an anonymous
 * class cannot `extend` either one — PHP fatals with "cannot extend final
 * class" at the point of instantiation. Both are used for real here instead,
 * driven through the same $wpdb stub/global-callback pattern
 * MappingPromoterTest already established for this exact class. See setUp();
 * idMap() and mapRepo() are thin factories over the real repositories.
 */
final class MappingPromoterOrphanTest extends PluginTestCase
{
    /** @var list<array{0: string, 1: string, 2: int, 3: string, 4: bool}> */
    private array $stored = [];

    /** @var list<array{0: int, 1: string}> */
    private array $created = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->stored  = [];
        $this->created = [];
        $GLOBALS['_cartshift_test_product_map_rows'] = [];

        $stored = &$this->stored;

        // One dispatcher for both tables: the wpdb stub exposes a single global
        // insert callback shared by every repository in the process, and
        // MappingPromoter drives two repositories (IdMapRepository::store() and
        // ProductMapRepository::save()) through the same stub instance. A
        // per-table callback here would silently clobber the other's. Mirrors
        // MappingPromoterTest::setUp() exactly.
        $GLOBALS['_cartshift_test_insert_callback'] = static function (string $table, array $data) use (&$stored): int {
            if (str_contains($table, 'cartshift_id_map')) {
                $stored[] = [
                    $data['entity_type'],
                    $data['wc_id'],
                    (int) $data['fc_id'],
                    $data['migration_id'],
                    (bool) $data['created_by_migration'],
                    // Index 5, captured so the get_var stub below can honour the
                    // realm predicate a real run carries. Without it a dry run's
                    // rows would answer a real run's "already promoted?" check,
                    // which is the whole thing keeping the two apart.
                    (bool) $data['is_simulated'],
                ];

                return 1;
            }

            if (str_contains($table, 'cartshift_product_map')) {
                $GLOBALS['_cartshift_test_product_map_rows'][(int) $data['wc_id']] = $data;

                return 1;
            }

            return 1;
        };

        // Backs IdMapRepository::getFcId()'s DB fallback. A fresh
        // IdMapRepository's in-request memo is empty, so the "already
        // promoted?" check in MappingPromoter::promote() always reaches this.
        //
        // The realm predicate is honoured rather than ignored, because that is
        // what makes a real run re-promote what a rehearsal only pretended to:
        // a real run's query carries `AND is_simulated = 0` and must not see a
        // dry run's rows. A stub that answered both alike would make the
        // dry-run tests below pass while the real behaviour was broken.
        $GLOBALS['_cartshift_test_get_var_callback'] = static function (string $query) use (&$stored): int|string|null {
            if (!str_contains($query, 'cartshift_id_map')) {
                return null;
            }

            preg_match("/entity_type = '([^']*)' AND wc_id = '([^']*)'/", $query, $matches);

            $realOnly = str_contains($query, 'is_simulated = 0');

            foreach ($stored as $row) {
                if ($row[0] !== $matches[1] || $row[1] !== $matches[2]) {
                    continue;
                }

                if ($realOnly && $row[5]) {
                    continue;
                }

                return $row[2];
            }

            return null;
        };

        // Backs ProductMapRepository::linked() / skippedProductIds(), copied
        // from MappingPromoterTest's own stub of the same two queries.
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
     * The scope every test here runs under unless it is about scoping.
     *
     * "Everything" is the mode MigrationScope falls back to for any unusable
     * input, so it is also the one a bug in scope handling is least likely to
     * be caught by accident under — which is why the scope filter has its own
     * test class rather than being asserted from here. ScopeResolver issues no
     * query at all in this mode, so no $wpdb stubbing is needed for it.
     */
    private static function everythingScope(): ScopeResolver
    {
        return new ScopeResolver(MigrationScope::everything());
    }

    /** @param list<ProductMapDecision> $decisions */
    private function promoter(
        array $decisions,
        ?callable $createVariant = null,
        bool $simulating = false,
    ): MappingPromoter {
        $created = &$this->created;

        $idMap = $this->idMap();
        $idMap->setSimulating($simulating);

        return new MappingPromoter(
            $this->mapRepo($decisions),
            $idMap,
            static fn (int $id): bool => true,
            $createVariant ?? static function (int $fcPostId, array $orphan) use (&$created): ?int {
                $created[] = [$fcPostId, $orphan['name']];
                return 9000 + $orphan['id'];
            },
            // The membership check and the missing-downloads check are separate
            // concerns with their own tests in MappingPromoterTest. Wired open
            // here so an orphan test can only fail for orphan reasons: every
            // mapped variant is on the product, and no files go missing.
            static fn (int $fcPostId): array => self::everyMappedVariantId($decisions),
            static fn (int $wcId, int $fcPostId): bool => false,
        );
    }

    /**
     * Every FluentCart variant ID these decisions point at.
     *
     * @param list<ProductMapDecision> $decisions
     *
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

    /** @return ProductMapDecision */
    private function linkWithOneOrphan(): ProductMapDecision
    {
        return ProductMapDecision::link(
            42,
            'variable',
            900,
            'likely',
            [11 => 501],
            [['id' => 13, 'sku' => 'TS-XL', 'name' => 'XL']],
        );
    }

    /**
     * The one ID map row for an entity type and WooCommerce id, or null.
     *
     * Rows are matched rather than indexed because the write *order* is itself
     * under test — the product row moved to the end so a half-finished decision
     * can be retried — and a test that asserts "index 2 is the orphan" fails
     * for the wrong reason the next time that order changes.
     *
     * @return array{0: string, 1: string, 2: int, 3: string, 4: bool, 5: bool}|null
     */
    private function row(string $entityType, string $wcId): ?array
    {
        foreach ($this->stored as $row) {
            if ($row[0] === $entityType && $row[1] === $wcId) {
                return $row;
            }
        }

        return null;
    }

    public function testAnOrphanVariantIsCreatedAndFlaggedAsOurs(): void
    {
        $decision = ProductMapDecision::link(
            42,
            'variable',
            900,
            'likely',
            [11 => 501],
            [['id' => 13, 'sku' => 'TS-XL', 'name' => 'XL']],
        );

        $result = $this->promoter([$decision])->promote('run-1', self::everythingScope());

        $this->assertSame(1, $result['added']);
        $this->assertSame([[900, 'XL']], $this->created);

        $orphanRow = $this->row(Constants::ENTITY_VARIATION, '13');

        $this->assertNotNull($orphanRow);
        $this->assertSame(9013, $orphanRow[2]);
        $this->assertTrue(
            $orphanRow[4],
            'A variant CartShift added is migration output and must roll back with the run.',
        );
    }

    public function testTheProductItselfIsStillFlaggedAsNotOurs(): void
    {
        $decision = ProductMapDecision::link(
            42,
            'variable',
            900,
            'likely',
            [],
            [['id' => 13, 'sku' => '', 'name' => 'XL']],
        );

        $this->promoter([$decision])->promote('run-1', self::everythingScope());

        $this->assertFalse(
            $this->row(Constants::ENTITY_PRODUCT, '42')[4],
            'Adding a variant does not make the owner product ours to delete.',
        );
    }

    public function testAFailedVariantCreationIsNotMappedToNothing(): void
    {
        $decision = ProductMapDecision::link(
            42,
            'variable',
            900,
            'likely',
            [],
            [['id' => 13, 'sku' => '', 'name' => 'XL']],
        );

        $result = $this->promoter([$decision], static fn (int $p, array $o): ?int => null)->promote('run-1', self::everythingScope());

        $this->assertSame(0, $result['added']);
        $this->assertCount(1, $this->stored, 'Only the product row; a null variant must not be stored.');
    }

    public function testNoOrphansMeansNoCreation(): void
    {
        $decision = ProductMapDecision::link(42, 'simple', 900, 'strong', [42 => 777]);

        $result = $this->promoter([$decision])->promote('run-1', self::everythingScope());

        $this->assertSame(0, $result['added']);
        $this->assertSame([], $this->created);
    }

    // ── A dry run writes nothing to FluentCart ───────────────
    //
    // `wp cartshift migrate --dry-run` prints "no records will be created",
    // and until promotion ran on every path this class was never reached by a
    // rehearsal. Now it is, and adding a variant to a product the owner built
    // by hand is the single worst write in the plugin to make by accident.

    /**
     * The creator callable is the seam: it is the only thing here that touches
     * FluentCart, so "never invoked" is the assertion that means "nothing was
     * created" rather than "nothing was created that this test looked at".
     */
    public function testADryRunNeverCreatesAFluentCartVariant(): void
    {
        $result = $this->promoter([$this->linkWithOneOrphan()], simulating: true)->promote('run-dry', self::everythingScope());

        $this->assertSame(
            [],
            $this->created,
            'A rehearsal that adds a variant to the owner catalogue is not a rehearsal.',
        );
        $this->assertSame(1, $result['added'], 'The orphan is still accounted for; only the write is withheld.');
    }

    /**
     * Skipping the orphan outright was the obvious alternative and would leave
     * every order line item referencing that variation unresolvable, so the
     * rehearsal would report problems the real run will not have. A synthetic
     * ID keeps the reference resolvable — the same thing ProductMigrator does
     * for the variations it would have created.
     */
    public function testADryRunMintsASyntheticVariationIdInstead(): void
    {
        $this->promoter([$this->linkWithOneOrphan()], simulating: true)->promote('run-dry', self::everythingScope());

        $orphanRow = $this->row(Constants::ENTITY_VARIATION, '13');

        $this->assertNotNull($orphanRow);
        $this->assertSame(1_150_000_013, $orphanRow[2], 'SIMULATED_VARIATION_BASE + the Woo variation id.');
    }

    public function testEveryRowADryRunPromotesIsFlaggedSimulated(): void
    {
        $this->promoter([$this->linkWithOneOrphan()], simulating: true)->promote('run-dry', self::everythingScope());

        $this->assertCount(3, $this->stored);

        foreach ($this->stored as $row) {
            $this->assertTrue($row[5], 'A rehearsal must leave nothing behind a real run can see.');
        }
    }

    /**
     * The consequence that makes the whole design hold together. A dry run's
     * rows are invisible to a real run's realm-filtered read, so promote()'s
     * idempotency check does not fire and the real run creates the variant
     * properly. Get this wrong and a rehearsal permanently prevents the
     * variants from ever being created.
     */
    public function testARealRunAfterARehearsalStillPromotesAndCreatesForReal(): void
    {
        $this->promoter([$this->linkWithOneOrphan()], simulating: true)->promote('run-dry', self::everythingScope());

        $this->assertSame([], $this->created);

        // The staging table survives a dry run untouched, so the same decision
        // is still there — a fresh promoter reads it exactly as the next
        // request would.
        $result = $this->promoter([$this->linkWithOneOrphan()])->promote('run-1', self::everythingScope());

        $this->assertSame(1, $result['linked'], 'A rehearsal must not convince the real run the work is done.');
        $this->assertSame([[900, 'XL']], $this->created);

        $realRows = array_values(array_filter($this->stored, static fn (array $row): bool => !$row[5]));

        $this->assertCount(3, $realRows);

        $realOrphan = array_values(array_filter(
            $realRows,
            static fn (array $row): bool => $row[0] === Constants::ENTITY_VARIATION && $row[1] === '13',
        ));

        $this->assertSame(9013, $realOrphan[0][2], 'The real run stores the id the creator actually returned.');
    }

    // ── A half-finished decision is finishable ───────────────
    //
    // The finding these exist for: the product's ID map row used to be written
    // *first*, and it is what the idempotency check reads. So anything that
    // went wrong after it — a SKU collision throwing out of the creator, a
    // fatal mid-loop — was permanent. The retry saw a promoted product, skipped
    // the whole decision, and the orphan variants were never created on any
    // attempt. promote() reported `added: 0` and the owner was told nothing.

    /**
     * The invariant, stated directly. Everything else in this section follows
     * from it: the product row is the completion marker, so it must be last.
     */
    public function testTheProductRowIsWrittenAfterEverythingTheDecisionImplies(): void
    {
        $this->promoter([$this->linkWithOneOrphan()])->promote('run-1', self::everythingScope());

        $entityTypes = array_column($this->stored, 0);

        $this->assertSame(
            Constants::ENTITY_PRODUCT,
            end($entityTypes),
            'The product row is what makes a retry skip the decision, so nothing may follow it.',
        );
    }

    /**
     * A decision with two orphans, interrupted between them.
     *
     * A PHP fatal or an Action Scheduler timeout mid-loop is the failure the
     * ordering exists for, and it is exactly what an exception escaping the
     * creator looks like from here. The next tick must be able to finish the
     * job, and finishing it must not mean adding a second "L".
     */
    public function testAnInterruptedDecisionIsFinishedByTheNextTickWithoutDuplicates(): void
    {
        $decision = ProductMapDecision::link(42, 'variable', 900, 'likely', [11 => 501], [
            ['id' => 13, 'sku' => 'TS-L', 'name' => 'L'],
            ['id' => 14, 'sku' => 'TS-XL', 'name' => 'XL'],
        ]);

        $created = &$this->created;

        $interrupted = static function (int $fcPostId, array $orphan) use (&$created): ?int {
            if ($orphan['id'] === 14) {
                throw new \RuntimeException('the process died here');
            }

            $created[] = [$fcPostId, $orphan['name']];

            return 9000 + $orphan['id'];
        };

        try {
            $this->promoter([$decision], $interrupted)->promote('run-1', self::everythingScope());
            $this->fail('Fixture check: the creator was supposed to blow up.');
        } catch (\RuntimeException) {
            // The tick died. Everything below is the next one.
        }

        $this->assertNull(
            $this->row(Constants::ENTITY_PRODUCT, '42'),
            'The product row is the completion marker; an interrupted decision has not completed.',
        );

        $second = $this->promoter([$decision])->promote('run-1', self::everythingScope());

        $this->assertSame(1, $second['linked'], 'A half-finished decision must be re-enterable, not skipped for ever.');
        $this->assertSame(1, $second['added'], 'Only the orphan the first tick never reached.');
        $this->assertSame(
            [[900, 'L'], [900, 'XL']],
            $this->created,
            'Exactly one variant per orphan across both ticks — a retry must not add a second "L".',
        );

        foreach (['11', '13', '14'] as $wooVariationId) {
            $this->assertCount(
                1,
                array_filter(
                    $this->stored,
                    static fn (array $row): bool
                        => $row[0] === Constants::ENTITY_VARIATION && $row[1] === $wooVariationId,
                ),
                "One WooCommerce variation, one ID map row, however many ticks ({$wooVariationId}).",
            );
        }
    }

    /**
     * A refusal is not an interruption, and the difference matters.
     *
     * An advanced-variation target or an exhausted SKU suffix will fail on
     * every attempt for ever. Holding the product link open for it would mean
     * the link is never promoted, ProductMigrator creates a duplicate of the
     * product the owner built by hand, and every historical order attaches to
     * the duplicate — trading one unresolvable variation for a split
     * catalogue. So the decision completes, and the orphan is *reported*
     * instead: MigrationOrchestratorFactory turns `failed` into a warning row
     * naming the WooCommerce variation.
     */
    public function testARefusedOrphanIsReportedRatherThanBlockingTheLink(): void
    {
        $decision = ProductMapDecision::link(42, 'variable', 900, 'likely', [11 => 501], [
            ['id' => 13, 'sku' => '', 'name' => 'XL'],
        ]);

        $result = $this->promoter([$decision], static fn (int $p, array $o): ?int => null)->promote('run-1', self::everythingScope());

        $this->assertSame(1, $result['linked'], 'One variant CartShift cannot add is not a reason to duplicate the product.');
        $this->assertSame(0, $result['added']);
        $this->assertSame(
            [13],
            $result['failed'],
            'A failure the owner cannot see is a failure nobody ever fixes.',
        );
        $this->assertNotNull($this->row(Constants::ENTITY_PRODUCT, '42'));
    }
}

<?php

declare(strict_types=1);

namespace CartShift\Domain\Migration;

use CartShift\Domain\Mapping\ProductMapDecision;
use CartShift\Domain\Scope\ScopeResolver;
use CartShift\Storage\IdMapRepository;
use CartShift\Storage\ProductMapRepository;
use CartShift\Support\Constants;

defined('ABSPATH') || exit;

/**
 * Turns the owner's drafted decisions into ID map rows, once, at run start.
 *
 * This is the entire integration surface of product mapping. Every migrator
 * downstream resolves products through IdMapRepository::getFcId(), so once a
 * link is promoted, orders, subscriptions, coupon restrictions and downloads
 * all attach to the owner's own FluentCart product without a line of change.
 *
 * created_by_migration is passed as false and that is safety-critical:
 * rollback deletes only rows flagged true, so a product the owner built by
 * hand survives a rollback of the run that referenced it.
 *
 * Promotion honours the simulation realm like every other write in this plugin,
 * and on a dry run that means two things: the ID map rows it writes are flagged
 * is_simulated = 1 (IdMapRepository::store() does that on its own), and orphan
 * variants are not created at all — a synthetic ID is minted instead. A
 * rehearsal that added a variant to the owner's hand-built product would be the
 * single write this whole feature exists to stop anyone making carelessly, and
 * nothing a rehearsal is expected to leave behind would undo it. See
 * addOrphanVariant().
 *
 * ## Why a run's scope is a parameter and not a field
 *
 * The staging table outlives any one run. An owner maps their catalogue on
 * Monday and on Tuesday runs "orders since March" or "just these four
 * products", and the decisions from Monday are all still sitting there. Every
 * one of them used to be promoted regardless, which meant a narrowed run
 * created orphan variants inside FluentCart products it was never going to
 * migrate — real writes into the owner's live catalogue — and filed them under
 * this run's migration id, so rolling the run back deleted variants it had no
 * business creating in the first place.
 *
 * So promote() takes the run's ScopeResolver and asks it, once per decision,
 * whether the WooCommerce product is one this run touches. It asks rather than
 * decides: this class has no notion of scope of its own, and must not grow one
 * — ScopeResolver::includesProduct() is the same answer productPredicate()
 * gives ProductMigrator, which is the only way promotion and migration can be
 * guaranteed to agree about what a run covers.
 *
 * A parameter rather than a constructor dependency because the scope is read
 * from MigrationState, which a later batch — a fresh request — may see
 * differently from the request that built this object. AbstractMigrator draws
 * exactly the same line for exactly the same reason.
 *
 * Out-of-scope decisions are left in the staging table untouched, never
 * deleted: the spec's edge-case table says so, and the reason is that the
 * owner's next run may well be wider. They are reported instead, so a run says
 * what it declined to do.
 *
 * ## Why the product row is written last
 *
 * It used to be written first, and that made a partial failure permanent. The
 * product's ID map row is what the idempotency check above reads, so once it
 * exists the *whole* decision is skipped on every later tick — variant rows and
 * orphans included. Anything that went wrong after that write could therefore
 * never be retried: the orphan variants were never created, on any attempt,
 * every historical order line referencing them dangled, and promote() reported
 * `added: 0` as though there had been nothing to do. Only a full reset
 * recovered it.
 *
 * So the product row is the completion marker, written after everything the
 * decision implies. A tick that dies half way leaves it absent, the next tick
 * re-enters the decision, and the per-row checks skip what already landed. The
 * cost is one extra ID map read per variant on the tick that promotes a
 * decision; a decision already finished still costs exactly one read, because
 * the product row short-circuits it.
 *
 * The alternative was to make orphan *creation* idempotent — look for a variant
 * on the target product with this title or SKU and adopt it instead of creating
 * a second one. It was rejected, and not narrowly. Orphans exist only when the
 * Woo variations outnumber the FluentCart variants, and VariantResolver's
 * position pass claims every free FC variant before giving up; so whenever
 * there is an orphan at all, *every* pre-existing variant on that product is
 * already paired with some other Woo variation. A lookup by title or SKU can
 * therefore only find a row that is already spoken for, and adopting it puts XL
 * revenue on the L row — the exact failure this feature exists to prevent. The
 * ID map is an exact key and needs no such argument.
 */
final class MappingPromoter
{
    /**
     * Base for a dry run's synthetic FluentCart variation IDs.
     *
     * Its own constant rather than a shared one with
     * ProductMigrator::SIMULATED_VARIATION_BASE (950,000,000 plus the
     * WooCommerce variation's post ID). Two ranges growing from the same base
     * by adding a post ID are disjoint only by an argument about which records
     * each side touches — true today, because a promoted product is skipped by
     * ProductMigrator, and exactly the kind of reasoning that stops being true
     * quietly. This base sits 200,000,000 above that one, double the headroom
     * its own docblock claims for post IDs, so neither range can reach the
     * other on any store whose post IDs fit in that space.
     */
    private const int SIMULATED_VARIATION_BASE = 1_150_000_000;

    /**
     * Normalised from the constructor's $fcProductExists at construction time.
     *
     * A property cannot be declared `callable` in PHP — only `Closure` is a
     * real type — so the broad `callable` accepted publicly is converted once
     * here rather than re-validated on every promote() call.
     */
    private readonly \Closure $fcProductExists;

    /**
     * Normalised from the constructor's $createVariant, for the same reason
     * $fcProductExists is: `callable` cannot be a property type, standalone
     * or in a union, so the public parameter is converted once here.
     */
    private readonly \Closure $createVariant;

    /**
     * Normalised from the constructor's $fcVariantIds. Same reason again.
     */
    private readonly \Closure $fcVariantIds;

    /**
     * Normalised from the constructor's $downloadsLost. Same reason again.
     */
    private readonly \Closure $downloadsLost;

    /**
     * @param callable(int): bool                                             $fcProductExists
     *        Injected rather than called directly on FluentCart, so this
     *        class stays unit-testable without a live FluentCart install.
     * @param callable(int, array{id: int, sku: string, name: string, price: int|null, fulfillment_type: string, downloadable: string}): ?int $createVariant
     *        Creates one FluentCart variant on the given product and returns
     *        its ID, or null when it could not — a target it must refuse, or a
     *        write that failed. Injected for the same reason as
     *        $fcProductExists: this class must stay testable without a live
     *        FluentCart. Null is a reported outcome, not a silent one: it lands
     *        in promote()'s `failed` list and the caller logs it.
     * @param callable(int): list<int> $fcVariantIds
     *        Every `fct_product_variations.id` on the given FluentCart product.
     *        The authority for whether a mapped variant is that product's to
     *        map — see the membership check in promote().
     * @param callable(int, int): bool $downloadsLost
     *        Whether linking this WooCommerce product to this FluentCart
     *        product loses its downloadable files: true when the Woo product
     *        has files and the FluentCart one has none. Reported, never acted
     *        on — see the note in promote().
     */
    public function __construct(
        private readonly ProductMapRepository $map,
        private readonly IdMapRepository $idMap,
        callable $fcProductExists,
        callable $createVariant,
        callable $fcVariantIds,
        callable $downloadsLost,
    ) {
        $this->fcProductExists = \Closure::fromCallable($fcProductExists);
        $this->createVariant   = \Closure::fromCallable($createVariant);
        $this->fcVariantIds    = \Closure::fromCallable($fcVariantIds);
        $this->downloadsLost   = \Closure::fromCallable($downloadsLost);
    }

    /**
     * @param ScopeResolver $scope The run's own resolver, built fresh by the
     *        caller from MigrationState — see the class docblock for why this
     *        is a parameter rather than a constructor dependency.
     *
     * @return array{linked: int, variants: int, added: int, skipped: list<int>, outOfScope: list<int>, dead: list<int>, failed: list<int>, foreign: list<int>, fileless: list<int>}
     */
    public function promote(string $migrationId, ScopeResolver $scope): array
    {
        $linked     = 0;
        $variants   = 0;
        $added      = 0;
        $outOfScope = [];
        $dead       = [];
        $failed     = [];
        $foreign    = [];
        $fileless   = [];

        foreach ($this->map->linked() as $decision) {
            $fcPostId = $decision->fcPostId();

            if ($fcPostId === null) {
                continue;
            }

            // First, ahead of every read and every write, because a decision
            // this run does not cover is one it should not so much as look up:
            // not dead, not fileless, not an orphan to create. On an
            // "Everything" or a date-limited run this is a no-op that costs
            // nothing — both take the whole catalogue — so the only run it
            // narrows is the one that asked to be narrowed.
            if (!$scope->includesProduct($decision->wcId())) {
                $outOfScope[] = $decision->wcId();
                continue;
            }

            // A link whose target was deleted between mapping and running is a
            // reason to fall back to creating the product, not a reason to fail
            // a migration the owner has already confirmed. The caller logs it.
            if (!($this->fcProductExists)($fcPostId)) {
                $dead[] = $fcPostId;
                continue;
            }

            // Resumed runs re-enter this method. The ID map is the record of
            // what has already been promoted, so consult it rather than keeping
            // a separate "promoted" flag that could disagree with it — and the
            // product row is written *last* precisely so this check means "the
            // whole decision is done", not "we got as far as the product".
            if ($this->idMap->getFcId(Constants::ENTITY_PRODUCT, (string) $decision->wcId()) !== null) {
                continue;
            }

            // One query per decision, read before the orphan loop adds anything
            // to this product, and the only thing that establishes the map is
            // *this* product's to make.
            //
            // Nothing else in the chain checks it. MappingController::build()
            // only absint()s what the browser sent, promote() validates the
            // product and never the variants, and OrderMapper resolves post_id
            // and object_id as two unrelated lookups joined by the convention
            // that whoever built the map got it right. FluentCart has no
            // foreign key to catch the difference, so a variant belonging to
            // another product attaches this product's order lines to someone
            // else's — silently, and permanently once the orders are written.
            //
            // The ordinary way in is not a hand-made POST. It is time:
            // `fct_product_variations.id` is a global auto-increment that is
            // never reused, so a decision made last week and run today points
            // at a *deleted* variant whenever the owner has tidied their
            // product in between.
            $ownVariantIds = array_flip(($this->fcVariantIds)($fcPostId));

            foreach ($decision->variantMap() as $wooVariationId => $fcVariationId) {
                if ($this->idMap->getFcId(Constants::ENTITY_VARIATION, (string) $wooVariationId) !== null) {
                    continue;
                }

                if (!isset($ownVariantIds[$fcVariationId])) {
                    $foreign[] = $wooVariationId;
                    continue;
                }

                $this->idMap->store(
                    Constants::ENTITY_VARIATION,
                    (string) $wooVariationId,
                    $fcVariationId,
                    $migrationId,
                    false,
                );

                $variants++;
            }

            // The one place CartShift writes into a product the owner built by hand.
            // Flagged created_by_migration = 1, unlike the product row below, because
            // this variant IS migration output and rollback should remove it.
            //
            // Per-orphan, and against the ID map rather than against the product's
            // variant titles: re-entering a half-finished decision must not add a
            // second "XL", and an existing variant that *looks* like this orphan is
            // one the resolver already paired with a different Woo variation — see
            // the ordering note in the class docblock.
            foreach ($decision->orphans() as $orphan) {
                if ($this->idMap->getFcId(Constants::ENTITY_VARIATION, (string) $orphan['id']) !== null) {
                    continue;
                }

                $fcVariationId = $this->addOrphanVariant($fcPostId, $orphan);

                if ($fcVariationId === null || $fcVariationId <= 0) {
                    $failed[] = $orphan['id'];
                    continue;
                }

                $this->idMap->store(
                    Constants::ENTITY_VARIATION,
                    (string) $orphan['id'],
                    $fcVariationId,
                    $migrationId,
                    true,
                );

                $added++;
            }

            // Reported, never acted on, and the restraint is the point.
            //
            // A mapped product is skipped by ProductMigrator — processRecord()
            // sees the promoted ENTITY_PRODUCT row and returns before it ever
            // reaches migrateDownloadFiles() — so the Woo product's files never
            // arrive. Writing them into the linked product would fix that and
            // would be the second unrequested write into hand-built data this
            // feature exists to avoid, with no safe answer to the question it
            // raises: which of the owner's variants should each file belong to?
            // `fct_product_downloads.product_variation_id` is a list, and
            // guessing it wrong hands the wrong customer the wrong file.
            //
            // So the owner is told instead, once, with the product named. The
            // cost of saying nothing lands on their customers rather than on
            // them: Order::getDownloads() feeds the order page, the receipt and
            // the paid/shipped emails, and all three would show no files at all.
            if (($this->downloadsLost)($decision->wcId(), $fcPostId)) {
                $fileless[] = $decision->wcId();
            }

            // Last, and that is the entire point. See the class docblock.
            $this->idMap->store(
                Constants::ENTITY_PRODUCT,
                (string) $decision->wcId(),
                $fcPostId,
                $migrationId,
                false,
            );

            $linked++;
        }

        return [
            'linked'     => $linked,
            'variants'   => $variants,
            'added'      => $added,
            'skipped'    => $this->map->skippedProductIds(),
            'outOfScope' => $outOfScope,
            'dead'       => $dead,
            'failed'     => $failed,
            'foreign'    => $foreign,
            'fileless'   => $fileless,
        ];
    }

    /**
     * The FluentCart variant an orphan Woo variation will resolve to.
     *
     * A real run creates it. A dry run must not: `wp cartshift migrate
     * --dry-run` prints "no records will be created", and a rehearsal that
     * quietly adds a variant to a product the owner built by hand breaks that
     * promise in the worst available place.
     *
     * So a dry run mints a synthetic ID instead, the same convention
     * ProductMigrator uses for the variations it would have created. Skipping
     * the orphan entirely was the obvious alternative and is wrong: every order
     * line item referencing that variation would fail to resolve, so the
     * rehearsal would report problems the real run will not have — which is the
     * one thing a rehearsal exists to avoid.
     *
     * The synthetic rows are flagged is_simulated = 1 by IdMapRepository, so a
     * real run cannot see them and re-promotes properly, and `reset` clears
     * them with the rest of the rehearsal. That is also why the older objection
     * to skipping — that a promoted product without its orphans would satisfy
     * promote()'s idempotency check and make the real run skip the decision —
     * no longer applies: it was true only while promotion wrote real rows.
     *
     * @param array{id: int, sku: string, name: string, price: int|null, fulfillment_type: string, downloadable: string} $orphan
     */
    private function addOrphanVariant(int $fcPostId, array $orphan): ?int
    {
        if ($this->idMap->isSimulating()) {
            return self::SIMULATED_VARIATION_BASE + $orphan['id'];
        }

        return ($this->createVariant)($fcPostId, $orphan);
    }
}

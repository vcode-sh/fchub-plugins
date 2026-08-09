<?php

declare(strict_types=1);

namespace CartShift\Domain\Migration;

defined('ABSPATH') || exit;

use CartShift\Domain\Mapping\ProductMapDecision;
use CartShift\Domain\Mapping\VariationMapper;
use CartShift\Domain\Migration\Contracts\MigratorInterface;
use CartShift\Domain\Scope\ScopeResolver;
use CartShift\Domain\Subscription\Source\WooSubscriptionDatasetSource;
use CartShift\Domain\Subscription\SubscriptionHistoryIndex;
use CartShift\Domain\Subscription\SubscriptionSelection;
use CartShift\Migrator\CouponMigrator;
use CartShift\Migrator\CustomerMigrator;
use CartShift\Migrator\OrderMigrator;
use CartShift\Migrator\ProductMigrator;
use CartShift\Migrator\SubscriptionMigrator;
use CartShift\State\MigrationState;
use CartShift\Storage\IdMapRepository;
use CartShift\Storage\MigrationLogRepository;
use CartShift\Storage\ProductMapRepository;
use CartShift\Support\Constants;
use CartShift\Support\Enums\FcBillingInterval;
use CartShift\Support\Enums\MigrationErrorCode;
use CartShift\Support\Logger;
use CartShift\Support\ProductTypes;
use CartShift\Support\SkuAllocator;
use FluentCart\App\Models\ProductDetail;
use FluentCart\App\Models\ProductVariation;

/**
 * The one place that knows how to assemble a migration run.
 *
 * Product mapping is not something a call site can be trusted to remember.
 * Five of them built their own migrator list — the REST controller for
 * /migrate and /migrate/batch, MigrationModule's Action Scheduler factory,
 * and three in WP-CLI — and exactly one of them promoted the owner's mapping
 * decisions first. The one that did not is the one the wizard uses, so on the
 * default path every link was ignored, every mapped product was duplicated,
 * and every skip was migrated anyway. The feature did nothing at all.
 *
 * So assembly lives here, once. Everything else asks for a run rather than
 * building one, and a sixth call site added tomorrow inherits the behaviour
 * instead of forgetting it.
 *
 * Two things have to be true before a single product is read, and they happen
 * at different moments — which is the only subtlety in this class.
 *
 *  - **The skip list** needs nothing but the staging table, so it is applied
 *    when the migrators are built. ProductMigrator's source query honours it,
 *    so it must be on the instance before count() or fetchBatch() is called.
 *  - **Promotion** writes ID map rows stamped with the migration ID, and on
 *    the path that *starts* a run that ID does not exist yet: MigrationState
 *    mints it inside MigrationOrchestrator::startMigration(), which then runs
 *    the first batch before returning. Building the orchestrator and starting
 *    the run are two separate statements at every call site, and there is no
 *    third moment in between. So promotion is deferred to
 *    `cartshift/migration/started` — the action startMigration() and
 *    startRetry() both fire after the ID exists and before any record is
 *    touched. A factory asked for a run that is *already* in flight (a
 *    /migrate/batch request, an Action Scheduler tick, a resumed run) promotes
 *    immediately instead, because there the ID is already real.
 */
final class MigrationOrchestratorFactory
{
    /**
     * Every migrator, dependencies first. The order a caller does not override.
     *
     * @var array<string, class-string<MigratorInterface>>
     */
    private const array MIGRATORS = [
        Constants::ENTITY_PRODUCT      => ProductMigrator::class,
        Constants::ENTITY_CUSTOMER     => CustomerMigrator::class,
        Constants::ENTITY_COUPON       => CouponMigrator::class,
        Constants::ENTITY_ORDER        => OrderMigrator::class,
        Constants::ENTITY_SUBSCRIPTION => SubscriptionMigrator::class,
    ];

    /**
     * Post statuses that count as "this FluentCart product can still be
     * bought". Deliberately a membership check, not `get_post_status(...)
     * !== false`: wp_trash_post() sets post_status = 'trash' without
     * deleting the row or changing post_type, so a trashed product still
     * answers a string, not false, to get_post_status(). Matches the status
     * set MappingController's own candidate query already filters on.
     *
     * @var list<string>
     */
    private const array LIVE_POST_STATUSES = ['publish', 'draft', 'private'];

    /**
     * What a variant CartShift adds is called when the Woo variation has no
     * attribute labels to build a title from.
     */
    private const string ORPHAN_VARIANT_TITLE = 'Migrated variant';

    /**
     * Whether this instance has already asked to be told when a run starts.
     *
     * Guards against stacking listeners if forRun() is called twice before a
     * run begins. Promotion is idempotent, so a double fire would be harmless
     * rather than wrong — this keeps the hook list honest anyway.
     */
    private bool $awaitingRunStart = false;

    /** Memoised per request. See orderRelationships(). */
    private ?SubscriptionHistoryIndex $orderRelationships = null;

    public function __construct(
        private readonly IdMapRepository $idMap,
        private readonly MigrationLogRepository $log,
        private readonly MigrationState $state,
        private readonly MappingPromoter $promoter,
        private readonly ProductMapRepository $map,
    ) {
    }

    /**
     * Production wiring for a caller with no container — that is, WP-CLI.
     *
     * The two FluentCart touchpoints are this class's own statics, so the CLI
     * and the REST layer promote through identical code rather than through
     * two lookalike closures that can drift.
     */
    public static function standalone(
        IdMapRepository $idMap,
        MigrationLogRepository $log,
        MigrationState $state,
    ): self {
        $map = new ProductMapRepository();

        return new self(
            $idMap,
            $log,
            $state,
            new MappingPromoter(
                $map,
                $idMap,
                self::fcProductStillExists(...),
                self::createOrphanVariant(...),
                self::fcVariantIdsFor(...),
                self::linkLosesDownloads(...),
            ),
            $map,
        );
    }

    /**
     * An orchestrator ready to run: decisions promoted, skips excluded.
     *
     * @param list<string> $entityTypes Empty means every migrator, in the
     *                                  canonical order. A non-empty list is
     *                                  honoured in the caller's own order,
     *                                  which is what WP-CLI relies on.
     * @param int|null     $batchSize   Null leaves each migrator on its default.
     */
    public function forRun(array $entityTypes = [], ?int $batchSize = null): MigrationOrchestrator
    {
        $this->promoteNowOrAtRunStart();

        return new MigrationOrchestrator(
            $this->migrators($entityTypes, $batchSize, true),
            $this->state,
            $this->idMap,
            $this->log,
        );
    }

    /**
     * The same migrators, for a caller that only wants to count.
     *
     * Nothing is promoted and nothing is logged: /preview and /counts are
     * read-only by construction and the owner is still choosing. The skip list
     * and the unrepresentable-cadence exclusion *are* applied, because a receipt
     * that counts products the run will not migrate is a receipt for a different
     * run — but the refusals reach the migration log only on the run itself,
     * where there is a migration to attach them to.
     *
     * @return list<MigratorInterface>
     */
    public function migratorsForCounting(): array
    {
        return $this->migrators([], null, false);
    }

    /**
     * Promote the owner's `link` decisions into the ID map.
     *
     * Idempotent by way of the ID map itself (see MappingPromoter), so a
     * resumed run re-entering here writes nothing twice.
     *
     * The realm is derived from MigrationState, exactly as
     * MigrationOrchestrator::processBatch() derives it — and it has to be
     * derived *here* because promotion runs before processBatch() does. On a
     * batch tick the IdMapRepository is freshly constructed and not simulating
     * yet, so without this a dry run would promote into the real realm: its
     * links would outlive the rehearsal, its orphan variants would be created
     * in the owner's catalogue for real, and the subsequent real run would find
     * promotion already done and skip the decision entirely.
     *
     * Left set rather than restored afterwards, because it is the correct value
     * for the run either way — the run-start path had already set it to the
     * same thing, and processBatch() re-derives it on every batch regardless.
     *
     * The scope is resolved here, not held on the promoter, and read fresh from
     * MigrationState on every call. This class is the one place that assembles
     * a run, so it is the one place that knows what the run covers — and a
     * resolver built once and kept would be a second, ageing copy of a value
     * later batches read from state in their own requests. AbstractMigrator
     * ::scopeResolver() memoises per request for the same reason and no longer.
     *
     * @return array{linked: int, variants: int, added: int, skipped: list<int>, outOfScope: list<int>, dead: list<int>, failed: list<int>, foreign: list<int>, fileless: list<int>}
     */
    public function promote(string $migrationId): array
    {
        if ($migrationId === '') {
            return [
                'linked'     => 0,
                'variants'   => 0,
                'added'      => 0,
                'skipped'    => [],
                'outOfScope' => [],
                'dead'       => [],
                'failed'     => [],
                'foreign'    => [],
                'fileless'   => [],
            ];
        }

        $this->idMap->setSimulating($this->state->isDryRun());

        $promotion = $this->promoter->promote($migrationId, new ScopeResolver($this->state->getScope()));

        self::logDeadLinksOnce($this->log, $migrationId, $promotion['dead']);
        self::logOrphanFailuresOnce($this->log, $migrationId, $promotion['failed']);
        self::logForeignVariantsOnce($this->log, $migrationId, $promotion['foreign']);
        self::logLostDownloadsOnce($this->log, $migrationId, $promotion['fileless']);
        self::logOutOfScopeLinksOnce($this->log, $migrationId, $promotion['outOfScope']);

        return $promotion;
    }

    /**
     * Whether a FluentCart product is still there to link to.
     *
     * The one predicate MappingPromoter::promote() trusts before writing an
     * ID map row for a `link` decision — get it wrong and promotion links a
     * Woo product, and every order and subscription that resolves through
     * the ID map afterwards, to a post nobody can buy. A named, independently
     * testable method rather than an inline closure for exactly that reason:
     * this is the one place a regression here needs a seam a test can reach
     * directly, without wiring the whole container.
     */
    public static function fcProductStillExists(int $fcPostId): bool
    {
        return in_array(get_post_status($fcPostId), self::LIVE_POST_STATUSES, true)
            && get_post_type($fcPostId) === Constants::FC_PRODUCT_POST_TYPE;
    }

    /**
     * Every variant ID on a FluentCart product.
     *
     * MappingPromoter's authority for whether a mapped variant is that
     * product's to map. The same `WHERE post_id = ?` MappingController::
     * fcVariants() builds the mapping screen's dropdown from, which is the
     * point: promotion re-asks, at run time, the question the screen answered
     * when the decision was made. Anything the owner has deleted or moved in
     * between falls out here rather than becoming an order line on somebody
     * else's product.
     *
     * Raw SQL rather than the ProductVariation model, so it works on a site
     * where FluentCart's classes are not loaded — the same reason
     * MappingController queries `fct_product_variations` directly.
     *
     * @return list<int>
     */
    public static function fcVariantIdsFor(int $fcPostId): array
    {
        global $wpdb;

        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}fct_product_variations WHERE post_id = %d",
            $fcPostId,
        ));

        return array_values(array_map(intval(...), $ids ?: []));
    }

    /**
     * Whether linking this WooCommerce product to this FluentCart product
     * leaves its customers with no files.
     *
     * True only when the Woo side has downloadable files and the FluentCart
     * side has none — the case where migrating changes what a customer can
     * download. The other shapes are somebody else's problem or nobody's: a Woo
     * product with no files loses nothing, and a linked product that already
     * carries files serves them to every migrated order through
     * Order::getDownloads(), which resolves by `post_id`.
     *
     * The Woo side is counted the way ProductMigrator migrates it — parent
     * first, then variations, because a variable product carries its files on
     * the variations rather than on the parent (migrateVariableDownloads()).
     * Short-circuited at the first file found, so the child walk only happens
     * for a variable product whose parent has none.
     */
    public static function linkLosesDownloads(int $wcId, int $fcPostId): bool
    {
        if (!function_exists('wc_get_product')) {
            return false;
        }

        $product = wc_get_product($wcId);

        if (!$product instanceof \WC_Product || !self::wooHasDownloads($product)) {
            return false;
        }

        global $wpdb;

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}fct_product_downloads WHERE post_id = %d",
            $fcPostId,
        )) === 0;
    }

    /**
     * Whether a WooCommerce product has any downloadable file at all, parent or
     * variation.
     */
    private static function wooHasDownloads(\WC_Product $product): bool
    {
        if ($product->get_downloads() !== []) {
            return true;
        }

        if (!ProductTypes::isVariable($product->get_type())) {
            return false;
        }

        foreach ($product->get_children() as $childId) {
            $child = wc_get_product((int) $childId);

            if ($child instanceof \WC_Product && $child->get_downloads() !== []) {
                return true;
            }
        }

        return false;
    }

    /**
     * Add one variant to a FluentCart product the owner built by hand.
     *
     * The only write CartShift makes into such a product, and it happens
     * because the alternatives are worse: pointing an orphan Woo variation at
     * an existing FC variant puts "XL" revenue on the "L" row for ever, and
     * leaving it unlinked leaves orphan lines on the order detail page for a
     * product that plainly exists. MappingPromoter flags the ID map row
     * created_by_migration = 1, so rollback takes this back out again.
     *
     * Everything below is a column `fct_product_variations` would otherwise
     * default, and the defaults are wrong here in ways the owner would not
     * connect to a migration weeks later. Checked against FluentCart's own two
     * variant-creating paths — ProductVariationResource::create() and
     * AdvancedVariationService::syncVariantCombinations() — and against the 76
     * real variant rows in a live store.
     *
     *  - **`sku` is null, never `''`.** `fct_product_variations` carries
     *    `UNIQUE INDEX sku_unique (sku)`. MySQL permits many NULLs in a
     *    unique index but only one empty string, so a run adding a second
     *    SKU-less orphan would collide and lose the variant — and blank SKUs
     *    are the normal case, which is the entire premise of the matcher.
     *    VariationMapper writes `$sku ?: null` for the same reason. A SKU that
     *    is present goes through SkuAllocator, because the owner very likely
     *    copied their Woo SKUs across when they built this product by hand,
     *    and the same string twice in a UNIQUE column is a thrown
     *    QueryException, not a warning.
     *  - **`variation_identifier` is null.** It is not a slug. FluentCart
     *    writes the underscore-joined attribute *term IDs* of an
     *    advanced-variation combination there (`implode('_', $variant)`,
     *    AdvancedVariationService) and reads it back with `explode('_', …)`.
     *    All 76 real rows in the live store have it NULL. The previous
     *    docblock claimed the opposite — that a row without one is not
     *    addressable — and nothing in FluentCart or FluentCart Pro supports it.
     *  - **`stock_status` is 'in-stock' with `manage_stock = 0`.** That pairing
     *    is FluentCart's own invariant, forced in ProductVariationResource
     *    ::create(). The schema default is 'out-of-stock', which renders the
     *    buy button disabled and labelled "Not Available" once the Stock
     *    Management module is on — which a shop migrating off WooCommerce is
     *    very likely to turn on.
     *  - **`other_info` is FluentCart's own baseline**, copied verbatim from
     *    AdvancedVariationService::defaultVariantOtherInfo(). NULL there is the
     *    failure FluentCart's own comment records: readers of
     *    `other_info.payment_type` got NULL and tripped the
     *    `fct_order_items.payment_type` NOT NULL constraint. It also blocks the
     *    owner's next save of a `simple` product, whose validator requires it.
     *  - **`serial_index` is max + 1.** It is the display order of variants
     *    everywhere in FluentCart, and NULL sorts *first* in both MySQL and the
     *    framework's Collection::sortBy(). A migrated variant must not lead the
     *    owner's carefully ordered list.
     *  - **`payment_type`** is always `onetime` here, matching `other_info` —
     *    and that is now an invariant this method enforces on itself rather
     *    than a fact about its callers. A migrated historical line must not
     *    silently become a one-off sale when the source was a recurring
     *    contract, so a subscription source variation is refused before this
     *    payload is ever built. See the guard at the top of the method.
     *
     * Price is the Woo variation's own, in FluentCart's x100 format, resolved
     * by MoneyHelper back on the mapping screen where the WooCommerce object
     * was still loaded. Zero was the old behaviour and it was not neutral: it
     * adds a free, purchasable item to a live catalogue, and it drags the
     * product's `min_price` to 0 the next time the owner opens the editor and
     * clicks Save. A decision saved before the price travelled carries no price
     * at all, and falls back to the linked product's own `min_price` — the
     * owner's number, not CartShift's opinion.
     *
     * Refuses outright on an `advanced_variations` target. Belt and braces with
     * MappingController::fcCandidates(), which keeps those products out of the
     * dropdown: FluentCart regenerates such a product's variants from the
     * attribute cartesian on every combination save and deletes everything not
     * in it, so a variant added here is destroyed later and every order line
     * pointing at it dangles. Returning null is already handled — MappingPromoter
     * skips the orphan, reports it, and the factory logs it once.
     *
     * Refuses just as outright when the WooCommerce source is a subscription.
     * Reproducing a recurring contract exactly — cadence, trial, length, setup
     * fee, and the payment strategy that decides who charges the customer next
     * — is a whole feature this orphan path does not have, and guessing at any
     * one of those fields is worse than asking: `payment_type=onetime` silently
     * turns a membership into a single sale, and the shop only finds out when
     * the customer is never billed again. Lapka never needs this path at all —
     * both its target variants already exist — so the safe answer is to block
     * it here rather than build a second, partial subscription writer. See the
     * `payment_type` bullet above and orphanSourceIsSubscription() below.
     *
     * @param array{id: int, sku: string, name: string, price?: int|null, fulfillment_type?: string, downloadable?: string} $orphan
     */
    public static function createOrphanVariant(int $fcPostId, array $orphan): ?int
    {
        if (!class_exists(ProductVariation::class)) {
            return null;
        }

        if (self::orphanSourceIsSubscription($orphan)) {
            Logger::error(
                'Refused to add a one-time variant for a subscription source variation. Automatic '
                . 'subscription-orphan creation is out of scope — create or select a compatible '
                . 'FluentCart subscription variation for this product by hand, then re-run.',
                [
                    'fc_post_id'      => $fcPostId,
                    'wc_variation_id' => $orphan['id'],
                ],
            );

            return null;
        }

        // Nothing in here is allowed to abort the run. A throw escaping this
        // method kills the Action Scheduler action mid-migration, and the batch
        // that never gets rescheduled is the smaller half of the damage: the
        // decision is left half-promoted for ever. Catch, report, carry on.
        try {
            $detail = self::productDetail($fcPostId);

            if ($detail !== null && (string) ($detail->variation_type ?? '') === Constants::FC_ADVANCED_VARIATIONS) {
                return null;
            }

            $title = $orphan['name'] !== '' ? $orphan['name'] : self::ORPHAN_VARIANT_TITLE;

            $highestSerial = (int) ProductVariation::query()->where('post_id', $fcPostId)->max('serial_index');

            $payload = [
                'post_id'              => $fcPostId,
                'serial_index'         => $highestSerial + 1,
                'variation_title'      => $title,
                'variation_identifier' => null,
                'sku'                  => self::orphanSku($orphan),
                'payment_type'         => 'onetime',
                'item_price'           => self::orphanPrice($orphan, $detail),
                'compare_price'        => 0,
                'item_cost'            => 0,
                'manage_cost'          => 'false',
                'manage_stock'         => 0,
                'stock_status'         => 'in-stock',
                'total_stock'          => 1,
                'available'            => 1,
                'committed'            => 0,
                'on_hold'              => 0,
                'backorders'           => 0,
                'fulfillment_type'     => self::orphanFulfillmentType($orphan, $detail),
                'item_status'          => 'active',
                'downloadable'         => self::orphanDownloadable($orphan),
                'sold_individually'    => 0,
                'other_info'           => self::defaultVariantOtherInfo(),
            ];

            $variation = ProductVariation::query()->create($payload);

            $variationId = $variation && $variation->id ? (int) $variation->id : null;

            if ($variationId === null) {
                return null;
            }

            self::refreshProductDetail($fcPostId, $detail);

            // FluentCart fires this after every one of its own variant writes,
            // for cache invalidators, search indexers and webhook subscribers.
            // Omitting it makes CartShift's write invisible to all of them.
            do_action('fluent_cart/product/variants_updated', [
                'post_id'  => $fcPostId,
                'variants' => [$payload + ['id' => $variationId]],
            ]);

            return $variationId;
        } catch (\Throwable $exception) {
            Logger::error('Could not add a variant to the linked FluentCart product.', [
                'fc_post_id'      => $fcPostId,
                'wc_variation_id' => $orphan['id'],
                'error'           => $exception->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * The linked product's detail row, or null when FluentCart has none.
     *
     * Read once per orphan and passed around rather than re-queried: it answers
     * three separate questions (is this an advanced product, what does the
     * owner charge, what is it fulfilled as) and is written back at the end.
     */
    private static function productDetail(int $fcPostId): ?object
    {
        if (!class_exists(ProductDetail::class)) {
            return null;
        }

        $detail = ProductDetail::query()->where('post_id', $fcPostId)->first();

        return is_object($detail) ? $detail : null;
    }

    /**
     * Whether the WooCommerce row this orphan describes is a subscription.
     *
     * Read live off WooCommerce rather than threaded through the orphan
     * descriptor and its JSON round trip through the browser (build() in
     * MappingController, ProductMapDecision::normalizeOrphans()) — a decision
     * can sit in the staging table for days before the run that promotes it,
     * and `$orphan['id']` is already the one identifier createOrphanVariant()
     * is handed that WooCommerce itself can still answer questions about. It is
     * also the same object VariationMapper::isSubscription() would be asked
     * about were this variation migrated normally rather than added as an
     * orphan, so the two paths cannot reach different verdicts for the same
     * WooCommerce row.
     *
     * `$orphan['id']` is a `WC_Product_Variation` id for a variable-or-
     * variable-subscription source and the parent product's own id for a
     * simple-or-subscription one — wooProductPage() keys the descriptor either
     * way, and wc_get_product() resolves both without this method needing to
     * know which.
     *
     * @param array{id: int, ...} $orphan
     */
    /**
     * Woo subscription products the operator chose to CREATE whose cadence
     * FluentCart cannot hold.
     *
     * The other half of the hazard `orphanSourceIsSubscription()` closes below.
     * A mapping row with no compatible target variation is reported as blocked
     * on the mapping screen — and the screen still offers "Create" beside it,
     * which routes into `ProductMigrator` and `VariationMapper`, and those write
     * `repeat_interval` through `FcBillingInterval::fromWooCommerce()`. That
     * reading collapses: `week/2` becomes weekly, `year/2` becomes yearly,
     * `month/2` and `month/12` become monthly. So the operator's answer to
     * "CartShift cannot express this contract" would be a FluentCart product
     * that quietly claims a different one.
     *
     * Only `create` decisions are examined. A `link` decision points at a
     * variation that already exists and whose contract `MappingSetValidator`
     * has already gated; a `skip` is already excluded. And the subscription
     * write path does not depend on this at all — `SubscriptionAssessor` blocks
     * an unrepresentable cadence whatever the catalogue ended up saying — which
     * is deliberate: this keeps a misleading product out of the shop, and that
     * gate keeps a customer off the wrong billing schedule.
     *
     * READ-ONLY, AND IT HAS TO STAY THAT WAY. `migratorsForCounting()` reaches
     * this method, and `PreviewController::preview()` calls that inside a GET
     * whose own comment says nothing may be promoted. So this answers the
     * question and writes nothing to the migration log; `reportUnrepresentableCadences()`
     * does the reporting, and only `forRun()` calls it.
     *
     * @return array<int, array{period: string, interval: string}> Woo product id => why it was refused.
     */
    private function unrepresentableSubscriptionProducts(): array
    {
        if (!function_exists('wc_get_product')) {
            return [];
        }

        $refused = [];

        foreach ($this->map->all() as $decision) {
            if ($decision->decision() !== ProductMapDecision::CREATE) {
                continue;
            }

            $product = wc_get_product($decision->wcId());

            if (!$product instanceof \WC_Product || self::subscriptionCadenceIsRepresentable($product)) {
                continue;
            }

            $refused[$decision->wcId()] = [
                'period'   => (string) $product->get_meta('_subscription_period'),
                'interval' => (string) $product->get_meta('_subscription_period_interval'),
            ];
        }

        return $refused;
    }

    /**
     * Tell the operator which products the run is dropping, and why.
     *
     * ON THE RUN PATH ONLY. This writes an `error` row against the current
     * migration, and it used to sit inside the method above — which
     * `migratorsForCounting()` calls, which `PreviewController::preview()` calls
     * inside a read-only GET. Every scope preview on a store with one such
     * decision therefore appended a row to whatever migration ID happened to be
     * in state: `''` with no run in flight, or a FINISHED run's own log,
     * corrupting a completed run's record from a GET.
     *
     * `Logger::error()` stays beside it rather than in the reader: reporting a
     * refusal is one job and it belongs in one place.
     *
     * @param array<int, array{period: string, interval: string}> $refused
     */
    private function reportUnrepresentableCadences(array $refused): void
    {
        foreach ($refused as $wcId => $cadence) {
            $message = sprintf(
                'Refused to create a FluentCart product for WooCommerce subscription #%d: its billing '
                . 'cadence (every %s %s) is not one FluentCart can express. Creating it would write the '
                . 'nearest interval instead, which is a different contract from the one the subscriber '
                . 'agreed to. Change the source schedule, or link the product to a compatible FluentCart '
                . 'subscription variation by hand, then re-run.',
                $wcId,
                $cadence['interval'] !== '' ? $cadence['interval'] : '1',
                $cadence['period'] !== '' ? $cadence['period'] : 'unknown period',
            );

            // THE MIGRATION LOG, not only `error_log`. This refusal drops a
            // product from the run, and reporting it through `Logger::error()`
            // alone put it in a file the operator never opens — while the
            // mapping screen went on offering "Create" for the row that caused
            // it. A coded log row is how every other refusal in this plugin
            // reaches the person who has to act on it.
            $this->log->write(
                (string) $this->state->getMigrationId(),
                Constants::ENTITY_PRODUCT,
                $wcId,
                'error',
                $message,
                $cadence,
                MigrationErrorCode::SubscriptionCadenceUnrepresentable,
            );

            Logger::error($message, ['wc_product_id' => $wcId] + $cadence);
        }
    }

    /**
     * Whether section 7.2's exact table has a row for this product's cadence.
     *
     * True for anything that is not a subscription product at all — a one-time
     * product has no cadence to be wrong about.
     */
    public static function subscriptionCadenceIsRepresentable(\WC_Product $product): bool
    {
        if (!VariationMapper::isSubscription($product)) {
            return true;
        }

        $period   = (string) ($product->get_meta('_subscription_period') ?: 'month');
        $interval = (int) ($product->get_meta('_subscription_period_interval') ?: 1);

        return FcBillingInterval::tryFromWooCommerce($period, $interval) !== null;
    }

    private static function orphanSourceIsSubscription(array $orphan): bool
    {
        if (!function_exists('wc_get_product')) {
            return false;
        }

        $source = wc_get_product((int) $orphan['id']);

        return $source instanceof \WC_Product && VariationMapper::isSubscription($source);
    }

    /**
     * A SKU the UNIQUE index will accept, or null.
     *
     * Null rather than '' for the reason on createOrphanVariant(): the index
     * holds many NULLs and exactly one empty string.
     *
     * @param array{sku: string, id: int, ...} $orphan
     */
    private static function orphanSku(array $orphan): ?string
    {
        $sku = trim($orphan['sku']);

        if ($sku === '') {
            return null;
        }

        return (new SkuAllocator())->allocate($sku, $orphan['id']);
    }

    /**
     * What the variant costs, in FluentCart's x100 storage format.
     *
     * @param array{price?: int|null, ...} $orphan
     */
    private static function orphanPrice(array $orphan, ?object $detail): float
    {
        $price = $orphan['price'] ?? null;

        if ($price === null) {
            // Pre-dates the price travelling with the decision. The linked
            // product's own floor is the least wrong answer available: it is a
            // number the owner chose, on the product this variant is joining.
            $price = (float) ($detail->min_price ?? 0);
        }

        return max(0.0, (float) $price);
    }

    /**
     * `'true'` or `'false'` — a varchar column, not a boolean.
     *
     * Defaults to `'false'` rather than to the linked product's setting: a
     * variant marked downloadable with no files attached is a broken purchase,
     * and only the Woo variation knows.
     *
     * @param array{downloadable?: string, ...} $orphan
     */
    private static function orphanDownloadable(array $orphan): string
    {
        return ($orphan['downloadable'] ?? '') !== '' ? (string) $orphan['downloadable'] : 'false';
    }

    /**
     * Physical, digital or service — the Woo variation's answer, then the
     * linked product's, then FluentCart's own column default.
     *
     * Cart::requireShipping() reads this per line, so a downloadable Woo
     * variation landing as 'physical' makes FluentCart demand a shipping
     * address and a shipping method for a file.
     *
     * @param array{fulfillment_type?: string, ...} $orphan
     */
    private static function orphanFulfillmentType(array $orphan, ?object $detail): string
    {
        if (($orphan['fulfillment_type'] ?? '') !== '') {
            return (string) $orphan['fulfillment_type'];
        }

        $fromDetail = (string) ($detail->fulfillment_type ?? '');

        return $fromDetail !== '' ? $fromDetail : 'physical';
    }

    /**
     * Put one product's detail row back in agreement with whatever variants it
     * has now, having loaded the row itself.
     *
     * The same recompute createOrphanVariant() runs after an add, exposed for
     * the other half of the round trip: MigrationRollback deletes the variants
     * CartShift added, and a hand-built product left behind keeps the widened
     * range it was given. A live rollback left the owner's five-variant product
     * advertising "up to £79.00" against a dearest surviving variant of £12.30,
     * and nothing in the plugin would ever have corrected it.
     *
     * Deliberately one shared implementation rather than a second one over
     * there. Add and remove disagreeing about how a range is computed is the
     * bug this method exists to prevent, not a difference worth having.
     */
    public static function refreshProductRange(int $fcPostId): void
    {
        self::refreshProductDetail($fcPostId, self::productDetail($fcPostId));
    }

    /**
     * Put the product detail row back in agreement with its variants.
     *
     * `ProductVariation` has no create/save model events — booted() registers
     * only `retrieved`, boot() only `deleting` — so a raw create() fires
     * nothing at all. FluentCart maintains the price range in exactly two
     * places, both explicit and both full-product saves, and refreshes
     * `stock_availability` in ProductDetailResource::update(). Neither is
     * reachable from here, so both are done by hand, the same way
     * ProductMigrator already does after its own variant loop.
     *
     * Skipping the range recompute is what makes the damage arrive late: the
     * catalogue card keeps quoting the owner's old "From £14.00" until the day
     * they open the product and click Save, at which point FluentCart
     * recomputes from all variants and the number moves with no visible cause.
     */
    private static function refreshProductDetail(int $fcPostId, ?object $detail): void
    {
        if ($detail === null) {
            return;
        }

        $detail->min_price = (float) ProductVariation::query()->where('post_id', $fcPostId)->min('item_price');
        $detail->max_price = (float) ProductVariation::query()->where('post_id', $fcPostId)->max('item_price');

        // FluentCart's own branch, verbatim: a product not managing stock is
        // unconditionally in stock, and one that is asks its variants.
        $inStock = !$detail->manage_stock
            || ProductVariation::query()
                ->where('post_id', $fcPostId)
                ->where('stock_status', 'in-stock')
                ->exists();

        $detail->stock_availability = $inStock ? 'in-stock' : 'out-of-stock';

        $detail->save();
    }

    /**
     * The keys every fresh FluentCart variant's `other_info` carries.
     *
     * A verbatim copy of AdvancedVariationService::defaultVariantOtherInfo(),
     * including the empty strings. FluentCart's own comment there records why
     * it exists: writing a partial `other_info` left readers of
     * `other_info.payment_type` with NULL and tripped the
     * `fct_order_items.payment_type` NOT NULL constraint. All 76 real variant
     * rows in the live store have this column populated; none is NULL.
     *
     * @return array<string, string|null>
     */
    private static function defaultVariantOtherInfo(): array
    {
        return [
            'description'        => '',
            'payment_type'       => 'onetime',
            'tax_class'          => 'standard',
            'tax_exempt'         => 'no',
            'tax_inclusion'      => '',
            'times'              => '',
            'repeat_interval'    => 'yearly',
            'billing_summary'    => '',
            'manage_setup_fee'   => 'no',
            'signup_fee_name'    => '',
            'signup_fee'         => '',
            'setup_fee_per_item' => 'no',
            'package_slug'       => '',
            'weight'             => null,
        ];
    }

    /**
     * Log each dead mapped-FC-product id at most once per migration run.
     *
     * A run re-enters promotion on every batch tick — BatchProcessor
     * ::handleBatch() asks for a fresh orchestrator per Action Scheduler
     * action, and reschedules until the run finishes — and
     * MappingPromoter::promote() is idempotent by design: it keeps reporting
     * the same dead ids on every tick after the first, because promotion
     * itself has nothing to compare against. Without this check, three real
     * dead links across forty batches would write roughly 120 warning rows for
     * three actual problems, which inflates MigrationLogRepository::getStats()'s
     * warning count and code breakdown — the list-disagrees-with-summary
     * failure that class's own docblocks call out. hasEntryFor() is the
     * de-dup: it checks the log itself rather than adding new state, so a
     * resumed run (fresh PHP process, empty memory) still sees what an earlier
     * tick already wrote.
     *
     * @param list<int> $deadFcIds
     */
    public static function logDeadLinksOnce(MigrationLogRepository $log, string $migrationId, array $deadFcIds): void
    {
        self::logIdsOnce(
            $log,
            $migrationId,
            Constants::ENTITY_PRODUCT,
            $deadFcIds,
            MigrationErrorCode::MappedFcProductMissing,
            'Mapped FluentCart product %d no longer exists; the WooCommerce product it was linked to will be created instead.',
        );
    }

    /**
     * Log each orphan variant promotion could not create, at most once per run.
     *
     * The failure this exists for used to be entirely silent: promote() counted
     * `added: 0`, the wizard reported success, and the owner found out when an
     * order detail page showed a line item pointing at nothing. A refused
     * advanced-variation target and a create() that threw both land here, and
     * both are things the owner can act on — relink the product, or add the
     * variant by hand and re-run.
     *
     * Keyed on the WooCommerce variation, not the FluentCart product, because
     * that is the record the owner has to go and look at.
     *
     * @param list<int> $wooVariationIds
     */
    public static function logOrphanFailuresOnce(
        MigrationLogRepository $log,
        string $migrationId,
        array $wooVariationIds,
    ): void {
        self::logIdsOnce(
            $log,
            $migrationId,
            Constants::ENTITY_VARIATION,
            $wooVariationIds,
            MigrationErrorCode::OrphanVariantNotCreated,
            'WooCommerce variation %d has no FluentCart counterpart and one could not be added to the linked product; order lines referencing it will not resolve.',
        );
    }

    /**
     * Log each variant mapping promotion refused, at most once per run.
     *
     * Keyed on the WooCommerce variation because that is the row the owner has
     * to go and remap; the FluentCart variant it pointed at is by definition
     * not on the product they were looking at.
     *
     * @param list<int> $wooVariationIds
     */
    public static function logForeignVariantsOnce(
        MigrationLogRepository $log,
        string $migrationId,
        array $wooVariationIds,
    ): void {
        self::logIdsOnce(
            $log,
            $migrationId,
            Constants::ENTITY_VARIATION,
            $wooVariationIds,
            MigrationErrorCode::MappedVariantNotOnProduct,
            'WooCommerce variation %d was mapped to a FluentCart variant that is not on the linked product; '
            . 'the mapping was dropped rather than attaching this product\'s order lines to another one.',
        );
    }

    /**
     * Log each linked product whose files did not come with it, once per run.
     *
     * Keyed on the WooCommerce product: the FluentCart product is the one that
     * needs the files attaching, but the WooCommerce one is where they are, and
     * the log's entity column is WooCommerce ids throughout.
     *
     * @param list<int> $wcProductIds
     */
    public static function logLostDownloadsOnce(
        MigrationLogRepository $log,
        string $migrationId,
        array $wcProductIds,
    ): void {
        self::logIdsOnce(
            $log,
            $migrationId,
            Constants::ENTITY_PRODUCT,
            $wcProductIds,
            MigrationErrorCode::MappedProductHasNoDownloads,
            'WooCommerce product %d has downloadable files and the FluentCart product it was linked to has '
            . 'none. Migrated orders for it will show the customer no files until you attach them by hand.',
        );
    }

    /**
     * Log each mapped product this run's scope left out, at most once per run.
     *
     * Status 'skipped' and Info severity, unlike its four neighbours, because
     * nothing has gone wrong: the owner narrowed the run and promotion obeyed.
     * Recording it anyway is the point — a link the owner drafted and then did
     * not see happen is otherwise indistinguishable from a link that silently
     * failed, and they would have no way to tell which without this row.
     *
     * @param list<int> $wcProductIds
     */
    public static function logOutOfScopeLinksOnce(
        MigrationLogRepository $log,
        string $migrationId,
        array $wcProductIds,
    ): void {
        self::logIdsOnce(
            $log,
            $migrationId,
            Constants::ENTITY_PRODUCT,
            $wcProductIds,
            MigrationErrorCode::MappedProductOutOfScope,
            'WooCommerce product %d is mapped to a FluentCart product, but this run\'s selection does not '
            . 'include it. The mapping was left in place for a later run.',
            'skipped',
        );
    }

    /**
     * One log row per id per run, and no more.
     *
     * hasEntryFor() is the de-dup: it checks the log itself rather than adding
     * new state, so a resumed run — fresh PHP process, empty memory — still
     * sees what an earlier tick already wrote.
     *
     * @param list<int> $ids
     */
    private static function logIdsOnce(
        MigrationLogRepository $log,
        string $migrationId,
        string $entityType,
        array $ids,
        MigrationErrorCode $code,
        string $messageFormat,
        string $status = 'warning',
    ): void {
        foreach ($ids as $id) {
            if ($log->hasEntryFor($migrationId, $entityType, (string) $id, $code)) {
                continue;
            }

            $log->write(
                $migrationId,
                $entityType,
                (string) $id,
                $status,
                sprintf($messageFormat, $id),
                null,
                $code,
            );
        }
    }

    /**
     * Promote against the live migration ID, or arrange to as soon as there is one.
     *
     * See the class docblock for why these are two cases rather than one.
     */
    private function promoteNowOrAtRunStart(): void
    {
        $migrationId = (string) ($this->state->getMigrationId() ?? '');

        // isRunning() and not merely "has an id": a finished run leaves its id
        // in the stored state until reset, and promoting under a previous run's
        // id would file this run's links where its own rollback cannot find them.
        if ($migrationId !== '' && $this->state->isRunning()) {
            $this->promote($migrationId);

            return;
        }

        if ($this->awaitingRunStart) {
            return;
        }

        $this->awaitingRunStart = true;

        // Priority 1, ahead of whatever else a site hangs off this action: any
        // of those listeners may legitimately read the ID map, and the owner's
        // links have to be there before they do.
        add_action(
            'cartshift/migration/started',
            function (mixed $startedMigrationId = ''): void {
                $this->promote((string) $startedMigrationId);
            },
            1,
            1,
        );
    }

    /**
     * Which orders are subscription parents and which are renewals, read once
     * per request.
     *
     * DEFERRED, and that is not an optimisation. `migratorsForCounting()` builds
     * every migrator including this one, `PreviewController` calls it inside a
     * read-only REST request, and the entity-type filter is applied afterwards
     * by `ScopePreview::build()` — so an eagerly-built index made a
     * products-only preview page `wcs_get_subscriptions()` in full and hydrate
     * one `WC_Subscription` per row. 564 hydrations on the reference dataset for
     * a preview that never maps an order; a timeout on a large store. The index
     * now costs nothing until an order is actually mapped.
     *
     * Memoised on the instance as well, so the deferred build runs at most once
     * per request rather than once per migrator: `migrators()` is called once
     * per `forRun()`, `forRun()` once per batch tick, and each tick is its own
     * PHP process.
     *
     * Relationships only — no order payloads. `OrderMapper` asks which
     * FluentCart type an order takes and which order a renewal hangs off, and
     * never asks for a payload, so hydrating 5,000 orders to answer 5,000
     * type questions would be a dataset export wearing a migrator's clothes.
     * The dependency closure section 6.2 requires is the staging command's
     * business, not this one's.
     *
     * Empty when WooCommerce Subscriptions is not active, which is the honest
     * answer: a store with no subscriptions has no renewals, and every order
     * stays a `checkout` exactly as before.
     */
    private function orderRelationships(): SubscriptionHistoryIndex
    {
        return $this->orderRelationships ??= SubscriptionHistoryIndex::deferred(
            Constants::DEFAULT_SOURCE_KEY,
            static function (): array {
                if (!function_exists('wcs_get_subscriptions')) {
                    return ['orders' => [], 'relationships' => []];
                }

                return SubscriptionHistoryIndex::liveRelationships(self::allSourceSubscriptions());
            },
        );
    }

    /**
     * Every source subscription, one at a time.
     *
     * Through `WooSubscriptionDatasetSource`, which already owns the only
     * verified `wcs_get_subscriptions()` argument vocabulary in the plugin —
     * `subscriptions_per_page`, `offset`, `orderby`, `order`. WooCommerce
     * Subscriptions is a paid add-on and is not installed on this machine, so a
     * second pager written here would be a second guess at an API nobody can
     * check, and the two would drift.
     *
     * A generator rather than a list: the index is 564 lightweight rows on
     * Lapka but a hydrated `WC_Subscription` each is not, and nothing here needs
     * more than one at a time.
     *
     * Not scoped. A renewal order's type is a fact about that order, not about
     * whether this run happens to be migrating the subscription that owns it —
     * a run narrowed to orders alone would otherwise write every renewal as a
     * `checkout` and leave no trace of why.
     *
     * @return \Generator<int, object>
     */
    private static function allSourceSubscriptions(): \Generator
    {
        $source    = new WooSubscriptionDatasetSource(Constants::DEFAULT_SOURCE_KEY);
        $selection = SubscriptionSelection::all(Constants::DEFAULT_SOURCE_KEY);

        foreach ($source->selectionIndex($selection) as $row) {
            $subscription = $source->hydrate((int) $row['id']);

            if ($subscription !== null) {
                yield $subscription;
            }
        }
    }

    /**
     * @param list<string> $entityTypes
     * @param bool         $reportRefusals Whether this is a run, and may therefore write to the log.
     *
     * @return list<MigratorInterface>
     */
    private function migrators(
        array $entityTypes = [],
        ?int $batchSize = null,
        bool $reportRefusals = false,
    ): array {
        $wanted = $entityTypes === [] ? array_keys(self::MIGRATORS) : $entityTypes;

        $skipped = $this->map->skippedProductIds();

        // Computed either way — the exclusion is a fact about the run and a
        // preview that counted products the run will drop is a receipt for a
        // different run. REPORTED only on the run path: see
        // `reportUnrepresentableCadences()`.
        $unrepresentable = $this->unrepresentableSubscriptionProducts();

        if ($reportRefusals) {
            $this->reportUnrepresentableCadences($unrepresentable);
        }

        $migrators = [];

        foreach ($wanted as $type) {
            if (!isset(self::MIGRATORS[$type])) {
                continue;
            }

            $class = self::MIGRATORS[$type];

            // The order migrator takes one more argument than its siblings, and
            // it is not optional in practice: without it every WooCommerce
            // Subscriptions renewal order is written `type = checkout`, which
            // `RenewalController`, `CustomerOrderController` and
            // `Subscription::renewalOrders()` all filter out — the subscriber's
            // own invoice list loses every renewal they ever paid.
            $migrator = match (true) {
                $class === OrderMigrator::class => new OrderMigrator(
                    $this->idMap,
                    $this->log,
                    $this->state,
                    $batchSize ?? Constants::DEFAULT_BATCH_SIZE,
                    $this->orderRelationships(),
                ),
                $batchSize === null => new $class($this->idMap, $this->log, $this->state),
                default             => new $class($this->idMap, $this->log, $this->state, $batchSize),
            };

            if ($migrator instanceof ProductMigrator) {
                $migrator->excludeProductIds(array_values(array_unique(array_merge(
                    $skipped,
                    array_keys($unrepresentable),
                ))));
            }

            $migrators[] = $migrator;
        }

        return $migrators;
    }
}

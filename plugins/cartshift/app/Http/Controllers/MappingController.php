<?php

declare(strict_types=1);

namespace CartShift\Http\Controllers;

use CartShift\Core\Container;
use CartShift\Domain\Mapping\ProductMapDecision;
use CartShift\Domain\Mapping\ProductMatcher;
use CartShift\Domain\Mapping\VariantResolver;
use CartShift\Domain\Mapping\VariationMapper;
use CartShift\Domain\Scope\MigrationScope;
use CartShift\Domain\Scope\ScopeResolver;
use CartShift\Storage\ProductMapRepository;
use CartShift\Support\Constants;
use CartShift\Support\ProductTypes;
use WP_REST_Request;
use WP_REST_Response;

defined('ABSPATH') || exit;

/**
 * REST surface for the mapping screen.
 *
 * Read-only against WooCommerce and FluentCart; the only thing it writes is
 * the staging table. Nothing here promotes anything into the ID map — that
 * happens once, at run start, in MappingPromoter.
 */
final class MappingController
{
    private const string NAMESPACE = 'cartshift/v1';

    private const int DEFAULT_PER_PAGE = 50;
    private const int MAX_PER_PAGE     = 200;

    private const int MAX_CANDIDATES = 8;

    /**
     * post_id => variants, filled once by fcCandidates() and reused by
     * rows() for whichever candidate the matcher picked.
     *
     * Without this, one GET request queried the same catalogue twice: once
     * building the candidate list in fcCandidates(), then again per matched
     * row when rows() resolved variants — on a 500-product FluentCart
     * catalogue against a 50-row page, up to 550 variant queries where 500
     * would do.
     *
     * @var array<int, list<array{id: int, sku: string, name: string, price: float}>>
     */
    private array $variantsByFcProduct = [];

    /**
     * post_id => whether that FluentCart product has any downloadable file,
     * filled once by fcCandidates() from a single grouped query.
     *
     * One query for the whole catalogue rather than one per candidate: the
     * answer is needed for every candidate of every row on the page, and
     * `fct_product_downloads` is a small table even on a large shop.
     *
     * @var array<int, bool>
     */
    private array $downloadsByFcProduct = [];

    public function __construct(
        private readonly Container $container,
    ) {
    }

    public function registerRoutes(): void
    {
        register_rest_route(self::NAMESPACE, '/mapping/rows', [
            'methods'             => 'GET',
            'callback'            => [$this, 'rows'],
            'permission_callback' => [$this, 'checkPermission'],
            'args'                => [
                'page'     => ['type' => 'integer', 'default' => 1, 'sanitize_callback' => 'absint'],
                'per_page' => ['type' => 'integer', 'default' => self::DEFAULT_PER_PAGE, 'sanitize_callback' => 'absint'],
                // Deliberately untyped: this arrives as a JSON string from the
                // wizard, because a GET has no body and spelling a nested scope
                // as `scope[product_ids][]=…` in a query string is a shape the
                // Vue side would have to hand-assemble. scopeFrom() takes either.
                'scope' => [
                    'description' => 'The MigrationScope this run will use, JSON-encoded. Absent means everything.',
                ],
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/mapping/decide', [
            'methods'             => 'POST',
            'callback'            => [$this, 'decide'],
            'permission_callback' => [$this, 'checkPermission'],
        ]);

        register_rest_route(self::NAMESPACE, '/mapping/bulk', [
            'methods'             => 'POST',
            'callback'            => [$this, 'bulk'],
            'permission_callback' => [$this, 'checkPermission'],
        ]);

        register_rest_route(self::NAMESPACE, '/mapping/clear', [
            'methods'             => 'POST',
            'callback'            => [$this, 'clear'],
            'permission_callback' => [$this, 'checkPermission'],
        ]);
    }

    public function checkPermission(): bool
    {
        return current_user_can('manage_options');
    }

    /**
     * One page of in-scope Woo products, each with ranked FluentCart candidates.
     *
     * Paginated because the matcher is O(page x catalogue) and a 2,000-product
     * shop against a 500-product FluentCart catalogue is a million comparisons
     * in one request otherwise.
     */
    public function rows(WP_REST_Request $request): WP_REST_Response
    {
        $page    = max(1, (int) $request->get_param('page'));
        $perPage = min(self::MAX_PER_PAGE, max(1, (int) $request->get_param('per_page')));
        $scope   = self::scopeFrom($request->get_param('scope'));

        $wooProducts = $this->wooProductPage($scope, $page, $perPage);
        // Built once for the whole request, not once per row: the matcher is
        // O(page x catalogue) already, and re-querying the FC catalogue for
        // every Woo product would make it O(page x catalogue) queries too.
        $candidates  = $this->fcCandidates();

        $matcher  = new ProductMatcher();
        $resolver = new VariantResolver();
        $repo     = $this->repo();

        $rows = [];

        foreach ($wooProducts as $woo) {
            $match = $matcher->match($woo['match_fields'], $candidates);

            // 'none' entries are dropped before the slice, not after. A row the
            // matcher found nothing for must offer no dropdown at all: eight
            // implausible products under a heading saying "No candidate" is an
            // invitation to fuse a Gift Card with a T-shirt, and that is
            // unrecoverable once orders are attached.
            $ranked = array_slice(
                array_values(array_filter(
                    $match['ranked'],
                    static fn (array $entry): bool => $entry['band'] !== ProductMatcher::BAND_NONE,
                )),
                0,
                self::MAX_CANDIDATES,
            );

            // One variant block per candidate, not one for the suggestion.
            // The FC side is a <select>, and fct_product_variations IDs are
            // global — so shipping the suggested candidate's map and letting
            // the owner change the product underneath it wrote ENTITY_VARIATION
            // rows pointing at a different product's variants, while the row
            // label went on reporting the old count. Free, because
            // fcCandidates() has already read every candidate's variants.
            $variantByCandidate = [];

            foreach ($ranked as $entry) {
                $variantByCandidate[$entry['id']] = $this->variantSummary($woo, $entry['id'], $resolver);
            }

            // Per candidate, and for the same reason the variant block is: a
            // different FluentCart product is a different answer, and a warning
            // that goes on describing the previous one is worse than none.
            //
            // Promotion warns about this too, and has to — the owner may never
            // open this screen again before running. But by then the migration
            // is under way and the fix is manual; here they are looking at the
            // row, with the alternative candidates in a dropdown beside it.
            $downloadsByCandidate = [];

            foreach ($ranked as $entry) {
                $downloadsByCandidate[$entry['id']] = $woo['has_downloads']
                    && !($this->downloadsByFcProduct[$entry['id']] ?? false);
            }

            $existing = $repo->get($woo['id']);

            $rows[] = [
                'wc_id'       => $woo['id'],
                'name'        => $woo['name'],
                'wc_type'     => $woo['type'],
                'sku'         => $woo['match_fields']['sku'],
                'variations'  => count($woo['variations']),
                'order_count' => $woo['order_count'],
                'band'        => $match['band'],
                'suggested'   => $match['candidate_id'],
                'candidates'  => $this->labelCandidates(
                    $ranked,
                    $candidates,
                    $variantByCandidate,
                    $downloadsByCandidate,
                ),
                'variant'     => $match['candidate_id'] === null
                    ? null
                    : ($variantByCandidate[$match['candidate_id']] ?? null),
                'downloads_lost' => $match['candidate_id'] !== null
                    && ($downloadsByCandidate[$match['candidate_id']] ?? false),
                'decision'    => $existing?->toArray(),
            ];
        }

        return new WP_REST_Response(['data' => [
            'rows'             => $rows,
            'page'             => $page,
            'per_page'         => $perPage,
            'total'            => $this->wooProductCount($scope),
            'fc_product_count' => count($candidates),
        ]]);
    }

    /**
     * What linking this Woo product to this FluentCart product would do to its
     * variants: how many pair up, and which have no counterpart at all.
     *
     * @param array{variations: list<array{id: int, sku: string, name: string, price: int, fulfillment_type: string, downloadable: string}>, ...} $woo
     *
     * @return array{matched: int, total: int, adds: int, map: array<int, int>, orphans: list<array{id: int, sku: string, name: string, price: int, fulfillment_type: string, downloadable: string}>}
     */
    private function variantSummary(array $woo, int $fcPostId, VariantResolver $resolver): array
    {
        $resolved = $resolver->resolve($woo['variations'], $this->cachedFcVariants($fcPostId));

        // The resolver returns orphan IDs; promotion needs the whole descriptor
        // to name and SKU the variant it will create, so rehydrate here where
        // the Woo variations are still in hand.
        $byId = [];

        foreach ($woo['variations'] as $variation) {
            $byId[$variation['id']] = $variation;
        }

        $orphanDetail = [];

        foreach ($resolved['orphans'] as $orphanId) {
            if (isset($byId[$orphanId])) {
                $orphanDetail[] = $byId[$orphanId];
            }
        }

        return [
            'matched' => count($resolved['map']),
            'total'   => count($woo['variations']),
            'adds'    => count($orphanDetail),
            'map'     => $resolved['map'],
            'orphans' => $orphanDetail,
        ];
    }

    /**
     * The scope this page is for.
     *
     * Accepts the JSON string the wizard sends as readily as an already-decoded
     * array. Never refuses: MigrationScope::fromArray() reads anything unusable
     * as "everything", which on this screen means showing the owner more rather
     * than silently hiding a product they were about to map.
     */
    private static function scopeFrom(mixed $raw): MigrationScope
    {
        if (is_string($raw)) {
            $raw = json_decode($raw, true);
        }

        return MigrationScope::fromArray($raw);
    }

    public function decide(WP_REST_Request $request): WP_REST_Response
    {
        $wcId = absint($request->get_param('wc_id'));

        if ($wcId <= 0) {
            return $this->refuse('A decision needs a WooCommerce product.');
        }

        $decision = sanitize_text_field((string) $request->get_param('decision'));
        $wcType   = sanitize_text_field((string) ($request->get_param('wc_type') ?? ''));
        $band     = sanitize_text_field((string) ($request->get_param('band') ?? ProductMatcher::BAND_NONE));

        $built = $this->build($wcId, $wcType, $decision, $band, [
            'fc_post_id'  => $request->get_param('fc_post_id'),
            'variant_map' => $request->get_param('variant_map'),
            'orphans'     => $request->get_param('orphans'),
        ]);

        if ($built === null) {
            return $this->refuse(sprintf('Unusable decision "%s" for product %d.', $decision, $wcId));
        }

        $this->repo()->save($built);

        return new WP_REST_Response(['data' => ['saved' => true, 'decision' => $built->toArray()]]);
    }

    /**
     * Apply one decision to many rows.
     *
     * Rows it cannot use are dropped rather than failing the batch: a bulk
     * "link all" over a band where one row lost its candidate should link the
     * other eighteen, not refuse the lot.
     */
    public function bulk(WP_REST_Request $request): WP_REST_Response
    {
        $decision = sanitize_text_field((string) $request->get_param('decision'));
        $band     = sanitize_text_field((string) ($request->get_param('band') ?? ProductMatcher::BAND_NONE));
        $rows     = $request->get_param('rows');

        if (!is_array($rows)) {
            return $this->refuse('Bulk needs a list of rows.');
        }

        $built = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $wcId = absint($row['wc_id'] ?? 0);

            if ($wcId <= 0) {
                continue;
            }

            $one = $this->build(
                $wcId,
                sanitize_text_field((string) ($row['wc_type'] ?? '')),
                $decision,
                $band,
                [
                    'fc_post_id'  => $row['fc_post_id'] ?? null,
                    'variant_map' => $row['variant_map'] ?? null,
                    'orphans'     => $row['orphans'] ?? null,
                ],
            );

            if ($one !== null) {
                $built[] = $one;
            }
        }

        $this->repo()->saveMany($built);

        // The decisions come back, not just a count. A bulk press has to leave
        // the rows in exactly the state a per-row press would, and the only way
        // to guarantee that is for both to adopt the same server-built shape —
        // the client used to synthesise `{decision, fc_post_id}` here and the
        // full ProductMapDecision::toArray() there, so a row's `decision`
        // meant two different things depending on which button made it.
        return new WP_REST_Response(['data' => [
            'saved'     => count($built),
            'decisions' => array_map(
                static fn (ProductMapDecision $decision): array => $decision->toArray(),
                $built,
            ),
        ]]);
    }

    public function clear(WP_REST_Request $request): WP_REST_Response
    {
        $this->repo()->clear();

        return new WP_REST_Response(['data' => ['cleared' => true]]);
    }

    /**
     * @param array{fc_post_id: mixed, variant_map: mixed, orphans: mixed} $extra
     */
    private function build(int $wcId, string $wcType, string $decision, string $band, array $extra): ?ProductMapDecision
    {
        if ($decision === ProductMapDecision::CREATE) {
            return ProductMapDecision::create($wcId, $wcType, $band);
        }

        if ($decision === ProductMapDecision::SKIP) {
            return ProductMapDecision::skip($wcId, $wcType, $band);
        }

        if ($decision !== ProductMapDecision::LINK) {
            return null;
        }

        $fcPostId = absint($extra['fc_post_id'] ?? 0);

        if ($fcPostId <= 0) {
            return null;
        }

        $variantMap = [];

        if (is_array($extra['variant_map'])) {
            foreach ($extra['variant_map'] as $wooVariationId => $fcVariationId) {
                $wooVariationId = absint($wooVariationId);
                $fcVariationId  = absint($fcVariationId);

                if ($wooVariationId > 0 && $fcVariationId > 0) {
                    $variantMap[$wooVariationId] = $fcVariationId;
                }
            }
        }

        $orphans = [];

        if (is_array($extra['orphans'])) {
            foreach ($extra['orphans'] as $orphan) {
                if (!is_array($orphan) || absint($orphan['id'] ?? 0) <= 0) {
                    continue;
                }

                $one = [
                    'id'   => absint($orphan['id']),
                    'sku'  => sanitize_text_field((string) ($orphan['sku'] ?? '')),
                    'name' => sanitize_text_field((string) ($orphan['name'] ?? '')),
                    // Sanitised, not merely cast, and then whitelisted again in
                    // ProductMapDecision. These three describe a row that will
                    // be written into a product the owner built by hand, and
                    // they arrive back from the browser: a rubbish
                    // fulfillment_type would decide whether their orders demand
                    // a shipping address.
                    'fulfillment_type' => sanitize_text_field((string) ($orphan['fulfillment_type'] ?? '')),
                    'downloadable'     => sanitize_text_field((string) ($orphan['downloadable'] ?? '')),
                ];

                // Absent stays absent. A row saved before the price travelled
                // must not be read as "this variation was free" — see
                // ProductMapDecision::normalizeOrphans().
                if (isset($orphan['price'])) {
                    $one['price'] = max(0, (int) $orphan['price']);
                }

                $orphans[] = $one;
            }
        }

        return ProductMapDecision::link($wcId, $wcType, $fcPostId, $band, $variantMap, $orphans);
    }

    private function refuse(string $message): WP_REST_Response
    {
        return new WP_REST_Response(['data' => ['message' => $message, 'saved' => false]], 422);
    }

    private function repo(): ProductMapRepository
    {
        return $this->container->get(ProductMapRepository::class);
    }

    /**
     * Attach display labels — and variant consequences — to the ranked IDs.
     *
     * `band` travels with each candidate, not just the winner: ProductMatcher
     * selects band-first (see its class docblock), so the mapping screen needs
     * every candidate's own band to show trust per row in the dropdown, not
     * only the headline pick's.
     *
     * `variant` travels the same way and for a harder reason: choosing a
     * different candidate has to change the variant map that gets saved with
     * it, and the label that describes it. A dropdown offered without that is
     * a dropdown that silently mislabels and mis-links.
     *
     * `downloads_lost` travels the same way again: whether the files come
     * across depends on which FluentCart product is picked, so it belongs to
     * the candidate rather than to the row.
     *
     * @param list<array{id: int, band: string, score: float}>                                    $ranked
     * @param list<array{id: int, name: string, sku: string, price: float, variation_count: int}>  $candidates
     * @param array<int, array{matched: int, total: int, adds: int, map: array<int, int>, orphans: list<array{id: int, sku: string, name: string, price: int, fulfillment_type: string, downloadable: string}>}> $variantByCandidate
     * @param array<int, bool>                                                                     $downloadsByCandidate
     *
     * @return list<array{id: int, band: string, label: string, score: float, variant: array<string, mixed>|null, downloads_lost: bool}>
     */
    private function labelCandidates(
        array $ranked,
        array $candidates,
        array $variantByCandidate = [],
        array $downloadsByCandidate = [],
    ): array {
        $byId = [];

        foreach ($candidates as $candidate) {
            $byId[(int) $candidate['id']] = $candidate['name'];
        }

        $out = [];

        foreach ($ranked as $entry) {
            if (!isset($byId[$entry['id']])) {
                continue;
            }

            $out[] = [
                'id'             => $entry['id'],
                'band'           => $entry['band'],
                'label'          => $byId[$entry['id']],
                'score'          => $entry['score'],
                'variant'        => $variantByCandidate[$entry['id']] ?? null,
                'downloads_lost' => $downloadsByCandidate[$entry['id']] ?? false,
            ];
        }

        return $out;
    }

    /**
     * One page of in-scope WooCommerce products, shaped for the matcher.
     *
     * The source shape is ProductMigrator::countTotal()'s, join for join and
     * predicate for predicate — `wc_product_meta_lookup` joined to `wp_posts`,
     * ProductTypes::migratableClause(), then the scope. That is not tidiness:
     * this screen used to read `wp_posts` alone with no scope and no type
     * test, so `total` could never agree with the migrator's own count, an
     * explicit twelve-product run presented the whole catalogue for mapping,
     * and a LearnDash course got a row inviting the owner to link something
     * the run would never touch.
     *
     * The skip exclusion is deliberately *not* applied. This is the screen
     * where skips are decided; hiding them would leave no way to change one.
     *
     * @return list<array{id: int, name: string, type: string, order_count: int, match_fields: array{name: string, sku: string, price: float, variation_count: int}, variations: list<array{id: int, sku: string, name: string, price: int, fulfillment_type: string, downloadable: string}>}>
     */
    private function wooProductPage(MigrationScope $scope, int $page, int $perPage): array
    {
        global $wpdb;

        $offset = ($page - 1) * $perPage;

        [$typeSql, $typeValues] = ProductTypes::migratableClause('pml.product_id');
        $selection = (new ScopeResolver($scope))->productPredicate('p.ID');

        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT p.ID
             FROM {$wpdb->prefix}wc_product_meta_lookup pml
             INNER JOIN {$wpdb->posts} p ON p.ID = pml.product_id
             WHERE p.post_type = 'product'
               AND p.post_status IN ('publish', 'draft', 'private')
               AND {$typeSql}"
            . $selection->andSql()
            . ' ORDER BY p.ID ASC
             LIMIT %d OFFSET %d',
            ...[...$typeValues, ...$selection->values(), $perPage, $offset],
        ));

        // One instance for the whole page: its attribute-label lookups are
        // memoised per instance, and every variation of a variable product
        // repeats the same handful of attribute slugs.
        //
        // The whole mapper, not just variationTitle(). Everything the orphan
        // descriptor carries — title, price in FluentCart's storage format,
        // fulfilment type, downloadability — is something VariationMapper
        // already derives from a WooCommerce product, and it is the reviewed
        // copy of those rules. Deriving them a second time here is how the
        // orphan variant and the migrated variant start disagreeing about what
        // the same Woo variation costs.
        $mapper = new VariationMapper(
            function_exists('get_woocommerce_currency') ? (string) get_woocommerce_currency() : '',
        );

        $rows = [];

        foreach ($ids as $id) {
            $product = function_exists('wc_get_product') ? wc_get_product((int) $id) : null;

            if (!$product instanceof \WC_Product) {
                continue;
            }

            $variations = [];

            if ($product->get_type() === 'variable') {
                foreach ($product->get_children() as $childId) {
                    $child = wc_get_product((int) $childId);

                    if ($child instanceof \WC_Product_Variation) {
                        $variations[] = self::describeVariation(
                            (int) $childId,
                            (string) $child->get_sku(),
                            $mapper->mapVariation($child),
                        );
                    }
                }
            } else {
                // A simple product is one pseudo-variation keyed by the product
                // ID — the shape ProductMigrator and VariantResolver both expect.
                // Named for what CartShift itself writes for a simple product
                // (VariationMapper::mapSimple()), so a hand-built FluentCart
                // product carrying the same default pairs by name rather than
                // by falling through to position.
                $variations[] = self::describeVariation(
                    (int) $product->get_id(),
                    (string) $product->get_sku(),
                    $mapper->mapSimple($product),
                );
            }

            $rows[] = [
                'id'           => (int) $product->get_id(),
                'name'         => (string) $product->get_name(),
                'type'         => (string) $product->get_type(),
                'order_count'  => $this->orderCount((int) $product->get_id()),
                // Parent files first, then the variations', because a variable
                // product carries its downloads on the variations rather than
                // on the parent — the same split ProductMigrator has
                // migrateSimpleDownloads() and migrateVariableDownloads() for.
                'has_downloads' => $product->get_downloads() !== []
                    || self::anyVariationHasDownloads($product),
                'match_fields' => [
                    'name'            => (string) $product->get_name(),
                    'sku'             => (string) $product->get_sku(),
                    'price'           => (float) $product->get_price(),
                    'variation_count' => count($variations),
                ],
                'variations'   => $variations,
            ];
        }

        return $rows;
    }

    /**
     * Whether any variation of a variable product carries a downloadable file.
     *
     * Returns false without a query for anything else — a simple product's
     * files are the parent's, and the caller has already looked there.
     */
    private static function anyVariationHasDownloads(\WC_Product $product): bool
    {
        if ($product->get_type() !== 'variable') {
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
     * One Woo variation, as both the matcher and promotion need it.
     *
     * `name` is VariationMapper's title, never `WC_Product::get_name()`. The
     * latter is the generated post title — "Parent - Blue, Large", or bare
     * "Parent" once a product has three or more attributes — and the FluentCart
     * side holds attribute labels joined by ' / '. Feeding the two to
     * VariantResolver's name pass meant it never matched anything, so "SKU,
     * then name, then position" was really "SKU, then position" — and on a
     * blank-SKU catalogue, position alone. That is XL revenue on the L row.
     *
     * The other three fields exist for the orphans among these: a variation
     * with no FluentCart counterpart is created inside the owner's product, and
     * a created variant is priced, fulfilled and downloadable or not. Read
     * straight off the mapped payload rather than re-derived, so the orphan and
     * the variant ProductMigrator would have created cannot disagree.
     *
     * @param array<string, mixed> $mapped VariationMapper output for this variation.
     *
     * @return array{id: int, sku: string, name: string, price: int, fulfillment_type: string, downloadable: string}
     */
    private static function describeVariation(int $id, string $sku, array $mapped): array
    {
        return [
            'id'               => $id,
            'sku'              => $sku,
            'name'             => (string) $mapped['variation_title'],
            'price'            => (int) $mapped['item_price'],
            'fulfillment_type' => (string) $mapped['fulfillment_type'],
            'downloadable'     => (string) $mapped['downloadable'],
        ];
    }

    /**
     * How many products this screen is responsible for, under the same
     * predicates the page query applies. The two must agree or the summary
     * tile contradicts the rows underneath it.
     */
    private function wooProductCount(MigrationScope $scope): int
    {
        global $wpdb;

        [$typeSql, $typeValues] = ProductTypes::migratableClause('pml.product_id');
        $selection = (new ScopeResolver($scope))->productPredicate('p.ID');

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*)
             FROM {$wpdb->prefix}wc_product_meta_lookup pml
             INNER JOIN {$wpdb->posts} p ON p.ID = pml.product_id
             WHERE p.post_type = 'product'
               AND p.post_status IN ('publish', 'draft', 'private')
               AND {$typeSql}"
            . $selection->andSql(),
            ...[...$typeValues, ...$selection->values()],
        ));
    }

    /**
     * How many WooCommerce order line items reference this product.
     *
     * This is what tells the owner which twelve rows out of three hundred
     * actually matter. Without it every row looks equally important.
     */
    private function orderCount(int $productId): int
    {
        global $wpdb;

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT oi.order_id)
             FROM {$wpdb->prefix}woocommerce_order_itemmeta oim
             INNER JOIN {$wpdb->prefix}woocommerce_order_items oi ON oi.order_item_id = oim.order_item_id
             WHERE oim.meta_key = '_product_id' AND oim.meta_value = %d",
            $productId,
        ));
    }

    /**
     * The FluentCart catalogue, shaped for the matcher.
     *
     * Advanced-variation products are not in it, and that exclusion is the
     * whole reason this query joins `fct_product_details` at all. Such a
     * product's variants are a cartesian of attribute terms, regenerated in
     * full every time the owner touches the attribute options —
     * AdvancedVariationService::syncVariantCombinations() ends in
     * ProductAdminHelper::deleteOrphanVariant(), which deletes every variant
     * not in the freshly computed combination set. A variant CartShift added
     * for an orphan Woo variation is never in that set. So linking to one of
     * these products means the added variant is invisible on the storefront
     * from day one and is permanently deleted the next time the owner saves
     * combinations, taking every historical order line pointing at it into a
     * dangling state — the exact failure the orphan feature exists to prevent.
     *
     * Refusing at promotion time would be the safety net; refusing here is the
     * kindness. A target the owner must not pick should not be in the dropdown.
     * Both exist: see MigrationOrchestratorFactory::createOrphanVariant().
     *
     * LEFT JOIN, not INNER: a product whose detail row is missing is broken in
     * some other way, and silently dropping it from the mapping screen would
     * turn "I cannot find my product" into a support ticket with no evidence.
     *
     * @return list<array{id: int, name: string, sku: string, price: float, variation_count: int}>
     */
    private function fcCandidates(): array
    {
        global $wpdb;

        $products = $wpdb->get_results($wpdb->prepare(
            "SELECT p.ID, p.post_title
             FROM {$wpdb->posts} p
             LEFT JOIN {$wpdb->prefix}fct_product_details d ON d.post_id = p.ID
             WHERE p.post_type = '" . Constants::FC_PRODUCT_POST_TYPE . "'
               AND p.post_status IN ('publish', 'draft', 'private')
               AND (d.variation_type IS NULL OR d.variation_type != %s)
             ORDER BY p.ID ASC",
            Constants::FC_ADVANCED_VARIATIONS,
        ));

        $this->downloadsByFcProduct = self::fcProductsWithDownloads();

        $out = [];

        foreach ($products ?: [] as $product) {
            $fcPostId = (int) $product->ID;
            $variants = $this->fcVariants($fcPostId);

            // Cached for cachedFcVariants(), so rows() resolving variants for
            // whichever candidate the matcher picked does not re-query a
            // catalogue this method just finished reading.
            $this->variantsByFcProduct[$fcPostId] = $variants;

            $out[] = [
                'id'              => $fcPostId,
                'name'            => (string) $product->post_title,
                'sku'             => (string) ($variants[0]['sku'] ?? ''),
                'price'           => (float) ($variants[0]['price'] ?? 0.0),
                'variation_count' => count($variants),
            ];
        }

        return $out;
    }

    /**
     * Which FluentCart products carry at least one downloadable file.
     *
     * One grouped query for the whole catalogue rather than one COUNT per
     * candidate: every candidate of every row on the page needs the answer, and
     * `fct_product_downloads` holds one row per file — small even on a shop
     * with a large catalogue.
     *
     * Only the products that *have* files are in the result, so the caller's
     * `?? false` is the answer for everything else.
     *
     * @return array<int, bool>
     */
    private static function fcProductsWithDownloads(): array
    {
        global $wpdb;

        $ids = $wpdb->get_col(
            "SELECT DISTINCT post_id FROM {$wpdb->prefix}fct_product_downloads",
        );

        $out = [];

        foreach ($ids ?: [] as $id) {
            $out[(int) $id] = true;
        }

        return $out;
    }

    /**
     * fcVariants(), served from fcCandidates()'s cache when available.
     *
     * By the time rows() reaches this call $candidates has always already
     * been built, so the cache always has an entry for any id the matcher
     * could have returned. The direct fcVariants() fallback exists only for
     * a caller that reaches this method without going through
     * fcCandidates() first — nothing in this class does today, but the
     * fallback keeps the method honest on its own rather than relying on
     * call-order elsewhere.
     *
     * @return list<array{id: int, sku: string, name: string, price: float}>
     */
    private function cachedFcVariants(int $fcPostId): array
    {
        return $this->variantsByFcProduct[$fcPostId] ?? $this->fcVariants($fcPostId);
    }

    /**
     * @return list<array{id: int, sku: string, name: string, price: float}>
     */
    private function fcVariants(int $fcPostId): array
    {
        global $wpdb;

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id, variation_title, item_price, sku
             FROM {$wpdb->prefix}fct_product_variations
             WHERE post_id = %d
             ORDER BY id ASC",
            $fcPostId,
        ));

        $out = [];

        foreach ($rows ?: [] as $row) {
            $out[] = [
                'id'    => (int) $row->id,
                'sku'   => (string) ($row->sku ?? ''),
                'name'  => (string) ($row->variation_title ?? ''),
                // FluentCart stores money as amount x 100 for every currency —
                // verified against fluent-cart/app/Helpers/Helper.php::toCent()
                // / toDecimalWithoutComma(), the same unconditional x100/÷100
                // pair CartShift's own MoneyHelper docblock documents. No
                // reverse helper exists on MoneyHelper yet (nothing previously
                // read an FC price back out), so this divides inline rather
                // than inventing a currency-conditional rule that would
                // disagree with both.
                'price' => ((int) ($row->item_price ?? 0)) / 100,
            ];
        }

        return $out;
    }
}

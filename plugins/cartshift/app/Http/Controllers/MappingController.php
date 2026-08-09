<?php

declare(strict_types=1);

namespace CartShift\Http\Controllers;

use CartShift\Core\Container;
use CartShift\Domain\Mapping\MappingSetValidation;
use CartShift\Domain\Mapping\MappingSetValidator;
use CartShift\Domain\Mapping\ProductMapDecision;
use CartShift\Domain\Mapping\ProductMatcher;
use CartShift\Domain\Mapping\SubscriptionVariantMatcher;
use CartShift\Domain\Mapping\VariantResolver;
use CartShift\Domain\Mapping\VariationMapper;
use CartShift\Domain\Subscription\NormalizedSubscriptionContract;
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
     * The decision's own WooCommerce product cannot be loaded.
     *
     * §9.4's `required_reference_missing`, used at its source end: the
     * reference a mapping decision is *about* is the one reference it cannot
     * do without. Reusing the plan's code rather than inventing one keeps the
     * list closed, and keeps commands, receipts and retry logic reading one
     * vocabulary.
     */
    private const string ERROR_SOURCE_UNREADABLE = 'required_reference_missing';

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

        // The band=none rescue. rows() drops every `none` candidate before the
        // slice, which is right for a dropdown of suggestions and wrong as the
        // only route to a product: "no automatic suggestion" is not "cannot be
        // selected". ProductMatcher scored both Lapka source products `none`,
        // and they are the two products the whole migration exists for.
        register_rest_route(self::NAMESPACE, '/mapping/catalogue', [
            'methods'             => 'GET',
            'callback'            => [$this, 'catalogue'],
            'permission_callback' => [$this, 'checkPermission'],
            'args'                => [
                'q'        => ['type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_text_field'],
                'page'     => ['type' => 'integer', 'default' => 1, 'sanitize_callback' => 'absint'],
                'per_page' => ['type' => 'integer', 'default' => self::DEFAULT_PER_PAGE, 'sanitize_callback' => 'absint'],
            ],
        ]);

        // What linking this Woo product to that FluentCart product would do,
        // for a product the matcher never offered. The variation choice is a
        // billing contract, so it is decided here rather than synthesised in
        // the browser from a catalogue listing.
        register_rest_route(self::NAMESPACE, '/mapping/variants', [
            'methods'             => 'GET',
            'callback'            => [$this, 'variants'],
            'permission_callback' => [$this, 'checkPermission'],
            'args'                => [
                'wc_id'      => ['type' => 'integer', 'sanitize_callback' => 'absint'],
                'fc_post_id' => ['type' => 'integer', 'sanitize_callback' => 'absint'],
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

        $productMatcher = new ProductMatcher();
        $variantMatcher = $this->variantMatcher();
        $repo           = $this->repo();

        $rows = [];

        foreach ($wooProducts as $woo) {
            $match = $productMatcher->match($woo['match_fields'], $candidates);

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
                $variantByCandidate[$entry['id']] = $this->variantSummary($woo, $entry['id'], $variantMatcher);
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
     * variants: how many pair up, which have no counterpart at all, and — for
     * the subscriptions among them — every target variation's billing contract
     * and whether it is compatible.
     *
     * @param array{variations: list<array<string, mixed>>, ...} $woo
     * @param array<int, int>                                    $chosen Operator overrides.
     *
     * @return array{matched: int, total: int, adds: int, map: array<int, int>, orphans: list<array{id: int, sku: string, name: string, price: int, fulfillment_type: string, downloadable: string}>, errors: list<array<string, mixed>>, warnings: list<array<string, mixed>>, sources: list<array<string, mixed>>, subscription: bool}
     */
    private function variantSummary(
        array $woo,
        int $fcPostId,
        SubscriptionVariantMatcher $matcher,
        array $chosen = [],
    ): array {
        $resolved = $matcher->match($woo['variations'], $this->cachedFcVariants($fcPostId), $chosen);

        // The matcher returns orphan IDs; promotion needs the whole descriptor
        // to name and SKU the variant it will create, so rehydrate here where
        // the Woo variations are still in hand — and only the six fields
        // ProductMapDecision persists, because the rest is matcher input that
        // would otherwise make a round trip through the browser for nothing.
        $byId = [];

        foreach ($woo['variations'] as $variation) {
            $byId[$variation['id']] = $variation;
        }

        $orphanDetail = [];

        foreach ($resolved['orphans'] as $orphanId) {
            if (isset($byId[$orphanId])) {
                $orphanDetail[] = self::orphanDescriptor($byId[$orphanId]);
            }
        }

        return [
            'matched'      => count($resolved['map']),
            'total'        => count($woo['variations']),
            'adds'         => count($orphanDetail),
            'map'          => $resolved['map'],
            'orphans'      => $orphanDetail,
            'errors'       => $resolved['errors'],
            'warnings'     => $resolved['warnings'],
            'sources'      => $resolved['sources'],
            'subscription' => $resolved['sources'] !== [],
        ];
    }

    /**
     * The six fields a saved decision keeps for an orphan variation.
     *
     * @param array<string, mixed> $variation
     * @return array{id: int, sku: string, name: string, price: int, fulfillment_type: string, downloadable: string}
     */
    private static function orphanDescriptor(array $variation): array
    {
        return [
            'id'               => (int) $variation['id'],
            'sku'              => (string) $variation['sku'],
            'name'             => (string) $variation['name'],
            'price'            => (int) $variation['price'],
            'fulfillment_type' => (string) $variation['fulfillment_type'],
            'downloadable'     => (string) $variation['downloadable'],
        ];
    }

    private function variantMatcher(): SubscriptionVariantMatcher
    {
        return new SubscriptionVariantMatcher(new VariantResolver());
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
            'fc_post_id'          => $request->get_param('fc_post_id'),
            'variant_map'         => $request->get_param('variant_map'),
            'orphans'             => $request->get_param('orphans'),
            'allow_shared_target' => $request->get_param('allow_shared_target'),
        ]);

        if ($built === null) {
            return $this->refuse(sprintf('Unusable decision "%s" for product %d.', $decision, $wcId));
        }

        $contractErrors = $this->contractErrors($built);

        if ($contractErrors !== []) {
            return $this->refuseErrors($contractErrors);
        }

        $validation = $this->validateSet([$built]);

        if (!$validation->isValid()) {
            return $this->refuseSet($validation);
        }

        $this->repo()->save($built);

        return new WP_REST_Response(['data' => [
            'saved'               => true,
            'decision'            => $built->toArray(),
            'mapping_fingerprint' => $validation->fingerprint(),
        ]]);
    }

    /**
     * Apply one decision to many rows.
     *
     * Rows it cannot *build* are dropped rather than failing the batch: a bulk
     * "link all" over a band where one row lost its candidate should link the
     * other eighteen, not refuse the lot.
     *
     * A row that builds and then fails validation is the opposite, and
     * deliberately so. Dropping it would leave that product silently unmapped
     * behind a screen reporting eighteen successes — which on Lapka is the
     * yearly product and its 188 subscribers. So one contract error or one
     * collision refuses the whole batch, naming the source variation at fault.
     * Pinned by MappingControllerTest
     * ::testOneContractErrorRefusesTheWholeBatchIncludingTheGoodRows.
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
                    'fc_post_id'          => $row['fc_post_id'] ?? null,
                    'variant_map'         => $row['variant_map'] ?? null,
                    'orphans'             => $row['orphans'] ?? null,
                    'allow_shared_target' => $row['allow_shared_target'] ?? null,
                ],
            );

            if ($one !== null) {
                $built[] = $one;
            }
        }

        // Unusable rows are dropped; a colliding *set* is not. Dropping half a
        // collision would leave whichever row happened to be second silently
        // unmapped, which is the failure mode this validation exists to remove.
        // A contract-incompatible row is refused for the same reason: a bulk
        // "link all" that quietly skipped the yearly product would leave 188
        // subscribers with nowhere to go and a screen reporting success.
        foreach ($built as $decision) {
            $contractErrors = $this->contractErrors($decision);

            if ($contractErrors !== []) {
                return $this->refuseErrors($contractErrors);
            }
        }

        $validation = $this->validateSet($built);

        if (!$validation->isValid()) {
            return $this->refuseSet($validation);
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
            'mapping_fingerprint' => $validation->fingerprint(),
        ]]);
    }

    public function clear(WP_REST_Request $request): WP_REST_Response
    {
        $this->repo()->clear();

        return new WP_REST_Response(['data' => ['cleared' => true]]);
    }

    /**
     * The whole target catalogue, searchable and paged.
     *
     * rows() offers only what ProductMatcher scored above `none`, which is
     * right — eight implausible products under a heading saying "No candidate"
     * is an invitation to fuse a Gift Card with a T-shirt. But it left an owner
     * whose product the matcher could not recognise with no way to map it at
     * all, and the two Lapka subscription products are exactly that: both
     * scored `none`, and both must be mapped for the migration to mean
     * anything.
     *
     * Every variation comes with its billing contract, because on a
     * subscription product that is what the operator is choosing between.
     */
    public function catalogue(WP_REST_Request $request): WP_REST_Response
    {
        $page    = max(1, (int) $request->get_param('page'));
        $perPage = min(self::MAX_PER_PAGE, max(1, (int) $request->get_param('per_page')));
        $search  = trim(sanitize_text_field((string) ($request->get_param('q') ?? '')));

        return new WP_REST_Response(['data' => [
            'products' => $this->fcCataloguePage($search, $page, $perPage),
            'page'     => $page,
            'per_page' => $perPage,
            'total'    => $this->fcCatalogueCount($search),
        ]]);
    }

    /**
     * What linking this Woo product to that FluentCart product would do.
     *
     * The variant block for a product the matcher never offered, computed the
     * same way rows() computes it for one it did — server-side, because a
     * subscription's variation is a billing contract and choosing it in the
     * browser from a catalogue listing would be a second, divergent copy of the
     * cadence gate.
     */
    public function variants(WP_REST_Request $request): WP_REST_Response
    {
        $wcId     = absint($request->get_param('wc_id'));
        $fcPostId = absint($request->get_param('fc_post_id'));

        if ($wcId <= 0 || $fcPostId <= 0) {
            return $this->refuse('A variant preview needs a WooCommerce product and a FluentCart product.');
        }

        $product = function_exists('wc_get_product') ? wc_get_product($wcId) : null;

        if (!$product instanceof \WC_Product) {
            return $this->refuse(sprintf('WooCommerce product %d is not readable.', $wcId));
        }

        $chosen = [];

        if (is_array($request->get_param('variant_map'))) {
            foreach ($request->get_param('variant_map') as $sourceId => $targetId) {
                $sourceId = absint($sourceId);
                $targetId = absint($targetId);

                if ($sourceId > 0 && $targetId > 0) {
                    $chosen[$sourceId] = $targetId;
                }
            }
        }

        $mapper = new VariationMapper(
            function_exists('get_woocommerce_currency') ? (string) get_woocommerce_currency() : '',
        );

        $woo = $this->describeWooProduct($product, $mapper);

        return new WP_REST_Response(['data' => [
            'variant' => $this->variantSummary($woo, $fcPostId, $this->variantMatcher(), $chosen),
            'label'   => (string) get_the_title($fcPostId),
        ]]);
    }

    /**
     * @param array{fc_post_id: mixed, variant_map: mixed, orphans: mixed, allow_shared_target: mixed} $extra
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

        // Identity against the two shapes a JSON body can carry a boolean in,
        // and nothing else. `'no'`, `'0'` and `'false'` are all truthy strings,
        // and each would read as the operator having approved a shared billing
        // contract they were never shown.
        $allowSharedTarget = in_array($extra['allow_shared_target'] ?? null, [true, 'true'], true);

        return ProductMapDecision::link(
            $wcId,
            $wcType,
            $fcPostId,
            $band,
            $variantMap,
            $orphans,
            $allowSharedTarget,
        );
    }

    /**
     * Validate the incoming decisions against every decision already saved.
     *
     * `VariantResolver::$claimed` protects one product decision, so two Woo
     * products decided one after the other can each claim the same FluentCart
     * variation with neither call noticing. This is where that is caught, and
     * it has to run over the whole set rather than over what just arrived.
     *
     * @param list<ProductMapDecision> $incoming
     */
    private function validateSet(array $incoming): MappingSetValidation
    {
        $set = [];

        foreach ($this->repo()->all() as $existing) {
            $set[$existing->wcId()] = $existing;
        }

        // The incoming decisions replace their own rows rather than joining
        // them: re-deciding a product must not collide with its former self.
        foreach ($incoming as $decision) {
            $set[$decision->wcId()] = $decision;
        }

        $index = $this->contractIndex($set);

        return (new MappingSetValidator($index['contracts'], $index['unreadable']))
            ->validate(array_values($set));
    }

    private function refuseSet(MappingSetValidation $validation): WP_REST_Response
    {
        return $this->refuseErrors($validation->errors);
    }

    /**
     * @param list<array<string, mixed>> $errors
     */
    private function refuseErrors(array $errors): WP_REST_Response
    {
        return new WP_REST_Response([
            'data' => [
                'saved'   => false,
                'message' => $errors[0]['message'] ?? 'This mapping cannot be saved.',
                'errors'  => $errors,
            ],
        ], 422);
    }

    /**
     * Re-derive this link's contracts and check the posted variant map against
     * them.
     *
     * The mapping screen hides the Link button while a subscription source has
     * no compatible target, and a screen is not a gate: this endpoint takes a
     * variant map from the browser, which is the one participant on this path
     * CartShift does not control. Section 7.3 says saving *requires* an
     * explicit compatible variation for every source variation, so this is
     * where "requires" happens.
     *
     * One-time products keep their freedoms: their variant map may be absent,
     * partial or resolver-derived exactly as it has always been. Two things
     * they do not keep, both of which used to pass because this gate only ever
     * looked at the shape it was written for — claiming a *subscription*
     * variation, which the matcher refuses below, and naming a source variation
     * the product does not have, which the membership check refuses first.
     *
     * @return list<array<string, mixed>>
     */
    private function contractErrors(ProductMapDecision $decision): array
    {
        $fcPostId = $decision->fcPostId();

        if (!$decision->isLink() || $fcPostId === null) {
            return [];
        }

        $product = function_exists('wc_get_product') ? wc_get_product($decision->wcId()) : null;

        if (!$product instanceof \WC_Product) {
            // A named refusal, not a shrug. This used to return no errors —
            // and sourceContract() separately returned null for the same
            // reason, which MappingSetValidator read as one-time — so a
            // decision about a product nobody can read passed both gates and a
            // monthly/yearly collision between two such decisions validated
            // clean. The plan's inference policy is explicit: an unresolved
            // load-bearing fact gets a named branch or a stop, never a
            // cheerful default.
            return [[
                'code'                => self::ERROR_SOURCE_UNREADABLE,
                'source_variation_id' => $decision->wcId(),
                'target_variation_id' => null,
                'message'             => sprintf(
                    'WooCommerce product %d cannot be read, so nothing can be verified about what it bills. '
                    . 'Restore it, or skip it.',
                    $decision->wcId(),
                ),
            ]];
        }

        $mapper = new VariationMapper(
            function_exists('get_woocommerce_currency') ? (string) get_woocommerce_currency() : '',
        );

        $woo = $this->describeWooProduct($product, $mapper, withOrderCount: false);

        $subscriptionVariations = array_values(array_filter(
            $woo['variations'],
            static fn (array $variation): bool => ($variation['payment_type'] ?? '') === 'subscription',
        ));

        $variantMap = $decision->variantMap();
        $errors     = [];

        // A variant map key names a source variation, and the browser chose it.
        // A key naming something that is not a variation of this product used
        // to be persisted verbatim and then passed the set validator as a
        // single, uncontested claim — the third member of the family this gate
        // has now closed twice: it refused the shape it was written for and
        // waved through everything else. It is reachable without a hostile
        // client, by re-saving a decision after the owner deleted or
        // regenerated a variation.
        $ownVariationIds = array_map(
            static fn (array $variation): int => (int) $variation['id'],
            $woo['variations'],
        );

        foreach (array_keys($variantMap) as $sourceVariationId) {
            if (in_array((int) $sourceVariationId, $ownVariationIds, true)) {
                continue;
            }

            // Named, not numbered. The two integers are correct and nearly
            // useless: the ordinary route here is catalogue maintenance —
            // somebody deleted or regenerated a variation of a mapped variable
            // product — and an owner meeting this refusal has to find one row
            // among a few thousand and know which choice is being discarded.
            // "Reload and choose again" told them neither.
            $errors[] = [
                'code'                => self::ERROR_SOURCE_UNREADABLE,
                'source_variation_id' => (int) $sourceVariationId,
                'target_variation_id' => (int) $variantMap[$sourceVariationId],
                'wc_id'               => $decision->wcId(),
                'wc_name'             => (string) $woo['name'],
                'message'             => sprintf(
                    'Variation %d is no longer a variation of "%s" (WooCommerce product %d) — it has '
                    . 'been deleted or regenerated since this mapping was saved, so the FluentCart '
                    . 'variation %d it pointed at cannot be honoured. Nothing was saved. Find "%s" on '
                    . 'the mapping screen, reload it, and choose a variation for each of the ones it '
                    . 'has now.',
                    (int) $sourceVariationId,
                    $woo['name'],
                    $decision->wcId(),
                    (int) $variantMap[$sourceVariationId],
                    $woo['name'],
                ),
            ];
        }

        foreach ($subscriptionVariations as $variation) {
            if (!isset($variantMap[$variation['id']])) {
                $errors[] = [
                    'code'                => SubscriptionVariantMatcher::ERROR_TARGET_MISSING,
                    'source_variation_id' => (int) $variation['id'],
                    'target_variation_id' => null,
                    'message'             => sprintf(
                        'Choose a FluentCart variation for "%s". A subscription is never paired by position.',
                        $variation['name'],
                    ),
                ];
            }
        }

        // The matcher judges the choices that were made; the loop above catches
        // the ones that were not.
        return [
            ...$errors,
            ...$this->variantMatcher()->match(
                $woo['variations'],
                $this->cachedFcVariants($fcPostId),
                $variantMap,
            )['errors'],
        ];
    }

    /**
     * Source contracts for the variations that need one.
     *
     * Only contested target variations are resolved. A target claimed once
     * passes on the per-row contract gate the matcher already applied, so
     * loading a WooCommerce product for every decision in a 2,000-row catalogue
     * to answer a question nobody asks would be a query storm on every save.
     *
     * Unreadable sources are reported separately rather than left absent. An
     * absent contract is indistinguishable from a one-time product, and the
     * validator's all-one-time arm passes several claims without asking — so
     * two decisions whose products had been deleted would key identically and
     * a monthly/yearly collision would validate clean.
     *
     * @param array<int, ProductMapDecision> $set
     * @return array{contracts: array<int, NormalizedSubscriptionContract>, unreadable: list<int>}
     */
    private function contractIndex(array $set): array
    {
        $claims = [];

        foreach ($set as $decision) {
            if (!$decision->isLink()) {
                continue;
            }

            foreach ($decision->variantMap() as $sourceVariationId => $targetVariationId) {
                $claims[$targetVariationId][] = (int) $sourceVariationId;
            }
        }

        $contracts  = [];
        $unreadable = [];
        $seen       = [];

        foreach ($claims as $sourceVariationIds) {
            if (count($sourceVariationIds) < 2) {
                continue;
            }

            foreach ($sourceVariationIds as $sourceVariationId) {
                if (isset($seen[$sourceVariationId])) {
                    continue;
                }

                $seen[$sourceVariationId] = true;

                if (!self::sourceIsReadable($sourceVariationId)) {
                    $unreadable[] = $sourceVariationId;

                    continue;
                }

                $contract = self::sourceContract($sourceVariationId);

                if ($contract !== null) {
                    $contracts[$sourceVariationId] = $contract;
                }
            }
        }

        return ['contracts' => $contracts, 'unreadable' => $unreadable];
    }

    private static function sourceIsReadable(int $sourceVariationId): bool
    {
        return function_exists('wc_get_product')
            && wc_get_product($sourceVariationId) instanceof \WC_Product;
    }

    /**
     * The normalised contract of one Woo variation — or of a simple product,
     * whose own ID is its pseudo-variation key.
     *
     * Null means "not a subscription", which MappingSetValidator reads as the
     * one-time behaviour CartShift has always had.
     */
    private static function sourceContract(int $sourceVariationId): ?NormalizedSubscriptionContract
    {
        $product = function_exists('wc_get_product') ? wc_get_product($sourceVariationId) : null;

        if (!$product instanceof \WC_Product) {
            return null;
        }

        $mapper = new VariationMapper(
            function_exists('get_woocommerce_currency') ? (string) get_woocommerce_currency() : '',
        );

        $mapped = $product instanceof \WC_Product_Variation
            ? $mapper->mapVariation($product)
            : $mapper->mapSimple($product);

        $fields = self::subscriptionFields($product, $mapped);

        if ($fields['payment_type'] !== 'subscription') {
            return null;
        }

        return NormalizedSubscriptionContract::fromWooCommerce(
            $fields['period'],
            $fields['multiplier'],
            $fields['trial_days'],
            $fields['times'],
        );
    }

    /**
     * The raw cadence a Woo row bills on, alongside the trial and term
     * VariationMapper already derived.
     *
     * The period and multiplier are read from WooCommerce Subscriptions' own
     * meta rather than from the mapped payload, and that is deliberate: the
     * payload's `repeat_interval` has already been through
     * FcBillingInterval::fromWooCommerce(), which collapses `week/2` to weekly
     * and `year/2` to yearly. Reading it back would ask the exact cadence table
     * a question that had already been answered wrongly. Trial and term come
     * from the payload, because those the mapper derives without loss and a
     * second copy of `ceil($length / $interval)` here is how the orphan variant
     * and the migrated variant start disagreeing.
     *
     * @param array<string, mixed> $mapped VariationMapper output for this row.
     * @return array{payment_type: string, period: string, multiplier: int, trial_days: int, times: int}
     */
    private static function subscriptionFields(\WC_Product $product, array $mapped): array
    {
        if (($mapped['payment_type'] ?? '') !== 'subscription') {
            return ['payment_type' => 'onetime', 'period' => '', 'multiplier' => 0, 'trial_days' => 0, 'times' => 0];
        }

        $otherInfo = is_array($mapped['other_info'] ?? null) ? $mapped['other_info'] : [];

        return [
            'payment_type' => 'subscription',
            'period'       => (string) ($product->get_meta('_subscription_period') ?: ''),
            'multiplier'   => (int) ($product->get_meta('_subscription_period_interval') ?: 1),
            'trial_days'   => (int) ($otherInfo['trial_days'] ?? 0),
            'times'        => (int) ($otherInfo['times'] ?? 0),
        ];
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

            if ($product instanceof \WC_Product) {
                $rows[] = $this->describeWooProduct($product, $mapper);
            }
        }

        return $rows;
    }

    /**
     * One WooCommerce product, shaped for the matcher and the variant summary.
     *
     * Extracted from wooProductPage() so the manual-selection endpoint can
     * describe a single product the same way the page does. Two descriptions of
     * one product is how the row's variant block and the block the operator
     * actually saved come to disagree.
     *
     * `$withOrderCount` exists because `orderCount()` is a COUNT(DISTINCT) over
     * `woocommerce_order_itemmeta`, and the save gate never reads it. On a
     * per-row decide that is one wasted query; on a bulk press over a band it
     * is one per row, and useMapping.js will hold twenty thousand of them.
     *
     * @return array{id: int, name: string, type: string, order_count: int, has_downloads: bool, match_fields: array{name: string, sku: string, price: float, variation_count: int}, variations: list<array<string, mixed>>}
     */
    private function describeWooProduct(
        \WC_Product $product,
        VariationMapper $mapper,
        bool $withOrderCount = true,
    ): array {
        $variations = [];

        if (ProductTypes::isVariable($product->get_type())) {
            foreach ($product->get_children() as $childId) {
                $child = wc_get_product((int) $childId);

                if ($child instanceof \WC_Product_Variation) {
                    $variations[] = self::describeVariation(
                        (int) $childId,
                        (string) $child->get_sku(),
                        $mapper->mapVariation($child),
                        $child,
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
                $product,
            );
        }

        return [
            'id'           => (int) $product->get_id(),
            'name'         => (string) $product->get_name(),
            'type'         => (string) $product->get_type(),
            'order_count'  => $withOrderCount ? $this->orderCount((int) $product->get_id()) : 0,
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

    /**
     * Whether any variation of a variable product carries a downloadable file.
     *
     * Returns false without a query for anything else — a simple product's
     * files are the parent's, and the caller has already looked there.
     */
    private static function anyVariationHasDownloads(\WC_Product $product): bool
    {
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
     * The five subscription fields beyond those exist for
     * SubscriptionVariantMatcher, which cannot pair a recurring row by name and
     * position the way the other three passes do. `period` and `multiplier` are
     * the raw WooCommerce Subscriptions cadence — see subscriptionFields() for
     * why they cannot be read back out of the mapped payload.
     *
     * @param array<string, mixed> $mapped VariationMapper output for this variation.
     *
     * @return array{id: int, sku: string, name: string, price: int, fulfillment_type: string, downloadable: string, payment_type: string, period: string, multiplier: int, trial_days: int, times: int}
     */
    private static function describeVariation(int $id, string $sku, array $mapped, \WC_Product $source): array
    {
        return [
            'id'               => $id,
            'sku'              => $sku,
            'name'             => (string) $mapped['variation_title'],
            'price'            => (int) $mapped['item_price'],
            'fulfillment_type' => (string) $mapped['fulfillment_type'],
            'downloadable'     => (string) $mapped['downloadable'],
            ...self::subscriptionFields($source, $mapped),
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
     * One FluentCart product's variants, in the order FluentCart shows them.
     *
     * The ordering is load-bearing and used to be wrong. VariantResolver's
     * third pass pairs positionally — the nth Woo variation to the nth FC
     * variant — which is only defensible if "nth" is the position the owner
     * sees. This was `ORDER BY id ASC`, which is creation order. An owner who
     * had ever reordered their variants got the first Woo variation attached to
     * a variant that is no longer first, and every migrated order line and
     * subscription for it filed against the wrong one, silently.
     *
     * `serial_index ASC` is FluentCart's own answer, and it is unanimous:
     * ProductController::get() (the admin product editor, which is where the
     * owner does the reordering), ProductResource::find(), ProductDetail
     * ::variants(), Product::duplicateProduct(), ShopResource, BulkProduct
     * UpdateService and ProductCardShortCode all order the relation exactly so,
     * and ProductRenderer sorts the storefront's variant list by the same
     * column. Nothing anywhere orders variants by id.
     *
     * **NULLs first, matching MySQL and matching FluentCart.** `serial_index`
     * is nullable and NULL is the normal case for a hand-built product: 58 of
     * the 76 variant rows on the live store are NULL, and only the ones
     * CartShift itself added carry a number, because ProductAdminHelper only
     * assigns the index when the owner saves through the editor. FluentCart's
     * variant *lists* are all plain `orderBy('serial_index', 'ASC')`, so its
     * NULLs lead — verified against the live store, where product 81 displays
     * variant 52 (NULL) ahead of variants 50 and 51 (1 and 2), the exact
     * inversion `ORDER BY id ASC` got wrong.
     *
     * FluentCart does have one place that disagrees — ProductRenderer and
     * PackageDescriptionRenderer sort NULL to PHP_INT_MAX — but that is the
     * choice of a *default* variant, not the order of the list, and it is
     * explicitly justified there as "don't let an unordered row become the
     * default". Copying it here would reorder the list against every screen the
     * owner actually maps from.
     *
     * `id ASC` survives as the tie-break, and it has real work to do: NULLs are
     * all equal to each other, and duplicate indexes exist in live data
     * (product 548 has two variants at serial_index 1). MySQL leaves ties
     * unordered, so without this the pairing could differ between two runs of
     * the same query — a positional map that is not deterministic is worse than
     * one that is merely in the wrong order, because it cannot even be
     * reproduced. It is also what InnoDB returns for ties in practice, so this
     * pins FluentCart's own effective behaviour rather than inventing one.
     *
     * @return list<array{id: int, sku: string, name: string, price: float}>
     */
    private function fcVariants(int $fcPostId): array
    {
        global $wpdb;

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id, variation_title, item_price, sku, payment_type, other_info
             FROM {$wpdb->prefix}fct_product_variations
             WHERE post_id = %d
             ORDER BY serial_index ASC, id ASC",
            $fcPostId,
        ));

        $out = [];

        foreach ($rows ?: [] as $row) {
            // FluentCart keeps a variation's recurring configuration in
            // `other_info` — verified against ProductRequest.php:279, which
            // requires `other_info.repeat_interval` whenever
            // `other_info.payment_type` is `subscription`, and
            // ProductVariationRequest.php:64, which lists the whole
            // subscription field set. Nullable in the schema, so absent means
            // one-time rather than broken.
            $otherInfo = json_decode((string) ($row->other_info ?? ''), true);
            $otherInfo = is_array($otherInfo) ? $otherInfo : [];

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
                'price' => (float) (((int) ($row->item_price ?? 0)) / 100),
                'payment_type'    => (string) ($row->payment_type ?? ''),
                'repeat_interval' => (string) ($otherInfo['repeat_interval'] ?? ''),
                'trial_days'      => (int) ($otherInfo['trial_days'] ?? 0),
                'times'           => (int) ($otherInfo['times'] ?? 0),
            ];
        }

        return $out;
    }

    /**
     * One page of the target catalogue, each product with every variation's
     * billing contract.
     *
     * Same exclusions as fcCandidates(): Advanced Variations products are not
     * offered, because FluentCart regenerates their variants from the attribute
     * cartesian and deletes anything not in it. A LEFT JOIN for the same reason
     * too — a product with no detail row is broken some other way, and hiding
     * it turns "I cannot find my product" into a support ticket with no
     * evidence.
     *
     * @return list<array{id: int, name: string, sku: string, variation_count: int, variations: list<array<string, mixed>>}>
     */
    private function fcCataloguePage(string $search, int $page, int $perPage): array
    {
        global $wpdb;

        [$searchSql, $searchValues] = self::catalogueSearchClause($search);

        $products = $wpdb->get_results($wpdb->prepare(
            "SELECT p.ID, p.post_title
             FROM {$wpdb->posts} p
             LEFT JOIN {$wpdb->prefix}fct_product_details d ON d.post_id = p.ID
             WHERE p.post_type = '" . Constants::FC_PRODUCT_POST_TYPE . "'
               AND p.post_status IN ('publish', 'draft', 'private')
               AND (d.variation_type IS NULL OR d.variation_type != %s)"
            . $searchSql
            . ' ORDER BY p.post_title ASC, p.ID ASC
             LIMIT %d OFFSET %d',
            ...[Constants::FC_ADVANCED_VARIATIONS, ...$searchValues, $perPage, ($page - 1) * $perPage],
        ));

        $out = [];

        foreach ($products ?: [] as $product) {
            $variants = $this->fcVariants((int) $product->ID);

            $out[] = [
                'id'              => (int) $product->ID,
                'name'            => (string) $product->post_title,
                'sku'             => (string) ($variants[0]['sku'] ?? ''),
                'variation_count' => count($variants),
                'variations'      => $variants,
            ];
        }

        return $out;
    }

    private function fcCatalogueCount(string $search): int
    {
        global $wpdb;

        [$searchSql, $searchValues] = self::catalogueSearchClause($search);

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*)
             FROM {$wpdb->posts} p
             LEFT JOIN {$wpdb->prefix}fct_product_details d ON d.post_id = p.ID
             WHERE p.post_type = '" . Constants::FC_PRODUCT_POST_TYPE . "'
               AND p.post_status IN ('publish', 'draft', 'private')
               AND (d.variation_type IS NULL OR d.variation_type != %s)"
            . $searchSql,
            ...[Constants::FC_ADVANCED_VARIATIONS, ...$searchValues],
        ));
    }

    /**
     * @return array{0: string, 1: list<string>}
     */
    private static function catalogueSearchClause(string $search): array
    {
        if ($search === '') {
            return ['', []];
        }

        global $wpdb;

        return [' AND p.post_title LIKE %s', ['%' . $wpdb->esc_like($search) . '%']];
    }
}

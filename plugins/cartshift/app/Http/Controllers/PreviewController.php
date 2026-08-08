<?php

declare(strict_types=1);

namespace CartShift\Http\Controllers;

defined('ABSPATH') || exit;

use CartShift\Core\Container;
use CartShift\Domain\Scope\MigrationScope;
use CartShift\Domain\Scope\ScopePreview;
use CartShift\Domain\Scope\ScopeResolver;
use CartShift\Migrator\CouponMigrator;
use CartShift\Migrator\CustomerMigrator;
use CartShift\Migrator\OrderMigrator;
use CartShift\Migrator\ProductMigrator;
use CartShift\Migrator\SubscriptionMigrator;
use CartShift\State\MigrationState;
use CartShift\Storage\IdMapRepository;
use CartShift\Storage\MigrationLogRepository;
use CartShift\Support\Constants;
use CartShift\Support\WooStorage;
use CartShift\Validator\PreflightCheck;
use WP_REST_Request;
use WP_REST_Response;

/**
 * POST /preview: counts and consequences for a candidate scope.
 * GET /scope/search: candidates for the "let me choose" picker.
 *
 * Both read-only by construction. Neither reaches MigrationState::start() or
 * any other state write — the five migrators /preview builds are built
 * exactly as PreflightController::counts() builds them, purely to count
 * rows, and the scope is handed to them directly via useScope() rather than
 * persisted anywhere; /scope/search never touches a migrator at all, only
 * SELECTs. That is deliberate: the owner is still choosing, and the UI is
 * expected to call these repeatedly as the selection changes.
 */
final class PreviewController
{
    private const string NAMESPACE = 'cartshift/v1';

    /** /scope/search never returns more rows than this, however large `limit` is asked for. */
    private const int MAX_SEARCH_LIMIT = 50;

    /** /scope/search's own default row count, mirrored in the route's `args` for real requests. */
    private const int DEFAULT_SEARCH_LIMIT = 20;

    /** Search types /scope/search accepts. Anything else is a 422, not a silent fallback. */
    private const array SEARCH_TYPES = [
        Constants::ENTITY_PRODUCT,
        Constants::ENTITY_CUSTOMER,
    ];

    /** The entity types /preview counts when the caller does not narrow the list. */
    private const array ALL_ENTITY_TYPES = [
        Constants::ENTITY_PRODUCT,
        Constants::ENTITY_CUSTOMER,
        Constants::ENTITY_COUPON,
        Constants::ENTITY_ORDER,
        Constants::ENTITY_SUBSCRIPTION,
    ];

    public function __construct(
        private readonly Container $container,
    ) {
    }

    public function registerRoutes(): void
    {
        register_rest_route(self::NAMESPACE, '/preview', [
            'methods'             => 'POST',
            'callback'            => [$this, 'preview'],
            'permission_callback' => [$this, 'checkPermission'],
        ]);

        register_rest_route(self::NAMESPACE, '/scope/search', [
            'methods'             => 'GET',
            'callback'            => [$this, 'search'],
            'permission_callback' => [$this, 'checkPermission'],
            'args'                => [
                'type' => [
                    'type'              => 'string',
                    'required'          => true,
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'q' => [
                    'type'              => 'string',
                    'default'           => '',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'limit' => [
                    'type'              => 'integer',
                    'default'           => self::DEFAULT_SEARCH_LIMIT,
                    'sanitize_callback' => 'absint',
                ],
            ],
        ]);
    }

    public function preview(WP_REST_Request $request): WP_REST_Response
    {
        // Never rejected, only normalised — the owner is mid-selection, and a
        // 422 in the middle of a preview screen explains nothing. An unusable
        // value falls back to "everything", the mode that cannot lose data.
        $scope = MigrationScope::fromArray($request->get_param('scope'));
        $resolver = new ScopeResolver($scope);

        $entityTypes = $this->resolveEntityTypes($request->get_param('entity_types'));

        /** @var IdMapRepository $idMap */
        $idMap = $this->container->get(IdMapRepository::class);
        /** @var MigrationLogRepository $log */
        $log = $this->container->get(MigrationLogRepository::class);
        /** @var MigrationState $state */
        $state = $this->container->get(MigrationState::class);

        $migrators = [
            new ProductMigrator($idMap, $log, $state),
            new CustomerMigrator($idMap, $log, $state),
            new CouponMigrator($idMap, $log, $state),
            new OrderMigrator($idMap, $log, $state),
            new SubscriptionMigrator($idMap, $log, $state),
        ];

        $preview = new ScopePreview($migrators, $resolver);

        return new WP_REST_Response(['data' => $preview->build($entityTypes)]);
    }

    /**
     * GET /scope/search: candidates for the "let me choose" picker.
     *
     * Read-only, same as /preview — this never touches MigrationState. An
     * empty or whitespace-only term returns [] before any query runs, on
     * purpose: a picker that dumps the whole catalogue on focus is the thing
     * a tab-per-entity browser would have been, and the design spec rejected
     * that in favour of search.
     *
     * `id` is always a string, because a guest customer's identity is an
     * email address, not a row id. The front end reads `kind` to decide which
     * of MigrationScope's three id fields (product_ids, customer_ids,
     * guest_emails) an `id` belongs in, which is why a registered customer
     * and a guest carry different `kind` values rather than both saying
     * "customer".
     */
    public function search(WP_REST_Request $request): WP_REST_Response
    {
        $type = (string) ($request->get_param('type') ?? '');

        if (!in_array($type, self::SEARCH_TYPES, true)) {
            return new WP_REST_Response(
                ['data' => ['message' => 'Unknown search type.']],
                422,
            );
        }

        $term = trim((string) ($request->get_param('q') ?? ''));

        if ($term === '') {
            return new WP_REST_Response(['data' => ['results' => [], 'truncated' => false]]);
        }

        $rawLimit = $request->get_param('limit');
        $limit    = max(1, min(self::MAX_SEARCH_LIMIT, $rawLimit === null ? self::DEFAULT_SEARCH_LIMIT : (int) $rawLimit));

        [$results, $truncated] = $type === Constants::ENTITY_PRODUCT
            ? $this->searchProducts($term, $limit)
            : $this->searchCustomers($term, $limit);

        return new WP_REST_Response(['data' => ['results' => $results, 'truncated' => $truncated]]);
    }

    public function checkPermission(): bool
    {
        return current_user_can('manage_options');
    }

    /**
     * @return list<string>
     */
    private function resolveEntityTypes(mixed $raw): array
    {
        if (!is_array($raw) || $raw === []) {
            return self::ALL_ENTITY_TYPES;
        }

        $whitelisted = MigrationController::whitelistEntityTypes($raw);

        // A request that named only invalid types still gets a useful answer
        // rather than an empty one — "counts" with no keys tells the owner
        // nothing, so fall back to the full set exactly as an absent list does.
        return $whitelisted === [] ? self::ALL_ENTITY_TYPES : $whitelisted;
    }

    /**
     * Products matching `$term` in title or SKU.
     *
     * `LEFT JOIN` on the SKU lookup, not `INNER JOIN`: a product with no SKU
     * row yet still has to match on title. The term is escaped for LIKE via
     * esc_like() and then bound through prepare() — esc_like() alone only
     * neutralises the `%`/`_` wildcards a typed search term might contain, it
     * does not escape the value for SQL, that is prepare()'s job.
     *
     * Unsupported product types (a LearnDash `course`, for instance) are
     * excluded from the result set entirely, not merely left unmarked. A
     * picked product of a type ProductMigrator does not source travels into
     * MigrationScope::productIds() unfiltered — ScopeResolver's closure does
     * not know about product types either — and only ProductMigrator's own
     * SUPPORTED_PRODUCT_TYPES join at count/fetch time drops it, silently, as
     * counts['product'] === 0 for a pick the owner made deliberately. The
     * picker showing it at all is the point the owner has no way to notice
     * that decision was made. Reuses PreflightCheck::unsupportedProductTypeCounts()
     * — see PreflightCheck::SUPPORTED_PRODUCT_TYPES for why a second copy of
     * the supported-type list must never exist.
     *
     * One extra row is asked for so truncation can be reported without a
     * second COUNT query: if the limit'th-plus-one row comes back, more
     * matched than are being returned.
     *
     * @return array{0: list<array{id: string, kind: string, label: string, sublabel: string}>, 1: bool}
     */
    private function searchProducts(string $term, int $limit): array
    {
        global $wpdb;

        $like = '%' . self::escLike($term) . '%';

        $unsupportedTypes = array_keys(PreflightCheck::unsupportedProductTypeCounts());

        $exclusion = '';
        $exclusionValues = [];

        if ($unsupportedTypes !== []) {
            $placeholders = implode(', ', array_fill(0, count($unsupportedTypes), '%s'));

            $exclusion = " AND p.ID NOT IN (
                   SELECT tr.object_id FROM {$wpdb->term_relationships} tr
                   INNER JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
                   INNER JOIN {$wpdb->terms} t ON tt.term_id = t.term_id
                   WHERE tt.taxonomy = 'product_type'
                     AND t.slug IN ({$placeholders})
               )";
            $exclusionValues = $unsupportedTypes;
        }

        $rows = (array) $wpdb->get_results($wpdb->prepare(
            "SELECT p.ID AS id, p.post_title AS title, pml.sku AS sku
             FROM {$wpdb->posts} p
             LEFT JOIN {$wpdb->prefix}wc_product_meta_lookup pml ON pml.product_id = p.ID
             WHERE p.post_type = 'product'
               AND p.post_status IN ('publish', 'draft', 'private')
               AND (p.post_title LIKE %s OR pml.sku LIKE %s)"
            . $exclusion
            . " ORDER BY p.post_title ASC
             LIMIT %d",
            ...[$like, $like, ...$exclusionValues, $limit + 1],
        ), ARRAY_A);

        $truncated = count($rows) > $limit;
        $rows      = array_slice($rows, 0, $limit);

        $results = array_map(static function (array $row): array {
            $sku = trim((string) ($row['sku'] ?? ''));

            return [
                'id'       => (string) $row['id'],
                'kind'     => Constants::ENTITY_PRODUCT,
                'label'    => (string) $row['title'],
                'sublabel' => $sku === '' ? '' : 'SKU ' . $sku,
            ];
        }, $rows);

        return [$results, $truncated];
    }

    /**
     * Customers matching `$term`, registered first then guests.
     *
     * Two separate row sources sharing one `wc_orders` status scope — the
     * same `WooStorage::orderScopeParts()` fragment every other count in this
     * plugin uses, so a customer whose only order is an abandoned checkout
     * draft does not show up here either.
     *
     * Registered customers are matched by joining `wc_orders.customer_id` to
     * `wp_users` on display name and email; guests have no user row, so they
     * are matched on `billing_email` alone and reported with an order count
     * instead of an email sublabel, since the email already is the label.
     *
     * `$limit` is split, not doubled: if registered search alone already
     * fills it, the guest query is still run — one extra row, LIMIT 1 — to
     * learn whether truncation happened, without paying for a full guest
     * result set nothing will show.
     *
     * @return array{0: list<array{id: string, kind: string, label: string, sublabel: string}>, 1: bool}
     */
    private function searchCustomers(string $term, int $limit): array
    {
        global $wpdb;

        $like  = '%' . self::escLike($term) . '%';
        $table = WooStorage::ordersTable();

        [$scope, $scopeValues] = WooStorage::orderScopeParts();

        $registeredRows = (array) $wpdb->get_results($wpdb->prepare(
            "SELECT DISTINCT o.customer_id AS id, u.display_name AS display_name, u.user_email AS email
             FROM {$table} o
             INNER JOIN {$wpdb->prefix}users u ON u.ID = o.customer_id
             WHERE o.customer_id > 0
               AND {$scope}
               AND (u.display_name LIKE %s OR u.user_email LIKE %s)
             ORDER BY u.display_name ASC
             LIMIT %d",
            ...[...$scopeValues, $like, $like, $limit + 1],
        ), ARRAY_A);

        $registeredTruncated = count($registeredRows) > $limit;
        $registeredRows      = array_slice($registeredRows, 0, $limit);

        $results = array_map(static fn (array $row): array => [
            'id'       => (string) $row['id'],
            'kind'     => 'registered',
            'label'    => (string) $row['display_name'],
            'sublabel' => (string) $row['email'],
        ], $registeredRows);

        // Never negative: a full registered page still runs this query, just
        // asking for one row, purely to learn whether a guest match exists.
        $remaining = max(0, $limit - count($results));

        $guestRows = (array) $wpdb->get_results($wpdb->prepare(
            "SELECT billing_email AS email, COUNT(*) AS order_count
             FROM {$table}
             WHERE (customer_id IS NULL OR customer_id = 0)
               AND billing_email != ''
               AND {$scope}
               AND billing_email LIKE %s
             GROUP BY billing_email
             ORDER BY billing_email ASC
             LIMIT %d",
            ...[...$scopeValues, $like, $remaining + 1],
        ), ARRAY_A);

        $guestTruncated = count($guestRows) > $remaining;
        $guestRows      = array_slice($guestRows, 0, $remaining);

        foreach ($guestRows as $row) {
            $orderCount = (int) $row['order_count'];

            $results[] = [
                'id'       => (string) $row['email'],
                'kind'     => 'guest',
                'label'    => (string) $row['email'],
                'sublabel' => sprintf('guest, %d order%s', $orderCount, $orderCount === 1 ? '' : 's'),
            ];
        }

        return [$results, $registeredTruncated || $guestTruncated];
    }

    /**
     * Escape a literal for use inside a LIKE pattern.
     *
     * `esc_like()` alone is only the LIKE half of escaping a user-typed search
     * term: it neutralises `%`/`_` so they read as literal characters instead
     * of wildcards, but the result still has to go through `$wpdb->prepare()`
     * to be safe as SQL. Guarded because $wpdb is swappable and not every
     * replacement carries esc_like() — mirrors Migrations::escLike().
     */
    private static function escLike(string $literal): string
    {
        global $wpdb;

        return method_exists($wpdb, 'esc_like')
            ? $wpdb->esc_like($literal)
            : addcslashes($literal, '_%\\');
    }
}

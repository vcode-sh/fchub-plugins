<?php

declare(strict_types=1);

namespace CartShift\Http\Controllers;

defined('ABSPATH') || exit;

use CartShift\Core\Container;
use CartShift\Migrator\CouponMigrator;
use CartShift\Migrator\CustomerMigrator;
use CartShift\Migrator\OrderMigrator;
use CartShift\Migrator\ProductMigrator;
use CartShift\Migrator\SubscriptionMigrator;
use CartShift\State\MigrationState;
use CartShift\Storage\IdMapRepository;
use CartShift\Storage\MigrationLogRepository;
use CartShift\Support\Constants;
use CartShift\Validator\PreflightCheck;
use WP_REST_Request;
use WP_REST_Response;

final class PreflightController
{
    private const string NAMESPACE = 'cartshift/v1';

    /**
     * The preflight arrives through a seam for the same reason `PreflightCheck`
     * takes its symbol table through one: `function_exists()` cannot be told a
     * function is absent, so the missing-API branch of `counts()` is unreachable
     * from a shared-process suite that also exercises the present-API path.
     * Controllers are constructed as `new $class($container)`, so the default is
     * what production gets.
     */
    public function __construct(
        private readonly Container $container,
        private readonly PreflightCheck $preflight = new PreflightCheck(),
    ) {
    }

    public function registerRoutes(): void
    {
        register_rest_route(self::NAMESPACE, '/preflight', [
            'methods'             => 'GET',
            'callback'            => [$this, 'preflight'],
            'permission_callback' => [$this, 'checkPermission'],
        ]);

        register_rest_route(self::NAMESPACE, '/counts', [
            'methods'             => 'GET',
            'callback'            => [$this, 'counts'],
            'permission_callback' => [$this, 'checkPermission'],
        ]);
    }

    /**
     * Preflight for one operation.
     *
     * The operation matters for exactly one check: generic migration reads the
     * HPOS table directly and stays gated on it, while the subscription dataset
     * path reads through WooCommerce's public APIs and takes whichever backend
     * WooCommerce considers authoritative. An unknown value is refused rather
     * than defaulted to the permissive one — a gate that can be talked into
     * standing aside by a query string is not a gate — and an absent one keeps
     * the behaviour every existing caller already relies on.
     */
    public function preflight(WP_REST_Request $request): WP_REST_Response
    {
        $operation = (string) ($request->get_param('operation') ?? PreflightCheck::OPERATION_MIGRATION);

        if (!in_array($operation, PreflightCheck::OPERATIONS, true)) {
            return new WP_REST_Response(
                [
                    'code'    => 'cartshift_unknown_operation',
                    'message' => sprintf(
                        'Unknown preflight operation "%s". Use one of: %s.',
                        $operation,
                        implode(', ', PreflightCheck::OPERATIONS),
                    ),
                ],
                400,
            );
        }

        $check = new PreflightCheck();
        $result = $check->run($operation);

        return new WP_REST_Response(['data' => $result + ['operation' => $operation]]);
    }

    /**
     * How many of each entity this source holds.
     *
     * A SUBSCRIPTION COUNT IS ONLY ANSWERABLE WHERE THE API IS. Without
     * `wcs_get_subscriptions()`, `WooSubscriptionDatasetSource::selectionIndex()`
     * hands back an empty index, the count comes out 0, and nothing raises its
     * hand — a shop with 564 subscribers and a shop with none read identically,
     * which is the exact silent-success failure the HPOS gate and the audit's
     * 409 both exist to stop, arriving through a third door.
     *
     * So the migrator is not built at all when its API is absent, the count is
     * `null` rather than a number, and `unavailable` says which lookups are
     * missing. Null is the difference between "none" and "cannot say". Every
     * other entity is counted exactly as before, because products, customers,
     * orders and coupons have nothing to do with an optional add-on.
     */
    public function counts(WP_REST_Request $request): WP_REST_Response
    {
        $counts = [];

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
        ];

        $missingApis = $this->preflight->missingSubscriptionDatasetApis();
        $unavailable = [];

        if ($missingApis === []) {
            $migrators[] = new SubscriptionMigrator($idMap, $log, $state);
        } else {
            $counts['subscription'] = null;
            $unavailable['subscription'] = [
                'reason'       => 'wc_subscriptions_inactive',
                'missing_apis' => $missingApis,
                'message'      => 'WooCommerce Subscriptions is not active here, so subscriptions cannot be '
                    . 'counted. This is not a count of zero. Everything else on this list is unaffected and '
                    . 'migrates normally.',
            ];
        }

        foreach ($migrators as $migrator) {
            $counts[$migrator->entityType()] = $migrator->count();
        }

        // How many FluentCart products already exist — the single value that
        // lets the Vue wizard skip the mapping step entirely on a virgin
        // FluentCart install. Nested inside $counts, not alongside it: the
        // wizard's useMigration.js does
        // `state.counts = countsData.counts || countsData`, so anything not
        // inside this array is invisible to it. The post type comes from
        // Constants rather than a literal — verified there against the
        // installed plugin's app/CPT/FluentProducts.php::CPT_NAME — so this
        // query and MappingController's candidate query cannot come to
        // disagree about what a FluentCart product is.
        global $wpdb;

        $counts['fc_product_count'] = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->posts}
             WHERE post_type = '" . Constants::FC_PRODUCT_POST_TYPE . "'
               AND post_status IN ('publish', 'draft', 'private')",
        );

        return new WP_REST_Response(['data' => [
            'counts'      => $counts,
            'unavailable' => $unavailable,
        ]]);
    }

    public function checkPermission(): bool
    {
        return current_user_can('manage_options');
    }
}

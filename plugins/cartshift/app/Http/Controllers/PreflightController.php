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

    public function __construct(
        private readonly Container $container,
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
            new SubscriptionMigrator($idMap, $log, $state),
        ];

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

        return new WP_REST_Response(['data' => ['counts' => $counts]]);
    }

    public function checkPermission(): bool
    {
        return current_user_can('manage_options');
    }
}

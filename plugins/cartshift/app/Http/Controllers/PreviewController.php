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
use WP_REST_Request;
use WP_REST_Response;

/**
 * POST /preview: counts and consequences for a candidate scope.
 *
 * Read-only by construction. It never reaches MigrationState::start() or any
 * other state write — the five migrators below are built exactly as
 * PreflightController::counts() builds them, purely to count rows, and the
 * scope is handed to them directly via useScope() rather than persisted
 * anywhere. That is deliberate: the owner is still choosing, and the UI is
 * expected to call this repeatedly as the selection changes.
 */
final class PreviewController
{
    private const string NAMESPACE = 'cartshift/v1';

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
}

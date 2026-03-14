<?php

declare(strict_types=1);

namespace CartShift\Http\Controllers;

defined('ABSPATH') || exit;

use CartShift\Core\Container;
use CartShift\Domain\Migration\MigrationOrchestrator;
use CartShift\Migrator\CouponMigrator;
use CartShift\Migrator\CustomerMigrator;
use CartShift\Migrator\OrderMigrator;
use CartShift\Migrator\ProductMigrator;
use CartShift\Migrator\SubscriptionMigrator;
use CartShift\State\MigrationState;
use CartShift\Storage\IdMapRepository;
use CartShift\Storage\MigrationLogRepository;
use WP_REST_Request;
use WP_REST_Response;

final class MigrationController
{
    private const string NAMESPACE = 'cartshift/v1';

    public function __construct(
        private readonly Container $container,
    ) {
    }

    public function registerRoutes(): void
    {
        register_rest_route(self::NAMESPACE, '/migrate', [
            'methods'             => 'POST',
            'callback'            => [$this, 'migrate'],
            'permission_callback' => [$this, 'checkPermission'],
        ]);

        register_rest_route(self::NAMESPACE, '/migrate/batch', [
            'methods'             => 'POST',
            'callback'            => [$this, 'batch'],
            'permission_callback' => [$this, 'checkPermission'],
        ]);

        register_rest_route(self::NAMESPACE, '/progress', [
            'methods'             => 'GET',
            'callback'            => [$this, 'progress'],
            'permission_callback' => [$this, 'checkPermission'],
        ]);

        register_rest_route(self::NAMESPACE, '/cancel', [
            'methods'             => 'POST',
            'callback'            => [$this, 'cancel'],
            'permission_callback' => [$this, 'checkPermission'],
        ]);
    }

    /**
     * POST /migrate — initialise migration and process first batch.
     */
    public function migrate(WP_REST_Request $request): WP_REST_Response
    {
        $entityTypes = $request->get_param('entity_types') ?? [];
        $dryRun = (bool) $request->get_param('dry_run');

        if (empty($entityTypes) || !is_array($entityTypes)) {
            return new WP_REST_Response(
                ['data' => ['message' => 'entity_types is required and must be a non-empty array.']],
                422,
            );
        }

        $orchestrator = $this->buildOrchestrator();

        $result = $orchestrator->startMigration($entityTypes, $dryRun);

        return new WP_REST_Response(['data' => $result]);
    }

    /**
     * POST /migrate/batch — process next batch.
     */
    public function batch(WP_REST_Request $request): WP_REST_Response
    {
        /** @var MigrationState $state */
        $state = $this->container->get(MigrationState::class);

        if (!$state->isRunning()) {
            return new WP_REST_Response(
                ['data' => ['continue' => false, 'message' => 'No migration is currently running.']],
                422,
            );
        }

        $orchestrator = $this->buildOrchestrator();

        $result = $orchestrator->processBatch();

        return new WP_REST_Response(['data' => $result]);
    }

    public function progress(WP_REST_Request $request): WP_REST_Response
    {
        /** @var MigrationState $state */
        $state = $this->container->get(MigrationState::class);

        return new WP_REST_Response(['data' => $state->getProgress()]);
    }

    public function cancel(WP_REST_Request $request): WP_REST_Response
    {
        /** @var MigrationState $state */
        $state = $this->container->get(MigrationState::class);

        $state->cancel();

        return new WP_REST_Response([
            'data' => ['message' => 'Migration cancellation requested.'],
        ]);
    }

    public function checkPermission(): bool
    {
        return current_user_can('manage_options');
    }

    /**
     * Build the MigrationOrchestrator with all registered migrators.
     */
    private function buildOrchestrator(): MigrationOrchestrator
    {
        /** @var MigrationState $state */
        $state = $this->container->get(MigrationState::class);
        /** @var IdMapRepository $idMap */
        $idMap = $this->container->get(IdMapRepository::class);
        /** @var MigrationLogRepository $log */
        $log = $this->container->get(MigrationLogRepository::class);

        $migrationId = $state->getMigrationId() ?? wp_generate_uuid4();

        $migrators = [
            new ProductMigrator($idMap, $log, $state, $migrationId),
            new CustomerMigrator($idMap, $log, $state, $migrationId),
            new CouponMigrator($idMap, $log, $state, $migrationId),
            new OrderMigrator($idMap, $log, $state, $migrationId),
            new SubscriptionMigrator($idMap, $log, $state, $migrationId),
        ];

        return new MigrationOrchestrator($migrators, $state, $idMap, $log);
    }
}

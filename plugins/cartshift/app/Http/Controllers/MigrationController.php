<?php

declare(strict_types=1);

namespace CartShift\Http\Controllers;

defined('ABSPATH') || exit;

use CartShift\Core\Container;
use CartShift\Domain\Migration\MigrationOrchestrator;
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

        /** @var MigrationOrchestrator $orchestrator */
        $orchestrator = $this->container->get(MigrationOrchestrator::class);

        $migrationId = $orchestrator->run($entityTypes, $dryRun);

        return new WP_REST_Response([
            'data' => [
                'migration_id' => $migrationId,
                'message'      => 'Migration started.',
            ],
        ]);
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
}

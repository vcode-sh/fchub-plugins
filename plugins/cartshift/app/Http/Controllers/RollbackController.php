<?php

declare(strict_types=1);

namespace CartShift\Http\Controllers;

defined('ABSPATH') || exit;

use CartShift\Core\Container;
use CartShift\Domain\Migration\MigrationRollback;
use CartShift\Storage\IdMapRepository;
use CartShift\Storage\MigrationLogRepository;
use WP_REST_Request;
use WP_REST_Response;

final class RollbackController
{
    private const string NAMESPACE = 'cartshift/v1';

    public function __construct(
        private readonly Container $container,
    ) {
    }

    public function registerRoutes(): void
    {
        register_rest_route(self::NAMESPACE, '/rollback', [
            'methods'             => 'POST',
            'callback'            => [$this, 'rollback'],
            'permission_callback' => [$this, 'checkPermission'],
        ]);
    }

    public function rollback(WP_REST_Request $request): WP_REST_Response
    {
        $migrationId = $request->get_param('migration_id');

        if (empty($migrationId) || !is_string($migrationId)) {
            return new WP_REST_Response(
                ['data' => ['message' => 'migration_id is required.']],
                422,
            );
        }

        /** @var IdMapRepository $idMap */
        $idMap = $this->container->get(IdMapRepository::class);
        /** @var MigrationLogRepository $log */
        $log = $this->container->get(MigrationLogRepository::class);

        $rollback = new MigrationRollback($idMap, $log);
        $stats = $rollback->rollback($migrationId);

        return new WP_REST_Response([
            'data' => [
                'migration_id' => $migrationId,
                'stats'        => $stats,
                'message'      => 'Rollback completed.',
            ],
        ]);
    }

    public function checkPermission(): bool
    {
        return current_user_can('manage_options');
    }
}

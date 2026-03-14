<?php

declare(strict_types=1);

namespace CartShift\Http\Controllers;

defined('ABSPATH') || exit;

use CartShift\Core\Container;
use CartShift\Domain\Migration\MigrationFinalizer;
use WP_REST_Request;
use WP_REST_Response;

final class FinalizeController
{
    private const string NAMESPACE = 'cartshift/v1';

    public function __construct(
        private readonly Container $container,
    ) {
    }

    public function registerRoutes(): void
    {
        register_rest_route(self::NAMESPACE, '/finalize', [
            'methods'             => 'POST',
            'callback'            => [$this, 'finalize'],
            'permission_callback' => [$this, 'checkPermission'],
        ]);
    }

    public function finalize(WP_REST_Request $request): WP_REST_Response
    {
        $migrationId = $request->get_param('migration_id');

        if (empty($migrationId) || !is_string($migrationId)) {
            return new WP_REST_Response(
                ['data' => ['message' => 'migration_id is required.']],
                422,
            );
        }

        /** @var MigrationFinalizer $finalizer */
        $finalizer = $this->container->get(MigrationFinalizer::class);

        $stats = $finalizer->finalize($migrationId);

        return new WP_REST_Response([
            'data' => [
                'migration_id' => $migrationId,
                'stats'        => $stats,
                'message'      => 'Finalization completed.',
            ],
        ]);
    }

    public function checkPermission(): bool
    {
        return current_user_can('manage_options');
    }
}

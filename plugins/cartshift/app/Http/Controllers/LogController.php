<?php

declare(strict_types=1);

namespace CartShift\Http\Controllers;

defined('ABSPATH') || exit;

use CartShift\Core\Container;
use CartShift\Storage\MigrationLogRepository;
use WP_REST_Request;
use WP_REST_Response;

final class LogController
{
    private const string NAMESPACE = 'cartshift/v1';

    public function __construct(
        private readonly Container $container,
    ) {
    }

    public function registerRoutes(): void
    {
        register_rest_route(self::NAMESPACE, '/log', [
            'methods'             => 'GET',
            'callback'            => [$this, 'index'],
            'permission_callback' => [$this, 'checkPermission'],
            'args'                => [
                'page' => [
                    'type'              => 'integer',
                    'default'           => 1,
                    'minimum'           => 1,
                    'sanitize_callback' => 'absint',
                ],
                'per_page' => [
                    'type'              => 'integer',
                    'default'           => 50,
                    'minimum'           => 1,
                    'maximum'           => 100,
                    'sanitize_callback' => 'absint',
                ],
                'status' => [
                    'type'              => 'string',
                    'default'           => null,
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'migration_id' => [
                    'type'              => 'string',
                    'default'           => null,
                    'sanitize_callback' => 'sanitize_text_field',
                ],
            ],
        ]);
    }

    public function index(WP_REST_Request $request): WP_REST_Response
    {
        /** @var MigrationLogRepository $log */
        $log = $this->container->get(MigrationLogRepository::class);

        $result = $log->getPaginated(
            migrationId: $request->get_param('migration_id'),
            page: (int) ($request->get_param('page') ?? 1),
            perPage: (int) ($request->get_param('per_page') ?? 50),
            status: $request->get_param('status'),
        );

        return new WP_REST_Response(['data' => $result]);
    }

    public function checkPermission(): bool
    {
        return current_user_can('manage_options');
    }
}

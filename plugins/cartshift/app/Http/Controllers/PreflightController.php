<?php

declare(strict_types=1);

namespace CartShift\Http\Controllers;

defined('ABSPATH') || exit;

use CartShift\Core\Container;
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

    public function preflight(WP_REST_Request $request): WP_REST_Response
    {
        $check = new PreflightCheck();
        $result = $check->run();

        return new WP_REST_Response(['data' => $result]);
    }

    public function counts(WP_REST_Request $request): WP_REST_Response
    {
        $counts = [];

        /** @var \CartShift\Domain\Migration\Contracts\MigratorInterface[] $migrators */
        $migrators = $this->container->get('migrators');

        foreach ($migrators as $migrator) {
            $counts[$migrator->entityType()] = $migrator->count();
        }

        return new WP_REST_Response(['data' => ['counts' => $counts]]);
    }

    public function checkPermission(): bool
    {
        return current_user_can('manage_options');
    }
}

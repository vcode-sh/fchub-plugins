<?php

namespace FChubHub\Http;

use FChubHub\Operations\ProductOperationService;
use WP_REST_Request;
use WP_REST_Server;

defined('ABSPATH') || exit;

/**
 * The whole REST surface: one read, five product operations, one refresh.
 *
 * Each mutation is guarded by exactly the capability its operation needs, read
 * from the same map the service enforces — so a route cannot become a quieter
 * way of asking for something the operation itself would refuse. Reading and
 * refreshing need `manage_options` and nothing more; neither changes a file.
 *
 * Controllers are built inside the callbacks rather than at registration, so a
 * REST request bound for somebody else's route pays nothing for FCHub existing.
 */
final class Routes
{
    public const REST_NAMESPACE = 'fchub/v1';

    private const READ_CAPABILITY = 'manage_options';

    public static function register(): void
    {
        register_rest_route(self::REST_NAMESPACE, '/products', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => static fn (WP_REST_Request $request) => ProductController::forSite()->products($request),
            'permission_callback' => static fn (): bool => current_user_can(self::READ_CAPABILITY),
        ]);

        foreach (array_keys(ProductController::ACTIONS) as $action) {
            register_rest_route(
                self::REST_NAMESPACE,
                '/products/(?P<slug>' . ProductController::SLUG_PATTERN . ')/' . $action,
                [
                    'methods' => WP_REST_Server::CREATABLE,
                    'args' => self::slugArgument(),
                    'callback' => static fn (WP_REST_Request $request) => ProductController::forSite()
                        ->operate($action, $request),
                    'permission_callback' => static fn (): bool => ProductOperationService::userCan($action),
                ]
            );
        }

        register_rest_route(self::REST_NAMESPACE, '/catalogue/refresh', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => static fn (WP_REST_Request $request) => ProductController::forSite()->refresh($request),
            'permission_callback' => static fn (): bool => current_user_can(self::READ_CAPABILITY),
        ]);
    }

    /**
     * No sanitize_callback on purpose: core validates before it sanitises, and
     * the route pattern and this validator between them already reject
     * anything a trim or a lowercase would have altered. A sanitiser that can
     * never change a value is protection theatre.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function slugArgument(): array
    {
        return [
            'slug' => [
                'required' => true,
                'type' => 'string',
                'validate_callback' => static fn ($value): bool => is_string($value)
                    && preg_match('/^' . ProductController::SLUG_PATTERN . '$/D', $value) === 1,
            ],
        ];
    }
}

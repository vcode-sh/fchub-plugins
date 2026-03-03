<?php

declare(strict_types=1);

namespace FChubWishlist\Http\Routes;

use FChubWishlist\Http\Controllers\Pub\ItemsController;
use FChubWishlist\Http\Controllers\Pub\StatusController;
use FChubWishlist\Http\Controllers\Pub\CartController;
use FChubWishlist\Support\Constants;

defined('ABSPATH') || exit;

final class PublicRoutes
{
    public static function register(): void
    {
        $ns = Constants::REST_NAMESPACE;

        register_rest_route($ns, '/items', [
            [
                'methods'             => 'GET',
                'callback'            => [StatusController::class, 'items'],
                'permission_callback' => '__return_true',
                'args'                => [
                    'page' => [
                        'required'          => false,
                        'type'              => 'integer',
                        'default'           => 1,
                        'sanitize_callback' => 'absint',
                        'validate_callback' => function ($value): bool {
                            return is_numeric($value) && (int) $value >= 1;
                        },
                    ],
                    'per_page' => [
                        'required'          => false,
                        'type'              => 'integer',
                        'default'           => 20,
                        'sanitize_callback' => 'absint',
                        'validate_callback' => function ($value): bool {
                            return is_numeric($value) && (int) $value >= 1 && (int) $value <= 100;
                        },
                    ],
                ],
            ],
            [
                'methods'             => 'POST',
                'callback'            => [ItemsController::class, 'add'],
                'permission_callback' => '__return_true',
                'args'                => self::itemArgs(),
            ],
            [
                'methods'             => 'DELETE',
                'callback'            => [ItemsController::class, 'remove'],
                'permission_callback' => '__return_true',
                'args'                => self::itemArgs(),
            ],
        ]);

        register_rest_route($ns, '/items/toggle', [
            'methods'             => 'POST',
            'callback'            => [ItemsController::class, 'toggle'],
            'permission_callback' => '__return_true',
            'args'                => self::itemArgs(),
        ]);

        register_rest_route($ns, '/status', [
            'methods'             => 'GET',
            'callback'            => [StatusController::class, 'status'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route($ns, '/add-all-to-cart', [
            'methods'             => 'POST',
            'callback'            => [CartController::class, 'addAll'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route($ns, '/items/all', [
            'methods'             => 'DELETE',
            'callback'            => [ItemsController::class, 'clearAll'],
            'permission_callback' => '__return_true',
        ]);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function itemArgs(): array
    {
        return [
            'product_id' => [
                'required'          => true,
                'type'              => 'integer',
                'sanitize_callback' => 'absint',
                'validate_callback' => function ($value): bool {
                    return is_numeric($value) && (int) $value > 0;
                },
            ],
            'variant_id' => [
                'required'          => false,
                'type'              => 'integer',
                'default'           => 0,
                'sanitize_callback' => 'absint',
                'validate_callback' => function ($value): bool {
                    return is_numeric($value) && (int) $value >= 0;
                },
            ],
        ];
    }
}

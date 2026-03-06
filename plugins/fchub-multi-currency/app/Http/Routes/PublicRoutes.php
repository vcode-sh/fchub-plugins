<?php

declare(strict_types=1);

namespace FChubMultiCurrency\Http\Routes;

use FChubMultiCurrency\Http\Controllers\Pub\ContextController;
use FChubMultiCurrency\Http\Controllers\Pub\RatesController;
use FChubMultiCurrency\Support\Constants;

defined('ABSPATH') || exit;

final class PublicRoutes
{
    public static function register(): void
    {
        register_rest_route(Constants::REST_NAMESPACE, '/context', [
            [
                'methods'             => 'GET',
                'callback'            => [new ContextController(), 'get'],
                'permission_callback' => '__return_true',
            ],
            [
                'methods'             => 'POST',
                'callback'            => [new ContextController(), 'set'],
                'permission_callback' => '__return_true',
                'args'                => [
                    'currency' => [
                        'required'          => true,
                        'type'              => 'string',
                        'sanitize_callback' => 'sanitize_text_field',
                        'validate_callback' => static function ($value): bool {
                            return is_string($value) && preg_match('/^[A-Za-z]{3}$/', $value) === 1;
                        },
                    ],
                ],
            ],
        ]);

        register_rest_route(Constants::REST_NAMESPACE, '/rates', [
            'methods'             => 'GET',
            'callback'            => [new RatesController(), 'index'],
            'permission_callback' => '__return_true',
        ]);
    }
}

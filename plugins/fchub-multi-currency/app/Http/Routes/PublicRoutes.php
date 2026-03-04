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
            ],
        ]);

        register_rest_route(Constants::REST_NAMESPACE, '/rates', [
            'methods'             => 'GET',
            'callback'            => [new RatesController(), 'index'],
            'permission_callback' => '__return_true',
        ]);
    }
}

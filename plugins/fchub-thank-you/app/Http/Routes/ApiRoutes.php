<?php

declare(strict_types=1);

namespace FchubThankYou\Http\Routes;

use FchubThankYou\Http\Controllers\ProductSettingsController;
use FchubThankYou\Http\Controllers\SearchController;
use FchubThankYou\Support\Constants;
use FchubThankYou\Support\ProductMetaStore;

final class ApiRoutes
{
    public function register(): void
    {
        $controller = new ProductSettingsController(new ProductMetaStore());

        register_rest_route(Constants::REST_NAMESPACE, '/product/(?P<id>\d+)', [
            [
                'methods'             => \WP_REST_Server::READABLE,
                'callback'            => [$controller, 'show'],
                'permission_callback' => static fn (): bool => current_user_can('edit_posts'),
                'args'                => [
                    'id' => ['required' => true, 'type' => 'integer', 'sanitize_callback' => 'absint'],
                ],
            ],
            [
                'methods'             => \WP_REST_Server::CREATABLE,
                'callback'            => [$controller, 'save'],
                'permission_callback' => static fn (): bool => current_user_can('edit_posts'),
                'args'                => [
                    'id'        => ['required' => true, 'type' => 'integer', 'sanitize_callback' => 'absint'],
                    'enabled'   => ['required' => true, 'type' => 'boolean'],
                    'type'      => ['required' => false, 'type' => 'string', 'default' => 'url'],
                    'target_id' => ['required' => false, 'type' => 'integer', 'sanitize_callback' => 'absint'],
                    'url'       => ['required' => false, 'type' => 'string', 'default' => '', 'sanitize_callback' => 'esc_url_raw'],
                    'post_type' => ['required' => false, 'type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_key'],
                ],
            ],
        ]);

        $search = new SearchController();

        register_rest_route(Constants::REST_NAMESPACE, '/search', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [$search, 'search'],
            'permission_callback' => static fn (): bool => current_user_can('edit_posts'),
            'args'                => [
                'post_type' => ['required' => false, 'type' => 'string', 'default' => 'page', 'sanitize_callback' => 'sanitize_key'],
                's'         => ['required' => false, 'type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_text_field'],
            ],
        ]);

        register_rest_route(Constants::REST_NAMESPACE, '/post-types', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [$search, 'postTypes'],
            'permission_callback' => static fn (): bool => current_user_can('edit_posts'),
        ]);
    }
}

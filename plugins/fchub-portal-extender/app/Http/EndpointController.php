<?php

namespace FChubPortalExtender\Http;

defined('ABSPATH') || exit;

use FChubPortalExtender\Storage\EndpointRepository;

class EndpointController
{
    private const NAMESPACE = 'fchub-portal-extender/v1';

    public static function registerRoutes(): void
    {
        register_rest_route(self::NAMESPACE, '/endpoints', [
            [
                'methods'             => 'GET',
                'callback'            => [self::class, 'index'],
                'permission_callback' => [self::class, 'checkPermission'],
            ],
            [
                'methods'             => 'POST',
                'callback'            => [self::class, 'store'],
                'permission_callback' => [self::class, 'checkPermission'],
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/endpoints/reorder', [
            'methods'             => 'POST',
            'callback'            => [self::class, 'reorder'],
            'permission_callback' => [self::class, 'checkPermission'],
        ]);

        register_rest_route(self::NAMESPACE, '/endpoints/(?P<id>[a-f0-9-]+)', [
            [
                'methods'             => 'PUT',
                'callback'            => [self::class, 'update'],
                'permission_callback' => [self::class, 'checkPermission'],
            ],
            [
                'methods'             => 'DELETE',
                'callback'            => [self::class, 'destroy'],
                'permission_callback' => [self::class, 'checkPermission'],
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/pages', [
            'methods'             => 'GET',
            'callback'            => [self::class, 'searchPages'],
            'permission_callback' => [self::class, 'checkPermission'],
        ]);
    }

    public static function checkPermission(): bool
    {
        return current_user_can('manage_options');
    }

    public static function index(): \WP_REST_Response
    {
        $repo = new EndpointRepository();

        return new \WP_REST_Response([
            'endpoints' => $repo->getAll(),
        ]);
    }

    public static function store(\WP_REST_Request $request): \WP_REST_Response
    {
        $repo = new EndpointRepository();

        try {
            $endpoint = $repo->create($request->get_json_params());
        } catch (\InvalidArgumentException $e) {
            return new \WP_REST_Response([
                'message' => $e->getMessage(),
            ], 422);
        }

        return new \WP_REST_Response([
            'endpoint' => $endpoint,
            'message'  => __('Endpoint created.', 'fchub-portal-extender'),
        ], 201);
    }

    public static function update(\WP_REST_Request $request): \WP_REST_Response
    {
        $repo = new EndpointRepository();
        $id = $request->get_param('id');

        try {
            $endpoint = $repo->update($id, $request->get_json_params());
        } catch (\InvalidArgumentException $e) {
            return new \WP_REST_Response([
                'message' => $e->getMessage(),
            ], 422);
        }

        if ($endpoint === null) {
            return new \WP_REST_Response([
                'message' => __('Endpoint not found.', 'fchub-portal-extender'),
            ], 404);
        }

        return new \WP_REST_Response([
            'endpoint' => $endpoint,
            'message'  => __('Endpoint updated.', 'fchub-portal-extender'),
        ]);
    }

    public static function destroy(\WP_REST_Request $request): \WP_REST_Response
    {
        $repo = new EndpointRepository();
        $deleted = $repo->delete($request->get_param('id'));

        if (!$deleted) {
            return new \WP_REST_Response([
                'message' => __('Endpoint not found.', 'fchub-portal-extender'),
            ], 404);
        }

        return new \WP_REST_Response([
            'message' => __('Endpoint deleted.', 'fchub-portal-extender'),
        ]);
    }

    public static function reorder(\WP_REST_Request $request): \WP_REST_Response
    {
        $ids = $request->get_param('ids');

        if (!is_array($ids)) {
            return new \WP_REST_Response([
                'message' => __('Invalid request. Expected an array of endpoint IDs.', 'fchub-portal-extender'),
            ], 422);
        }

        $repo = new EndpointRepository();
        $endpoints = $repo->reorder($ids);

        return new \WP_REST_Response([
            'endpoints' => $endpoints,
            'message'   => __('Endpoints reordered.', 'fchub-portal-extender'),
        ]);
    }

    public static function searchPages(\WP_REST_Request $request): \WP_REST_Response
    {
        $search = sanitize_text_field($request->get_param('search') ?? '');

        $args = [
            'post_type'      => 'page',
            'post_status'    => 'publish',
            'posts_per_page' => 20,
            'orderby'        => 'title',
            'order'          => 'ASC',
        ];

        if (!empty($search)) {
            $args['s'] = $search;
        }

        $query = new \WP_Query($args);
        $pages = [];

        foreach ($query->posts as $post) {
            $pages[] = [
                'id'    => $post->ID,
                'title' => $post->post_title ?: __('(no title)', 'fchub-portal-extender'),
            ];
        }

        return new \WP_REST_Response([
            'pages' => $pages,
        ]);
    }
}

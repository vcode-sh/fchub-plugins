<?php

namespace FChubMemberships\Http\Controllers\Plans;

use FChubMemberships\Domain\Plan\PlanProductLinkService;

defined('ABSPATH') || exit;

final class PlanProductController
{
    public static function linkedProducts(\WP_REST_Request $request): \WP_REST_Response
    {
        $result = (new PlanProductLinkService())->linkedProducts((int) $request->get_param('id'));

        if (isset($result['error'])) {
            return new \WP_REST_Response(['message' => $result['error']], 404);
        }

        return new \WP_REST_Response($result);
    }

    public static function linkProduct(\WP_REST_Request $request): \WP_REST_Response
    {
        $data = $request->get_json_params();
        $result = (new PlanProductLinkService())->linkProduct(
            (int) $request->get_param('id'),
            (int) ($data['product_id'] ?? 0)
        );

        if (isset($result['error'])) {
            return new \WP_REST_Response(['message' => $result['error']], $result['status'] ?? 422);
        }

        return new \WP_REST_Response([
            'data'    => $result['data'] ?? [],
            'message' => $result['message'] ?? __('Product linked successfully.', 'fchub-memberships'),
        ], $result['status'] ?? 201);
    }

    public static function unlinkProduct(\WP_REST_Request $request): \WP_REST_Response
    {
        $result = (new PlanProductLinkService())->unlinkProduct(
            (int) $request->get_param('id'),
            (int) $request->get_param('feed_id')
        );

        if (isset($result['error'])) {
            return new \WP_REST_Response(['message' => $result['error']], $result['status'] ?? 422);
        }

        return new \WP_REST_Response(['message' => $result['message'] ?? __('Product unlinked successfully.', 'fchub-memberships')]);
    }

    public static function searchProducts(\WP_REST_Request $request): \WP_REST_Response
    {
        return new \WP_REST_Response(
            (new PlanProductLinkService())->searchProducts((string) ($request->get_param('search') ?? ''))
        );
    }
}

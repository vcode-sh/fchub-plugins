<?php

namespace FChubMemberships\Http\Controllers\Plans;

use FChubMemberships\Domain\Plan\PlanImportExportService;
use FChubMemberships\Domain\Plan\PlanScheduleService;

defined('ABSPATH') || exit;

final class PlanTransferController
{
    public static function export(\WP_REST_Request $request): \WP_REST_Response
    {
        $result = (new PlanImportExportService())->export((int) $request->get_param('id'));
        if (isset($result['error'])) {
            return new \WP_REST_Response(['message' => $result['error']], 404);
        }

        return new \WP_REST_Response($result);
    }

    public static function import(\WP_REST_Request $request): \WP_REST_Response
    {
        $result = (new PlanImportExportService())->import($request->get_json_params());
        if (isset($result['error'])) {
            return new \WP_REST_Response(['message' => $result['error']], 422);
        }

        return new \WP_REST_Response(['data' => $result, 'message' => __('Plan imported successfully.', 'fchub-memberships')], 201);
    }

    public static function exportAll(\WP_REST_Request $request): \WP_REST_Response
    {
        return new \WP_REST_Response((new PlanImportExportService())->exportAll());
    }

    public static function schedule(\WP_REST_Request $request): \WP_REST_Response
    {
        $data = $request->get_json_params();
        $result = (new PlanScheduleService())->save(
            (int) $request->get_param('id'),
            sanitize_text_field($data['scheduled_status'] ?? ''),
            sanitize_text_field($data['scheduled_at'] ?? '')
        );

        if (isset($result['error'])) {
            return new \WP_REST_Response(['message' => $result['error']], $result['status'] ?? 422);
        }

        return new \WP_REST_Response(['data' => $result['data'] ?? [], 'message' => $result['message'] ?? '']);
    }
}

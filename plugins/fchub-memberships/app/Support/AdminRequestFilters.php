<?php

namespace FChubMemberships\Support;

defined('ABSPATH') || exit;

final class AdminRequestFilters
{
    /**
     * @return array<string, mixed>
     */
    public static function planList(\WP_REST_Request $request): array
    {
        return [
            'status'   => PlanStatus::normalizeNullable(self::stringOrNull($request->get_param('status'))),
            'search'   => self::stringOrNull($request->get_param('search')),
            'per_page' => $request->get_param('per_page') ?: 20,
            'page'     => $request->get_param('page') ?: 1,
            'order_by' => $request->get_param('order_by') ?: 'level',
            'order'    => $request->get_param('order') ?: 'ASC',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function memberList(\WP_REST_Request $request): array
    {
        $planId = self::stringOrNull($request->get_param('plan_id'));
        if ($planId === null) {
            $planId = self::stringOrNull($request->get_param('plan'));
        }

        return [
            'status'      => self::stringOrNull($request->get_param('status')),
            'plan_id'     => $planId,
            'search'      => self::stringOrNull($request->get_param('search')),
            'source_type' => self::stringOrNull($request->get_param('source_type')),
            'per_page'    => $request->get_param('per_page') ?: 20,
            'page'        => $request->get_param('page') ?: 1,
        ];
    }

    private static function stringOrNull(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $string = trim((string) $value);

        return $string === '' ? null : $string;
    }
}

<?php

declare(strict_types=1);

namespace FChubMemberships\Tests\Unit\Support;

use FChubMemberships\Support\AdminRequestFilters;
use FChubMemberships\Support\PlanStatus;
use FChubMemberships\Tests\Unit\PluginTestCase;

final class AdminRequestFiltersTest extends PluginTestCase
{
    public function test_plan_list_filters_normalize_legacy_status_aliases(): void
    {
        $request = new \WP_REST_Request('GET', '/plans', [
            'status' => 'draft',
            'search' => 'Gold',
            'page' => 3,
        ]);

        $filters = AdminRequestFilters::planList($request);

        self::assertSame(PlanStatus::INACTIVE, $filters['status']);
        self::assertSame('Gold', $filters['search']);
        self::assertSame(3, $filters['page']);
        self::assertSame(20, $filters['per_page']);
    }

    public function test_member_list_filters_accept_plan_alias_from_existing_ui(): void
    {
        $request = new \WP_REST_Request('GET', '/members', [
            'plan' => '42',
            'status' => 'paused',
        ]);

        $filters = AdminRequestFilters::memberList($request);

        self::assertSame('42', $filters['plan_id']);
        self::assertSame('paused', $filters['status']);
    }

    public function test_member_list_filters_prefer_plan_id_and_drop_empty_values(): void
    {
        $request = new \WP_REST_Request('GET', '/members', [
            'plan_id' => '7',
            'plan' => '42',
            'search' => '   ',
        ]);

        $filters = AdminRequestFilters::memberList($request);

        self::assertSame('7', $filters['plan_id']);
        self::assertNull($filters['search']);
    }
}

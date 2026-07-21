<?php

declare(strict_types=1);

namespace FChubMemberships\Tests\Unit\Http\Controllers;

use FChubMemberships\Domain\Reports\DashboardQueryService;
use FChubMemberships\Http\Controllers\DashboardController;
use FChubMemberships\Modules\Runtime\FluentCartRuntimeModule;
use FChubMemberships\Reports\MemberStatsReport;
use FChubMemberships\Tests\Unit\PluginTestCase;

final class DashboardControllerTest extends PluginTestCase
{
    public function test_registers_the_admin_dashboard_route_with_reports_permission(): void
    {
        DashboardController::registerRoutes();

        $route = $GLOBALS['_fchub_test_routes']['fchub-memberships/v1/admin/dashboard'];
        $GLOBALS['_fchub_test_current_user_can'] = false;

        self::assertSame('GET', $route['methods']);
        self::assertFalse(($route['permission_callback'])());
    }

    public function test_wraps_dashboard_data_in_the_standard_data_envelope(): void
    {
        $stats = new class extends MemberStatsReport {
            public function __construct()
            {
            }

            public function getOverview(?string $from = null, ?string $to = null): array
            {
                return [
                    'active_members' => 2,
                    'active_plans' => 1,
                    'content_protected' => 1,
                    'grants_this_month' => 2,
                    'new_this_month' => 1,
                ];
            }

            public function getMembersOverTime(string $period = '12m', ?string $from = null, ?string $to = null): array
            {
                return [];
            }

            public function getPlanDistribution(?string $from = null, ?string $to = null): array
            {
                return [];
            }
        };

        $service = new DashboardQueryService(
            $stats,
            static fn(): int => 0,
            static fn(): array => ['failed' => 0],
            static fn(): array => []
        );

        $response = DashboardController::dashboard(new \WP_REST_Request('GET', '/admin/dashboard'), $service);

        self::assertSame(200, $response->get_status());
        self::assertSame(2, $response->get_data()['data']['summary']['active_members']);
    }

    public function test_runtime_controller_bootstrap_registers_the_dashboard_route(): void
    {
        (new FluentCartRuntimeModule())->registerRestRoutes();

        self::assertArrayHasKey('fchub-memberships/v1/admin/dashboard', $GLOBALS['_fchub_test_routes']);
    }
}

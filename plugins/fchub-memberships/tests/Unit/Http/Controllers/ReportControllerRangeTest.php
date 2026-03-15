<?php

declare(strict_types=1);

namespace FChubMemberships\Tests\Unit\Http\Controllers;

use FChubMemberships\Http\Controllers\ReportController;
use FChubMemberships\Tests\Unit\PluginTestCase;

final class ReportControllerRangeTest extends PluginTestCase
{
    public function test_overview_honors_explicit_start_and_end_dates(): void
    {
        $request = new \WP_REST_Request('GET', '/fchub-memberships/v1/admin/reports/overview', [
            'start_date' => '2026-01-01',
            'end_date' => '2026-01-31',
        ]);

        $response = ReportController::overview($request);
        $queries = implode("\n", array_map(static fn(array $item): string => is_string($item[1] ?? null) ? $item[1] : '', $GLOBALS['_fchub_test_queries']));

        $this->assertSame(200, $response->get_status());
        $this->assertStringContainsString("'2026-01-01 00:00:00'", $queries);
        $this->assertStringContainsString("'2026-01-31 23:59:59'", $queries);
    }

    public function test_renewal_and_trial_reports_honor_explicit_date_range(): void
    {
        ReportController::renewalRate(new \WP_REST_Request('GET', '/fchub-memberships/v1/admin/reports/renewal-rate', [
            'start_date' => '2026-02-01',
            'end_date' => '2026-02-28',
        ]));
        ReportController::trialConversion(new \WP_REST_Request('GET', '/fchub-memberships/v1/admin/reports/trial-conversion', [
            'start_date' => '2026-02-01',
            'end_date' => '2026-02-28',
        ]));

        $queries = implode("\n", array_map(static fn(array $item): string => is_string($item[1] ?? null) ? $item[1] : '', $GLOBALS['_fchub_test_queries']));

        $this->assertStringContainsString("'2026-02-01 00:00:00'", $queries);
        $this->assertStringContainsString("'2026-02-28 23:59:59'", $queries);
    }
}

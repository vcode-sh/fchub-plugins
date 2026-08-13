<?php

declare(strict_types=1);

namespace FChubMemberships\Tests\Unit\Http\Controllers;

use FChubMemberships\Http\Controllers\MemberController;
use FChubMemberships\Tests\Unit\PluginTestCase;

final class MemberControllerActivityExportTest extends PluginTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $GLOBALS['_fchub_test_users'][21] = (object) [
            'ID' => 21,
            'display_name' => 'Alice Example',
            'user_email' => 'alice@example.com',
        ];
    }

    private function grantRow(array $overrides = []): array
    {
        return array_merge([
            'id' => 100,
            'user_id' => 21,
            'plan_id' => 5,
            'provider' => 'wordpress_core',
            'resource_type' => 'post',
            'resource_id' => '55',
            'source_type' => 'manual',
            'source_id' => 0,
            'feed_id' => null,
            'grant_key' => 'grant-100',
            'status' => 'active',
            'starts_at' => null,
            'expires_at' => null,
            'drip_available_at' => null,
            'trial_ends_at' => null,
            'cancellation_requested_at' => null,
            'cancellation_effective_at' => null,
            'cancellation_reason' => null,
            'renewal_count' => 0,
            'source_ids' => '[]',
            'meta' => '{}',
            'created_at' => '2026-03-01 10:00:00',
            'updated_at' => '2026-03-02 10:00:00',
            'user_email' => 'alice@example.com',
            'display_name' => 'Alice Example',
            'plan_title' => 'Gold Plan',
        ], $overrides);
    }

    public function test_export_and_bulk_export_return_expected_rows_and_csv(): void
    {
        $GLOBALS['_fchub_test_wpdb_overrides']['get_results'] = fn(string $query): array => match (true) {
            str_contains($query, 'LEFT JOIN wp_users') => [$this->grantRow()],
            str_contains($query, 'FROM wp_fchub_membership_grants') => [$this->grantRow()],
            str_contains($query, 'FROM wp_fchub_membership_plans') => [$this->planRow()],
            default => [],
        };

        $export = MemberController::export(new \WP_REST_Request('GET', '/members/export', [
            'status' => 'active',
            'plan_id' => 5,
        ]))->get_data();
        $bulk = MemberController::bulkExport(new \WP_REST_Request('POST', '/members/bulk-export', [
            'user_ids' => [21],
        ]))->get_data();

        self::assertSame('alice@example.com', $export['data'][0]['email']);
        self::assertSame('Gold Plan', $export['data'][0]['plan_title']);
        self::assertStringContainsString('Gold Plan', $bulk['csv']);
        self::assertStringContainsString('"alice@example.com"', $bulk['csv']);
    }

    public function test_both_exports_describe_a_membership_with_the_same_columns(): void
    {
        $this->stubOneMembershipOfTwoRules();

        $export = MemberController::export(new \WP_REST_Request('GET', '/members/export', []))->get_data();
        $csv = MemberController::bulkExport(new \WP_REST_Request('POST', '/members/bulk-export', [
            'user_ids' => [21],
        ]))->get_data()['csv'];

        $header = explode("\n", $csv)[0];

        self::assertSame(implode(',', array_keys($export['data'][0])), $header);
    }

    public function test_bulk_export_writes_one_row_per_membership_not_per_rule(): void
    {
        $this->stubOneMembershipOfTwoRules();

        $csv = MemberController::bulkExport(new \WP_REST_Request('POST', '/members/bulk-export', [
            'user_ids' => [21],
        ]))->get_data()['csv'];

        $lines = array_values(array_filter(explode("\n", $csv)));

        self::assertCount(2, $lines, 'One header line and one membership line.');
        self::assertStringContainsString('"Gold Plan"', $lines[1]);
    }

    public function test_bulk_export_reports_the_derived_status_the_list_and_profile_report(): void
    {
        $this->stubOneMembershipOfTwoRules([
            ['id' => 100, 'status' => 'active', 'starts_at' => '2099-01-01 00:00:00'],
            ['id' => 101, 'status' => 'paused'],
        ]);

        $csv = MemberController::bulkExport(new \WP_REST_Request('POST', '/members/bulk-export', [
            'user_ids' => [21],
        ]))->get_data()['csv'];

        self::assertStringContainsString('"scheduled"', $csv);
        self::assertStringNotContainsString('"paused"', $csv);
    }

    public function test_bulk_export_reports_a_lifetime_membership_with_an_empty_expiry(): void
    {
        $this->stubOneMembershipOfTwoRules([
            ['id' => 100, 'expires_at' => '2026-12-01 00:00:00'],
            ['id' => 101, 'expires_at' => null],
        ]);

        $csv = MemberController::bulkExport(new \WP_REST_Request('POST', '/members/bulk-export', [
            'user_ids' => [21],
        ]))->get_data()['csv'];

        self::assertStringNotContainsString('2026-12-01', $csv);
    }

    public function test_bulk_export_reads_plan_titles_once_however_many_memberships_it_writes(): void
    {
        $this->stubOneMembershipOfTwoRules([
            ['id' => 100, 'plan_id' => 5],
            ['id' => 101, 'plan_id' => 6],
            ['id' => 102, 'plan_id' => 7],
        ]);

        MemberController::bulkExport(new \WP_REST_Request('POST', '/members/bulk-export', [
            'user_ids' => [21],
        ]));

        $planReads = array_filter(
            $GLOBALS['_fchub_test_queries'],
            static fn(array $entry): bool => $entry[0] === 'get_results'
                && str_contains((string) $entry[1], 'wp_fchub_membership_plans')
        );

        self::assertCount(1, $planReads);
    }

    public function test_bulk_export_refuses_an_empty_user_list(): void
    {
        $response = MemberController::bulkExport(new \WP_REST_Request('POST', '/members/bulk-export', [
            'user_ids' => [],
        ]));

        self::assertSame(422, $response->get_status());
    }

    public function test_bulk_export_neutralises_formula_prefixes_in_every_csv_cell(): void
    {
        $GLOBALS['_fchub_test_users'][21] = (object) [
            'ID' => 21,
            'display_name' => '+display',
            'user_email' => '=email',
        ];
        $GLOBALS['_fchub_test_wpdb_overrides']['get_results'] = fn(string $query): array => match (true) {
            str_contains($query, 'FROM wp_fchub_membership_plans') => [$this->planRow(['title' => '-plan'])],
            default => [$this->grantRow([
                'source_type' => "\tsource",
                'created_at' => "\rcreated",
                'expires_at' => "\nexpires",
            ])],
        };

        $csv = MemberController::bulkExport(new \WP_REST_Request('POST', '/members/bulk-export', [
            'user_ids' => [21],
        ]))->get_data()['csv'];

        foreach (["\"'=email\"", "\"'+display\"", "\"'-plan\"", "\"'\tsource\"", "\"'\rcreated\"", "\"'\nexpires\""] as $safeCell) {
            self::assertStringContainsString($safeCell, $csv);
        }
    }

    /** @param list<array<string, mixed>> $rowOverrides */
    private function stubOneMembershipOfTwoRules(array $rowOverrides = []): void
    {
        $rowOverrides = $rowOverrides ?: [
            ['id' => 100, 'resource_id' => '55'],
            ['id' => 101, 'resource_id' => '56'],
        ];
        $grants = array_map(fn(array $overrides): array => $this->grantRow($overrides), $rowOverrides);
        $planIds = array_unique(array_column($grants, 'plan_id'));
        $plans = array_map(fn($planId): array => $this->planRow(['id' => $planId]), $planIds);

        $GLOBALS['_fchub_test_wpdb_overrides']['get_results'] = fn(string $query): array => match (true) {
            str_contains($query, 'FROM wp_fchub_membership_plans') => $plans,
            default => $grants,
        };
    }

    public function test_activity_describes_recorded_events_and_paginates_them(): void
    {
        $GLOBALS['_fchub_test_users'][1] = (object) ['ID' => 1, 'display_name' => 'tomrobak'];
        $GLOBALS['_fchub_test_options']['date_format'] = 'j F Y';
        $GLOBALS['_fchub_test_wpdb_overrides']['get_results'] = fn(string $query): array => match (true) {
            str_contains($query, 'FROM wp_fchub_membership_grants') => [
                $this->grantRow(['id' => 100, 'status' => 'expired']),
                $this->grantRow(['id' => 101, 'status' => 'expired', 'resource_id' => '56']),
            ],
            str_contains($query, 'FROM wp_fchub_membership_plans') => [$this->planRow()],
            str_contains($query, 'FROM wp_fchub_membership_audit_log') => [
                $this->auditRow(100, 'created', '2026-03-01 10:00:00'),
                $this->auditRow(101, 'created', '2026-03-01 10:00:00'),
                $this->auditRow(100, 'extended', '2026-03-07 10:00:00', [
                    'new_value' => '{"expires_at":"2026-12-31 00:00:00"}',
                ]),
            ],
            str_contains($query, 'FROM wp_fchub_membership_drip_notifications') => [[
                'id' => 901,
                'grant_id' => 100,
                'plan_rule_id' => 55,
                'user_id' => 21,
                'notify_at' => '2026-03-08 10:00:00',
                'sent_at' => '2026-03-08 11:00:00',
                'status' => 'sent',
                'retry_count' => 0,
                'next_retry_at' => null,
            ]],
            default => [],
        };

        $activity = MemberController::activity(new \WP_REST_Request('GET', '/members/21/activity', [
            'user_id' => 21,
            'page' => 1,
            'per_page' => 10,
        ]))->get_data();

        self::assertSame(3, $activity['total']);
        self::assertSame(['drip_sent', 'extended', 'granted'], array_column($activity['data'], 'type'));
        self::assertSame(
            'Gold Plan extended to 31 December 2026 by tomrobak',
            $activity['data'][1]['description']
        );
    }

    public function test_activity_pages_without_repeating_events(): void
    {
        $GLOBALS['_fchub_test_users'][1] = (object) ['ID' => 1, 'display_name' => 'tomrobak'];
        $GLOBALS['_fchub_test_wpdb_overrides']['get_results'] = fn(string $query): array => match (true) {
            str_contains($query, 'FROM wp_fchub_membership_grants') => [$this->grantRow()],
            str_contains($query, 'FROM wp_fchub_membership_plans') => [$this->planRow()],
            str_contains($query, 'FROM wp_fchub_membership_audit_log') => [
                $this->auditRow(100, 'created', '2026-03-01 10:00:00'),
                $this->auditRow(100, 'paused', '2026-03-02 10:00:00'),
                $this->auditRow(100, 'resumed', '2026-03-03 10:00:00'),
            ],
            default => [],
        };

        $first = MemberController::activity(new \WP_REST_Request('GET', '/members/21/activity', [
            'user_id' => 21,
            'page' => 1,
            'per_page' => 10,
        ]))->get_data();
        $second = MemberController::activity(new \WP_REST_Request('GET', '/members/21/activity', [
            'user_id' => 21,
            'page' => 2,
            'per_page' => 10,
        ]))->get_data();

        self::assertSame(3, $first['total']);
        self::assertCount(3, $first['data']);
        self::assertSame([], $second['data']);
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function planRow(array $overrides = []): array
    {
        return array_merge([
            'id' => 5,
            'title' => 'Gold Plan',
            'slug' => 'gold-plan',
            'description' => '',
            'status' => 'active',
            'level' => 0,
            'duration_type' => 'lifetime',
            'duration_days' => null,
            'trial_days' => 0,
            'grace_period_days' => 0,
            'includes_plan_ids' => '[]',
            'restriction_message' => null,
            'redirect_url' => null,
            'settings' => '{}',
            'meta' => '{}',
            'created_at' => '2026-01-01 00:00:00',
            'updated_at' => '2026-01-01 00:00:00',
        ], $overrides);
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function auditRow(int $grantId, string $action, string $createdAt, array $overrides = []): array
    {
        return array_merge([
            'id' => $grantId * 10,
            'entity_type' => 'grant',
            'entity_id' => $grantId,
            'action' => $action,
            'actor_type' => 'admin',
            'actor_id' => 1,
            'context' => '',
            'old_value' => '{}',
            'new_value' => '{}',
            'created_at' => $createdAt,
        ], $overrides);
    }
}

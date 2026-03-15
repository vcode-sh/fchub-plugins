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
        ], $overrides);
    }

    public function test_export_and_bulk_export_return_expected_rows_and_csv(): void
    {
        $GLOBALS['_fchub_test_wpdb_overrides']['get_results'] = fn(string $query): array => match (true) {
            str_contains($query, 'LEFT JOIN wp_users') => [$this->grantRow()],
            str_contains($query, 'ORDER BY created_at DESC') => [$this->grantRow()],
            default => [],
        };

        $GLOBALS['_fchub_test_wpdb_overrides']['get_row'] = fn(string $query): ?array => str_contains($query, 'wp_fchub_membership_plans')
            ? [
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
            ]
            : null;

        $export = MemberController::export(new \WP_REST_Request('GET', '/members/export', [
            'status' => 'active',
            'plan_id' => 5,
        ]))->get_data();
        $bulk = MemberController::bulkExport(new \WP_REST_Request('POST', '/members/bulk-export', [
            'user_ids' => [21],
        ]))->get_data();

        self::assertSame('alice@example.com', $export['data'][0]['email']);
        self::assertStringContainsString('Gold Plan', $bulk['csv']);
        self::assertStringContainsString('"alice@example.com"', $bulk['csv']);
    }

    public function test_activity_and_audit_log_merge_and_paginate_member_events(): void
    {
        $GLOBALS['_fchub_test_wpdb_overrides']['get_results'] = fn(string $query): array => match (true) {
            str_contains($query, 'FROM wp_fchub_membership_grants') && str_contains($query, 'ORDER BY created_at DESC') => [
                $this->grantRow([
                    'status' => 'expired',
                    'updated_at' => '2026-03-05 10:00:00',
                    'trial_ends_at' => '2026-03-03 10:00:00',
                    'renewal_count' => 2,
                    'paused_at' => '2026-03-04 10:00:00',
                    'revoked_at' => '2026-03-06 10:00:00',
                ]),
            ],
            str_contains($query, 'FROM wp_fchub_membership_audit_log') => [[
                'id' => 501,
                'entity_type' => 'grant',
                'entity_id' => 100,
                'action' => 'updated',
                'actor_type' => 'admin',
                'actor_id' => 1,
                'context' => 'Manual correction',
                'old_value' => '{}',
                'new_value' => '{}',
                'created_at' => '2026-03-07 10:00:00',
            ]],
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

        $GLOBALS['_fchub_test_wpdb_overrides']['get_row'] = fn(string $query): ?array => str_contains($query, 'wp_fchub_membership_plans')
            ? [
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
            ]
            : null;

        $audit = MemberController::auditLog(new \WP_REST_Request('GET', '/members/21/audit-log', ['user_id' => 21]))->get_data()['data'];
        $activity = MemberController::activity(new \WP_REST_Request('GET', '/members/21/activity', [
            'user_id' => 21,
            'page' => 1,
            'per_page' => 10,
        ]))->get_data();

        self::assertCount(1, $audit);
        self::assertSame('updated', $audit[0]['action']);
        self::assertSame(8, $activity['total']);
        self::assertSame('drip_sent', $activity['data'][0]['type']);
        self::assertContains('grant_expired', array_column($activity['data'], 'type'));
        self::assertContains('drip_sent', array_column($activity['data'], 'type'));
    }
}

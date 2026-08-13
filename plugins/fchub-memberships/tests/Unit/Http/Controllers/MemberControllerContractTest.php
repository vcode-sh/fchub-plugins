<?php

declare(strict_types=1);

namespace FChubMemberships\Tests\Unit\Http\Controllers;

use FChubMemberships\Http\Controllers\MemberController;
use FChubMemberships\Tests\Unit\PluginTestCase;

final class MemberControllerContractTest extends PluginTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $user = (object) [
            'ID' => 21,
            'display_name' => 'Alice Example',
            'user_email' => 'alice@example.com',
            'user_registered' => '2025-01-10 09:15:00',
        ];

        $GLOBALS['_fchub_test_users'][21] = $user;
        $GLOBALS['_fchub_test_users_by_email']['alice@example.com'] = $user;
        $GLOBALS['_fchub_test_posts'][55] = (object) [
            'ID' => 55,
            'post_title' => 'Members Post',
            'post_type' => 'post',
        ];
        $GLOBALS['_fchub_test_post_types'] = ['post'];
    }

    public function test_index_users_only_returns_user_records_instead_of_grant_rows(): void
    {
        $request = new \WP_REST_Request('GET', '/fchub-memberships/v1/admin/members', [
            'users_only' => true,
            'search' => 'alice',
            'per_page' => 10,
        ]);

        $response = MemberController::index($request);
        $data = $response->get_data();

        $this->assertSame(200, $response->get_status());
        $this->assertCount(1, $data['data']);
        $this->assertSame(21, $data['data'][0]['id']);
        $this->assertSame('Alice Example', $data['data'][0]['display_name']);
        $this->assertSame('alice@example.com', $data['data'][0]['email']);
        $this->assertArrayNotHasKey('plan_id', $data['data'][0], 'User search should not return grant rows.');
    }

    public function test_user_picker_queries_are_bounded_and_use_deterministic_ordering(): void
    {
        MemberController::index(new \WP_REST_Request('GET', '/fchub-memberships/v1/admin/members', [
            'users_only' => true,
            'search' => '',
            'per_page' => 10,
        ]));

        MemberController::index(new \WP_REST_Request('GET', '/fchub-memberships/v1/admin/members', [
            'users_only' => true,
            'search' => 'alice',
            'per_page' => 200,
        ]));

        [$browseArgs, $searchArgs] = $GLOBALS['_fchub_test_get_users_args'];
        self::assertSame('', $browseArgs['search']);
        self::assertSame('registered', $browseArgs['orderby']);
        self::assertSame('DESC', $browseArgs['order']);
        self::assertSame(10, $browseArgs['number']);
        self::assertFalse($browseArgs['count_total']);

        self::assertSame('*alice*', $searchArgs['search']);
        self::assertSame('display_name', $searchArgs['orderby']);
        self::assertSame('ASC', $searchArgs['order']);
        self::assertSame(20, $searchArgs['number']);
        self::assertFalse($searchArgs['count_total']);
    }

    public function test_show_returns_one_membership_per_plan_rather_than_one_per_rule_row(): void
    {
        $this->stubMemberReads();

        $response = MemberController::show(new \WP_REST_Request('GET', '/fchub-memberships/v1/admin/members/21', [
            'user_id' => 21,
        ]));
        $data = $response->get_data()['data'];

        self::assertSame('alice@example.com', $data['user']['email']);
        self::assertSame('2025-01-10 09:15:00', $data['user']['registered_at']);
        self::assertSame('https://example.com/wp-admin/user-edit.php?user_id=21', $data['user']['edit_url']);
        self::assertCount(1, $data['memberships']);
        self::assertSame('Gold Plan', $data['memberships'][0]['plan_title']);
        self::assertSame([101, 102], $data['memberships'][0]['grant_ids']);
        self::assertCount(2, $data['memberships'][0]['resources']);
    }

    public function test_show_no_longer_carries_the_payload_the_admin_app_never_read(): void
    {
        $this->stubMemberReads();

        $data = MemberController::show(new \WP_REST_Request('GET', '/fchub-memberships/v1/admin/members/21', [
            'user_id' => 21,
        ]))->get_data()['data'];

        self::assertArrayNotHasKey('audit_log', $data);
        self::assertArrayNotHasKey('history', $data);
        self::assertArrayNotHasKey('plans', $data);
    }

    public function test_show_reads_a_fixed_number_of_tables_however_many_rules_the_plan_has(): void
    {
        $this->stubMemberReads(ruleRows: 12);

        MemberController::show(new \WP_REST_Request('GET', '/fchub-memberships/v1/admin/members/21', [
            'user_id' => 21,
        ]));

        $reads = array_values(array_filter(
            $GLOBALS['_fchub_test_queries'],
            static fn(array $entry): bool => $entry[0] === 'get_results'
        ));

        self::assertCount(3, $reads);
    }

    public function test_show_names_the_administrator_behind_a_manual_grant(): void
    {
        $GLOBALS['_fchub_test_users'][9] = (object) ['ID' => 9, 'display_name' => 'tomrobak'];
        $this->stubMemberReads(auditRows: [[
            'id' => 1,
            'entity_type' => 'grant',
            'entity_id' => 101,
            'action' => 'created',
            'actor_type' => 'admin',
            'actor_id' => 9,
            'context' => '',
            'old_value' => '{}',
            'new_value' => '{}',
            'created_at' => '2026-03-10 10:00:00',
        ]]);

        $data = MemberController::show(new \WP_REST_Request('GET', '/fchub-memberships/v1/admin/members/21', [
            'user_id' => 21,
        ]))->get_data()['data'];

        self::assertSame('Manual grant', $data['memberships'][0]['source']['label']);
        self::assertSame('tomrobak', $data['memberships'][0]['source']['actor']);
        self::assertNull($data['memberships'][0]['source']['url']);
    }

    /** @param list<array<string, mixed>> $auditRows */
    private function stubMemberReads(int $ruleRows = 2, array $auditRows = []): void
    {
        $grantRows = [];
        for ($index = 0; $index < $ruleRows; $index++) {
            $grantRows[] = $this->grantRow(101 + $index, (string) (55 + $index));
        }

        $GLOBALS['_fchub_test_wpdb_overrides']['get_results'] = fn(string $query): array => match (true) {
            str_contains($query, 'FROM wp_fchub_membership_grants') => $grantRows,
            str_contains($query, 'FROM wp_fchub_membership_plans') => [$this->planRow()],
            str_contains($query, 'FROM wp_fchub_membership_audit_log') => $auditRows,
            default => [],
        };
    }

    /** @return array<string, mixed> */
    private function grantRow(int $id, string $resourceId): array
    {
        return [
            'id' => $id,
            'user_id' => 21,
            'plan_id' => 5,
            'provider' => 'wordpress_core',
            'resource_type' => 'post',
            'resource_id' => $resourceId,
            'source_type' => 'manual',
            'source_id' => 0,
            'feed_id' => null,
            'grant_key' => 'grant-' . $id,
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
            'created_at' => '2026-03-10 10:00:00',
            'updated_at' => '2026-03-10 10:00:00',
        ];
    }

    /** @return array<string, mixed> */
    private function planRow(): array
    {
        return [
            'id' => 5,
            'title' => 'Gold Plan',
            'slug' => 'gold-plan',
            'description' => '',
            'status' => 'active',
            'level' => 0,
            'includes_plan_ids' => '[]',
            'restriction_message' => '',
            'redirect_url' => '',
            'settings' => '{}',
            'meta' => '{}',
            'duration_type' => 'lifetime',
            'duration_days' => null,
            'trial_days' => 0,
            'grace_period_days' => 0,
            'scheduled_status' => null,
            'scheduled_at' => null,
            'created_at' => '2026-01-01 00:00:00',
            'updated_at' => '2026-01-01 00:00:00',
        ];
    }
}

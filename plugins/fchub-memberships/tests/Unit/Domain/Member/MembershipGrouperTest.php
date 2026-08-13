<?php

declare(strict_types=1);

namespace FChubMemberships\Tests\Unit\Domain\Member;

use FChubMemberships\Domain\Member\MembershipGrouper;
use FChubMemberships\Support\Clock;
use FChubMemberships\Tests\Unit\PluginTestCase;

final class MembershipGrouperTest extends PluginTestCase
{
    public function test_it_collapses_every_rule_row_of_one_plan_into_one_membership(): void
    {
        $memberships = $this->group([
            self::row(3, 1, ['resource_type' => 'category', 'resource_id' => '0']),
            self::row(4, 1, ['resource_type' => 'post', 'resource_id' => '1']),
        ]);

        self::assertCount(1, $memberships);
        self::assertSame('plan:1', $memberships[0]['key']);
        self::assertSame([3, 4], $memberships[0]['grant_ids']);
        self::assertCount(2, $memberships[0]['resources']);
        self::assertSame(['category', 'post'], array_column($memberships[0]['resources'], 'resource_type'));
    }

    public function test_it_keeps_each_plan_less_grant_as_its_own_membership(): void
    {
        $memberships = $this->group([
            self::row(7, null, ['resource_type' => 'post', 'resource_id' => '9']),
            self::row(8, null, ['resource_type' => 'post', 'resource_id' => '10']),
        ]);

        self::assertCount(2, $memberships);
        self::assertSame(['grant:7', 'grant:8'], array_column($memberships, 'key'));
        self::assertSame('Direct grant', $memberships[0]['plan_title']);
    }

    public function test_active_row_decides_the_membership_even_when_another_row_is_expired(): void
    {
        $memberships = $this->group([
            self::row(3, 1, ['status' => 'expired', 'expires_at' => '2026-01-01 00:00:00']),
            self::row(4, 1, ['status' => 'active', 'expires_at' => '2026-12-01 00:00:00']),
        ]);

        self::assertSame('active', $memberships[0]['status']);
    }

    public function test_a_row_whose_expiry_has_passed_does_not_keep_the_membership_active(): void
    {
        $memberships = $this->group([
            self::row(3, 1, ['status' => 'active', 'expires_at' => '2026-01-01 00:00:00']),
        ]);

        self::assertSame('expired', $memberships[0]['status']);
    }

    public function test_a_row_that_has_not_started_does_not_make_the_membership_active(): void
    {
        $memberships = $this->group([
            self::row(3, 1, ['status' => 'active', 'starts_at' => '2027-01-01 00:00:00']),
        ]);

        self::assertSame('scheduled', $memberships[0]['status']);
    }

    public function test_paused_outranks_revoked_and_expired(): void
    {
        $memberships = $this->group([
            self::row(3, 1, ['status' => 'revoked']),
            self::row(4, 1, ['status' => 'paused']),
            self::row(5, 1, ['status' => 'expired']),
        ]);

        self::assertSame('paused', $memberships[0]['status']);
    }

    public function test_revoked_outranks_expired(): void
    {
        $memberships = $this->group([
            self::row(3, 1, ['status' => 'expired']),
            self::row(4, 1, ['status' => 'revoked']),
        ]);

        self::assertSame('revoked', $memberships[0]['status']);
    }

    public function test_lifetime_expiry_wins_over_a_dated_row(): void
    {
        $memberships = $this->group([
            self::row(3, 1, ['expires_at' => '2026-12-01 00:00:00']),
            self::row(4, 1, ['expires_at' => null]),
        ]);

        self::assertNull($memberships[0]['expires_at']);
    }

    public function test_the_latest_dated_expiry_represents_the_membership(): void
    {
        $memberships = $this->group([
            self::row(3, 1, ['expires_at' => '2026-09-12 00:00:00']),
            self::row(4, 1, ['expires_at' => '2026-12-01 00:00:00']),
        ]);

        self::assertSame('2026-12-01 00:00:00', $memberships[0]['expires_at']);
    }

    public function test_it_carries_source_trial_and_renewal_facts_from_the_earliest_row(): void
    {
        $memberships = $this->group([
            self::row(3, 1, [
                'created_at' => '2026-02-01 10:00:00',
                'source_type' => 'subscription',
                'source_id' => 55,
                'trial_ends_at' => '2026-02-15 10:00:00',
                'renewal_count' => 2,
            ]),
            self::row(4, 1, ['created_at' => '2026-02-01 10:00:05']),
        ]);

        self::assertSame('subscription', $memberships[0]['source_type']);
        self::assertSame(55, $memberships[0]['source_id']);
        self::assertSame('2026-02-15 10:00:00', $memberships[0]['trial_ends_at']);
        self::assertSame(2, $memberships[0]['renewal_count']);
        self::assertSame('2026-02-01 10:00:00', $memberships[0]['created_at']);
    }

    public function test_it_titles_a_membership_from_the_supplied_plan_map(): void
    {
        $memberships = $this->group([self::row(3, 4)], [4 => 'Pro']);

        self::assertSame('Pro', $memberships[0]['plan_title']);
        self::assertSame(4, $memberships[0]['plan_id']);
    }

    public function test_it_falls_back_to_the_plan_id_when_the_plan_row_is_gone(): void
    {
        $memberships = $this->group([self::row(3, 4)], []);

        self::assertSame('Plan #4', $memberships[0]['plan_title']);
    }

    public function test_it_orders_memberships_with_active_ones_first(): void
    {
        $memberships = $this->group([
            self::row(3, 1, ['status' => 'expired', 'expires_at' => '2026-01-01 00:00:00']),
            self::row(4, 2, ['status' => 'active']),
        ], [1 => 'Old', 2 => 'Current']);

        self::assertSame(['Current', 'Old'], array_column($memberships, 'plan_title'));
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @param array<int, string> $planTitles
     * @return list<array<string, mixed>>
     */
    private function group(array $rows, array $planTitles = [1 => 'Plan A']): array
    {
        $clock = new Clock(new \DateTimeImmutable('2026-08-13 12:00:00'));

        return (new MembershipGrouper($clock))->group($rows, $planTitles);
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private static function row(int $id, ?int $planId, array $overrides = []): array
    {
        return array_merge([
            'id' => $id,
            'user_id' => 1,
            'plan_id' => $planId,
            'provider' => 'wordpress_core',
            'resource_type' => 'post',
            'resource_id' => '1',
            'source_type' => 'order',
            'source_id' => 4,
            'status' => 'active',
            'starts_at' => null,
            'expires_at' => null,
            'drip_available_at' => null,
            'trial_ends_at' => null,
            'renewal_count' => 0,
            'meta' => [],
            'created_at' => '2026-02-01 10:00:00',
            'updated_at' => '2026-02-01 10:00:00',
        ], $overrides);
    }
}

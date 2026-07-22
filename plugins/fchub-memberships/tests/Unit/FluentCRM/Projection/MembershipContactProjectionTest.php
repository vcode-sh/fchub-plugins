<?php

declare(strict_types=1);

namespace FChubMemberships\Tests\Unit\FluentCRM\Projection;

use FChubMemberships\FluentCRM\Projection\MembershipContactProjection;
use PHPUnit\Framework\TestCase;

final class MembershipContactProjectionTest extends TestCase
{
    public function test_it_collapses_resource_grants_into_one_active_plan(): void
    {
        $projection = MembershipContactProjection::fromGrants(21, [
            $this->grant(1, 5, 'active', 2, '2026-06-01 00:00:00', null, '2026-02-01 10:00:00'),
            $this->grant(2, 5, 'active', 4, '2026-06-01 00:00:00', null, '2026-02-02 10:00:00'),
            $this->grant(3, 5, 'active', 3, '2026-06-01 00:00:00', null, '2026-02-03 10:00:00'),
        ], $this->plans());

        self::assertSame(21, $projection->userId);
        self::assertSame('active', $projection->status);
        self::assertSame([5], $projection->activePlanIds);
        self::assertSame(['Gold Plan'], $projection->activePlanTitles);
        self::assertSame(['gold-plan'], $projection->managedPlanTagNames);
        self::assertSame('2026-06-01 00:00:00', $projection->expiresAt);
        self::assertNull($projection->trialEndsAt);
        self::assertSame(4, $projection->renewalCount);
        self::assertSame('2026-02-01 10:00:00', $projection->memberSince);
        self::assertTrue($projection->hasActiveMembership);
    }

    public function test_it_projects_stacked_active_plans_in_deterministic_order(): void
    {
        $projection = MembershipContactProjection::fromGrants(21, [
            $this->grant(1, 8, 'active', 1, '2026-07-01 00:00:00'),
            $this->grant(2, 5, 'active', 2, '2026-05-01 00:00:00'),
            $this->grant(3, 8, 'active', 3, '2026-07-01 00:00:00'),
            $this->grant(4, 5, 'active', 4, '2026-05-01 00:00:00'),
        ], $this->plans());

        self::assertSame('active', $projection->status);
        self::assertSame([5, 8], $projection->activePlanIds);
        self::assertSame(['Gold Plan', 'Silver Plan'], $projection->activePlanTitles);
        self::assertSame(['gold-plan', 'silver-plan'], $projection->managedPlanTagNames);
        self::assertSame('2026-07-01 00:00:00', $projection->expiresAt);
        self::assertSame(7, $projection->renewalCount);
        self::assertTrue($projection->hasActiveMembership);
    }

    public function test_a_revoked_grant_does_not_override_an_active_plan(): void
    {
        $projection = MembershipContactProjection::fromGrants(21, [
            $this->grant(1, 5, 'revoked', 5, '2026-04-01 00:00:00'),
            $this->grant(2, 8, 'active', 2, '2026-08-01 00:00:00'),
        ], $this->plans());

        self::assertSame('active', $projection->status);
        self::assertSame([8], $projection->activePlanIds);
        self::assertSame(['Silver Plan'], $projection->activePlanTitles);
        self::assertSame(['silver-plan'], $projection->managedPlanTagNames);
        self::assertTrue($projection->hasActiveMembership);
    }

    public function test_all_paused_plans_project_a_paused_contact_without_active_access(): void
    {
        $projection = MembershipContactProjection::fromGrants(21, [
            $this->grant(1, 5, 'paused', 2, '2026-06-01 00:00:00'),
            $this->grant(2, 8, 'paused', 3, '2026-07-01 00:00:00'),
        ], $this->plans());

        self::assertSame('paused', $projection->status);
        self::assertSame([], $projection->activePlanIds);
        self::assertSame([], $projection->activePlanTitles);
        self::assertSame([], $projection->managedPlanTagNames);
        self::assertNull($projection->expiresAt);
        self::assertSame(5, $projection->renewalCount);
        self::assertFalse($projection->hasActiveMembership);
    }

    public function test_an_active_trial_takes_precedence_over_active_paid_access(): void
    {
        $projection = MembershipContactProjection::fromGrants(21, [
            $this->grant(1, 5, 'active', 1, '2026-06-01 00:00:00'),
            $this->grant(2, 8, 'active', 0, '2026-05-01 00:00:00', '2026-04-15 00:00:00'),
        ], $this->plans());

        self::assertSame('trial', $projection->status);
        self::assertSame([5, 8], $projection->activePlanIds);
        self::assertSame('2026-04-15 00:00:00', $projection->trialEndsAt);
        self::assertSame('2026-06-01 00:00:00', $projection->expiresAt);
        self::assertTrue($projection->hasActiveMembership);
    }

    public function test_mixed_dated_expiries_use_the_latest_active_expiry(): void
    {
        $projection = MembershipContactProjection::fromGrants(21, [
            $this->grant(1, 5, 'active', 1, '2026-05-01 00:00:00'),
            $this->grant(2, 5, 'active', 1, '2026-06-15 00:00:00'),
            $this->grant(3, 8, 'active', 1, '2026-06-01 00:00:00'),
        ], $this->plans());

        self::assertSame('2026-06-15 00:00:00', $projection->expiresAt);
    }

    public function test_any_lifetime_active_plan_makes_the_contact_expiry_lifetime(): void
    {
        $projection = MembershipContactProjection::fromGrants(21, [
            $this->grant(1, 5, 'active', 1, null),
            $this->grant(2, 8, 'active', 1, '2026-06-01 00:00:00'),
        ], $this->plans());

        self::assertNull($projection->expiresAt);
    }

    public function test_planless_grants_share_one_direct_renewal_bucket(): void
    {
        $projection = MembershipContactProjection::fromGrants(21, [
            $this->grant(1, null, 'active', 2, '2026-05-01 00:00:00'),
            $this->grant(2, null, 'active', 3, '2026-06-01 00:00:00'),
        ], $this->plans());

        self::assertSame('active', $projection->status);
        self::assertSame([], $projection->activePlanIds);
        self::assertSame([], $projection->managedPlanTagNames);
        self::assertSame(3, $projection->renewalCount);
        self::assertTrue($projection->hasActiveMembership);
    }

    public function test_paused_status_takes_precedence_over_expired_and_revoked_grants(): void
    {
        $projection = MembershipContactProjection::fromGrants(21, [
            $this->grant(1, 5, 'revoked', 1, '2026-04-01 00:00:00'),
            $this->grant(2, 8, 'expired', 1, '2026-05-01 00:00:00'),
            $this->grant(3, null, 'paused', 1, '2026-06-01 00:00:00'),
        ], $this->plans());

        self::assertSame('paused', $projection->status);
        self::assertFalse($projection->hasActiveMembership);
    }

    public function test_expired_status_takes_precedence_over_revoked_grants(): void
    {
        $projection = MembershipContactProjection::fromGrants(21, [
            $this->grant(1, 5, 'revoked', 1, '2026-04-01 00:00:00'),
            $this->grant(2, 8, 'expired', 1, '2026-05-01 00:00:00'),
        ], $this->plans());

        self::assertSame('expired', $projection->status);
        self::assertFalse($projection->hasActiveMembership);
    }

    public function test_numeric_zero_plan_slug_is_preserved_as_a_managed_tag(): void
    {
        $plans = $this->plans();
        $plans[9] = ['id' => 9, 'title' => 'Zero Plan', 'slug' => '0'];

        $projection = MembershipContactProjection::fromGrants(21, [
            $this->grant(1, 9, 'active', 0, '2026-06-01 00:00:00'),
        ], $plans);

        self::assertSame(['0'], $projection->managedPlanTagNames);
    }

    public function test_no_grants_produce_the_empty_projection(): void
    {
        $projection = MembershipContactProjection::fromGrants(21, [], $this->plans());

        self::assertSame(21, $projection->userId);
        self::assertSame('none', $projection->status);
        self::assertSame([], $projection->activePlanIds);
        self::assertSame([], $projection->activePlanTitles);
        self::assertSame([], $projection->managedPlanTagNames);
        self::assertNull($projection->expiresAt);
        self::assertNull($projection->trialEndsAt);
        self::assertSame(0, $projection->renewalCount);
        self::assertNull($projection->memberSince);
        self::assertFalse($projection->hasActiveMembership);
    }

    /** @return array<int, array{id:int, title:string, slug:string}> */
    private function plans(): array
    {
        return [
            5 => ['id' => 5, 'title' => 'Gold Plan', 'slug' => 'gold-plan'],
            8 => ['id' => 8, 'title' => 'Silver Plan', 'slug' => 'silver-plan'],
        ];
    }

    /** @return array<string, mixed> */
    private function grant(
        int $id,
        ?int $planId,
        string $status,
        int $renewalCount,
        ?string $expiresAt,
        ?string $trialEndsAt = null,
        string $createdAt = '2026-03-01 10:00:00'
    ): array {
        return [
            'id' => $id,
            'user_id' => 21,
            'plan_id' => $planId,
            'status' => $status,
            'renewal_count' => $renewalCount,
            'expires_at' => $expiresAt,
            'trial_ends_at' => $trialEndsAt,
            'created_at' => $createdAt,
        ];
    }
}

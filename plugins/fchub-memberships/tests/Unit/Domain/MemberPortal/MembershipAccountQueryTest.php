<?php

declare(strict_types=1);

namespace FChubMemberships\Tests\Unit\Domain\MemberPortal;

use FChubMemberships\Domain\MemberPortal\MembershipAccountQuery;
use FChubMemberships\Domain\MemberPortal\MembershipCommerceResolver;
use FChubMemberships\Tests\Unit\PluginTestCase;

final class MembershipAccountQueryTest extends PluginTestCase
{
    public function test_it_returns_membership_level_current_and_history_models(): void
    {
        $grants = [
            $this->grant(1, 'paused', 5, 'subscription', 88, 'post', '101', null),
            $this->grant(2, 'paused', 5, 'subscription', 88, 'page', '102', null),
            $this->grant(3, 'expired', 6, 'order', 77, 'post', '103', '2026-03-10 00:00:00'),
            $this->grant(4, 'expired', 6, 'order', 77, 'page', '104', '2026-03-10 00:00:00'),
        ];
        $plans = [
            5 => ['id' => 5, 'title' => 'Premium', 'slug' => 'premium', 'description' => 'Everything'],
            6 => ['id' => 6, 'title' => 'Archive', 'slug' => 'archive', 'description' => 'Past access'],
        ];
        $commerce = new MembershipCommerceResolver(
            manageUrl: static fn(int $subscriptionId): string => "https://example.com/subscription/{$subscriptionId}",
            checkoutUrl: static fn(int $planId): string => 'https://example.com/checkout/' . $planId,
            nextBillingDate: static fn(int $subscriptionId): string => '1 Jun 2026'
        );
        $query = new MembershipAccountQuery(
            grantsForUser: static fn(int $userId): array => $grants,
            planById: static fn(int $planId): ?array => $plans[$planId] ?? null,
            progressForPlan: static fn(int $userId, int $planId): array => ['unlocked' => 1, 'total' => 2],
            timelineForPlan: static fn(int $userId, int $planId): array => [['label' => 'Welcome']],
            commerce: $commerce
        );

        $result = $query->get(9);

        self::assertCount(1, $result['plans']);
        self::assertSame('Premium', $result['plans'][0]['plan_title']);
        self::assertSame('paused', $result['plans'][0]['status']);
        self::assertSame(2, $result['plans'][0]['resource_count']);
        self::assertFalse($result['plans'][0]['is_lifetime']);
        self::assertSame('1 Jun 2026', $result['plans'][0]['next_billing_date']);
        self::assertSame('Manage subscription', $result['plans'][0]['action']['label']);
        self::assertSame(['unlocked' => 1, 'total' => 2], $result['plans'][0]['progress']);

        self::assertCount(1, $result['history']);
        self::assertSame('Archive', $result['history'][0]['plan_title']);
        self::assertSame(2, $result['history'][0]['resource_count']);
        self::assertSame('Renew membership', $result['history'][0]['action']['label']);
    }

    public function test_it_marks_mixed_resource_expiries_without_inventing_a_plan_date(): void
    {
        $query = new MembershipAccountQuery(
            grantsForUser: fn(int $userId): array => [
                $this->grant(1, 'active', 5, 'manual', 0, 'post', '101', null),
                $this->grant(2, 'active', 5, 'manual', 0, 'post', '102', '2026-06-01 00:00:00'),
            ],
            planById: static fn(int $planId): ?array => ['id' => 5, 'title' => 'Premium', 'slug' => 'premium'],
            progressForPlan: static fn(int $userId, int $planId): array => [],
            timelineForPlan: static fn(int $userId, int $planId): array => [],
            commerce: new MembershipCommerceResolver(
                manageUrl: static fn(int $subscriptionId): string => '',
                checkoutUrl: static fn(int $planId): string => '',
                nextBillingDate: static fn(int $subscriptionId): string => ''
            )
        );

        $plan = $query->get(9)['plans'][0];

        self::assertSame('varies', $plan['access_date_kind']);
        self::assertNull($plan['expires_at']);
        self::assertFalse($plan['is_lifetime']);
    }

    private function grant(
        int $id,
        string $status,
        ?int $planId,
        string $sourceType,
        int $sourceId,
        string $resourceType,
        string $resourceId,
        ?string $expiresAt
    ): array {
        return [
            'id' => $id,
            'user_id' => 9,
            'plan_id' => $planId,
            'status' => $status,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'provider' => 'wordpress_core',
            'resource_type' => $resourceType,
            'resource_id' => $resourceId,
            'starts_at' => '2026-03-01 00:00:00',
            'expires_at' => $expiresAt,
            'created_at' => '2026-03-01 00:00:00',
            'updated_at' => '2026-03-10 00:00:00',
        ];
    }
}

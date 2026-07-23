<?php

declare(strict_types=1);

namespace FChubMemberships\Tests\Unit\Domain\MemberPortal;

use FChubMemberships\Domain\MemberPortal\MembershipAccountQuery;
use FChubMemberships\Tests\Unit\PluginTestCase;

final class MembershipAccountCommunityCompositionTest extends PluginTestCase
{
    public function test_it_adds_one_top_level_community_context_without_changing_plans_or_history(): void
    {
        $calls = [];
        $community = [
            'state' => 'available',
            'profile' => ['is_verified' => true],
            'spaces' => [[
                'id' => 2,
                'title' => 'Members Lounge',
                'plan_ids' => [5],
                'ownership' => 'fchub',
                'operation_state' => 'healthy',
            ]],
            'courses' => [],
            'pending_access_count' => 0,
            'capabilities' => ['spaces' => 'available'],
        ];
        $query = new MembershipAccountQuery(
            grantsForUser: static fn(int $userId): array => [],
            communityForUser: static function (int $userId) use (&$calls, $community): array {
                $calls[] = $userId;
                return $community;
            }
        );

        $result = $query->get(17);

        self::assertSame([], $result['plans']);
        self::assertSame([], $result['history']);
        self::assertSame($community, $result['community']);
        self::assertSame([17], $calls);
    }

    public function test_community_query_failure_degrades_without_breaking_the_existing_account_contract(): void
    {
        $query = new MembershipAccountQuery(
            grantsForUser: static fn(int $userId): array => [],
            communityForUser: static fn(): never => throw new \RuntimeException(
                'private provider query and member@example.test'
            )
        );

        $result = $query->get(17);

        self::assertSame([], $result['plans']);
        self::assertSame([], $result['history']);
        self::assertSame([
            'state' => 'degraded',
            'profile' => ['is_verified' => null],
            'spaces' => [],
            'courses' => [],
            'pending_access_count' => 0,
            'capabilities' => [
                'spaces' => 'unverified',
                'courses' => 'unverified',
                'profile_verification_read' => 'unverified',
                'badges' => 'unverified',
                'points' => 'unverified',
                'leaderboard_levels' => 'unverified',
            ],
        ], $result['community']);
        self::assertStringNotContainsString('member@example.test', json_encode($result));
    }
}

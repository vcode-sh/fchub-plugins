<?php

declare(strict_types=1);

namespace FChubMemberships\Tests\Unit\Domain\MemberPortal;

use FChubMemberships\Domain\MemberPortal\MembershipAccountProjector;
use FChubMemberships\Tests\Unit\PluginTestCase;

final class MembershipAccountProjectorTest extends PluginTestCase
{
    public function test_it_collapses_resource_grants_from_one_purchase_into_one_history_episode(): void
    {
        $projector = new MembershipAccountProjector();

        $result = $projector->project([
            $this->grant(10, 'expired', 5, 'order', 77, 'post', '101'),
            $this->grant(11, 'expired', 5, 'order', 77, 'post', '102'),
        ]);

        self::assertCount(1, $result['history']);
        self::assertSame(2, $result['history'][0]['resource_count']);
        self::assertSame('expired', $result['history'][0]['status']);
        self::assertSame('order', $result['history'][0]['source_type']);
        self::assertSame(77, $result['history'][0]['source_id']);
    }

    public function test_it_keeps_paused_memberships_current_and_terminal_states_in_history(): void
    {
        $projector = new MembershipAccountProjector();

        $result = $projector->project([
            $this->grant(10, 'paused', 5, 'subscription', 88, 'post', '101'),
            $this->grant(11, 'active', 6, 'order', 79, 'post', '102'),
            $this->grant(12, 'revoked', 7, 'manual', 0, 'page', '103'),
        ]);

        self::assertSame(['paused', 'active'], array_column($result['current'], 'status'));
        self::assertSame(['revoked'], array_column($result['history'], 'status'));
    }

    public function test_it_reports_lifetime_shared_and_mixed_access_dates_honestly(): void
    {
        $projector = new MembershipAccountProjector();

        $lifetime = $projector->summariseAccessDates([
            $this->grant(1, 'active', 5, 'manual', 0, 'post', '1', null),
            $this->grant(2, 'active', 5, 'manual', 0, 'post', '2', null),
        ]);
        $shared = $projector->summariseAccessDates([
            $this->grant(3, 'active', 5, 'order', 77, 'post', '3', '2026-06-01 00:00:00'),
            $this->grant(4, 'active', 5, 'order', 77, 'post', '4', '2026-06-01 00:00:00'),
        ]);
        $mixed = $projector->summariseAccessDates([
            $this->grant(5, 'active', 5, 'order', 77, 'post', '5', null),
            $this->grant(6, 'active', 5, 'order', 77, 'post', '6', '2026-06-01 00:00:00'),
        ]);

        self::assertSame(['kind' => 'lifetime', 'expires_at' => null], $lifetime);
        self::assertSame(['kind' => 'fixed', 'expires_at' => '2026-06-01 00:00:00'], $shared);
        self::assertSame(['kind' => 'varies', 'expires_at' => null], $mixed);
    }

    public function test_it_keeps_separate_purchase_episodes_separate(): void
    {
        $projector = new MembershipAccountProjector();

        $result = $projector->project([
            $this->grant(10, 'expired', 5, 'order', 77, 'post', '101'),
            $this->grant(11, 'expired', 5, 'order', 78, 'post', '102'),
        ]);

        self::assertCount(2, $result['history']);
        self::assertSame([77, 78], array_column($result['history'], 'source_id'));
    }

    private function grant(
        int $id,
        string $status,
        ?int $planId,
        string $sourceType,
        int $sourceId,
        string $resourceType,
        string $resourceId,
        ?string $expiresAt = '2026-04-22 00:00:00'
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
            'expires_at' => $expiresAt,
            'starts_at' => '2026-03-01 00:00:00',
            'created_at' => '2026-03-01 00:00:00',
            'updated_at' => '2026-04-22 00:00:00',
        ];
    }
}

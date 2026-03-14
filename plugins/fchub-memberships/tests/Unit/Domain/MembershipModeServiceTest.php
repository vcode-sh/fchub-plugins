<?php

declare(strict_types=1);

namespace FChubMemberships\Tests\Unit\Domain;

use FChubMemberships\Domain\MembershipModeService;
use FChubMemberships\Storage\GrantRepository;
use FChubMemberships\Storage\PlanRepository;
use FChubMemberships\Tests\Unit\PluginTestCase;

final class MembershipModeServiceTest extends PluginTestCase
{
    public function test_upgrade_only_blocks_downgrade(): void
    {
        $GLOBALS['_fchub_test_options']['fchub_memberships_settings'] = [
            'membership_mode' => 'upgrade_only',
        ];

        $grants = new class() extends GrantRepository {
            public function __construct()
            {
            }

            public function getUserActivePlanIds(int $userId): array
            {
                return [1, 2];
            }

            public function getHighestActivePlanLevel(int $userId): int
            {
                return 10;
            }
        };

        $plans = new class() extends PlanRepository {
            public function __construct()
            {
            }
        };

        $service = new MembershipModeService($grants, $plans);
        $result = $service->enforce(9, 4, ['level' => 5], [], static fn(): array => []);

        self::assertSame('downgrade_blocked', $result['reason']);
        self::assertTrue($result['blocked']);
    }

    public function test_exclusive_revokes_other_active_plans(): void
    {
        $GLOBALS['_fchub_test_options']['fchub_memberships_settings'] = [
            'membership_mode' => 'exclusive',
        ];

        $grants = new class() extends GrantRepository {
            public function __construct()
            {
            }

            public function getUserActivePlanIds(int $userId): array
            {
                return [7, 8, 9];
            }
        };

        $plans = new class() extends PlanRepository {
            public function __construct()
            {
            }
        };

        $service = new MembershipModeService($grants, $plans);
        $revoked = [];
        $service->enforce(5, 9, ['level' => 20], [], static function (int $userId, int $planId, array $context) use (&$revoked): array {
            $revoked[] = [$userId, $planId, $context['reason']];
            return [];
        });

        self::assertCount(2, $revoked);
        self::assertSame([5, 7, 'Replaced by plan #9 (exclusive mode)'], $revoked[0]);
        self::assertSame([5, 8, 'Replaced by plan #9 (exclusive mode)'], $revoked[1]);
    }
}

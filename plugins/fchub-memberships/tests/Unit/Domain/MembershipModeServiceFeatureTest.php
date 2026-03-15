<?php

declare(strict_types=1);

namespace FChubMemberships\Tests\Unit\Domain;

use FChubMemberships\Domain\MembershipModeService;
use FChubMemberships\Storage\GrantRepository;
use FChubMemberships\Storage\PlanRepository;
use FChubMemberships\Tests\Unit\PluginTestCase;

final class MembershipModeServiceFeatureTest extends PluginTestCase
{
    public function test_membership_mode_service_covers_stack_exclusive_and_upgrade_paths(): void
    {
        $revokes = [];

        $grants = new class extends GrantRepository {
            public function getUserActivePlanIds(int $userId): array
            {
                return [5, 9];
            }

            public function getHighestActivePlanLevel(int $userId): int
            {
                return 20;
            }
        };

        $plans = new class extends PlanRepository {
            public function find(int $id): ?array
            {
                return match ($id) {
                    5 => ['id' => 5, 'level' => 10],
                    9 => ['id' => 9, 'level' => 5],
                    default => null,
                };
            }
        };

        $service = new MembershipModeService($grants, $plans);

        $GLOBALS['_fchub_test_options']['fchub_memberships_settings']['membership_mode'] = 'stack';
        self::assertNull($service->enforce(21, 5, ['id' => 5, 'level' => 10], [], static function (): array {
            return [];
        }));

        $GLOBALS['_fchub_test_options']['fchub_memberships_settings']['membership_mode'] = 'exclusive';
        $service->enforce(21, 5, ['id' => 5, 'level' => 10], [], static function (int $userId, int $planId, array $context) use (&$revokes): array {
            $revokes[] = [$userId, $planId, $context];
            return ['revoked' => 1];
        });

        $GLOBALS['_fchub_test_options']['fchub_memberships_settings']['membership_mode'] = 'upgrade_only';
        $blocked = $service->enforce(21, 5, ['id' => 5, 'level' => 10], [], static function (): array {
            return [];
        });
        $upgraded = $service->enforce(21, 50, ['id' => 50, 'level' => 30], [], static function (int $userId, int $planId, array $context) use (&$revokes): array {
            $revokes[] = [$userId, $planId, $context];
            return ['revoked' => 1];
        });

        self::assertNotEmpty($revokes);
        self::assertTrue($blocked['blocked']);
        self::assertSame('downgrade_blocked', $blocked['reason']);
        self::assertNull($upgraded);
    }
}

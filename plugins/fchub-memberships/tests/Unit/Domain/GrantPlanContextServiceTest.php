<?php

declare(strict_types=1);

namespace FChubMemberships\Tests\Unit\Domain;

use FChubMemberships\Domain\GrantPlanContextService;
use FChubMemberships\Storage\GrantRepository;
use FChubMemberships\Storage\PlanRepository;
use FChubMemberships\Tests\Unit\PluginTestCase;

final class GrantPlanContextServiceTest extends PluginTestCase
{
    public function test_resolve_adds_trial_for_first_time_grant(): void
    {
        $plans = new class() extends PlanRepository {
            public function __construct()
            {
            }

            public function find(int $id): ?array
            {
                return [
                    'id' => $id,
                    'trial_days' => 14,
                    'duration_type' => 'lifetime',
                ];
            }
        };

        $grants = new class() extends GrantRepository {
            public function __construct()
            {
            }

            public function getByUserId(int $userId, array $filters = []): array
            {
                return [];
            }
        };

        $service = new GrantPlanContextService($plans, $grants);
        $result = $service->resolve(3, 11, []);

        self::assertTrue($result['context']['is_trial']);
        self::assertArrayHasKey('trial_ends_at', $result['context']);
    }

    public function test_resolve_sets_fixed_duration_expiry_when_missing(): void
    {
        $plans = new class() extends PlanRepository {
            public function __construct()
            {
            }

            public function find(int $id): ?array
            {
                return [
                    'id' => $id,
                    'trial_days' => 0,
                    'duration_type' => 'fixed_days',
                    'duration_days' => 30,
                ];
            }
        };

        $grants = new class() extends GrantRepository {
            public function __construct()
            {
            }
        };

        $service = new GrantPlanContextService($plans, $grants);
        $result = $service->resolve(3, 11, []);

        self::assertArrayHasKey('expires_at', $result['context']);
        self::assertNotNull($result['context']['expires_at']);
    }
}

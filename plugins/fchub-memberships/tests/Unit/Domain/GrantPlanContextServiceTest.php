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

    // --- Membership Term tests ---

    public function test_resolve_injects_term_ends_at_for_lifetime_plan(): void
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
                    'duration_type' => 'lifetime',
                    'meta' => ['membership_term' => ['mode' => '1y']],
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

        // Lifetime plan with 1y term should get an expires_at
        self::assertNotNull($result['context']['expires_at']);
        self::assertArrayHasKey('membership_term_ends_at', $result['context']['meta']);
        // expires_at should equal the term end date
        self::assertSame(
            $result['context']['meta']['membership_term_ends_at'],
            $result['context']['expires_at']
        );
    }

    public function test_resolve_caps_fixed_days_at_term_end(): void
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
                    'duration_days' => 36500, // 100 years of days
                    'meta' => ['membership_term' => ['mode' => '1y']],
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

        // fixed_days gives ~100 years, but term caps at 1y
        self::assertNotNull($result['context']['expires_at']);
        self::assertSame(
            $result['context']['meta']['membership_term_ends_at'],
            $result['context']['expires_at']
        );
    }

    public function test_resolve_skips_term_when_mode_is_none(): void
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
                    'duration_type' => 'lifetime',
                    'meta' => ['membership_term' => ['mode' => 'none']],
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

        // mode=none should not inject term
        self::assertNull($result['context']['expires_at']);
        self::assertArrayNotHasKey('meta', $result['context']);
    }
}

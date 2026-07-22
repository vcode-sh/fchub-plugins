<?php

declare(strict_types=1);

namespace FChubMemberships\Tests\Unit\Domain\Grant;

use FChubMemberships\Domain\Grant\GrantCreationService;
use FChubMemberships\Domain\Grant\GrantRevocationService;
use FChubMemberships\Domain\Grant\PlanGrantExecutionService;
use FChubMemberships\Domain\GrantAdapterRegistry;
use FChubMemberships\Domain\GrantNotificationService;
use FChubMemberships\Domain\GrantPlanContextService;
use FChubMemberships\Domain\MembershipModeService;
use FChubMemberships\Domain\Plan\PlanRuleResolver;
use FChubMemberships\Storage\DripScheduleRepository;
use FChubMemberships\Storage\GrantRepository;
use FChubMemberships\Storage\GrantSourceRepository;
use FChubMemberships\Storage\PlanRepository;
use FChubMemberships\Tests\Unit\PluginTestCase;

final class GrantLifetimePreservationTest extends PluginTestCase
{
    public function test_plan_grant_explicit_lifetime_preservation_clears_existing_expiry(): void
    {
        $repository = new LifetimePreservationGrantRepository();
        $service = $this->planGrantService($repository);

        $result = $service->grantPlan(21, 8, [
            'source_type' => 'automation',
            'expires_at' => null,
            'preserve_expiry' => true,
        ]);

        self::assertSame(1, $result['updated']);
        self::assertArrayHasKey('expires_at', $repository->lastUpdate);
        self::assertNull($repository->lastUpdate['expires_at']);
        self::assertNull($repository->grant['expires_at']);
    }

    public function test_ordinary_null_grant_context_does_not_clear_existing_expiry(): void
    {
        $repository = new LifetimePreservationGrantRepository();
        $creation = $this->creationService($repository);

        $result = $creation->grantResource(21, 'lifetime_test', 'course', '42', [
            'plan_id' => 8,
            'source_type' => 'automation',
            'expires_at' => null,
        ]);

        self::assertSame('updated', $result['action']);
        self::assertArrayNotHasKey('expires_at', $repository->lastUpdate);
        self::assertSame('2026-09-01 00:00:00', $repository->grant['expires_at']);
    }

    private function planGrantService(LifetimePreservationGrantRepository $repository): PlanGrantExecutionService
    {
        $plans = new class extends PlanRepository {
            public function __construct()
            {
            }

            public function find(int $id): ?array
            {
                return [
                    'id' => $id,
                    'title' => 'Fixed destination',
                    'trial_days' => 0,
                    'duration_type' => 'fixed_days',
                    'duration_days' => 30,
                    'meta' => [],
                ];
            }
        };
        $rules = new class extends PlanRuleResolver {
            public function resolveUniqueRules(int $planId): array
            {
                return [[
                    'id' => 9,
                    'provider' => 'lifetime_test',
                    'resource_type' => 'course',
                    'resource_id' => '42',
                    'drip_type' => 'immediate',
                ]];
            }
        };
        $modes = new MembershipModeService($repository, $plans);
        $context = new GrantPlanContextService($plans, $repository);
        $revocation = new GrantRevocationService(
            $repository,
            new class extends GrantSourceRepository {},
            new class extends DripScheduleRepository {},
            new GrantAdapterRegistry(['lifetime_test' => LifetimePreservationAdapter::class]),
            new GrantNotificationService($plans)
        );
        $GLOBALS['_fchub_test_options']['fchub_memberships_settings']['email_access_granted'] = 'no';

        return new PlanGrantExecutionService(
            $rules,
            $modes,
            $context,
            $this->creationService($repository),
            $revocation,
            new GrantNotificationService($plans)
        );
    }

    private function creationService(LifetimePreservationGrantRepository $repository): GrantCreationService
    {
        return new GrantCreationService(
            $repository,
            new class extends GrantSourceRepository {},
            new class extends DripScheduleRepository {},
            new GrantAdapterRegistry(['lifetime_test' => LifetimePreservationAdapter::class])
        );
    }
}

final class LifetimePreservationGrantRepository extends GrantRepository
{
    public array $grant = [
        'id' => 31,
        'user_id' => 21,
        'plan_id' => 5,
        'provider' => 'lifetime_test',
        'resource_type' => 'course',
        'resource_id' => '42',
        'source_type' => 'automation',
        'source_ids' => [],
        'renewal_count' => 0,
        'expires_at' => '2026-09-01 00:00:00',
        'meta' => [],
        'status' => 'active',
    ];

    public array $lastUpdate = [];

    public function __construct()
    {
    }

    public function findByGrantKey(string $grantKey): ?array
    {
        return $this->grant;
    }

    public function find(int $id): ?array
    {
        return $this->grant;
    }

    public function update(int $id, array $data): bool
    {
        $this->lastUpdate = $data;
        $this->grant = array_replace($this->grant, $data);
        return true;
    }
}

final class LifetimePreservationAdapter
{
    public function check(int $userId, string $resourceType, string $resourceId): bool
    {
        return true;
    }

    public function grant(int $userId, string $resourceType, string $resourceId, array $context = []): array
    {
        return ['success' => true];
    }
}

<?php

declare(strict_types=1);

namespace FChubMemberships\Tests\Unit\Domain;

use FChubMemberships\Domain\AccessGrantService;
use FChubMemberships\Domain\GrantPlanContextService;
use FChubMemberships\Domain\GrantNotificationService;
use FChubMemberships\Domain\MembershipModeService;
use FChubMemberships\Domain\Plan\PlanRuleResolver;
use FChubMemberships\Storage\DripScheduleRepository;
use FChubMemberships\Storage\EventLockRepository;
use FChubMemberships\Storage\GrantRepository;
use FChubMemberships\Storage\GrantSourceRepository;
use FChubMemberships\Storage\PlanRepository;
use FChubMemberships\Tests\Unit\PluginTestCase;

final class AccessGrantServiceTest extends PluginTestCase
{
    public function test_service_covers_grant_revoke_bulk_and_lock_wrappers(): void
    {
        $lockPayloads = [];

        $grantRepo = new class extends GrantRepository {
            public function getByUserId(int $userId, array $filters = []): array
            {
                if (($filters['plan_id'] ?? null) === 5) {
                    return [[
                        'id' => 100 + $userId,
                        'user_id' => $userId,
                        'plan_id' => 5,
                        'provider' => 'wordpress_core',
                        'resource_type' => 'post',
                        'resource_id' => '55',
                        'source_type' => 'manual',
                        'source_ids' => [],
                        'meta' => [],
                        'status' => 'active',
                    ]];
                }

                if (($filters['status'] ?? null) === 'active') {
                    return [];
                }

                return [];
            }

            public function getBySourceId(int $sourceId, string $sourceType = 'order'): array
            {
                return [[
                    'id' => 200,
                    'user_id' => 9,
                    'plan_id' => 5,
                    'provider' => 'wordpress_core',
                    'resource_type' => 'post',
                    'resource_id' => '55',
                    'source_type' => 'order',
                    'source_ids' => [$sourceId],
                    'meta' => [],
                    'status' => 'active',
                ]];
            }

            public function find(int $id): ?array
            {
                return [
                    'id' => $id,
                    'user_id' => 9,
                    'plan_id' => 5,
                    'provider' => 'wordpress_core',
                    'resource_type' => 'post',
                    'resource_id' => '55',
                    'source_type' => 'manual',
                    'source_ids' => [],
                    'meta' => [],
                    'status' => 'active',
                ];
            }

            public function update(int $id, array $data): bool
            {
                return true;
            }

            public function getOverdueAnchorGrants(): array
            {
                return [['id' => 301, 'meta' => [], 'status' => 'active', 'user_id' => 9]];
            }

            public function getTermExpiredGrants(?string $now = null): array
            {
                return [['id' => 302, 'meta' => [], 'status' => 'active', 'user_id' => 9, 'plan_id' => 5]];
            }

            public function getOverdueGrants(): array
            {
                return [['id' => 303, 'meta' => [], 'status' => 'active', 'user_id' => 9, 'plan_id' => 5]];
            }

            public function expireOverdueGrants(): int
            {
                return 1;
            }

            public function getDueGracePeriodGrants(int $limit = 100): array
            {
                return [[
                    'id' => 304,
                    'user_id' => 9,
                    'plan_id' => 5,
                    'provider' => 'wordpress_core',
                    'resource_type' => 'post',
                    'resource_id' => '55',
                    'meta' => [],
                    'status' => 'active',
                    'cancellation_reason' => 'Expired grace',
                ]];
            }
        };

        $sourceRepo = new class extends GrantSourceRepository {
            public function addSource(int $grantId, string $sourceType, int $sourceId): bool
            {
                return true;
            }

            public function removeSource(int $grantId, string $sourceType, int $sourceId): bool
            {
                return true;
            }

            public function removeAllByGrant(int $grantId): bool
            {
                return true;
            }
        };

        $ruleResolver = new class extends PlanRuleResolver {
            public function resolveUniqueRules(int $planId): array
            {
                return [];
            }
        };

        $dripRepo = new class extends DripScheduleRepository {
            public function deleteByGrantId(int $grantId): int
            {
                return 1;
            }
        };

        $lockRepo = new class($lockPayloads) extends EventLockRepository {
            public function __construct(private array &$payloads)
            {
            }

            public function acquire(array $data): bool
            {
                $this->payloads[] = $data;
                return true;
            }
        };

        $notifications = new GrantNotificationService(new class extends PlanRepository {
            public function find(int $id): ?array
            {
                return null;
            }
        });

        $membershipModes = new MembershipModeService(new class extends GrantRepository {
            public function getUserActivePlanIds(int $userId): array
            {
                return [];
            }
        }, new class extends PlanRepository {
            public function find(int $id): ?array
            {
                return null;
            }
        });

        $planContext = new GrantPlanContextService(new class extends PlanRepository {
            public function find(int $id): ?array
            {
                return ['id' => $id, 'title' => 'Gold Plan', 'trial_days' => 0, 'duration_type' => 'lifetime', 'meta' => []];
            }
        }, new class extends GrantRepository {
            public function getByUserId(int $userId, array $filters = []): array
            {
                return [];
            }
        });

        $service = new AccessGrantService(
            $grantRepo,
            $sourceRepo,
            $ruleResolver,
            $dripRepo,
            $lockRepo,
            $notifications,
            null,
            $membershipModes,
            $planContext
        );

        $grant = $service->grantPlan(9, 5, []);
        $manual = $service->manualGrant(9, 5, '2026-04-01 00:00:00');
        $resource = $service->grantResource(9, 'wordpress_core', 'post', '55', []);
        $revokePlan = $service->revokePlan(9, 5, ['reason' => 'Stop']);
        $revokeSource = $service->revokeBySource(77, 'order', ['reason' => 'Stop']);
        $extend = $service->extendExpiry(9, 5, '2026-05-01 00:00:00', 88);
        $pause = $service->pauseGrant(100, 'Paused');
        $resume = $service->resumeGrant(100);
        $bulkGrant = $service->bulkGrant([9, 10], 5, []);
        $bulkRevoke = $service->bulkRevoke([9, 10], 5, ['reason' => 'Stop']);
        $locked = $service->acquireEventLock(99, 7, 'created', 123);
        $pausedAnchors = $service->pauseOverdueAnchorGrants();
        $termExpired = $service->expireTermExpiredGrants();
        $expired = $service->expireOverdueGrantsWithHooks();
        $grace = $service->revokeExpiredGracePeriodGrants();

        self::assertSame(['created' => 0, 'updated' => 0, 'total' => 0], $grant);
        self::assertSame(['created' => 0, 'updated' => 0, 'total' => 0], $manual);
        self::assertSame('created', $resource['action']);
        self::assertSame(1, $revokePlan['revoked']);
        self::assertSame(1, $revokeSource['revoked']);
        self::assertSame(1, $extend);
        self::assertSame(['success' => true, 'grant_id' => 100], $pause);
        self::assertSame(['success' => true, 'grant_id' => 100], $resume);
        self::assertSame(2, $bulkGrant['granted']);
        self::assertSame(2, $bulkRevoke['revoked']);
        self::assertTrue($locked);
        self::assertSame(1, $pausedAnchors);
        self::assertSame(1, $termExpired);
        self::assertSame(1, $expired);
        self::assertSame(1, $grace);
        self::assertCount(1, $lockPayloads);
        self::assertSame(99, $lockPayloads[0]['order_id']);
    }
}

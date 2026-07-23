<?php

declare(strict_types=1);

namespace FChubMemberships\Tests\Unit\Domain;

use FChubMemberships\Domain\AccessGrantService;
use FChubMemberships\Domain\Event\EventClaimResult;
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
    public function test_bulk_revoke_separates_immediate_deferred_and_failed_users(): void
    {
        $service = new class extends AccessGrantService {
            public function __construct()
            {
            }

            public function revokePlan(int $userId, int $planId, array $context = []): array
            {
                return match ($userId) {
                    9 => ['success' => true, 'revoked' => 1, 'grace_started' => 0, 'retained' => 0, 'failed' => 0],
                    10 => ['success' => true, 'revoked' => 0, 'grace_started' => 1, 'retained' => 0, 'failed' => 0],
                    default => [
                        'success' => false,
                        'revoked' => 0,
                        'grace_started' => 0,
                        'retained' => 0,
                        'failed' => 1,
                        'errors' => [['message' => 'Provider unavailable']],
                    ],
                };
            }
        };

        $result = $service->bulkRevoke([9, 10, 11], 5, ['reason' => 'Owner request']);

        self::assertSame(1, $result['revoked']);
        self::assertSame(1, $result['grace_started']);
        self::assertSame(1, $result['failed']);
        self::assertStringContainsString('Provider unavailable', $result['errors'][0]);
    }

    public function test_service_covers_plan_bulk_maintenance_and_lock_wrappers(): void
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

            public function claim(
                string $eventHash,
                array $context,
                string $ownerToken,
                int $leaseSeconds = 300
            ): EventClaimResult {
                $this->payloads[] = ['claim', $eventHash, $context, $ownerToken, $leaseSeconds];

                return EventClaimResult::acquired();
            }

            public function succeed(string $eventHash, string $ownerToken): bool
            {
                $this->payloads[] = ['succeed', $eventHash, $ownerToken];

                return true;
            }

            public function fail(
                string $eventHash,
                string $ownerToken,
                string $error,
                bool $retryable = true
            ): bool {
                $this->payloads[] = ['fail', $eventHash, $ownerToken, $error, $retryable];

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
        $extend = $service->extendExpiry(9, 5, '2026-05-01 00:00:00', 88);
        $pause = $service->pauseGrant(100, 'Paused');
        $resume = $service->resumeGrant(100);
        $bulkGrant = $service->bulkGrant([9, 10], 5, []);
        self::assertTrue(method_exists(AccessGrantService::class, 'orderEventHash'));
        $eventHash = $service->orderEventHash(99, 'product', 7, 'created', 'grant');
        $renewalHash = $service->subscriptionRenewalEventHash([
            'subscription' => (object) ['id' => 123],
            'order' => (object) ['id' => 456],
        ]);
        $claim = $service->claimOrderEvent(99, 'product', 7, 'created', 'grant', 'owner-a', 120);
        $succeeded = $service->succeedEventLock($eventHash, 'owner-a');
        $failed = $service->failEventLock($eventHash, 'owner-b', 'Broken', false);
        $pausedAnchors = $service->pauseOverdueAnchorGrants();
        $termExpired = $service->expireTermExpiredGrants();
        $expired = $service->expireOverdueGrantsWithHooks();

        self::assertSame(['created' => 0, 'updated' => 0, 'total' => 0], $grant);
        self::assertSame(['created' => 0, 'updated' => 0, 'total' => 0], $manual);
        self::assertSame(1, $extend);
        self::assertSame(['success' => true, 'grant_id' => 100], $pause);
        self::assertSame(['success' => true, 'grant_id' => 100], $resume);
        self::assertSame(2, $bulkGrant['granted']);
        self::assertSame(EventClaimResult::ACQUIRED, $claim->outcome);
        self::assertSame(
            hash('sha256', 'subscription:123|renewal_order:456|trigger:subscription_renewed'),
            $renewalHash
        );
        self::assertTrue($succeeded);
        self::assertTrue($failed);
        self::assertSame(1, $pausedAnchors);
        self::assertSame(1, $termExpired);
        self::assertSame(1, $expired);
        self::assertSame([
            [
                'claim',
                hash('sha256', 'order:99|scope:product|feed:7|trigger:created|mode:grant'),
                ['order_id' => 99, 'feed_id' => 7, 'trigger' => 'created'],
                'owner-a',
                120,
            ],
            ['succeed', $eventHash, 'owner-a'],
            ['fail', $eventHash, 'owner-b', 'Broken', false],
        ], $lockPayloads);
    }
}

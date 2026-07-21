<?php

declare(strict_types=1);

namespace FChubMemberships\Tests\Unit\Domain\Grant;

use FChubMemberships\Domain\AccessGrantService;
use FChubMemberships\Domain\Grant\GrantCreationService;
use FChubMemberships\Domain\Grant\GrantRevocationService;
use FChubMemberships\Domain\GrantAdapterRegistry;
use FChubMemberships\Domain\GrantNotificationService;
use FChubMemberships\Domain\MembershipModeService;
use FChubMemberships\Storage\DripScheduleRepository;
use FChubMemberships\Storage\GrantRepository;
use FChubMemberships\Storage\GrantSourceRepository;
use FChubMemberships\Storage\PlanRepository;
use FChubMemberships\Tests\Unit\PluginTestCase;

final class Task6LifecycleIntegrityTest extends PluginTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Task6FakeAdapter::reset();
    }

    public function test_mixed_plan_revocation_reports_partial_without_plan_success_effects(): void
    {
        Task6FakeAdapter::$access = ['41' => true, '42' => true];
        Task6FakeAdapter::$revokeResults['42'] = [
            'success' => false,
            'message' => 'Provider refused detach',
            'code' => 'remote_failure',
        ];
        $hookCalls = [];
        $GLOBALS['_fchub_test_actions']['fchub_memberships/grant_revoked'] = [
            static function (array $grants) use (&$hookCalls): void {
                $hookCalls[] = array_column($grants, 'id');
            },
        ];
        $GLOBALS['_fchub_test_users'][17] = $this->user(17);

        $service = $this->revocationService([
            $this->grant(71, '41'),
            $this->grant(72, '42'),
        ]);
        $order = new Task6Order();

        $result = $service->revokePlan(17, 5, ['order' => $order]);

        self::assertFalse($result['success']);
        self::assertTrue($result['partial']);
        self::assertSame(1, $result['revoked']);
        self::assertSame(1, $result['failed']);
        self::assertSame('remote_failure', $result['errors'][0]['provider_result']['code']);
        self::assertSame([], $hookCalls);
        self::assertSame([], $GLOBALS['_fchub_test_mails']);
        self::assertSame('Membership plan partially revoked', $order->logs[0][0]);
        self::assertSame('warning', $order->logs[0][2]);
    }

    public function test_failed_plan_revocation_uses_failure_order_log_copy(): void
    {
        Task6FakeAdapter::$access = ['41' => true];
        Task6FakeAdapter::$revokeResults['41'] = [
            'success' => false,
            'message' => 'Provider refused detach',
        ];
        $order = new Task6Order();

        $result = $this->revocationService([
            $this->grant(71, '41'),
        ])->revokePlan(17, 5, ['order' => $order]);

        self::assertSame(0, $result['revoked']);
        self::assertSame(1, $result['failed']);
        self::assertSame('Membership plan revocation failed', $order->logs[0][0]);
        self::assertSame('error', $order->logs[0][2]);
    }

    public function test_mixed_source_revocation_reports_partial_without_plan_success_effects(): void
    {
        Task6FakeAdapter::$access = ['41' => true, '42' => true];
        Task6FakeAdapter::$revokeResults['42'] = ['success' => false, 'message' => 'Remote unavailable'];
        $hookCalls = 0;
        $GLOBALS['_fchub_test_actions']['fchub_memberships/grant_revoked'] = [
            static function () use (&$hookCalls): void {
                $hookCalls++;
            },
        ];
        $GLOBALS['_fchub_test_users'][17] = $this->user(17);

        $repository = new Task6GrantRepository([
            $this->grant(71, '41', ['source_type' => 'order', 'source_ids' => [99]]),
            $this->grant(72, '42', ['source_type' => 'order', 'source_ids' => [99]]),
        ]);
        $service = $this->revocationService($repository->grants, $repository);

        $result = $service->revokeBySource(99, 'order');

        self::assertFalse($result['success']);
        self::assertTrue($result['partial']);
        self::assertSame(1, $result['revoked']);
        self::assertSame(1, $result['failed']);
        self::assertSame(0, $hookCalls);
        self::assertSame([], $GLOBALS['_fchub_test_mails']);
    }

    public function test_mixed_grace_expiry_does_not_emit_plan_success_for_a_failed_plan(): void
    {
        Task6FakeAdapter::$access = ['41' => true, '42' => true];
        Task6FakeAdapter::$revokeResults['42'] = ['success' => false, 'message' => 'Remote unavailable'];
        $hookCalls = 0;
        $GLOBALS['_fchub_test_actions']['fchub_memberships/grant_revoked'] = [
            static function () use (&$hookCalls): void {
                $hookCalls++;
            },
        ];
        $repository = new Task6GrantRepository([
            $this->grant(71, '41', ['cancellation_reason' => 'Grace ended']),
            $this->grant(72, '42', ['cancellation_reason' => 'Grace ended']),
        ]);
        $repository->dueGrants = $repository->grants;

        $revoked = $this->revocationService($repository->grants, $repository)->revokeExpiredGracePeriodGrants();

        self::assertSame(1, $revoked);
        self::assertSame(0, $hookCalls);
    }

    public function test_bulk_wrappers_do_not_count_structured_failures_as_success(): void
    {
        $service = new class extends AccessGrantService {
            public function __construct()
            {
            }

            public function grantPlan(int $userId, int $planId, array $context = []): array
            {
                return $userId === 10
                    ? ['created' => 0, 'updated' => 0, 'total' => 1, 'failed' => 1, 'errors' => [['message' => 'Grant failed']]]
                    : ['created' => 1, 'updated' => 0, 'total' => 1];
            }

            public function revokePlan(int $userId, int $planId, array $context = []): array
            {
                return $userId === 10
                    ? ['success' => false, 'partial' => false, 'revoked' => 0, 'retained' => 0, 'failed' => 1, 'errors' => [['message' => 'Revoke failed']]]
                    : ['success' => true, 'partial' => false, 'revoked' => 1, 'retained' => 0, 'failed' => 0, 'errors' => []];
            }
        };

        $grants = $service->bulkGrant([9, 10], 5);
        $revokes = $service->bulkRevoke([9, 10], 5);

        self::assertSame(1, $grants['granted']);
        self::assertSame(1, $grants['failed']);
        self::assertStringContainsString('Grant failed', $grants['errors'][0]);
        self::assertSame(1, $revokes['revoked']);
        self::assertSame(1, $revokes['failed']);
        self::assertStringContainsString('Revoke failed', $revokes['errors'][0]);
    }

    public function test_exclusive_replacement_aborts_on_revoke_failure_without_success_hook(): void
    {
        $GLOBALS['_fchub_test_options']['fchub_memberships_settings'] = ['membership_mode' => 'exclusive'];
        $hookCalls = 0;
        $GLOBALS['_fchub_test_actions']['fchub_memberships/plan_replaced'] = [
            static function () use (&$hookCalls): void {
                $hookCalls++;
            },
        ];
        $repository = new Task6GrantRepository();
        $repository->activePlanIds = [1, 2, 9];
        $service = new MembershipModeService($repository, new Task6PlanRepository());
        $calls = [];

        $result = $service->enforce(17, 9, ['id' => 9, 'level' => 30], [], static function (int $userId, int $planId) use (&$calls): array {
            $calls[] = $planId;
            return $planId === 2
                ? ['success' => false, 'partial' => false, 'revoked' => 0, 'retained' => 0, 'failed' => 1, 'errors' => [['message' => 'Detach failed']]]
                : ['success' => true, 'partial' => false, 'revoked' => 1, 'retained' => 0, 'failed' => 0, 'errors' => []];
        });

        self::assertSame('replacement_revoke_failed', $result['reason']);
        self::assertTrue($result['blocked']);
        self::assertTrue($result['partial']);
        self::assertSame([1, 2], $calls);
        self::assertSame(0, $hookCalls);
    }

    public function test_upgrade_aborts_on_revoke_failure_without_success_hook(): void
    {
        $GLOBALS['_fchub_test_options']['fchub_memberships_settings'] = ['membership_mode' => 'upgrade_only'];
        $hookCalls = 0;
        $GLOBALS['_fchub_test_actions']['fchub_memberships/plan_upgraded'] = [
            static function () use (&$hookCalls): void {
                $hookCalls++;
            },
        ];
        $repository = new Task6GrantRepository();
        $repository->activePlanIds = [1, 9];
        $repository->highestLevel = 10;
        $plans = new Task6PlanRepository([1 => ['id' => 1, 'level' => 10]]);
        $service = new MembershipModeService($repository, $plans);

        $result = $service->enforce(17, 9, ['id' => 9, 'level' => 30], [], static fn(): array => [
            'success' => false,
            'partial' => false,
            'revoked' => 0,
            'retained' => 0,
            'failed' => 1,
            'errors' => [['message' => 'Detach failed']],
        ]);

        self::assertSame('upgrade_revoke_failed', $result['reason']);
        self::assertTrue($result['blocked']);
        self::assertSame(1, $result['failed']);
        self::assertSame(0, $hookCalls);
    }

    public function test_idempotent_provider_revoke_is_not_compensated_when_local_close_fails(): void
    {
        Task6FakeAdapter::$access = ['41' => false];
        $repository = new Task6GrantRepository([$this->grant(71, '41')]);
        $repository->throwOnUpdate = true;

        $result = $this->revocationService($repository->grants, $repository)->revokePlan(17, 5);

        self::assertSame(1, $result['failed']);
        self::assertSame(1, Task6FakeAdapter::$checkCalls);
        self::assertSame(0, Task6FakeAdapter::$revokeCalls);
        self::assertSame(0, Task6FakeAdapter::$grantCalls);
    }

    public function test_new_grant_source_link_failure_rolls_back_local_and_provider_state(): void
    {
        $repository = new Task6GrantRepository();
        $sources = new Task6SourceRepository();
        $sources->addResult = false;
        $service = $this->creationService($repository, $sources, new Task6DripRepository());

        $result = $service->grantResource(17, 'task6_provider', 'course', '41', [
            'source_type' => 'order',
            'source_id' => 99,
        ]);

        self::assertSame('failed', $result['action']);
        self::assertSame('source_link', $result['persistence_stage']);
        self::assertSame([71], $repository->deletedIds);
        self::assertSame(1, Task6FakeAdapter::$revokeCalls);
    }

    public function test_new_grant_drip_failure_rolls_back_source_local_and_provider_state(): void
    {
        $repository = new Task6GrantRepository();
        $sources = new Task6SourceRepository();
        $drips = new Task6DripRepository();
        $drips->scheduleResult = 0;
        $service = $this->creationService($repository, $sources, $drips);

        $result = $service->grantResource(17, 'task6_provider', 'course', '41', [
            'source_type' => 'order',
            'source_id' => 99,
            'drip_rule' => ['id' => 12, 'drip_type' => 'delayed', 'drip_delay_days' => 2],
        ]);

        self::assertSame('failed', $result['action']);
        self::assertSame('drip_schedule', $result['persistence_stage']);
        self::assertSame([71], $repository->deletedIds);
        self::assertSame([71], $sources->removedGrantIds);
        self::assertSame(1, Task6FakeAdapter::$revokeCalls);
    }

    public function test_renewal_source_link_failure_rolls_back_renewal_without_success_hook(): void
    {
        Task6FakeAdapter::$access = ['41' => false];
        $repository = new Task6GrantRepository([$this->grant(71, '41', ['meta' => []])]);
        $sources = new Task6SourceRepository();
        $sources->addResult = false;
        $hookCalls = 0;
        $GLOBALS['_fchub_test_actions']['fchub_memberships/grant_renewed'] = [
            static function () use (&$hookCalls): void {
                $hookCalls++;
            },
        ];
        $service = $this->creationService($repository, $sources, new Task6DripRepository());

        $result = $service->grantResource(17, 'task6_provider', 'course', '41', [
            'source_type' => 'order',
            'source_id' => 99,
        ]);

        self::assertSame('failed', $result['action']);
        self::assertSame('source_link', $result['persistence_stage']);
        self::assertCount(2, $repository->updates);
        self::assertSame(0, $repository->updates[1][1]['renewal_count']);
        self::assertSame(1, Task6FakeAdapter::$revokeCalls);
        self::assertSame(0, $hookCalls);
    }

    private function creationService(
        Task6GrantRepository $grants,
        Task6SourceRepository $sources,
        Task6DripRepository $drips
    ): GrantCreationService {
        return new GrantCreationService(
            $grants,
            $sources,
            $drips,
            new GrantAdapterRegistry(['task6_provider' => Task6FakeAdapter::class])
        );
    }

    private function revocationService(array $grants, ?Task6GrantRepository $repository = null): GrantRevocationService
    {
        $repository ??= new Task6GrantRepository($grants);
        $plans = new Task6PlanRepository([5 => ['id' => 5, 'title' => 'Gold Plan']]);

        return new GrantRevocationService(
            $repository,
            new Task6SourceRepository(),
            new Task6DripRepository(),
            new GrantAdapterRegistry(['task6_provider' => Task6FakeAdapter::class]),
            new GrantNotificationService($plans)
        );
    }

    private function grant(int $id, string $resourceId, array $overrides = []): array
    {
        return array_merge([
            'id' => $id,
            'user_id' => 17,
            'plan_id' => 5,
            'provider' => 'task6_provider',
            'resource_type' => 'course',
            'resource_id' => $resourceId,
            'source_type' => 'manual',
            'source_ids' => [],
            'renewal_count' => 0,
            'expires_at' => null,
            'meta' => ['provider_access_owner' => 'fchub'],
            'status' => 'active',
        ], $overrides);
    }

    private function user(int $id): \WP_User
    {
        $user = new \WP_User();
        $user->ID = $id;
        $user->display_name = 'Test Member';
        $user->user_email = 'member@example.com';
        $user->user_login = 'member';
        return $user;
    }
}

final class Task6FakeAdapter
{
    /** @var array<string, bool> */
    public static array $access = [];
    /** @var array<string, array<string, mixed>> */
    public static array $revokeResults = [];
    public static int $checkCalls = 0;
    public static int $grantCalls = 0;
    public static int $revokeCalls = 0;

    public static function reset(): void
    {
        self::$access = [];
        self::$revokeResults = [];
        self::$checkCalls = 0;
        self::$grantCalls = 0;
        self::$revokeCalls = 0;
    }

    public function check(int $userId, string $resourceType, string $resourceId): bool
    {
        self::$checkCalls++;
        return self::$access[$resourceId] ?? false;
    }

    public function grant(int $userId, string $resourceType, string $resourceId, array $context = []): array
    {
        self::$grantCalls++;
        self::$access[$resourceId] = true;
        return ['success' => true, 'message' => 'Granted'];
    }

    public function revoke(int $userId, string $resourceType, string $resourceId, array $context = []): array
    {
        self::$revokeCalls++;
        $result = self::$revokeResults[$resourceId] ?? ['success' => true, 'message' => 'Revoked'];
        if (!empty($result['success'])) {
            self::$access[$resourceId] = false;
        }
        return $result;
    }
}

final class Task6GrantRepository extends GrantRepository
{
    /** @var list<array<string, mixed>> */
    public array $grants;
    /** @var list<array{int, array<string, mixed>}> */
    public array $updates = [];
    /** @var list<int> */
    public array $deletedIds = [];
    /** @var list<array<string, mixed>> */
    public array $dueGrants = [];
    /** @var list<int> */
    public array $activePlanIds = [];
    public int $highestLevel = 0;
    public bool $throwOnUpdate = false;

    public function __construct(array $grants = [])
    {
        $this->grants = $grants;
    }

    public function findByGrantKey(string $grantKey): ?array
    {
        return $this->grants[0] ?? null;
    }

    public function create(array $data): int
    {
        return 71;
    }

    public function update(int $id, array $data): bool
    {
        if ($this->throwOnUpdate) {
            throw new \RuntimeException('Database unavailable');
        }
        $this->updates[] = [$id, $data];
        return true;
    }

    public function delete(int $id): bool
    {
        $this->deletedIds[] = $id;
        return true;
    }

    public function getByUserId(int $userId, array $filters = []): array
    {
        return $this->grants;
    }

    public function getBySourceId(int $sourceId, string $sourceType = 'order'): array
    {
        return $this->grants;
    }

    public function getDueGracePeriodGrants(int $limit = 100): array
    {
        return $this->dueGrants;
    }

    public function getUserActivePlanIds(int $userId): array
    {
        return $this->activePlanIds;
    }

    public function getHighestActivePlanLevel(int $userId): int
    {
        return $this->highestLevel;
    }
}

final class Task6SourceRepository extends GrantSourceRepository
{
    public bool $addResult = true;
    /** @var list<int> */
    public array $removedGrantIds = [];

    public function __construct()
    {
    }

    public function addSource(int $grantId, string $sourceType, int $sourceId): bool
    {
        return $this->addResult;
    }

    public function removeSource(int $grantId, string $sourceType, int $sourceId): bool
    {
        return true;
    }

    public function removeAllByGrant(int $grantId): bool
    {
        $this->removedGrantIds[] = $grantId;
        return true;
    }
}

final class Task6DripRepository extends DripScheduleRepository
{
    public int $scheduleResult = 81;

    public function __construct()
    {
    }

    public function schedule(array $data): int
    {
        return $this->scheduleResult;
    }

    public function deleteByGrantId(int $grantId): int
    {
        return 1;
    }
}

final class Task6PlanRepository extends PlanRepository
{
    /** @param array<int, array<string, mixed>> $plans */
    public function __construct(private array $plans = [])
    {
    }

    public function find(int $id): ?array
    {
        return $this->plans[$id] ?? null;
    }
}

final class Task6Order
{
    /** @var list<array{string, string, string, string}> */
    public array $logs = [];

    public function addLog(string $title, string $description, string $type, string $module): void
    {
        $this->logs[] = [$title, $description, $type, $module];
    }
}

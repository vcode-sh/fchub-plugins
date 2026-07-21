<?php

declare(strict_types=1);

namespace FChubMemberships\Tests\Unit\Domain\Grant;

use FChubMemberships\Domain\Grant\GrantCreationService;
use FChubMemberships\Domain\Grant\GrantRevocationService;
use FChubMemberships\Domain\GrantAdapterRegistry;
use FChubMemberships\Domain\GrantNotificationService;
use FChubMemberships\Storage\DripScheduleRepository;
use FChubMemberships\Storage\GrantRepository;
use FChubMemberships\Storage\GrantSourceRepository;
use FChubMemberships\Tests\Unit\PluginTestCase;

final class ProviderFailureReconciliationTest extends PluginTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        ReconciliationFakeAdapter::reset();
    }

    public function test_failed_grant_that_created_access_is_compensated(): void
    {
        ReconciliationFakeAdapter::$grantBehavior = 'mutate_fail';
        $repository = new ReconciliationGrantRepository();

        $result = $this->creationService($repository)->grantResource(17, 'reconciliation', 'course', '41');

        self::assertSame('failed', $result['action']);
        self::assertSame('grant_failed', $result['provider_result']['code']);
        self::assertArrayHasKey('compensation', $result);
        if (!isset($result['compensation'])) {
            return;
        }
        self::assertTrue($result['compensation']['attempted']);
        self::assertTrue($result['compensation']['success']);
        self::assertFalse(ReconciliationFakeAdapter::$access);
        self::assertSame(2, ReconciliationFakeAdapter::$checkCalls);
        self::assertSame(1, ReconciliationFakeAdapter::$revokeCalls);
        self::assertSame([], $repository->created);
    }

    public function test_throwing_grant_that_created_access_is_compensated_without_losing_original_error(): void
    {
        ReconciliationFakeAdapter::$grantBehavior = 'mutate_throw';

        $result = $this->creationService(new ReconciliationGrantRepository())
            ->grantResource(17, 'reconciliation', 'course', '41');

        self::assertSame('failed', $result['action']);
        self::assertSame('Grant exploded after mutation', $result['provider_result']['message']);
        self::assertSame(\RuntimeException::class, $result['provider_result']['exception']);
        self::assertArrayHasKey('compensation', $result);
        if (!isset($result['compensation'])) {
            return;
        }
        self::assertTrue($result['compensation']['success']);
        self::assertFalse(ReconciliationFakeAdapter::$access);
    }

    public function test_failed_grant_exposes_failed_compensation_and_original_provider_error(): void
    {
        ReconciliationFakeAdapter::$grantBehavior = 'mutate_fail';
        ReconciliationFakeAdapter::$revokeBehavior = 'fail';

        $result = $this->creationService(new ReconciliationGrantRepository())
            ->grantResource(17, 'reconciliation', 'course', '41');

        self::assertSame('grant_failed', $result['provider_result']['code']);
        self::assertArrayHasKey('compensation', $result);
        if (!isset($result['compensation'])) {
            return;
        }
        self::assertTrue($result['compensation']['attempted']);
        self::assertFalse($result['compensation']['success']);
        self::assertSame('Compensation revoke failed', $result['compensation']['message']);
        self::assertTrue(ReconciliationFakeAdapter::$access);
        self::assertFalse($result['reconciliation']['success']);
    }

    public function test_failed_grant_post_check_error_leaves_state_unresolved_without_blind_compensation(): void
    {
        ReconciliationFakeAdapter::$grantBehavior = 'mutate_fail';
        ReconciliationFakeAdapter::$throwOnCheckCalls = [2];

        $result = $this->creationService(new ReconciliationGrantRepository())
            ->grantResource(17, 'reconciliation', 'course', '41');

        self::assertSame('grant_failed', $result['provider_result']['code']);
        self::assertArrayHasKey('reconciliation', $result);
        if (!isset($result['reconciliation'])) {
            return;
        }
        self::assertFalse($result['reconciliation']['success']);
        self::assertSame('post_failure_check', $result['reconciliation']['stage']);
        self::assertSame('Provider state check failed', $result['reconciliation']['message']);
        self::assertFalse($result['compensation']['attempted']);
        self::assertFalse($result['compensation']['success']);
        self::assertSame(0, ReconciliationFakeAdapter::$revokeCalls);
    }

    public function test_grant_pre_check_error_is_actionable_and_does_not_call_provider_grant(): void
    {
        ReconciliationFakeAdapter::$throwOnCheckCalls = [1];

        $result = $this->creationService(new ReconciliationGrantRepository())
            ->grantResource(17, 'reconciliation', 'course', '41');

        self::assertSame('failed', $result['action']);
        self::assertSame('precheck', $result['provider_result']['stage']);
        self::assertSame('Provider state check failed', $result['provider_result']['message']);
        self::assertSame(0, ReconciliationFakeAdapter::$grantCalls);
    }

    public function test_revoke_plan_restores_access_after_mutating_failure_and_keeps_local_grant_active(): void
    {
        ReconciliationFakeAdapter::$access = true;
        ReconciliationFakeAdapter::$revokeBehavior = 'mutate_fail';
        $repository = new ReconciliationGrantRepository([$this->grant()]);

        $result = $this->revocationService($repository)->revokePlan(17, 5);

        self::assertSame(0, $result['revoked']);
        self::assertSame(1, $result['failed']);
        self::assertSame('revoke_failed', $result['errors'][0]['provider_result']['code']);
        self::assertArrayHasKey('compensation', $result['errors'][0]);
        if (!isset($result['errors'][0]['compensation'])) {
            return;
        }
        self::assertTrue($result['errors'][0]['compensation']['attempted']);
        self::assertTrue($result['errors'][0]['compensation']['success']);
        self::assertTrue(ReconciliationFakeAdapter::$access);
        self::assertSame([], $repository->updates);
    }

    public function test_revoke_by_source_restores_access_after_mutating_throw(): void
    {
        ReconciliationFakeAdapter::$access = true;
        ReconciliationFakeAdapter::$revokeBehavior = 'mutate_throw';
        $repository = new ReconciliationGrantRepository([$this->grant(['source_type' => 'order', 'source_ids' => [99]])]);

        $result = $this->revocationService($repository)->revokeBySource(99, 'order');

        self::assertSame(0, $result['revoked']);
        self::assertSame(1, $result['failed']);
        self::assertSame('Revoke exploded after mutation', $result['errors'][0]['provider_result']['message']);
        self::assertSame(\RuntimeException::class, $result['errors'][0]['provider_result']['exception']);
        self::assertArrayHasKey('compensation', $result['errors'][0]);
        if (!isset($result['errors'][0]['compensation'])) {
            return;
        }
        self::assertTrue($result['errors'][0]['compensation']['success']);
        self::assertTrue(ReconciliationFakeAdapter::$access);
    }

    public function test_grace_expiry_logs_failed_restoration_with_original_provider_error(): void
    {
        ReconciliationFakeAdapter::$access = true;
        ReconciliationFakeAdapter::$revokeBehavior = 'mutate_fail';
        ReconciliationFakeAdapter::$grantBehavior = 'fail';
        $repository = new ReconciliationGrantRepository([$this->grant(['cancellation_reason' => 'Grace ended'])]);
        $repository->dueGrants = $repository->grants;

        $revoked = $this->revocationService($repository)->revokeExpiredGracePeriodGrants();

        self::assertSame(0, $revoked);
        self::assertSame([], $repository->updates);
        self::assertCount(1, $GLOBALS['_fchub_test_fc_error_logs']);
        $context = $GLOBALS['_fchub_test_fc_error_logs'][0][2];
        self::assertSame('revoke_failed', $context['provider_outcome']['provider_result']['code']);
        self::assertTrue($context['provider_outcome']['compensation']['attempted']);
        self::assertFalse($context['provider_outcome']['compensation']['success']);
        self::assertSame('Compensation grant failed', $context['provider_outcome']['compensation']['message']);
        self::assertFalse(ReconciliationFakeAdapter::$access);
    }

    public function test_revoke_post_check_error_keeps_local_grant_active_and_reports_unknown_state(): void
    {
        ReconciliationFakeAdapter::$access = true;
        ReconciliationFakeAdapter::$revokeBehavior = 'mutate_fail';
        ReconciliationFakeAdapter::$throwOnCheckCalls = [2];
        $repository = new ReconciliationGrantRepository([$this->grant()]);

        $result = $this->revocationService($repository)->revokePlan(17, 5);

        self::assertSame(0, $result['revoked']);
        self::assertSame(1, $result['failed']);
        self::assertArrayHasKey('reconciliation', $result['errors'][0]);
        if (!isset($result['errors'][0]['reconciliation'])) {
            return;
        }
        self::assertFalse($result['errors'][0]['reconciliation']['success']);
        self::assertSame('post_failure_check', $result['errors'][0]['reconciliation']['stage']);
        self::assertFalse($result['errors'][0]['compensation']['attempted']);
        self::assertFalse($result['errors'][0]['compensation']['success']);
        self::assertSame(0, ReconciliationFakeAdapter::$grantCalls);
        self::assertSame([], $repository->updates);
    }

    private function creationService(ReconciliationGrantRepository $repository): GrantCreationService
    {
        return new GrantCreationService(
            $repository,
            new ReconciliationSourceRepository(),
            new ReconciliationDripRepository(),
            new GrantAdapterRegistry(['reconciliation' => ReconciliationFakeAdapter::class])
        );
    }

    private function revocationService(ReconciliationGrantRepository $repository): GrantRevocationService
    {
        return new GrantRevocationService(
            $repository,
            new ReconciliationSourceRepository(),
            new ReconciliationDripRepository(),
            new GrantAdapterRegistry(['reconciliation' => ReconciliationFakeAdapter::class]),
            new GrantNotificationService()
        );
    }

    private function grant(array $overrides = []): array
    {
        return array_merge([
            'id' => 71,
            'user_id' => 17,
            'plan_id' => 5,
            'provider' => 'reconciliation',
            'resource_type' => 'course',
            'resource_id' => '41',
            'source_type' => 'manual',
            'source_ids' => [],
            'renewal_count' => 0,
            'expires_at' => null,
            'meta' => ['provider_access_owner' => 'fchub'],
            'status' => 'active',
        ], $overrides);
    }
}

final class ReconciliationFakeAdapter
{
    public static bool $access = false;
    public static string $grantBehavior = 'success';
    public static string $revokeBehavior = 'success';
    /** @var list<int> */
    public static array $throwOnCheckCalls = [];
    public static int $checkCalls = 0;
    public static int $grantCalls = 0;
    public static int $revokeCalls = 0;

    public static function reset(): void
    {
        self::$access = false;
        self::$grantBehavior = 'success';
        self::$revokeBehavior = 'success';
        self::$throwOnCheckCalls = [];
        self::$checkCalls = 0;
        self::$grantCalls = 0;
        self::$revokeCalls = 0;
    }

    public function check(int $userId, string $resourceType, string $resourceId): bool
    {
        self::$checkCalls++;
        if (in_array(self::$checkCalls, self::$throwOnCheckCalls, true)) {
            throw new \RuntimeException('Provider state check failed');
        }
        return self::$access;
    }

    public function grant(int $userId, string $resourceType, string $resourceId, array $context = []): array
    {
        self::$grantCalls++;
        if (str_starts_with(self::$grantBehavior, 'mutate_')) {
            self::$access = true;
        }
        if (self::$grantBehavior === 'mutate_throw' || self::$grantBehavior === 'throw') {
            throw new \RuntimeException('Grant exploded after mutation');
        }
        if (self::$grantBehavior === 'mutate_fail' || self::$grantBehavior === 'fail') {
            return ['success' => false, 'message' => 'Compensation grant failed', 'code' => 'grant_failed'];
        }

        self::$access = true;
        return ['success' => true, 'message' => 'Granted'];
    }

    public function revoke(int $userId, string $resourceType, string $resourceId, array $context = []): array
    {
        self::$revokeCalls++;
        if (str_starts_with(self::$revokeBehavior, 'mutate_')) {
            self::$access = false;
        }
        if (self::$revokeBehavior === 'mutate_throw' || self::$revokeBehavior === 'throw') {
            throw new \RuntimeException('Revoke exploded after mutation');
        }
        if (self::$revokeBehavior === 'mutate_fail' || self::$revokeBehavior === 'fail') {
            return ['success' => false, 'message' => 'Compensation revoke failed', 'code' => 'revoke_failed'];
        }

        self::$access = false;
        return ['success' => true, 'message' => 'Revoked'];
    }
}

final class ReconciliationGrantRepository extends GrantRepository
{
    /** @var list<array<string, mixed>> */
    public array $grants;
    /** @var list<array<string, mixed>> */
    public array $created = [];
    /** @var list<array{int, array<string, mixed>}> */
    public array $updates = [];
    /** @var list<array<string, mixed>> */
    public array $dueGrants = [];

    public function __construct(array $grants = [])
    {
        $this->grants = $grants;
    }

    public function findByGrantKey(string $grantKey): ?array
    {
        return null;
    }

    public function create(array $data): int
    {
        $this->created[] = $data;
        return 71;
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

    public function update(int $id, array $data): bool
    {
        $this->updates[] = [$id, $data];
        return true;
    }
}

final class ReconciliationSourceRepository extends GrantSourceRepository
{
    public function __construct()
    {
    }

    public function removeAllByGrant(int $grantId): bool
    {
        return true;
    }
}

final class ReconciliationDripRepository extends DripScheduleRepository
{
    public function __construct()
    {
    }

    public function deleteByGrantId(int $grantId): int
    {
        return 1;
    }
}

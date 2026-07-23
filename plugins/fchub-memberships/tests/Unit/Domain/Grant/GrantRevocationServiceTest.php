<?php

declare(strict_types=1);

namespace FChubMemberships\Tests\Unit\Domain\Grant;

use FChubMemberships\Domain\Grant\GrantRevocationService;
use FChubMemberships\Domain\GrantAdapterRegistry;
use FChubMemberships\Domain\GrantNotificationService;
use FChubMemberships\Storage\DripScheduleRepository;
use FChubMemberships\Storage\GrantRepository;
use FChubMemberships\Storage\GrantSourceRepository;
use FChubMemberships\Storage\PlanRepository;
use FChubMemberships\Support\Clock;
use FChubMemberships\Tests\Unit\PluginTestCase;

final class GrantRevocationServiceTest extends PluginTestCase
{
    public function test_grace_effective_time_uses_one_site_local_calendar_day(): void
    {
        $repository = new GraceLifecycleGrantRepository([$this->grant(9, [])]);
        $timezone = new \DateTimeZone('Europe/Warsaw');
        $clock = new Clock(new \DateTimeImmutable('2026-03-28 12:30:00', $timezone), $timezone);

        $this->service($repository, $clock)->revokePlan(21, 5, ['grace_period_days' => 1]);

        self::assertSame('2026-03-29 12:30:00', $repository->updates[9]['cancellation_effective_at']);
        self::assertSame('2026-03-28 12:30:00', $repository->updates[9]['cancellation_requested_at']);
    }

    protected function setUp(): void
    {
        parent::setUp();

        GraceLifecycleAdapter::reset();
        $GLOBALS['_fchub_test_options']['admin_email'] = 'admin@example.com';
        $user = new \WP_User();
        $user->ID = 21;
        $user->display_name = 'Alice Example';
        $user->user_email = 'alice@example.com';
        $user->user_login = 'alice';
        $GLOBALS['_fchub_test_users'][21] = $user;
    }

    public function test_retained_and_grace_grants_emit_only_one_deferred_batch_without_terminal_side_effects(): void
    {
        $repository = new GraceLifecycleGrantRepository([
            $this->grant(10, [77, 88]),
            $this->grant(11, [77]),
        ]);
        $graceHooks = [];
        $terminalHooks = [];
        add_action('fchub_memberships/grace_period_started', static function (...$args) use (&$graceHooks): void {
            $graceHooks[] = $args;
        }, 10, 5);
        add_action('fchub_memberships/grant_revoked', static function (...$args) use (&$terminalHooks): void {
            $terminalHooks[] = $args;
        }, 10, 4);
        $order = new GraceLifecycleOrder();

        $result = $this->service($repository)->revokePlan(21, 5, [
            'source_id' => 77,
            'grace_period_days' => 3,
            'reason' => 'Canceled',
            'order' => $order,
        ]);

        self::assertSame(0, $result['revoked']);
        self::assertSame(1, $result['retained']);
        self::assertSame(1, $result['grace_started']);
        self::assertSame(0, $result['failed']);
        self::assertTrue($result['success']);
        self::assertCount(1, $graceHooks);
        self::assertSame([11], array_column($graceHooks[0][0], 'id'));
        self::assertSame([5, 21, 'Canceled'], array_slice($graceHooks[0], 1, 3));
        self::assertSame($repository->updates[11]['cancellation_effective_at'], $graceHooks[0][4]);
        self::assertArrayNotHasKey('status', $repository->updates[11]);
        self::assertSame('active', $repository->statuses[11]);
        self::assertSame([], $terminalHooks);
        self::assertSame([], $GLOBALS['_fchub_test_mails']);
        self::assertStringContainsString('scheduled', strtolower($order->logs[0][0] . ' ' . $order->logs[0][1]));
        self::assertStringNotContainsString('plan revoked', strtolower($order->logs[0][0]));
    }

    public function test_retained_and_immediate_grants_emit_only_the_terminal_payload_and_email_once(): void
    {
        $repository = new GraceLifecycleGrantRepository([
            $this->grant(20, [77, 88]),
            $this->grant(21, [77]),
        ]);
        $terminalHooks = [];
        add_action('fchub_memberships/grant_revoked', static function (...$args) use (&$terminalHooks): void {
            $terminalHooks[] = $args;
        }, 10, 4);

        $result = $this->service($repository)->revokePlan(21, 5, [
            'source_id' => 77,
            'grace_period_days' => 0,
            'reason' => 'Immediate cancellation',
        ]);

        self::assertSame(1, $result['revoked']);
        self::assertSame(1, $result['retained']);
        self::assertSame(0, $result['grace_started']);
        self::assertCount(1, $terminalHooks);
        self::assertSame([21], array_column($terminalHooks[0][0], 'id'));
        self::assertSame([5, 21, 'Immediate cancellation'], array_slice($terminalHooks[0], 1));
        self::assertCount(1, $GLOBALS['_fchub_test_mails']);
    }

    public function test_expired_grace_revokes_provider_and_emits_one_terminal_batch_and_email(): void
    {
        $grant = $this->grant(30, [], 'Expired grace');
        $grant['meta']['provider_access_owner'] = 'fchub';
        $repository = new GraceLifecycleGrantRepository([], [$grant]);
        $terminalHooks = [];
        add_action('fchub_memberships/grant_revoked', static function (...$args) use (&$terminalHooks): void {
            $terminalHooks[] = $args;
        }, 10, 4);

        $revoked = $this->service($repository)->revokeExpiredGracePeriodGrants();

        self::assertSame(1, $revoked);
        self::assertSame(1, GraceLifecycleAdapter::$checkCalls);
        self::assertSame(1, GraceLifecycleAdapter::$revokeCalls);
        self::assertCount(1, $terminalHooks);
        self::assertSame([30], array_column($terminalHooks[0][0], 'id'));
        self::assertSame([5, 21, 'Expired grace'], array_slice($terminalHooks[0], 1));
        self::assertCount(1, $GLOBALS['_fchub_test_mails']);
        self::assertStringContainsString('Expired grace', $GLOBALS['_fchub_test_mails'][0][2]);
    }

    private function service(GraceLifecycleGrantRepository $repository, ?Clock $clock = null): GrantRevocationService
    {
        return new GrantRevocationService(
            $repository,
            new GraceLifecycleSourceRepository(),
            new GraceLifecycleDripRepository(),
            new GrantAdapterRegistry(['grace_lifecycle' => GraceLifecycleAdapter::class]),
            new GrantNotificationService(new GraceLifecyclePlanRepository()),
            $clock
        );
    }

    private function grant(int $id, array $sourceIds, string $reason = ''): array
    {
        return [
            'id' => $id,
            'user_id' => 21,
            'plan_id' => 5,
            'provider' => 'grace_lifecycle',
            'resource_type' => 'course',
            'resource_id' => (string) $id,
            'source_type' => 'order',
            'source_ids' => $sourceIds,
            'meta' => [],
            'status' => 'active',
            'cancellation_reason' => $reason,
        ];
    }
}

final class GraceLifecycleGrantRepository extends GrantRepository
{
    /** @var array<int, array<string, mixed>> */
    public array $updates = [];

    /** @var array<int, string> */
    public array $statuses = [];

    public function __construct(
        private array $grants = [],
        private array $dueGrants = []
    ) {
        foreach (array_merge($grants, $dueGrants) as $grant) {
            $this->statuses[(int) $grant['id']] = (string) $grant['status'];
        }
    }

    public function getByUserId(int $userId, array $filters = []): array
    {
        return $this->grants;
    }

    public function getDueGracePeriodGrants(int $limit = 100): array
    {
        return $this->dueGrants;
    }

    public function update(int $id, array $data): bool
    {
        $this->updates[$id] = $data;
        if (isset($data['status'])) {
            $this->statuses[$id] = (string) $data['status'];
        }
        return true;
    }
}

final class GraceLifecycleSourceRepository extends GrantSourceRepository
{
    public function removeSource(int $grantId, string $sourceType, int $sourceId): bool
    {
        return true;
    }

    public function removeAllByGrant(int $grantId): bool
    {
        return true;
    }
}

final class GraceLifecycleDripRepository extends DripScheduleRepository
{
    public function deleteByGrantId(int $grantId): int
    {
        return 1;
    }
}

final class GraceLifecyclePlanRepository extends PlanRepository
{
    public function find(int $id): ?array
    {
        return ['id' => $id, 'title' => 'Gold Plan'];
    }
}

final class GraceLifecycleAdapter
{
    public static int $checkCalls = 0;
    public static int $revokeCalls = 0;

    public static function reset(): void
    {
        self::$checkCalls = 0;
        self::$revokeCalls = 0;
    }

    public function check(int $userId, string $resourceType, string $resourceId): bool
    {
        self::$checkCalls++;
        return true;
    }

    public function revoke(int $userId, string $resourceType, string $resourceId, array $context = []): array
    {
        self::$revokeCalls++;
        return ['success' => true];
    }

    public function grant(int $userId, string $resourceType, string $resourceId, array $context = []): array
    {
        return ['success' => true];
    }
}

final class GraceLifecycleOrder
{
    /** @var list<array{string, string, string, string}> */
    public array $logs = [];

    public function addLog(string $title, string $description, string $type, string $module): void
    {
        $this->logs[] = [$title, $description, $type, $module];
    }
}

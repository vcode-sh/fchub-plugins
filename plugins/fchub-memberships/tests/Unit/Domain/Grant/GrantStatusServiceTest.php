<?php

declare(strict_types=1);

namespace FChubMemberships\Tests\Unit\Domain\Grant;

use FChubMemberships\Domain\Grant\GrantStatusService;
use FChubMemberships\Domain\Entitlement\EntitlementService;
use FChubMemberships\Domain\GrantNotificationService;
use FChubMemberships\Domain\ProviderOperationOutcome;
use FChubMemberships\Domain\ProviderOperationWorker;
use FChubMemberships\Storage\GrantRepository;
use FChubMemberships\Storage\PlanRepository;
use FChubMemberships\Tests\Unit\PluginTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class GrantStatusServiceTest extends PluginTestCase
{
    public function test_owned_external_pause_and_resume_use_explicit_provider_lifecycle_actions(): void
    {
        $repository = new Task7StatusGrantRepository();
        $entitlements = new Task7StatusEntitlementService();
        $worker = new Task7StatusProviderWorker(ProviderOperationOutcome::applied());
        $notifications = new GrantNotificationService(new class extends PlanRepository {
            public function find(int $id): ?array
            {
                return null;
            }
        });
        $service = new GrantStatusService($repository, $notifications, $entitlements, $worker);

        self::assertTrue($service->pauseGrant(10, 'Payment overdue')['success']);
        self::assertTrue($service->resumeGrant(10)['success']);

        self::assertSame(['suspend', 'resume'], $worker->actions);
        self::assertSame(['paused', 'active'], array_column(array_column($repository->updates, 1), 'status'));
    }

    public function test_retryable_external_suspend_is_visible_and_suppresses_success_effects(): void
    {
        $repository = new Task7StatusGrantRepository();
        $worker = new Task7StatusProviderWorker(ProviderOperationOutcome::retryableFailure(
            'provider_operation_failed',
            'Provider operation failed.'
        ));
        $notifications = new GrantNotificationService(new class extends PlanRepository {
            public function find(int $id): ?array
            {
                return null;
            }
        });
        $emitted = 0;
        add_action('fchub_memberships/grant_paused', static function () use (&$emitted): void {
            $emitted++;
        });

        $result = (new GrantStatusService(
            $repository,
            $notifications,
            new Task7StatusEntitlementService(),
            $worker
        ))->pauseGrant(10, 'Payment overdue');

        self::assertFalse($result['success']);
        self::assertTrue($result['pending']);
        self::assertSame('retryable-failure', $result['provider_outcome']->status);
        self::assertSame(0, $emitted);
        self::assertSame([], $GLOBALS['_fchub_test_mails']);
    }

    public function test_terminal_external_suspend_is_visible_and_suppresses_success_effects(): void
    {
        $repository = new Task7StatusGrantRepository();
        $worker = new Task7StatusProviderWorker(ProviderOperationOutcome::terminalFailure(
            'provider_not_certified',
            'The provider is not certified for automated assignment.'
        ));
        $notifications = new GrantNotificationService(new class extends PlanRepository {
            public function find(int $id): ?array
            {
                return null;
            }
        });
        $emitted = 0;
        add_action('fchub_memberships/grant_paused', static function () use (&$emitted): void {
            $emitted++;
        });

        $result = (new GrantStatusService(
            $repository,
            $notifications,
            new Task7StatusEntitlementService(),
            $worker
        ))->pauseGrant(10, 'Payment overdue');

        self::assertFalse($result['success']);
        self::assertFalse($result['pending']);
        self::assertSame('terminal-failure', $result['provider_outcome']->status);
        self::assertSame('provider_not_certified', $result['provider_outcome']->code);
        self::assertSame(0, $emitted);
        self::assertSame([], $GLOBALS['_fchub_test_mails']);
    }

    public function test_preexisting_assignment_is_preserved_without_provider_operation(): void
    {
        $repository = new Task7StatusGrantRepository();
        $entitlements = new Task7StatusEntitlementService();
        $entitlements->edges[0]['assignment_provenance'] = 'preexisting';
        $worker = new Task7StatusProviderWorker(ProviderOperationOutcome::applied());
        $notifications = new GrantNotificationService(new class extends PlanRepository {
            public function find(int $id): ?array
            {
                return null;
            }
        });

        $result = (new GrantStatusService($repository, $notifications, $entitlements, $worker))
            ->pauseGrant(10, 'Payment overdue');

        self::assertTrue($result['success']);
        self::assertSame([], $worker->actions);
    }

    public function test_pause_and_resume_grant_update_state_and_return_not_found_when_missing(): void
    {
        $updates = [];

        $grantRepo = new class($updates) extends GrantRepository {
            public function __construct(private array &$updates)
            {
            }

            public function find(int $id): ?array
            {
                return match ($id) {
                    10 => ['id' => 10, 'user_id' => 9, 'plan_id' => 5, 'status' => 'active', 'meta' => []],
                    11 => ['id' => 11, 'user_id' => 9, 'plan_id' => 5, 'status' => 'paused', 'meta' => []],
                    default => null,
                };
            }

            public function update(int $id, array $data): bool
            {
                $this->updates[] = [$id, $data];
                return true;
            }
        };

        $notifications = new GrantNotificationService(new class extends PlanRepository {
            public function find(int $id): ?array
            {
                return null;
            }
        });

        $service = new GrantStatusService($grantRepo, $notifications);

        $missing = $service->pauseGrant(99, 'No grant');
        $paused = $service->pauseGrant(10, 'Manual pause');
        $resumed = $service->resumeGrant(11);

        self::assertSame(['error' => 'Grant not found'], $missing);
        self::assertSame(['success' => true, 'grant_id' => 10], $paused);
        self::assertSame(['success' => true, 'grant_id' => 11], $resumed);
        self::assertSame('paused', $updates[0][1]['status']);
        self::assertSame('Manual pause', $updates[0][1]['meta']['pause_reason']);
        self::assertSame('active', $updates[1][1]['status']);
        self::assertArrayHasKey('resumed_at', $updates[1][1]['meta']);
    }

    #[DataProvider('failedStatusMutations')]
    public function test_repository_update_failure_is_reported_without_emitting_success_side_effects(
        string $method,
        string $initialStatus,
        string $hook
    ): void {
        $grantRepo = new class($initialStatus) extends GrantRepository {
            public function __construct(private string $initialStatus)
            {
            }

            public function find(int $id): ?array
            {
                return [
                    'id' => $id,
                    'user_id' => 9,
                    'plan_id' => 5,
                    'status' => $this->initialStatus,
                    'meta' => [],
                ];
            }

            public function update(int $id, array $data): bool
            {
                return false;
            }
        };
        $notifications = new GrantNotificationService(new class extends PlanRepository {
            public function find(int $id): ?array
            {
                return ['id' => $id, 'title' => 'Gold'];
            }
        });
        $emitted = false;
        add_action($hook, static function () use (&$emitted): void {
            $emitted = true;
        });

        $result = $method === 'pauseGrant'
            ? (new GrantStatusService($grantRepo, $notifications))->pauseGrant(10, 'Manual pause')
            : (new GrantStatusService($grantRepo, $notifications))->resumeGrant(10);

        self::assertFalse($result['success'] ?? true);
        self::assertSame('Grant update failed', $result['error'] ?? null);
        self::assertFalse($emitted);
        self::assertSame([], $GLOBALS['_fchub_test_mails']);
    }

    public static function failedStatusMutations(): array
    {
        return [
            'pause' => ['pauseGrant', 'active', 'fchub_memberships/grant_paused'],
            'resume' => ['resumeGrant', 'paused', 'fchub_memberships/grant_resumed'],
        ];
    }
}

final class Task7StatusGrantRepository extends GrantRepository
{
    public array $updates = [];
    private array $grant = [
        'id' => 10,
        'user_id' => 9,
        'plan_id' => 5,
        'status' => 'active',
        'provider' => 'fluentcrm',
        'resource_type' => 'fluentcrm_tag',
        'resource_id' => '41',
        'meta' => [],
    ];

    public function __construct()
    {
    }

    public function find(int $id): ?array
    {
        return $this->grant;
    }

    public function update(int $id, array $data): bool
    {
        $this->updates[] = [$id, $data];
        $this->grant = array_replace($this->grant, $data);
        return true;
    }
}

final class Task7StatusEntitlementService extends EntitlementService
{
    public array $edges = [[
        'id' => 7,
        'user_id' => 9,
        'provider' => 'fluentcrm',
        'resource_type' => 'fluentcrm_tag',
        'resource_id' => '41',
        'owner' => 'fchub',
        'assignment_provenance' => 'fchub_created',
        'lifecycle' => 'active',
    ]];

    public function __construct()
    {
    }

    public function getActiveByResource(array $edge): array
    {
        return $this->edges;
    }
}

final class Task7StatusProviderWorker extends ProviderOperationWorker
{
    public array $actions = [];

    public function __construct(private ProviderOperationOutcome $outcome)
    {
    }

    public function enqueue(
        int $edgeId,
        string $desiredAction,
        string $originEvent,
        ?\DateTimeImmutable $eligibleAt = null
    ): ?array {
        $this->actions[] = $desiredAction;
        return ['id' => count($this->actions), 'state' => 'pending'];
    }

    public function process(int $operationId): ProviderOperationOutcome
    {
        return $this->outcome;
    }
}

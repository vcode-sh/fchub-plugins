<?php

declare(strict_types=1);

namespace FChubMemberships\Tests\Unit\Domain\Grant;

use FChubMemberships\Domain\Grant\GrantStatusService;
use FChubMemberships\Domain\GrantNotificationService;
use FChubMemberships\Storage\GrantRepository;
use FChubMemberships\Storage\PlanRepository;
use FChubMemberships\Tests\Unit\PluginTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class GrantStatusServiceTest extends PluginTestCase
{
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

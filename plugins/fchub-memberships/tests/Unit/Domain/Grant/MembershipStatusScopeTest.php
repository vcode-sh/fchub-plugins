<?php

declare(strict_types=1);

namespace FChubMemberships\Tests\Unit\Domain\Grant;

use FChubMemberships\Domain\GrantNotificationService;
use FChubMemberships\Domain\Grant\GrantStatusService;
use FChubMemberships\Storage\GrantRepository;
use FChubMemberships\Storage\PlanRepository;
use FChubMemberships\Tests\Unit\PluginTestCase;

/**
 * A plan writes one grant row per rule, so a status change asked for on one row
 * has to reach the whole membership. Half-paused access is not a state anyone
 * asked for.
 */
final class MembershipStatusScopeTest extends PluginTestCase
{
    public function test_pausing_one_row_pauses_every_row_of_the_membership(): void
    {
        $updates = [];
        $service = $this->service($this->rows([
            ['id' => 10, 'status' => 'active'],
            ['id' => 11, 'status' => 'active'],
        ], $updates));

        $result = $service->pauseGrant(10, 'Payment overdue');

        self::assertSame(['success' => true, 'grant_id' => 10], $result);
        self::assertSame([10, 11], array_column($updates, 0));
        self::assertSame(['paused', 'paused'], array_column(array_column($updates, 1), 'status'));
    }

    public function test_resuming_one_row_resumes_every_paused_row_of_the_membership(): void
    {
        $updates = [];
        $service = $this->service($this->rows([
            ['id' => 10, 'status' => 'paused'],
            ['id' => 11, 'status' => 'paused'],
        ], $updates));

        $result = $service->resumeGrant(10);

        self::assertSame(['success' => true, 'grant_id' => 10], $result);
        self::assertSame([10, 11], array_column($updates, 0));
    }

    public function test_a_row_already_in_the_target_state_is_left_alone(): void
    {
        $updates = [];
        $service = $this->service($this->rows([
            ['id' => 10, 'status' => 'active'],
            ['id' => 11, 'status' => 'paused'],
        ], $updates));

        $service->pauseGrant(10, '');

        self::assertSame([10], array_column($updates, 0));
    }

    public function test_a_revoked_row_is_not_dragged_back_into_the_membership_transition(): void
    {
        $updates = [];
        $service = $this->service($this->rows([
            ['id' => 10, 'status' => 'active'],
            ['id' => 11, 'status' => 'revoked'],
        ], $updates));

        $result = $service->pauseGrant(10, '');

        self::assertTrue($result['success']);
        self::assertSame([10], array_column($updates, 0));
    }

    public function test_a_plan_less_grant_stays_a_membership_of_one(): void
    {
        $updates = [];
        $service = $this->service($this->rows([
            ['id' => 10, 'status' => 'active', 'plan_id' => null],
            ['id' => 11, 'status' => 'active', 'plan_id' => null],
        ], $updates));

        $service->pauseGrant(10, '');

        self::assertSame([10], array_column($updates, 0));
    }

    public function test_a_failing_row_reports_the_failure_and_names_the_row_that_failed(): void
    {
        $updates = [];
        $repository = $this->rows([
            ['id' => 10, 'status' => 'active'],
            ['id' => 11, 'status' => 'active'],
        ], $updates, failingIds: [11]);

        $result = $this->service($repository)->pauseGrant(10, '');

        self::assertFalse($result['success']);
        self::assertSame(11, $result['grant_id']);
    }

    public function test_a_missing_grant_is_still_reported_as_missing(): void
    {
        $updates = [];
        $service = $this->service($this->rows([['id' => 10, 'status' => 'active']], $updates));

        self::assertSame(['error' => 'Grant not found'], $service->pauseGrant(99, ''));
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @param list<array{0:int,1:array<string,mixed>}> $updates
     * @param list<int> $failingIds
     */
    private function rows(array $rows, array &$updates, array $failingIds = []): GrantRepository
    {
        $hydrated = array_map(static fn(array $row): array => array_merge([
            'user_id' => 9,
            'plan_id' => 5,
            'provider' => 'wordpress_core',
            'meta' => [],
        ], $row), $rows);

        return new class($hydrated, $updates, $failingIds) extends GrantRepository {
            /** @param list<array<string, mixed>> $rows */
            public function __construct(private array $rows, private array &$updates, private array $failingIds)
            {
            }

            public function find(int $id): ?array
            {
                foreach ($this->rows as $row) {
                    if ($row['id'] === $id) {
                        return $row;
                    }
                }

                return null;
            }

            public function getByUserId(int $userId, array $filters = []): array
            {
                return array_values(array_filter($this->rows, static function (array $row) use ($userId, $filters): bool {
                    if ($row['user_id'] !== $userId) {
                        return false;
                    }

                    return !isset($filters['plan_id']) || $row['plan_id'] === (int) $filters['plan_id'];
                }));
            }

            public function update(int $id, array $data): bool
            {
                if (in_array($id, $this->failingIds, true)) {
                    return false;
                }

                $this->updates[] = [$id, $data];

                return true;
            }
        };
    }

    private function service(GrantRepository $repository): GrantStatusService
    {
        $notifications = new GrantNotificationService(new class extends PlanRepository {
            public function find(int $id): ?array
            {
                return null;
            }
        });

        return new GrantStatusService($repository, $notifications);
    }
}

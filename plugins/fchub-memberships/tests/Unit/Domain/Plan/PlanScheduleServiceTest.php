<?php

declare(strict_types=1);

namespace FChubMemberships\Tests\Unit\Domain\Plan;

use FChubMemberships\Domain\Plan\PlanScheduleService;
use FChubMemberships\Domain\Plan\PlanService;
use FChubMemberships\Tests\Unit\PluginTestCase;

final class PlanScheduleServiceTest extends PluginTestCase
{
    public function test_save_normalizes_legacy_draft_status_when_scheduling(): void
    {
        $service = new PlanScheduleService(new class() extends PlanService {
            public string $savedStatus = '';

            public function __construct()
            {
            }

            public function find(int $id): ?array
            {
                return ['id' => $id, 'title' => 'Gold'];
            }

            public function schedulePlanStatus(int $planId, string $status, string $scheduledAt): array
            {
                $this->savedStatus = $status;

                return [
                    'id' => $planId,
                    'scheduled_status' => $status,
                    'scheduled_at' => $scheduledAt,
                ];
            }
        });

        $result = $service->save(11, 'draft', '2026-03-20 10:00:00');

        self::assertSame('inactive', $result['data']['scheduled_status']);
    }

    public function test_save_returns_not_found_for_missing_plan(): void
    {
        $service = new PlanScheduleService(new class() extends PlanService {
            public function __construct()
            {
            }

            public function find(int $id): ?array
            {
                return null;
            }
        });

        $result = $service->save(999, 'active', '2026-03-20 10:00:00');

        self::assertSame(404, $result['status']);
        self::assertArrayHasKey('error', $result);
    }
}

<?php

declare(strict_types=1);

namespace FChubMemberships\Tests\Unit\Domain\Plan;

use FChubMemberships\Domain\Plan\PlanImportExportService;
use FChubMemberships\Domain\Plan\PlanService;
use FChubMemberships\Tests\Unit\PluginTestCase;

final class PlanImportExportServiceTest extends PluginTestCase
{
    public function test_export_strips_internal_fields(): void
    {
        $service = new PlanImportExportService(new class() extends PlanService {
            public function __construct()
            {
            }

            public function getFullPlan(int $id): array
            {
                return [
                    'id' => $id,
                    'title' => 'Gold',
                    'created_at' => '2026-01-01 00:00:00',
                    'updated_at' => '2026-01-02 00:00:00',
                    'members_count' => 3,
                    'rules' => [[
                        'id' => 9,
                        'plan_id' => $id,
                        'created_at' => '2026-01-01 00:00:00',
                        'updated_at' => '2026-01-02 00:00:00',
                        'resource_type' => 'post',
                    ]],
                ];
            }
        });

        $result = $service->export(4);

        self::assertArrayNotHasKey('id', $result['data']);
        self::assertArrayNotHasKey('members_count', $result['data']);
        self::assertArrayNotHasKey('id', $result['data']['rules'][0]);
        self::assertArrayNotHasKey('plan_id', $result['data']['rules'][0]);
    }

    public function test_import_forces_safe_defaults_before_create(): void
    {
        $fake = new class() extends PlanService {
            /** @var array<string, mixed> */
            public array $captured = [];

            public function __construct()
            {
            }

            public function create(array $data): array
            {
                $this->captured = $data;

                return ['id' => 5] + $data;
            }
        };

        $service = new PlanImportExportService($fake);
        $service->import([
            'title' => 'Imported Plan',
            'slug' => 'should-be-overwritten',
            'status' => 'active',
        ]);

        self::assertSame('', $fake->captured['slug']);
        self::assertSame('inactive', $fake->captured['status']);
    }
}

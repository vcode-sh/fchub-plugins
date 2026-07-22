<?php

declare(strict_types=1);

namespace FChubMemberships\Tests\Unit\Domain\Plan;

use FChubMemberships\Domain\Plan\PlanService;
use FChubMemberships\Storage\PlanRepository;
use FChubMemberships\Tests\Unit\PluginTestCase;

final class PlanMappingOptionsTest extends PluginTestCase
{
    public function test_mapping_options_include_requested_inactive_plans_with_status(): void
    {
        $repository = new class extends PlanRepository {
            public function getActivePlans(): array
            {
                return [[
                    'id' => 5,
                    'title' => 'Premium Membership',
                    'status' => 'active',
                ]];
            }

            public function find(int $id): ?array
            {
                return $id === 8 ? [
                    'id' => 8,
                    'title' => 'Legacy Membership',
                    'status' => 'inactive',
                ] : null;
            }
        };

        $service = new PlanService();
        $property = new \ReflectionProperty(PlanService::class, 'planRepo');
        $property->setValue($service, $repository);

        self::assertSame([
            ['id' => 5, 'label' => 'Premium Membership', 'value' => '5', 'status' => 'active'],
            ['id' => 8, 'label' => 'Legacy Membership', 'value' => '8', 'status' => 'inactive'],
        ], $service->getOptions([8]));
    }
}

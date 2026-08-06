<?php

declare(strict_types=1);

namespace FChubMemberships\Tests\Unit\Domain\Plan;

use FChubMemberships\Domain\Plan\PlanService;
use FChubMemberships\Storage\PlanRepository;
use FChubMemberships\Storage\PlanRuleRepository;
use FChubMemberships\Tests\Unit\PluginTestCase;

final class PlanServiceSlugTest extends PluginTestCase
{
    public function testPreviewReturnsTheCanonicalCustomSlugAndAvailability(): void
    {
        $service = $this->serviceWith(new class extends PlanRepository {
            public function __construct()
            {
            }

            public function slugExists(string $slug, ?int $excludeId = null): bool
            {
                return $slug === 'already-used';
            }

            public function generateUniqueSlug(string $title, ?int $excludeId = null): string
            {
                return 'generated-title';
            }
        });

        self::assertSame([
            'slug' => 'custom-title',
            'mode' => 'custom',
            'available' => true,
        ], $service->previewSlug('Ignored title', ' Custom Title '));
    }

    public function testPreviewUsesTheRepositoryForAnAutomaticUniqueSlug(): void
    {
        $service = $this->serviceWith(new class extends PlanRepository {
            public function __construct()
            {
            }

            public function slugExists(string $slug, ?int $excludeId = null): bool
            {
                return false;
            }

            public function generateUniqueSlug(string $title, ?int $excludeId = null): string
            {
                return 'generated-title-2';
            }
        });

        self::assertSame([
            'slug' => 'generated-title-2',
            'mode' => 'automatic',
            'available' => true,
        ], $service->previewSlug('Generated title'));
    }

    public function testUpdateRejectsASlugThatCanonicalisesToEmpty(): void
    {
        $service = $this->serviceWith(new class extends PlanRepository {
            public function __construct()
            {
            }

            public function find(int $id): ?array
            {
                return ['id' => $id, 'slug' => 'existing-slug', 'meta' => []];
            }
        });

        self::assertSame(
            ['error' => 'Plan slug must contain usable characters.'],
            $service->update(7, ['slug' => '---'])
        );
    }

    private function serviceWith(PlanRepository $planRepo): PlanService
    {
        $service = new PlanService();

        $planReflection = new \ReflectionProperty(PlanService::class, 'planRepo');
        $planReflection->setValue($service, $planRepo);

        $ruleReflection = new \ReflectionProperty(PlanService::class, 'ruleRepo');
        $ruleReflection->setValue($service, new class extends PlanRuleRepository {
            public function __construct()
            {
            }
        });

        return $service;
    }
}

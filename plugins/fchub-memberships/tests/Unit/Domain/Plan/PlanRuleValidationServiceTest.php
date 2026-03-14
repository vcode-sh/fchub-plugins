<?php

declare(strict_types=1);

namespace FChubMemberships\Tests\Unit\Domain\Plan;

use FChubMemberships\Domain\Plan\PlanRuleValidationService;
use FChubMemberships\Support\ResourceTypeRegistry;
use FChubMemberships\Tests\Unit\PluginTestCase;

final class PlanRuleValidationServiceTest extends PluginTestCase
{
    public function test_validate_rejects_unknown_resource_type(): void
    {
        $service = new PlanRuleValidationService(new class() extends ResourceTypeRegistry {
            public function isValid(string $key): bool
            {
                return $key === 'post';
            }
        });

        $error = $service->validate([
            ['resource_type' => 'missing', 'drip_type' => 'immediate'],
        ]);

        self::assertStringContainsString('invalid resource type', (string) $error);
    }

    public function test_validate_rejects_missing_fixed_date_value(): void
    {
        $service = new PlanRuleValidationService(new class() extends ResourceTypeRegistry {
            public function isValid(string $key): bool
            {
                return true;
            }
        });

        $error = $service->validate([
            ['resource_type' => 'post', 'drip_type' => 'fixed_date'],
        ]);

        self::assertStringContainsString('drip_date is required', (string) $error);
    }

    public function test_prepare_for_storage_maps_provider_and_strips_ui_fields(): void
    {
        $service = new PlanRuleValidationService(new class() extends ResourceTypeRegistry {
            public function get(string $key): ?array
            {
                return ['provider' => 'wordpress_core'];
            }

            public function isValid(string $key): bool
            {
                return true;
            }
        });

        $prepared = $service->prepareForStorage([
            [
                'resource_type' => 'post',
                'access_type' => 'plan',
                'resource_label' => 'Title',
                'resource_type_label' => 'Posts',
            ],
        ]);

        self::assertSame('wordpress_core', $prepared[0]['provider']);
        self::assertArrayNotHasKey('access_type', $prepared[0]);
        self::assertArrayNotHasKey('resource_label', $prepared[0]);
        self::assertArrayNotHasKey('resource_type_label', $prepared[0]);
    }
}

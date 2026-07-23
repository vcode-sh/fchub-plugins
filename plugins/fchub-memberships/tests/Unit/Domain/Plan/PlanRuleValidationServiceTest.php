<?php

declare(strict_types=1);

namespace FChubMemberships\Tests\Unit\Domain\Plan;

use FChubMemberships\Domain\Plan\PlanRuleValidationService;
use FChubMemberships\Support\ResourceTypeRegistry;
use FChubMemberships\Tests\Unit\PluginTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

if (!defined('FLUENTCRM')) {
    define('FLUENTCRM', 'fluentcrm');
}

final class PlanRuleValidationServiceTest extends PluginTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        ResourceTypeRegistry::reset();
    }

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

    public function test_validate_accepts_canonical_crm_types_and_rejects_legacy_learndash_write_aliases(): void
    {
        $service = new PlanRuleValidationService();

        self::assertNull($service->validate([
            ['resource_type' => 'fluentcrm_tag', 'resource_id' => '11', 'drip_type' => 'immediate'],
            ['resource_type' => 'fluentcrm_list', 'resource_id' => '21', 'drip_type' => 'immediate'],
        ]));

        $error = $service->validate([
            ['resource_type' => 'sfwd-courses', 'resource_id' => '31', 'drip_type' => 'immediate'],
        ]);

        self::assertStringContainsString('invalid resource type', (string) $error);
    }

    public function test_validate_rejects_all_resources_for_external_enrolment_types(): void
    {
        $service = new PlanRuleValidationService();

        foreach (['0', '*'] as $allResourcesSentinel) {
            $error = $service->validate([
                ['resource_type' => 'fluentcrm_tag', 'resource_id' => $allResourcesSentinel, 'drip_type' => 'immediate'],
            ]);

            self::assertStringContainsString('does not support all resources', (string) $error);
        }
    }

    #[DataProvider('invalidExternalResourceIds')]
    public function test_validate_requires_a_positive_external_resource_id(mixed $resourceId): void
    {
        $service = new PlanRuleValidationService();

        $error = $service->validate([[
            'resource_type' => 'fluentcrm_tag',
            'resource_id' => $resourceId,
            'drip_type' => 'immediate',
        ]]);

        self::assertStringContainsString('positive resource ID', (string) $error);
    }

    public static function invalidExternalResourceIds(): array
    {
        return [
            'empty' => [''],
            'null' => [null],
            'zero' => [0],
            'wildcard' => ['*'],
            'negative' => [-7],
            'malformed' => ['12abc'],
        ];
    }

    public function test_slug_identity_requires_an_already_sanitised_non_numeric_resource_slug(): void
    {
        $service = new PlanRuleValidationService(new class() extends ResourceTypeRegistry {
            public function isValid(string $key): bool
            {
                return $key === 'future_slug_resource';
            }

            public function get(string $key): ?array
            {
                return [
                    'provider' => 'future_provider',
                    'allow_all' => false,
                    'identifier' => 'slug',
                ];
            }
        });

        self::assertNull($service->validate([[
            'resource_type' => 'future_slug_resource',
            'resource_id' => 'gold-member',
            'drip_type' => 'immediate',
        ]]));
        foreach (['', 'Gold Member', 'gold_member', '*', '12'] as $resourceId) {
            self::assertStringContainsString('sanitised resource slug', (string) $service->validate([[
                'resource_type' => 'future_slug_resource',
                'resource_id' => $resourceId,
                'drip_type' => 'immediate',
            ]]));
        }
    }

    public function test_fluentcommunity_badge_slug_must_exist_in_the_installed_catalogue(): void
    {
        $service = new PlanRuleValidationService(
            new class() extends ResourceTypeRegistry {
                public function isValid(string $key): bool
                {
                    return $key === 'fc_badge';
                }

                public function get(string $key): ?array
                {
                    return [
                        'provider' => 'fluent_community',
                        'allow_all' => false,
                        'identifier' => 'slug',
                    ];
                }
            },
            static fn(): array => [
                'founding-member' => ['title' => 'Founding Member'],
            ]
        );

        self::assertNull($service->validate([[
            'resource_type' => 'fc_badge',
            'resource_id' => 'founding-member',
            'drip_type' => 'immediate',
        ]]));
        self::assertStringContainsString('is not installed', (string) $service->validate([[
            'resource_type' => 'fc_badge',
            'resource_id' => 'unknown-badge',
            'drip_type' => 'immediate',
        ]]));
    }
}

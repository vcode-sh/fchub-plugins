<?php

declare(strict_types=1);

namespace FChubMemberships\Tests\Unit\Domain\Reconciliation;

use FChubMemberships\Domain\Reconciliation\FluentCommunityProviderHealthExtension;
use FChubMemberships\Domain\Reconciliation\FluentCrmProviderHealthExtension;
use FChubMemberships\Domain\Reconciliation\ProviderHealthCapability;
use FChubMemberships\Domain\Reconciliation\ProviderHealthObservation;
use FChubMemberships\Domain\Reconciliation\ProviderResource;
use FChubMemberships\Integration\Community\CommunityCapabilityRegistry;
use FChubMemberships\Tests\Unit\PluginTestCase;

final class ProviderHealthExtensionTest extends PluginTestCase
{
    public function test_canonical_health_contract_uses_minimal_immutable_values(): void
    {
        self::assertTrue(interface_exists(
            \FChubMemberships\Domain\Reconciliation\Contracts\ProviderHealthExtensionInterface::class
        ));
        foreach ([ProviderResource::class, ProviderHealthCapability::class, ProviderHealthObservation::class] as $class) {
            self::assertTrue(class_exists($class));
            self::assertTrue((new \ReflectionClass($class))->isReadOnly());
        }

        $resource = new ProviderResource(17, 'fluentcrm', 'fluentcrm_tag', '41');
        $capability = new ProviderHealthCapability('fluentcrm', true, true, ['fluentcrm_tag']);
        $observation = new ProviderHealthObservation('present', 'relation_present');

        self::assertSame([
            'user_id' => 17,
            'provider' => 'fluentcrm',
            'resource_type' => 'fluentcrm_tag',
            'resource_id' => '41',
        ], $resource->toArray());
        self::assertTrue($capability->supports($resource));
        self::assertSame('present', $observation->state);
    }

    public function test_fluentcrm_observation_uses_pure_user_id_relation_lookup(): void
    {
        self::assertTrue(
            class_exists(FluentCrmProviderHealthExtension::class),
            'The pure FluentCRM health extension is missing.'
        );
        $calls = [];
        $contact = (object) [
            'tags' => new HealthIdCollection([41]),
            'lists' => new HealthIdCollection([]),
        ];
        $extension = new FluentCrmProviderHealthExtension(
            static function (int $userId) use (&$calls, $contact): object {
                $calls[] = ['getContactByUserId', $userId];
                return $contact;
            },
            static fn(): bool => true
        );

        $observation = $extension->observe(new ProviderResource(17, 'fluentcrm', 'fluentcrm_tag', '41'));

        self::assertSame('present', $observation->state);
        self::assertSame([['getContactByUserId', 17]], $calls);
        self::assertStringNotContainsString(
            'FluentCrmAdapter',
            file_get_contents(FCHUB_MEMBERSHIPS_PATH . 'app/Domain/Reconciliation/FluentCrmProviderHealthExtension.php')
        );
        self::assertStringNotContainsString(
            'getContactByUserRef',
            file_get_contents(FCHUB_MEMBERSHIPS_PATH . 'app/Domain/Reconciliation/FluentCrmProviderHealthExtension.php')
        );
    }

    public function test_community_observation_is_pure_and_provider_failures_are_unknown(): void
    {
        self::assertTrue(
            class_exists(FluentCommunityProviderHealthExtension::class),
            'The pure FluentCommunity health extension is missing.'
        );
        $calls = [];
        $extension = new FluentCommunityProviderHealthExtension(
            static function (int $userId, int $resourceId) use (&$calls): bool {
                $calls[] = [$userId, $resourceId];
                return true;
            },
            static fn(): bool => true
        );

        self::assertSame(
            'present',
            $extension->observe(new ProviderResource(17, 'fluent_community', 'fc_course', '91'))->state
        );
        self::assertSame([[17, 91]], $calls);

        $throwing = new FluentCommunityProviderHealthExtension(
            static fn(): never => throw new \RuntimeException('private provider detail'),
            static fn(): bool => true
        );
        $unknown = $throwing->observe(new ProviderResource(17, 'fluent_community', 'fc_space', '91'));

        self::assertSame('unknown', $unknown->state);
        self::assertSame('provider_observation_failed', $unknown->code);
        self::assertStringNotContainsString('private provider detail', $unknown->code);
    }

    public function test_builtin_capability_resolution_runs_once_across_service_style_observation(): void
    {
        $crmAvailabilityCalls = 0;
        $crm = new FluentCrmProviderHealthExtension(
            static fn(): object => (object) [
                'tags' => new HealthIdCollection([41]),
                'lists' => new HealthIdCollection([]),
            ],
            static function () use (&$crmAvailabilityCalls): bool {
                $crmAvailabilityCalls++;
                if ($crmAvailabilityCalls > 1) {
                    throw new \RuntimeException('availability must not be resolved twice');
                }
                return true;
            }
        );

        self::assertTrue($crm->capability()->available);
        self::assertSame(
            'present',
            $crm->observe(new ProviderResource(17, 'fluentcrm', 'fluentcrm_tag', '41'))->state
        );
        self::assertSame(1, $crmAvailabilityCalls);

        $communityAvailabilityCalls = 0;
        $community = new FluentCommunityProviderHealthExtension(
            static fn(): bool => true,
            static function () use (&$communityAvailabilityCalls): bool {
                $communityAvailabilityCalls++;
                if ($communityAvailabilityCalls > 1) {
                    throw new \RuntimeException('availability must not be resolved twice');
                }
                return true;
            }
        );

        self::assertTrue($community->capability()->available);
        self::assertSame(
            'present',
            $community->observe(new ProviderResource(17, 'fluent_community', 'fc_space', '91'))->state
        );
        self::assertSame(1, $communityAvailabilityCalls);
    }

    public function test_direct_observe_contains_availability_resolution_failures(): void
    {
        $crm = new FluentCrmProviderHealthExtension(
            static fn(): never => throw new \LogicException('contact lookup must not run'),
            static fn(): never => throw new \RuntimeException('private availability failure')
        );
        $community = new FluentCommunityProviderHealthExtension(
            static fn(): never => throw new \LogicException('membership lookup must not run'),
            static fn(): never => throw new \RuntimeException('private availability failure')
        );

        foreach ([
            $crm->observe(new ProviderResource(17, 'fluentcrm', 'fluentcrm_tag', '41')),
            $community->observe(new ProviderResource(17, 'fluent_community', 'fc_space', '91')),
        ] as $observation) {
            self::assertSame('unknown', $observation->state);
            self::assertSame('provider_observation_failed', $observation->code);
        }
    }

    public function test_community_health_capability_exposes_safe_registry_metadata(): void
    {
        $registry = new CommunityCapabilityRegistry(
            static fn(): array => [
                'core_active' => true,
                'core_version' => '2.7.0',
                'pro_active' => false,
                'pro_version' => '2.7.0',
                'pro_certified' => false,
            ],
            static fn(string $feature): bool => $feature === 'course_module',
            static fn(string $capability): bool => true
        );
        $extension = new FluentCommunityProviderHealthExtension(
            static fn(): bool => true,
            null,
            $registry
        );

        $capability = $extension->capability();

        self::assertTrue($capability->available);
        self::assertSame('healthy', $capability->status);
        self::assertSame('2.7.0', $capability->version);
        self::assertSame('community_core_available', $capability->reason);
        self::assertSame('inactive', $capability->capabilities['badges']['status']);
    }

    public function test_community_health_observes_certified_badges_from_xprofile_state_only(): void
    {
        $coreReads = 0;
        $badgeReads = [];
        $registry = new CommunityCapabilityRegistry(
            static fn(): array => [
                'core_active' => true,
                'core_version' => '2.7.0',
                'pro_active' => true,
                'pro_version' => '2.7.0',
                'pro_certified' => true,
            ],
            static fn(string $feature): bool => in_array($feature, ['course_module', 'user_badge'], true),
            static fn(string $capability): bool => $capability !== 'points'
                && $capability !== 'leaderboard_levels'
        );
        $extension = new FluentCommunityProviderHealthExtension(
            static function () use (&$coreReads): never {
                $coreReads++;
                throw new \LogicException('Badge health must not use the space membership reader.');
            },
            null,
            $registry,
            static function (int $userId, string $badgeSlug) use (&$badgeReads): bool {
                $badgeReads[] = [$userId, $badgeSlug];

                return $badgeSlug === 'founder';
            }
        );

        $observation = $extension->observe(
            new ProviderResource(17, 'fluent_community', 'fc_badge', 'founder')
        );

        self::assertSame('present', $observation->state);
        self::assertSame([[17, 'founder']], $badgeReads);
        self::assertSame(0, $coreReads);
        self::assertSame('available', $extension->capability()->capabilities['badges']['status']);
    }

    public function test_unavailable_badge_capability_skips_xprofile_badge_reads_without_degrading_core_health(): void
    {
        $badgeReads = 0;
        $registry = new CommunityCapabilityRegistry(
            static fn(): array => [
                'core_active' => true,
                'core_version' => '2.7.0',
                'pro_active' => false,
                'pro_version' => null,
                'pro_certified' => false,
            ],
            static fn(string $feature): bool => $feature === 'course_module',
            static fn(string $capability): bool => true
        );
        $extension = new FluentCommunityProviderHealthExtension(
            static fn(): bool => true,
            null,
            $registry,
            static function () use (&$badgeReads): never {
                $badgeReads++;
                throw new \LogicException('Inactive Pro must not be read.');
            }
        );

        $observation = $extension->observe(
            new ProviderResource(17, 'fluent_community', 'fc_badge', 'founder')
        );

        self::assertSame('unknown', $observation->state);
        self::assertSame('provider_unavailable', $observation->code);
        self::assertSame(0, $badgeReads);
        self::assertSame('healthy', $extension->capability()->status);
        self::assertSame('inactive', $extension->capability()->capabilities['badges']['status']);
    }
}

final class HealthIdCollection
{
    public function __construct(private array $ids)
    {
    }

    public function pluck(string $column): self
    {
        return $this;
    }

    public function toArray(): array
    {
        return $this->ids;
    }
}

<?php

declare(strict_types=1);

namespace FChubMemberships\Tests\Unit\Domain\Grant;

use FChubMemberships\Adapters\Contracts\AccessAdapterInterface;
use FChubMemberships\Domain\Grant\GrantCreationService;
use FChubMemberships\Domain\Grant\GrantRevocationService;
use FChubMemberships\Domain\Grant\PlanGrantExecutionService;
use FChubMemberships\Domain\GrantAdapterRegistry;
use FChubMemberships\Domain\GrantNotificationService;
use FChubMemberships\Domain\GrantPlanContextService;
use FChubMemberships\Domain\MembershipModeService;
use FChubMemberships\Domain\Plan\PlanRuleResolver;
use FChubMemberships\Integration\FluentCommunitySync;
use FChubMemberships\Integration\MembershipSettingsOptionCoordinator;
use FChubMemberships\Storage\DripScheduleRepository;
use FChubMemberships\Storage\GrantRepository;
use FChubMemberships\Storage\GrantSourceRepository;
use FChubMemberships\Storage\PlanRepository;
use FChubMemberships\Storage\PlanRuleRepository;
use FChubMemberships\Tests\Unit\PluginTestCase;

final class FluentCommunityLegacyGrantPathTest extends PluginTestCase
{
    public function test_unready_plan_fails_before_rule_resolution_hooks_or_notifications(): void
    {
        $GLOBALS['_fchub_test_options']['fchub_memberships_settings'] = [
            'membership_mode' => 'stack',
            'email_access_granted' => 'no',
            'fc_enabled' => 'yes',
            'fc_space_mappings' => ['5' => '999'],
        ];
        $rules = new LegacyGrantPathRuleRepository();
        $sync = new FluentCommunitySync(
            $rules,
            static fn(int $resourceId): ?string => null,
            $this->coordinator()
        );
        $resolver = new LegacyGrantPathRuleResolver($rules);
        $hookCalls = 0;
        add_action('fchub_memberships/grant_created', static function () use (&$hookCalls): void {
            $hookCalls++;
        });

        $result = $this->service($resolver, $sync)->grantPlan(17, 5);

        self::assertSame(1, $result['failed']);
        self::assertSame(0, $result['created']);
        self::assertSame(0, $resolver->resolveCalls);
        self::assertSame(0, $hookCalls);
        self::assertSame([], $GLOBALS['_fchub_test_mails']);
        self::assertSame('legacy_mapping_not_converted', $result['errors'][0]['reason']);
    }

    public function test_migrated_canonical_rule_provider_failure_is_counted_and_returned(): void
    {
        $GLOBALS['_fchub_test_options']['fchub_memberships_settings'] = [
            'membership_mode' => 'stack',
            'email_access_granted' => 'no',
            'fc_enabled' => 'yes',
            'fc_space_mappings' => ['5' => '31'],
        ];
        $rules = new LegacyGrantPathRuleRepository();
        $sync = new FluentCommunitySync(
            $rules,
            static fn(int $resourceId): ?string => $resourceId === 31 ? 'community' : null,
            $this->coordinator()
        );
        $resolver = new LegacyGrantPathRuleResolver($rules);
        $hookCalls = 0;
        add_action('fchub_memberships/grant_created', static function () use (&$hookCalls): void {
            $hookCalls++;
        });

        $result = $this->service($resolver, $sync)->grantPlan(17, 5);

        self::assertCount(1, $rules->rules);
        self::assertSame('fc_space', $rules->rules[0]['resource_type']);
        self::assertSame([], $GLOBALS['_fchub_test_options']['fchub_memberships_settings']['fc_space_mappings']);
        self::assertSame(1, $resolver->resolveCalls);
        self::assertSame(1, $result['total']);
        self::assertSame(1, $result['failed']);
        self::assertSame('failed', $result['errors'][0]['action']);
        self::assertSame('Installed provider refused the grant.', $result['errors'][0]['message']);
        self::assertSame(0, $hookCalls);
    }

    private function service(LegacyGrantPathRuleResolver $resolver, FluentCommunitySync $sync): PlanGrantExecutionService
    {
        $grants = new LegacyGrantPathGrantRepository();
        $sources = new class extends GrantSourceRepository {
        };
        $drips = new class extends DripScheduleRepository {
        };
        $adapters = new GrantAdapterRegistry([
            'fluent_community' => FailingLegacyCommunityAdapter::class,
        ]);
        $plans = new class extends PlanRepository {
            public function __construct()
            {
            }

            public function find(int $id): ?array
            {
                return [
                    'id' => $id,
                    'title' => 'Legacy Community Plan',
                    'duration_type' => 'lifetime',
                    'trial_days' => 0,
                    'meta' => [],
                ];
            }
        };
        $notifications = new GrantNotificationService($plans);

        return new PlanGrantExecutionService(
            $resolver,
            new MembershipModeService($grants, $plans),
            new GrantPlanContextService($plans, $grants),
            new GrantCreationService($grants, $sources, $drips, $adapters),
            new GrantRevocationService($grants, $sources, $drips, $adapters, $notifications),
            $notifications,
            null,
            $sync->ensurePlanReady(...)
        );
    }

    private function coordinator(): MembershipSettingsOptionCoordinator
    {
        $locked = false;
        return new MembershipSettingsOptionCoordinator(
            static function () use (&$locked): bool {
                if ($locked) {
                    return false;
                }
                $locked = true;
                return true;
            },
            static function () use (&$locked): void {
                $locked = false;
            },
            static function (): array {
                return $GLOBALS['_fchub_test_options']['fchub_memberships_settings'] ?? [];
            },
            static function (array $settings): bool {
                $GLOBALS['_fchub_test_options']['fchub_memberships_settings'] = $settings;
                return true;
            }
        );
    }
}

final class LegacyGrantPathRuleRepository extends PlanRuleRepository
{
    /** @var list<array<string, mixed>> */
    public array $rules = [];

    public function __construct()
    {
    }

    public function getByPlanId(int $planId): array
    {
        return array_values(array_filter(
            $this->rules,
            static fn(array $rule): bool => (int) $rule['plan_id'] === $planId
        ));
    }

    public function create(array $data): int
    {
        $id = count($this->rules) + 1;
        $this->rules[] = ['id' => $id] + $data;
        return $id;
    }
}

final class LegacyGrantPathRuleResolver extends PlanRuleResolver
{
    public int $resolveCalls = 0;

    public function __construct(private LegacyGrantPathRuleRepository $rules)
    {
    }

    public function resolveUniqueRules(int $planId): array
    {
        $this->resolveCalls++;
        return $this->rules->getByPlanId($planId);
    }
}

final class LegacyGrantPathGrantRepository extends GrantRepository
{
    public function findByGrantKey(string $grantKey): ?array
    {
        return null;
    }

    public function getByUserId(int $userId, array $filters = []): array
    {
        return [];
    }

    public function getUserActivePlanIds(int $userId): array
    {
        return [];
    }
}

final class FailingLegacyCommunityAdapter implements AccessAdapterInterface
{
    public function supports(string $resourceType): bool
    {
        return in_array($resourceType, ['fc_space', 'fc_course'], true);
    }

    public function grant(int $userId, string $resourceType, string $resourceId, array $context = []): array
    {
        return ['success' => false, 'message' => 'Installed provider refused the grant.'];
    }

    public function revoke(int $userId, string $resourceType, string $resourceId, array $context = []): array
    {
        return ['success' => false, 'message' => 'Installed provider refused the revoke.'];
    }

    public function check(int $userId, string $resourceType, string $resourceId): bool
    {
        return false;
    }

    public function getResourceLabel(string $resourceType, string $resourceId): string
    {
        return $resourceType . ' #' . $resourceId;
    }

    public function searchResources(string $query, string $resourceType, int $limit = 20): array
    {
        return [];
    }

    public function getResourceTypes(): array
    {
        return ['fc_space' => 'Space', 'fc_course' => 'Course'];
    }
}

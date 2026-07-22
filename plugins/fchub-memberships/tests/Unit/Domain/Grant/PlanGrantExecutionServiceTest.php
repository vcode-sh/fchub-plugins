<?php

declare(strict_types=1);

namespace FChubMemberships\Tests\Unit\Domain\Grant;

use FChubMemberships\Domain\Grant\GrantCreationService;
use FChubMemberships\Domain\Grant\GrantRevocationService;
use FChubMemberships\Domain\Grant\PlanGrantExecutionService;
use FChubMemberships\Domain\GrantAdapterRegistry;
use FChubMemberships\Domain\GrantNotificationService;
use FChubMemberships\Domain\GrantPlanContextService;
use FChubMemberships\Domain\MembershipModeService;
use FChubMemberships\Domain\Plan\PlanRuleResolver;
use FChubMemberships\Storage\DripScheduleRepository;
use FChubMemberships\Storage\GrantRepository;
use FChubMemberships\Storage\GrantSourceRepository;
use FChubMemberships\Storage\PlanRepository;
use FChubMemberships\Tests\Unit\PluginTestCase;

final class PlanGrantExecutionServiceTest extends PluginTestCase
{
    public function test_plan_grant_execution_service_covers_blocked_and_success_paths(): void
    {
        $GLOBALS['_fchub_test_options']['admin_email'] = 'admin@example.com';
        $user = new \WP_User();
        $user->ID = 21;
        $user->display_name = 'Alice Example';
        $user->user_email = 'alice@example.com';
        $user->user_login = 'alice';
        $GLOBALS['_fchub_test_users'][21] = $user;

        $ruleResolver = new class extends PlanRuleResolver {
            public function resolveUniqueRules(int $planId): array
            {
                return [
                    ['provider' => 'wordpress_core', 'resource_type' => 'post', 'resource_id' => '55', 'id' => 1, 'drip_type' => 'delayed', 'drip_delay_days' => 1],
                    ['provider' => 'wordpress_core', 'resource_type' => 'page', 'resource_id' => '77', 'id' => 2, 'drip_type' => 'delayed', 'drip_delay_days' => 2],
                ];
            }
        };

        $blockingModes = new MembershipModeService(
            new class extends GrantRepository {
                public function getUserActivePlanIds(int $userId): array
                {
                    return [9];
                }

                public function getHighestActivePlanLevel(int $userId): int
                {
                    return 20;
                }
            },
            new class extends PlanRepository {
                public function __construct()
                {
                }

                public function find(int $id): ?array
                {
                    return ['id' => $id, 'level' => 20];
                }
            }
        );

        $planRepo = new class extends PlanRepository {
            public function __construct()
            {
            }

            public function find(int $id): ?array
            {
                return ['id' => $id, 'title' => 'Gold Plan', 'slug' => 'gold-plan', 'trial_days' => 7, 'duration_type' => 'lifetime', 'meta' => []];
            }
        };

        $grantRepo = new class extends GrantRepository {
            public function getByUserId(int $userId, array $filters = []): array
            {
                return [];
            }
        };

        $planContext = new GrantPlanContextService($planRepo, $grantRepo);

        $creationRepo = new class extends GrantRepository {
            public array $created = [];

            public function findByGrantKey(string $grantKey): ?array
            {
                return null;
            }

            public function create(array $data): int
            {
                $this->created[] = $data;
                return count($this->created);
            }
        };

        $sourceRepo = new class extends GrantSourceRepository {
            public function addSource(int $grantId, string $sourceType, int $sourceId): bool
            {
                return true;
            }
        };

        $dripRepo = new class extends DripScheduleRepository {
            public array $scheduled = [];

            public function schedule(array $data): int
            {
                $this->scheduled[] = $data;
                return 1;
            }
        };

        $creation = new GrantCreationService($creationRepo, $sourceRepo, $dripRepo, new GrantAdapterRegistry());

        $revocation = new GrantRevocationService(
            new class extends GrantRepository {
                public function getByUserId(int $userId, array $filters = []): array
                {
                    return [];
                }
            },
            new class extends GrantSourceRepository {},
            new class extends DripScheduleRepository {},
            new GrantAdapterRegistry(),
            new GrantNotificationService($planRepo)
        );

        $notifications = new GrantNotificationService($planRepo);

        $GLOBALS['_fchub_test_options']['fchub_memberships_settings']['membership_mode'] = 'upgrade_only';
        $service = new PlanGrantExecutionService($ruleResolver, $blockingModes, $planContext, $creation, $revocation, $notifications);
        $blocked = $service->grantPlan(21, 5, []);

        self::assertTrue($blocked['blocked']);

        $stackModes = new MembershipModeService(
            new class extends GrantRepository {
                public function getUserActivePlanIds(int $userId): array
                {
                    return [];
                }
            },
            new class extends PlanRepository {
                public function __construct()
                {
                }
            }
        );

        $GLOBALS['_fchub_test_options']['fchub_memberships_settings']['membership_mode'] = 'stack';
        $service = new PlanGrantExecutionService($ruleResolver, $stackModes, $planContext, $creation, $revocation, $notifications);
        $order = new class {
            public array $logs = [];
            public function addLog(string $title, string $description, string $type, string $module): void
            {
                $this->logs[] = [$title, $description, $type, $module];
            }
        };
        $success = $service->grantPlan(21, 5, ['order' => $order]);

        self::assertSame(2, $success['created']);
        self::assertSame(0, $success['updated']);
        self::assertCount(2, $creationRepo->created);
        self::assertTrue($creationRepo->created[0]['trial_ends_at'] !== null);
        self::assertCount(2, $dripRepo->scheduled);
        self::assertNotEmpty($GLOBALS['_fchub_test_mails']);
        self::assertCount(1, $order->logs);
    }

    public function test_completed_replacement_and_upgrade_publish_after_destination_success_and_keep_legacy_hooks(): void
    {
        foreach ([
            'exclusive' => ['legacy_hook' => 'fchub_memberships/plan_replaced', 'old_level' => 10, 'new_level' => 20],
            'upgrade_only' => ['legacy_hook' => 'fchub_memberships/plan_upgraded', 'old_level' => 10, 'new_level' => 20],
        ] as $mode => $case) {
            $GLOBALS['_fchub_test_options']['fchub_memberships_settings'] = ['membership_mode' => $mode, 'email_access_granted' => 'no'];
            PlanChangeExecutionAdapter::$succeeds = true;
            PlanChangeExecutionAdapter::$events = [];
            $published = [];
            $legacy = [];
            $GLOBALS['_fchub_test_actions']['fchub_memberships/plan_changed'] = [static function (array $change) use (&$published): void { PlanChangeExecutionAdapter::$events[] = 'canonical'; $published[] = $change; }];
            $GLOBALS['_fchub_test_actions'][$case['legacy_hook']] = [static function (...$args) use (&$legacy): void { PlanChangeExecutionAdapter::$events[] = 'legacy'; $legacy[] = $args; }];

            $service = $this->planChangeService($case['old_level'], $case['new_level']);
            $service->grantPlan(44, 8, ['source_type' => 'automation', 'source_id' => 91]);

            self::assertSame(['destination_granted', 'canonical', 'legacy'], PlanChangeExecutionAdapter::$events, $mode);
            self::assertSame('automation_change', $published[0]['change_type'], $mode);
            self::assertSame([3], $published[0]['from_plan_ids'], $mode);
            self::assertSame([[44, 8, [3]]], $legacy, $mode);

            PlanChangeExecutionAdapter::$succeeds = false;
            PlanChangeExecutionAdapter::$events = [];
            $published = [];
            $legacy = [];
            $service = $this->planChangeService($case['old_level'], $case['new_level']);
            $service->grantPlan(44, 8, ['source_type' => 'automation', 'source_id' => 91]);
            self::assertSame(['destination_failed'], PlanChangeExecutionAdapter::$events, $mode);
            self::assertSame([], $published, $mode);
            self::assertSame([], $legacy, $mode);
        }
    }

    private function planChangeService(int $oldLevel, int $newLevel): PlanGrantExecutionService
    {
        $plans = new class($oldLevel, $newLevel) extends PlanRepository {
            public function __construct(private int $oldLevel, private int $newLevel) {}
            public function find(int $id): ?array
            {
                return ['id' => $id, 'title' => 'Plan ' . $id, 'level' => $id === 3 ? $this->oldLevel : $this->newLevel, 'trial_days' => 0, 'duration_type' => 'lifetime', 'meta' => []];
            }
        };
        $memberGrants = new class($oldLevel) extends GrantRepository {
            public function __construct(private int $oldLevel) {}
            public function getUserActivePlanIds(int $userId): array { return [3]; }
            public function getHighestActivePlanLevel(int $userId): int { return $this->oldLevel; }
            public function getByUserId(int $userId, array $filters = []): array { return []; }
        };
        $rules = new class extends PlanRuleResolver {
            public function resolveUniqueRules(int $planId): array { return [['id' => 1, 'provider' => 'plan_change', 'resource_type' => 'post', 'resource_id' => '88', 'drip_type' => 'immediate']]; }
        };
        $creation = new GrantCreationService(
            new class extends GrantRepository {
                public function findByGrantKey(string $grantKey): ?array { return null; }
                public function create(array $data): int { return 501; }
            },
            new class extends GrantSourceRepository { public function addSource(int $grantId, string $sourceType, int $sourceId): bool { return true; } },
            new class extends DripScheduleRepository {},
            new GrantAdapterRegistry(['plan_change' => PlanChangeExecutionAdapter::class])
        );
        $notifications = new GrantNotificationService($plans);
        $revocation = new GrantRevocationService(
            new class extends GrantRepository { public function getByUserId(int $userId, array $filters = []): array { return []; } },
            new class extends GrantSourceRepository {},
            new class extends DripScheduleRepository {},
            new GrantAdapterRegistry(),
            $notifications
        );

        return new PlanGrantExecutionService(
            $rules,
            new MembershipModeService($memberGrants, $plans),
            new GrantPlanContextService($plans, $memberGrants),
            $creation,
            $revocation,
            $notifications
        );
    }
}

final class PlanChangeExecutionAdapter
{
    public static bool $succeeds = true;
    public static array $events = [];
    public function check(int $userId, string $resourceType, string $resourceId): bool { return false; }
    public function grant(int $userId, string $resourceType, string $resourceId, array $context = []): array
    {
        self::$events[] = self::$succeeds ? 'destination_granted' : 'destination_failed';
        return ['success' => self::$succeeds, 'message' => self::$succeeds ? 'ok' : 'failed'];
    }
}

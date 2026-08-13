<?php

declare(strict_types=1);

namespace FChubMemberships\Tests\Unit\FluentCRM\Triggers {

    use FChubMemberships\FluentCRM\FluentCrmAutomation;
    use FChubMemberships\FluentCRM\Triggers\DripContentUnlockedTrigger;
    use FChubMemberships\FluentCRM\Triggers\DripMilestoneTrigger;
    use FChubMemberships\FluentCRM\Triggers\MembershipAnniversaryTrigger;
    use FChubMemberships\FluentCRM\Triggers\MembershipExpiredTrigger;
    use FChubMemberships\FluentCRM\Triggers\MembershipExpiringSoonTrigger;
    use FChubMemberships\FluentCRM\Triggers\MembershipGrantedTrigger;
    use FChubMemberships\FluentCRM\Triggers\MembershipPausedTrigger;
    use FChubMemberships\FluentCRM\Triggers\MembershipPlanChangedTrigger;
    use FChubMemberships\FluentCRM\Triggers\MembershipRenewedTrigger;
    use FChubMemberships\FluentCRM\Triggers\MembershipResumedTrigger;
    use FChubMemberships\FluentCRM\Triggers\MembershipRevokedTrigger;
    use FChubMemberships\FluentCRM\Triggers\PaymentFailedTrigger;
    use FChubMemberships\FluentCRM\Triggers\TrialConvertedTrigger;
    use FChubMemberships\FluentCRM\Triggers\TrialExpiredTrigger;
    use FChubMemberships\FluentCRM\Triggers\TrialExpiringSoonTrigger;
    use FChubMemberships\FluentCRM\Triggers\TrialStartedTrigger;
    use FChubMemberships\Domain\Drip\DripScheduleService;
    use FChubMemberships\Domain\Grant\GrantCreationService;
    use FChubMemberships\Domain\Grant\GrantMaintenanceService;
    use FChubMemberships\Domain\Grant\PlanGrantExecutionService;
    use FChubMemberships\Domain\Grant\GrantRevocationService;
    use FChubMemberships\Domain\Grant\GrantStatusService;
    use FChubMemberships\Domain\GrantAdapterRegistry;
    use FChubMemberships\Domain\GrantNotificationService;
    use FChubMemberships\Domain\GrantPlanContextService;
    use FChubMemberships\Domain\MembershipModeService;
    use FChubMemberships\Domain\Plan\PlanRuleResolver;
    use FChubMemberships\Domain\SubscriptionPaymentFailureService;
    use FChubMemberships\Domain\TrialLifecycleService;
    use FChubMemberships\Domain\Trial\TrialGrantQueryService;
    use FChubMemberships\Email\AccessExpiringEmail;
    use FChubMemberships\Storage\DripScheduleRepository;
    use FChubMemberships\Storage\GrantRepository;
    use FChubMemberships\Storage\GrantSourceRepository;
    use FChubMemberships\Storage\PlanRepository;
    use FChubMemberships\Support\Clock;
    use FChubMemberships\Tests\Unit\PluginTestCase;
    use PHPUnit\Framework\Attributes\PreserveGlobalState;
    use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

    class TriggerBaseDouble
    {
        protected string $triggerName = '';
        protected int $priority = 10;
        protected int $actionArgNum = 1;

        public function __construct()
        {
            $this->register();
        }

        public function register(): void
        {
            add_filter('fluentcrm_funnel_triggers', [$this, 'addTrigger'], $this->priority);
            add_filter('fluentcrm_funnel_editor_details_' . $this->triggerName, [$this, 'prepareEditorDetails']);
            add_action('fluentcrm_funnel_start_' . $this->triggerName, [$this, 'handle'], 10, 2);
            add_filter('fluentcrm_funnel_arg_num_' . $this->triggerName, function (int $number): int {
                return max($number, $this->actionArgNum);
            });
        }

        public function addTrigger(array $triggers): array
        {
            $trigger = $this->getTrigger();
            if ($trigger) {
                $triggers[$this->triggerName] = $trigger;
            }

            return $triggers;
        }

        public function prepareEditorDetails(object $funnel): object
        {
            return $funnel;
        }
    }

    class TriggerBaseActionDouble
    {
        protected string $actionName = '';
        protected int $priority = 10;

        public function __construct()
        {
        }
    }

    class TriggerBaseBenchmarkDouble
    {
        protected string $triggerName = '';
        protected int $actionArgNum = 1;
        protected int $priority = 10;

        public function __construct()
        {
        }
    }

    class TriggerFunnelHelperDouble
    {
        public static function prepareUserData(object $user): array
        {
            $GLOBALS['_fchub_test_trigger_prepared_users'][] = (int) $user->ID;

            return ['email' => (string) $user->user_email];
        }

        public static function getSubscriber(string $email): object|false
        {
            return $GLOBALS['_fchub_test_trigger_subscribers'][$email] ?? false;
        }

        public static function ifAlreadyInFunnel(int $funnelId, int $subscriberId): bool
        {
            return (bool) ($GLOBALS['_fchub_test_trigger_already_in_funnel'][$funnelId][$subscriberId] ?? false);
        }

        public static function removeSubscribersFromFunnel(int $funnelId, array $subscriberIds): void
        {
            $GLOBALS['_fchub_test_trigger_removed_subscribers'][] = [$funnelId, $subscriberIds];
        }

        public static function getUpdateOptions(): array
        {
            return [];
        }
    }

    class TriggerFunnelProcessorDouble
    {
        public function startFunnelSequence(object $funnel, array $subscriberData, array $context): void
        {
            $GLOBALS['_fchub_test_trigger_sequences'][] = [$funnel, $subscriberData, $context];
        }
    }

    class TriggerArrDouble
    {
        public static function get(array $values, string $key, mixed $default = null): mixed
        {
            $value = $values;
            foreach (explode('.', $key) as $segment) {
                if (!is_array($value) || !array_key_exists($segment, $value)) {
                    return $default;
                }

                $value = $value[$segment];
            }

            return $value;
        }
    }

    class TriggerGrantAdapterDouble
    {
        public function check(int $userId, string $resourceType, string $resourceId): bool
        {
            return false;
        }

        public function grant(int $userId, string $resourceType, string $resourceId, array $context = []): array
        {
            return ['success' => true];
        }
    }

    #[RunTestsInSeparateProcesses]
    #[PreserveGlobalState(false)]
    final class MembershipTriggerContractTest extends PluginTestCase
    {
        /**
         * @var array<class-string, array{hook: string, arity: int, args: array, user_id: int, plan_id: int, source_ref_id: int}>
         */
        private const CONTRACTS = [
            MembershipGrantedTrigger::class => [
                'hook' => 'fchub_memberships/grant_created',
                'arity' => 3,
                'args' => [44, 7, ['source_type' => 'manual']],
                'user_id' => 44,
                'plan_id' => 7,
                'source_ref_id' => 7,
            ],
            MembershipRevokedTrigger::class => [
                'hook' => 'fchub_memberships/grant_revoked',
                'arity' => 4,
                'args' => [[['id' => 701]], 7, 44, 'Manual revoke'],
                'user_id' => 44,
                'plan_id' => 7,
                'source_ref_id' => 7,
            ],
            MembershipExpiredTrigger::class => [
                'hook' => 'fchub_memberships/grant_expired',
                'arity' => 1,
                'args' => [['id' => 702, 'user_id' => 44, 'plan_id' => 7]],
                'user_id' => 44,
                'plan_id' => 7,
                'source_ref_id' => 7,
            ],
            MembershipPausedTrigger::class => [
                'hook' => 'fchub_memberships/grant_paused',
                'arity' => 2,
                'args' => [['id' => 703, 'user_id' => 44, 'plan_id' => 7], 'Payment failed'],
                'user_id' => 44,
                'plan_id' => 7,
                'source_ref_id' => 7,
            ],
            MembershipResumedTrigger::class => [
                'hook' => 'fchub_memberships/grant_resumed',
                'arity' => 1,
                'args' => [['id' => 704, 'user_id' => 44, 'plan_id' => 7]],
                'user_id' => 44,
                'plan_id' => 7,
                'source_ref_id' => 7,
            ],
            MembershipRenewedTrigger::class => [
                'hook' => 'fchub_memberships/grant_renewed',
                'arity' => 2,
                'args' => [['id' => 705, 'user_id' => 44, 'plan_id' => 7], 3],
                'user_id' => 44,
                'plan_id' => 7,
                'source_ref_id' => 7,
            ],
            TrialStartedTrigger::class => [
                'hook' => 'fchub_memberships/trial_started',
                'arity' => 3,
                'args' => [['is_trial' => true], 7, 44],
                'user_id' => 44,
                'plan_id' => 7,
                'source_ref_id' => 7,
            ],
            TrialConvertedTrigger::class => [
                'hook' => 'fchub_memberships/trial_converted',
                'arity' => 3,
                'args' => [['id' => 706, 'user_id' => 44, 'plan_id' => 7], 7, 44],
                'user_id' => 44,
                'plan_id' => 7,
                'source_ref_id' => 7,
            ],
            TrialExpiredTrigger::class => [
                'hook' => 'fchub_memberships/trial_expired',
                'arity' => 1,
                'args' => [['id' => 707, 'user_id' => 44, 'plan_id' => 7]],
                'user_id' => 44,
                'plan_id' => 7,
                'source_ref_id' => 7,
            ],
            DripContentUnlockedTrigger::class => [
                'hook' => 'fchub_memberships/drip_unlocked',
                'arity' => 3,
                'args' => [['id' => 90], ['id' => 708, 'user_id' => 44, 'plan_id' => 7], 44],
                'user_id' => 44,
                'plan_id' => 7,
                'source_ref_id' => 708,
            ],
            MembershipExpiringSoonTrigger::class => [
                'hook' => 'fchub_memberships/grant_expiring_soon',
                'arity' => 2,
                'args' => [['id' => 709, 'user_id' => 44, 'plan_id' => 7], 5],
                'user_id' => 44,
                'plan_id' => 7,
                'source_ref_id' => 7,
            ],
            MembershipAnniversaryTrigger::class => [
                'hook' => 'fchub_memberships/grant_anniversary',
                'arity' => 2,
                'args' => [['id' => 710, 'user_id' => 44, 'plan_id' => 7], 365],
                'user_id' => 44,
                'plan_id' => 7,
                'source_ref_id' => 710,
            ],
            DripMilestoneTrigger::class => [
                'hook' => 'fchub_memberships/drip_milestone_reached',
                'arity' => 3,
                'args' => [['id' => 711, 'user_id' => 44, 'plan_id' => 7], 50, 44],
                'user_id' => 44,
                'plan_id' => 7,
                'source_ref_id' => 711,
            ],
            TrialExpiringSoonTrigger::class => [
                'hook' => 'fchub_memberships/trial_expiring_soon',
                'arity' => 2,
                'args' => [['id' => 712, 'user_id' => 44, 'plan_id' => 7], 2],
                'user_id' => 44,
                'plan_id' => 7,
                'source_ref_id' => 7,
            ],
            PaymentFailedTrigger::class => [
                'hook' => 'fchub_memberships/payment_failed',
                'arity' => 3,
                'args' => [[['id' => 713, 'user_id' => 44, 'plan_id' => 7]], ['id' => 55], ['attempt' => 2]],
                'user_id' => 44,
                'plan_id' => 7,
                'source_ref_id' => 7,
            ],
            MembershipPlanChangedTrigger::class => [
                'hook' => 'fchub_memberships/plan_changed',
                'arity' => 1,
                'args' => [[
                    'user_id' => 44,
                    'from_plan_ids' => [3, 7],
                    'to_plan_id' => 7,
                    'change_type' => 'level_upgrade',
                    'source_type' => 'manual',
                    'source_id' => 0,
                    'occurred_at' => '2026-07-22 10:00:00',
                ]],
                'user_id' => 44,
                'plan_id' => 7,
                'source_ref_id' => 7,
            ],
        ];

        protected function setUp(): void
        {
            self::installFluentCrmTestDoubles();
            parent::setUp();

            $GLOBALS['_fchub_test_trigger_prepared_users'] = [];
            $GLOBALS['_fchub_test_trigger_sequences'] = [];
            $GLOBALS['_fchub_test_trigger_subscribers'] = [];
            $GLOBALS['_fchub_test_trigger_already_in_funnel'] = [];
            $GLOBALS['_fchub_test_trigger_removed_subscribers'] = [];
            $GLOBALS['_fchub_test_trigger_bridge_args'] = [];
        }

        private static function installFluentCrmTestDoubles(): void
        {
            class_alias(TriggerBaseDouble::class, 'FluentCrm\App\Services\Funnel\BaseTrigger');
            class_alias(TriggerBaseActionDouble::class, 'FluentCrm\App\Services\Funnel\BaseAction');
            class_alias(TriggerBaseBenchmarkDouble::class, 'FluentCrm\App\Services\Funnel\BaseBenchMark');
            class_alias(TriggerFunnelHelperDouble::class, 'FluentCrm\App\Services\Funnel\FunnelHelper');
            class_alias(TriggerFunnelProcessorDouble::class, 'FluentCrm\App\Services\Funnel\FunnelProcessor');
            class_alias(TriggerArrDouble::class, 'FluentCrm\Framework\Support\Arr');
        }

        public function test_catalogue_registers_every_trigger_with_its_exact_hook_and_arity(): void
        {
            self::assertSame(array_keys(self::CONTRACTS), FluentCrmAutomation::TRIGGER_CLASSES);
            if (!defined('FLUENTCRM')) {
                define('FLUENTCRM', 'test');
            }

            FluentCrmAutomation::boot();
            $catalogue = apply_filters('fluentcrm_funnel_triggers', []);

            foreach (self::CONTRACTS as $class => $contract) {
                $startHook = 'fluentcrm_funnel_start_' . $contract['hook'];
                $registration = $GLOBALS['_fchub_test_action_registrations'][$startHook][0] ?? null;

                self::assertNotNull($registration, $class);
                self::assertInstanceOf($class, $registration['callback'][0], $class);
                self::assertSame('handle', $registration['callback'][1], $class);
                self::assertSame(10, $registration['priority'], $class);
                self::assertSame(2, $registration['accepted_args'], $class);
                self::assertArrayHasKey($contract['hook'], $catalogue, $class);
                self::assertSame(
                    $contract['arity'],
                    apply_filters('fluentcrm_funnel_arg_num_' . $contract['hook'], 1),
                    $class
                );
            }

            self::assertCount(16, self::CONTRACTS);
        }

        public function test_registered_arities_match_the_real_membership_event_emitters(): void
        {
            $emissions = $this->membershipEventEmissions();

            foreach (self::CONTRACTS as $class => $contract) {
                self::assertArrayHasKey($contract['hook'], $emissions, $class);
                self::assertSame([$contract['arity']], $emissions[$contract['hook']], $class);
            }
        }

        public function test_every_trigger_resolves_the_event_contact_and_maps_the_source_context(): void
        {
            $this->registerUser(44, 'member@example.test');

            foreach (self::CONTRACTS as $class => $contract) {
                $GLOBALS['_fchub_test_trigger_prepared_users'] = [];
                $GLOBALS['_fchub_test_trigger_sequences'] = [];

                $trigger = new $class();
                $result = $trigger->handle($this->funnel(), $contract['args']);

                self::assertNull($result, $class);
                self::assertSame([$contract['user_id']], $GLOBALS['_fchub_test_trigger_prepared_users'], $class);
                self::assertCount(1, $GLOBALS['_fchub_test_trigger_sequences'], $class);

                [, $subscriberData, $context] = $GLOBALS['_fchub_test_trigger_sequences'][0];
                self::assertSame('member@example.test', $subscriberData['email'], $class);
                self::assertSame('subscribed', $subscriberData['status'], $class);
                self::assertArrayNotHasKey('subscription_status', $subscriberData, $class);
                self::assertSame($contract['hook'], $context['source_trigger_name'], $class);
                self::assertSame($contract['source_ref_id'], $context['source_ref_id'], $class);
            }
        }

        public function test_every_trigger_rejects_a_missing_event_contact(): void
        {
            foreach (self::CONTRACTS as $class => $contract) {
                $GLOBALS['_fchub_test_trigger_sequences'] = [];

                $result = (new $class())->handle($this->funnel(), $contract['args']);

                self::assertFalse($result, $class);
                self::assertSame([], $GLOBALS['_fchub_test_trigger_sequences'], $class);
            }
        }

        public function test_every_trigger_honours_plan_filtering(): void
        {
            $this->registerUser(44, 'member@example.test');

            foreach (self::CONTRACTS as $class => $contract) {
                $GLOBALS['_fchub_test_trigger_sequences'] = [];

                $funnel = $this->funnel(['plan_ids' => [999]]);
                $result = (new $class())->handle($funnel, $contract['args']);

                self::assertFalse($result, $class);
                self::assertSame([], $GLOBALS['_fchub_test_trigger_sequences'], $class);
            }
        }

        public function test_event_specific_filters_are_applied_at_their_boundaries(): void
        {
            $this->registerUser(44, 'member@example.test');

            $cases = [
                [MembershipGrantedTrigger::class, [44, 7, ['source_type' => 'manual']], ['source_types' => ['automation']]],
                [MembershipPausedTrigger::class, [['user_id' => 44, 'plan_id' => 7], 'Payment failed'], ['pause_reasons' => ['manual']]],
                [MembershipRenewedTrigger::class, [['user_id' => 44, 'plan_id' => 7], 2], ['min_renewal_count' => 3]],
                [MembershipExpiringSoonTrigger::class, [['user_id' => 44, 'plan_id' => 7], 4], ['min_days_left' => 5]],
                [MembershipExpiringSoonTrigger::class, [['user_id' => 44, 'plan_id' => 7], 8], ['max_days_left' => 7]],
                [TrialExpiringSoonTrigger::class, [['user_id' => 44, 'plan_id' => 7], 1], ['min_days_left' => 2]],
                [TrialExpiringSoonTrigger::class, [['user_id' => 44, 'plan_id' => 7], 8], ['max_days_left' => 7]],
                [MembershipAnniversaryTrigger::class, [['user_id' => 44, 'plan_id' => 7], 60], ['milestone_days' => [30, 90]]],
                [DripMilestoneTrigger::class, [['user_id' => 44, 'plan_id' => 7], 75, 44], ['milestone_percentages' => [25, 50]]],
            ];

            foreach ($cases as [$class, $args, $conditions]) {
                $GLOBALS['_fchub_test_trigger_sequences'] = [];

                $result = (new $class())->handle($this->funnel($conditions), $args);

                self::assertFalse($result, $class);
                self::assertSame([], $GLOBALS['_fchub_test_trigger_sequences'], $class);
            }
        }

        public function test_repeat_run_policy_removes_an_existing_contact_only_when_enabled(): void
        {
            $this->registerUser(44, 'member@example.test');
            $GLOBALS['_fchub_test_trigger_subscribers']['member@example.test'] = (object) ['id' => 88];
            $GLOBALS['_fchub_test_trigger_already_in_funnel'][51][88] = true;

            $denyResult = (new MembershipExpiredTrigger())->handle(
                $this->funnel(['run_multiple' => 'no']),
                [['user_id' => 44, 'plan_id' => 7]]
            );
            self::assertFalse($denyResult);
            self::assertSame([], $GLOBALS['_fchub_test_trigger_removed_subscribers']);
            self::assertSame([], $GLOBALS['_fchub_test_trigger_sequences']);

            (new MembershipExpiredTrigger())->handle(
                $this->funnel(['run_multiple' => 'yes']),
                [['user_id' => 44, 'plan_id' => 7]]
            );
            self::assertSame([[51, [88]]], $GLOBALS['_fchub_test_trigger_removed_subscribers']);
            self::assertCount(1, $GLOBALS['_fchub_test_trigger_sequences']);
        }

        public function test_payment_failure_matches_any_affected_grant_instead_of_only_the_first(): void
        {
            $this->registerUser(44, 'member@example.test');
            $grants = [
                ['id' => 801, 'user_id' => 99, 'plan_id' => 5],
                ['id' => 802, 'user_id' => 44, 'plan_id' => 7],
            ];

            (new PaymentFailedTrigger())->handle(
                $this->funnel(['plan_ids' => [7]]),
                [$grants, (object) ['id' => 55], ['attempt' => 2]]
            );

            self::assertCount(1, $GLOBALS['_fchub_test_trigger_sequences']);
            self::assertSame([44], $GLOBALS['_fchub_test_trigger_prepared_users']);
            self::assertSame(
                7,
                $GLOBALS['_fchub_test_trigger_sequences'][0][2]['source_ref_id']
            );
        }

        public function test_pause_emitter_reaches_the_registered_handler_with_grant_then_reason(): void
        {
            $this->registerUser(44, 'member@example.test');
            $this->bootAutomation();
            $this->registerFunnelBridge(
                'fchub_memberships/grant_paused',
                $this->funnel(['plan_ids' => [7], 'pause_reasons' => ['payment_failed']])
            );

            $grant = ['id' => 70, 'user_id' => 44, 'plan_id' => 7, 'status' => 'active', 'meta' => []];
            $repository = new class($grant) extends GrantRepository {
                public function __construct(private array $grant)
                {
                }

                public function find(int $id): ?array
                {
                    return $this->grant;
                }

                public function getByUserId(int $userId, array $filters = []): array
                {
                    return [$this->grant];
                }

                public function update(int $id, array $data): bool
                {
                    $this->grant = array_replace($this->grant, $data);
                    return true;
                }
            };
            $notifications = new GrantNotificationService(new class extends PlanRepository {
                public function find(int $id): ?array
                {
                    return null;
                }
            });

            (new GrantStatusService($repository, $notifications))->pauseGrant(70, 'Payment failed twice');

            self::assertSame(
                [[$grant, 'Payment failed twice']],
                $GLOBALS['_fchub_test_trigger_bridge_args']['fchub_memberships/grant_paused']
            );
            self::assertSequenceStartedBy('fchub_memberships/grant_paused', 7);
        }

        public function test_maintenance_pause_fallback_reaches_the_registered_handler_with_grant_then_reason(): void
        {
            $this->registerUser(44, 'member@example.test');
            $this->bootAutomation();
            $this->registerFunnelBridge(
                'fchub_memberships/grant_paused',
                $this->funnel(['plan_ids' => [7], 'pause_reasons' => ['manual']])
            );

            $grant = ['id' => 80, 'user_id' => 44, 'plan_id' => 7, 'status' => 'active', 'meta' => []];
            $repository = new class($grant) extends GrantRepository {
                public function __construct(private array $grant)
                {
                }

                public function getOverdueAnchorGrants(): array
                {
                    return [$this->grant];
                }

                public function update(int $id, array $data): bool
                {
                    $this->grant = array_replace($this->grant, $data);
                    return true;
                }

                public function find(int $id): ?array
                {
                    return $this->grant;
                }
            };

            (new GrantMaintenanceService(
                $repository,
                new class extends GrantSourceRepository {},
                null
            ))->pauseOverdueAnchorGrants();

            self::assertSame(
                [[$grant, 'Anchor billing date overdue']],
                $GLOBALS['_fchub_test_trigger_bridge_args']['fchub_memberships/grant_paused']
            );
            self::assertSequenceStartedBy('fchub_memberships/grant_paused', 7);
        }

        public function test_plan_grant_emitter_reaches_grant_and_trial_handlers_with_their_distinct_argument_order(): void
        {
            $this->registerUser(44, 'member@example.test');
            $GLOBALS['_fchub_test_options']['fchub_memberships_settings'] = [
                'membership_mode' => 'stack',
                'email_access_granted' => 'no',
            ];
            $this->bootAutomation();
            $this->registerFunnelBridge(
                'fchub_memberships/grant_created',
                $this->funnel(['plan_ids' => [7], 'source_types' => ['manual']])
            );
            $this->registerFunnelBridge('fchub_memberships/trial_started', $this->funnel(['plan_ids' => [7]]));

            $plan = [
                'id' => 7,
                'title' => 'Gold',
                'level' => 1,
                'trial_days' => 7,
                'duration_type' => 'lifetime',
                'meta' => [],
            ];
            $plans = new class($plan) extends PlanRepository {
                public function __construct(private array $plan)
                {
                }

                public function find(int $id): ?array
                {
                    return $this->plan;
                }
            };
            $memberGrants = new class extends GrantRepository {
                public function getByUserId(int $userId, array $filters = []): array
                {
                    return [];
                }
            };
            $creationGrants = new class extends GrantRepository {
                public function findByGrantKey(string $grantKey): ?array
                {
                    return null;
                }

                public function create(array $data): int
                {
                    return 76;
                }
            };
            $sources = new class extends GrantSourceRepository {
                public function addSource(int $grantId, string $sourceType, int $sourceId): bool
                {
                    return true;
                }
            };
            $drips = new class extends DripScheduleRepository {
                public function schedule(array $data): int
                {
                    return 1;
                }
            };
            $creation = new GrantCreationService(
                $creationGrants,
                $sources,
                $drips,
                new GrantAdapterRegistry(['contract' => TriggerGrantAdapterDouble::class])
            );
            $notifications = new GrantNotificationService($plans);
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
                $notifications
            );
            $rules = new class extends PlanRuleResolver {
                public function resolveUniqueRules(int $planId): array
                {
                    return [[
                        'id' => 901,
                        'provider' => 'contract',
                        'resource_type' => 'post',
                        'resource_id' => '99',
                        'drip_type' => 'immediate',
                        'drip_delay_days' => 0,
                    ]];
                }
            };
            $service = new PlanGrantExecutionService(
                $rules,
                new MembershipModeService($memberGrants, $plans),
                new GrantPlanContextService($plans, $memberGrants),
                $creation,
                $revocation,
                $notifications
            );

            $service->grantPlan(44, 7, ['source_type' => 'manual', 'source_id' => 500]);

            $grantArgs = $GLOBALS['_fchub_test_trigger_bridge_args']['fchub_memberships/grant_created'][0];
            $trialArgs = $GLOBALS['_fchub_test_trigger_bridge_args']['fchub_memberships/trial_started'][0];
            self::assertSame(44, $grantArgs[0]);
            self::assertSame(7, $grantArgs[1]);
            self::assertTrue($grantArgs[2]['is_trial']);
            self::assertSame($grantArgs[2], $trialArgs[0]);
            self::assertSame(7, $trialArgs[1]);
            self::assertSame(44, $trialArgs[2]);
            self::assertCount(2, $GLOBALS['_fchub_test_trigger_sequences']);
        }

        public function test_revocation_emitter_reaches_the_registered_handler_with_all_four_arguments(): void
        {
            $this->registerUser(44, 'member@example.test');
            $this->bootAutomation();
            $this->registerFunnelBridge('fchub_memberships/grant_revoked', $this->funnel(['plan_ids' => [7]]));

            $grant = [
                'id' => 71,
                'user_id' => 44,
                'plan_id' => 7,
                'provider' => 'wordpress_core',
                'resource_type' => 'post',
                'resource_id' => '99',
                'source_type' => 'manual',
                'source_ids' => [],
                'status' => 'active',
                'meta' => [],
            ];
            $grants = new class($grant) extends GrantRepository {
                public function __construct(private array $grant)
                {
                }

                public function getByUserId(int $userId, array $filters = []): array
                {
                    return [$this->grant];
                }

                public function update(int $id, array $data): bool
                {
                    $this->grant = array_replace($this->grant, $data);
                    return true;
                }

                public function find(int $id): ?array
                {
                    return $this->grant;
                }
            };
            $sources = new class extends GrantSourceRepository {
                public function removeAllByGrant(int $grantId): bool
                {
                    return true;
                }
            };
            $drips = new class extends DripScheduleRepository {
                public function deleteByGrantId(int $grantId): int
                {
                    return 1;
                }
            };
            $notifications = new GrantNotificationService(new class extends PlanRepository {
                public function find(int $id): ?array
                {
                    return null;
                }
            });

            (new GrantRevocationService(
                $grants,
                $sources,
                $drips,
                new GrantAdapterRegistry(),
                $notifications
            ))->revokePlan(44, 7, ['reason' => 'Owner request']);

            self::assertSame(
                [[[$grant], 7, 44, 'Owner request']],
                $GLOBALS['_fchub_test_trigger_bridge_args']['fchub_memberships/grant_revoked']
            );
            self::assertSequenceStartedBy('fchub_memberships/grant_revoked', 7);
        }

        public function test_source_revocation_emitter_reaches_the_registered_handler_with_grouped_ordered_payload(): void
        {
            $this->registerUser(44, 'member@example.test');
            $this->bootAutomation();
            $this->registerFunnelBridge('fchub_memberships/grant_revoked', $this->funnel(['plan_ids' => [7]]));

            $grant = $this->revocableGrant(81, [500]);
            $repository = new class($grant) extends GrantRepository {
                public function __construct(private array $grant)
                {
                }

                public function getBySourceId(int $sourceId, string $sourceType = 'order'): array
                {
                    return [$this->grant];
                }

                public function update(int $id, array $data): bool
                {
                    return true;
                }
            };

            $this->revocationService($repository)->revokeBySource(
                500,
                'subscription',
                ['reason' => 'Payment source ended']
            );

            self::assertSame(
                [[[$grant], 7, 44, 'Payment source ended']],
                $GLOBALS['_fchub_test_trigger_bridge_args']['fchub_memberships/grant_revoked']
            );
            self::assertSequenceStartedBy('fchub_memberships/grant_revoked', 7);
        }

        public function test_grace_period_revocation_emitter_reaches_the_registered_handler_with_stored_reason(): void
        {
            $this->registerUser(44, 'member@example.test');
            $this->bootAutomation();
            $this->registerFunnelBridge('fchub_memberships/grant_revoked', $this->funnel(['plan_ids' => [7]]));

            $grant = $this->revocableGrant(82, []);
            $grant['cancellation_reason'] = 'Grace window elapsed';
            $repository = new class($grant) extends GrantRepository {
                public function __construct(private array $grant)
                {
                }

                public function getDueGracePeriodGrants(int $limit = 100): array
                {
                    return [$this->grant];
                }

                public function update(int $id, array $data): bool
                {
                    return true;
                }
            };

            $this->revocationService($repository)->revokeExpiredGracePeriodGrants();

            self::assertSame(
                [[[$grant], 7, 44, 'Grace window elapsed']],
                $GLOBALS['_fchub_test_trigger_bridge_args']['fchub_memberships/grant_revoked']
            );
            self::assertSequenceStartedBy('fchub_memberships/grant_revoked', 7);
        }

        public function test_renewal_emitter_reaches_the_registered_handler_with_grant_then_count(): void
        {
            $this->registerUser(44, 'member@example.test');
            $this->bootAutomation();
            $this->registerFunnelBridge(
                'fchub_memberships/grant_renewed',
                $this->funnel(['plan_ids' => [7], 'min_renewal_count' => 4])
            );

            $grant = [
                'id' => 72,
                'user_id' => 44,
                'plan_id' => 7,
                'source_ids' => [],
                'renewal_count' => 3,
                'expires_at' => null,
                'meta' => [],
            ];
            $grants = new class($grant) extends GrantRepository {
                public function __construct(private array $grant)
                {
                }

                public function findByGrantKey(string $grantKey): ?array
                {
                    return $this->grant;
                }

                public function update(int $id, array $data): bool
                {
                    $this->grant = array_replace($this->grant, $data);
                    return true;
                }

                public function find(int $id): ?array
                {
                    return $this->grant;
                }
            };

            (new GrantCreationService(
                $grants,
                new class extends GrantSourceRepository {},
                new class extends DripScheduleRepository {},
                new GrantAdapterRegistry(['contract' => TriggerGrantAdapterDouble::class])
            ))->grantResource(44, 'contract', 'post', '99', ['plan_id' => 7]);

            $renewal = $GLOBALS['_fchub_test_trigger_bridge_args']['fchub_memberships/grant_renewed'][0];
            self::assertSame(4, $renewal[1]);
            self::assertSame(4, $renewal[0]['renewal_count']);
            self::assertSequenceStartedBy('fchub_memberships/grant_renewed', 7);
        }

        public function test_trial_conversion_emitter_reaches_the_registered_handler_with_grant_plan_and_user(): void
        {
            $this->registerUser(44, 'member@example.test');
            $GLOBALS['_fchub_test_options']['fchub_memberships_settings'] = ['email_trial_converted' => 'no'];
            $this->bootAutomation();
            $this->registerFunnelBridge('fchub_memberships/trial_converted', $this->funnel(['plan_ids' => [7]]));

            $grant = [
                'id' => 73,
                'user_id' => 44,
                'plan_id' => 7,
                'status' => 'active',
                'source_ids' => [500],
                'meta' => [],
            ];
            $service = new TrialLifecycleService();
            (new \ReflectionProperty(TrialLifecycleService::class, 'grantRepo'))->setValue(
                $service,
                new class($grant) extends GrantRepository {
                    public function __construct(private array $grant) {}
                    public function update(int $id, array $data): bool
                    {
                        $this->grant = array_replace($this->grant, $data);
                        return true;
                    }
                    public function find(int $id): ?array { return $this->grant; }
                }
            );
            (new \ReflectionProperty(TrialLifecycleService::class, 'planRepo'))->setValue(
                $service,
                new class extends PlanRepository {
                    public function find(int $id): ?array
                    {
                        return ['id' => $id, 'title' => 'Gold', 'duration_type' => 'lifetime', 'meta' => []];
                    }
                }
            );

            (new \ReflectionMethod(TrialLifecycleService::class, 'convertTrial'))->invoke($service, $grant);

            $conversion = $GLOBALS['_fchub_test_trigger_bridge_args']['fchub_memberships/trial_converted'][0];
            self::assertSame(7, $conversion[1]);
            self::assertSame(44, $conversion[2]);
            self::assertNotEmpty($conversion[0]['meta']['trial_converted_at']);
            self::assertSequenceStartedBy('fchub_memberships/trial_converted', 7);
        }

        public function test_drip_emitter_reaches_both_registered_handlers_without_reordering_payloads(): void
        {
            $this->registerUser(44, 'member@example.test');
            $GLOBALS['_fchub_test_options']['fchub_memberships_settings'] = ['email_drip_unlocked' => 'no'];
            $this->bootAutomation();
            $this->registerFunnelBridge('fchub_memberships/drip_unlocked', $this->funnel(['plan_ids' => [7]]));
            $this->registerFunnelBridge(
                'fchub_memberships/drip_milestone_reached',
                $this->funnel(['plan_ids' => [7], 'milestone_percentages' => [50]])
            );

            $notification = ['id' => 91, 'grant_id' => 74, 'user_id' => 44, 'retry_count' => 0, 'plan_rule_id' => 900];
            $grant = ['id' => 74, 'user_id' => 44, 'plan_id' => 7, 'status' => 'active', 'meta' => []];
            $drips = new class($notification) extends DripScheduleRepository {
                public function __construct(private array $notification)
                {
                }

                public function getPendingNotifications(int $limit = 50): array
                {
                    return [$this->notification];
                }

                public function markSent(int $id): bool
                {
                    return true;
                }

                public function getByGrantId(int $grantId): array
                {
                    return [
                        ['status' => 'sent'],
                        ['status' => 'sent'],
                        ['status' => 'pending'],
                        ['status' => 'pending'],
                    ];
                }
            };
            $grants = new class($grant) extends GrantRepository {
                public function __construct(private array $grant)
                {
                }

                public function find(int $id): ?array
                {
                    return $this->grant;
                }

                public function update(int $id, array $data): bool
                {
                    return true;
                }
            };
            $service = new DripScheduleService();
            (new \ReflectionProperty(DripScheduleService::class, 'dripRepo'))->setValue($service, $drips);
            (new \ReflectionProperty(DripScheduleService::class, 'grantRepo'))->setValue($service, $grants);

            $service->processNotifications();

            self::assertSame(
                [[$notification, $grant, 44]],
                $GLOBALS['_fchub_test_trigger_bridge_args']['fchub_memberships/drip_unlocked']
            );
            self::assertSame(
                [[$grant, 25, 44], [$grant, 50, 44]],
                $GLOBALS['_fchub_test_trigger_bridge_args']['fchub_memberships/drip_milestone_reached']
            );
            self::assertCount(2, $GLOBALS['_fchub_test_trigger_sequences']);
        }

        public function test_payment_failure_emitter_reaches_the_registered_handler_with_grants_subscription_and_event(): void
        {
            $this->registerUser(44, 'member@example.test');
            $this->bootAutomation();
            $this->registerFunnelBridge('fchub_memberships/payment_failed', $this->funnel(['plan_ids' => [7]]));

            $subscription = (object) ['id' => 501];
            $event = ['subscription' => $subscription, 'attempt' => 2];
            $grants = [['id' => 75, 'user_id' => 44, 'plan_id' => 7]];
            $repository = new class($grants) extends GrantRepository {
                public function __construct(private array $grants)
                {
                }

                public function getBySourceId(int $sourceId, string $sourceType = 'order'): array
                {
                    return $this->grants;
                }

                public function update(int $id, array $data): bool
                {
                    return true;
                }
            };

            (new SubscriptionPaymentFailureService($repository))->handle($event, 'subscription');

            $paymentFailure = $GLOBALS['_fchub_test_trigger_bridge_args']['fchub_memberships/payment_failed'][0];
            self::assertSame($subscription, $paymentFailure[1]);
            self::assertSame($event, $paymentFailure[2]);
            self::assertSame(501, $paymentFailure[0][0]['meta']['payment_incident']['subscription_id']);
            self::assertSequenceStartedBy('fchub_memberships/payment_failed', 7);
        }

        public function test_access_expiry_emitter_reaches_the_registered_handler_with_grant_then_days_left(): void
        {
            $this->registerUser(44, 'member@example.test');
            $GLOBALS['_fchub_test_options']['fchub_memberships_settings'] = [
                'email_access_expiring' => 'no',
                'expiry_notice_days' => 7,
            ];
            $this->bootAutomation();
            $this->registerFunnelBridge(
                'fchub_memberships/grant_expiring_soon',
                $this->funnel(['plan_ids' => [7], 'min_days_left' => 5, 'max_days_left' => 5])
            );

            $timezone = new \DateTimeZone('UTC');
            $clock = new Clock(new \DateTimeImmutable('2026-03-13 22:00:00', $timezone), $timezone);
            $expiresAt = $clock->storage($clock->plusDays(5));
            $GLOBALS['_fchub_test_wpdb_overrides']['get_results'] = static fn(): array => [(object) [
                'id' => 77,
                'user_id' => 44,
                'plan_id' => 7,
                'expires_at' => $expiresAt,
                'meta' => '{}',
            ]];
            $GLOBALS['_fchub_test_wpdb_overrides']['get_row'] = static fn(): object => (object) [
                'title' => 'Gold',
                'slug' => 'gold',
            ];

            (new AccessExpiringEmail($clock))->sendPendingNotifications();

            $arguments = $GLOBALS['_fchub_test_trigger_bridge_args']['fchub_memberships/grant_expiring_soon'];
            self::assertSame(77, $arguments[0][0]['id']);
            self::assertSame(44, $arguments[0][0]['user_id']);
            self::assertSame(7, $arguments[0][0]['plan_id']);
            self::assertSame(5, $arguments[0][1]);
            self::assertSequenceStartedBy('fchub_memberships/grant_expiring_soon', 7);
        }

        public function test_trial_expiry_notice_emitter_reaches_the_registered_handler_with_grant_then_days_left(): void
        {
            $this->registerUser(44, 'member@example.test');
            $GLOBALS['_fchub_test_options']['fchub_memberships_settings'] = [
                'email_trial_expiring' => 'no',
                'trial_expiry_notice_days' => 3,
            ];
            $this->bootAutomation();
            $this->registerFunnelBridge(
                'fchub_memberships/trial_expiring_soon',
                $this->funnel(['plan_ids' => [7], 'min_days_left' => 2, 'max_days_left' => 2])
            );

            $timezone = new \DateTimeZone('UTC');
            $clock = new Clock(new \DateTimeImmutable('2026-03-13 22:00:00', $timezone), $timezone);
            $trialEndsAt = $clock->storage($clock->plusDays(2));
            $GLOBALS['_fchub_test_wpdb_overrides']['get_results'] = static fn(): array => [[
                'id' => 78,
                'user_id' => 44,
                'plan_id' => 7,
                'trial_ends_at' => $trialEndsAt,
                'meta' => '{}',
            ]];
            $GLOBALS['_fchub_test_wpdb_overrides']['get_row'] = static fn(): object => (object) [
                'title' => 'Gold',
                'slug' => 'gold',
            ];
            $service = new TrialLifecycleService($clock);
            (new \ReflectionProperty(TrialLifecycleService::class, 'queries'))->setValue(
                $service,
                new TrialGrantQueryService()
            );

            $service->sendTrialExpiringNotifications();

            $arguments = $GLOBALS['_fchub_test_trigger_bridge_args']['fchub_memberships/trial_expiring_soon'];
            self::assertSame(78, $arguments[0][0]['id']);
            self::assertSame(44, $arguments[0][0]['user_id']);
            self::assertSame(7, $arguments[0][0]['plan_id']);
            self::assertSame(2, $arguments[0][1]);
            self::assertSequenceStartedBy('fchub_memberships/trial_expiring_soon', 7);
        }

        public function test_anniversary_emitter_reaches_the_registered_handler_with_grant_then_milestone_days(): void
        {
            $this->registerUser(44, 'member@example.test');
            $this->bootAutomation();
            $this->registerFunnelBridge(
                'fchub_memberships/grant_anniversary',
                $this->funnel(['plan_ids' => [7], 'milestone_days' => [365]])
            );

            $GLOBALS['_fchub_test_wpdb_overrides']['get_results'] = static function (string $query): array {
                if (!str_contains($query, '= 365')) {
                    return [];
                }

                return [[
                    'id' => '79',
                    'user_id' => '44',
                    'plan_id' => '7',
                    'source_id' => '500',
                    'feed_id' => null,
                    'renewal_count' => '0',
                    'source_ids' => '[]',
                    'meta' => '{}',
                    'status' => 'active',
                    'created_at' => '2025-01-01 00:00:00',
                ]];
            };

            MembershipAnniversaryTrigger::checkAnniversaries();

            $arguments = $GLOBALS['_fchub_test_trigger_bridge_args']['fchub_memberships/grant_anniversary'];
            self::assertSame(79, $arguments[0][0]['id']);
            self::assertSame(44, $arguments[0][0]['user_id']);
            self::assertSame(7, $arguments[0][0]['plan_id']);
            self::assertSame(365, $arguments[0][1]);
            self::assertSequenceStartedBy('fchub_memberships/grant_anniversary', 79);
        }

        private function funnel(array $conditions = []): object
        {
            return (object) [
                'id' => 51,
                'settings' => ['subscription_status' => 'subscribed'],
                'conditions' => $conditions,
            ];
        }

        private function bootAutomation(): void
        {
            if (!defined('FLUENTCRM')) {
                define('FLUENTCRM', 'test');
            }

            FluentCrmAutomation::boot();
        }

        private function registerFunnelBridge(string $hook, object $funnel): void
        {
            $arity = apply_filters('fluentcrm_funnel_arg_num_' . $hook, 1);
            add_action($hook, static function (...$args) use ($hook, $funnel, $arity): void {
                $args = array_slice($args, 0, $arity);
                $GLOBALS['_fchub_test_trigger_bridge_args'][$hook][] = $args;
                do_action('fluentcrm_funnel_start_' . $hook, $funnel, $args);
            }, 10, $arity);
        }

        private static function assertSequenceStartedBy(string $hook, int $sourceRefId): void
        {
            self::assertCount(1, $GLOBALS['_fchub_test_trigger_sequences']);
            self::assertSame($hook, $GLOBALS['_fchub_test_trigger_sequences'][0][2]['source_trigger_name']);
            self::assertSame($sourceRefId, $GLOBALS['_fchub_test_trigger_sequences'][0][2]['source_ref_id']);
        }

        private function revocableGrant(int $id, array $sourceIds): array
        {
            return [
                'id' => $id,
                'user_id' => 44,
                'plan_id' => 7,
                'provider' => 'wordpress_core',
                'resource_type' => 'post',
                'resource_id' => '99',
                'source_type' => 'subscription',
                'source_ids' => $sourceIds,
                'status' => 'active',
                'meta' => [],
            ];
        }

        private function revocationService(GrantRepository $repository): GrantRevocationService
        {
            return new GrantRevocationService(
                $repository,
                new class extends GrantSourceRepository {
                    public function removeAllByGrant(int $grantId): bool
                    {
                        return true;
                    }
                },
                new class extends DripScheduleRepository {
                    public function deleteByGrantId(int $grantId): int
                    {
                        return 1;
                    }
                },
                new GrantAdapterRegistry(),
                new GrantNotificationService(new class extends PlanRepository {
                    public function find(int $id): ?array
                    {
                        return null;
                    }
                })
            );
        }

        private function registerUser(int $id, string $email): void
        {
            $GLOBALS['_fchub_test_users'][$id] = (object) [
                'ID' => $id,
                'user_email' => $email,
            ];
        }

        /** @return array<string, list<int>> */
        private function membershipEventEmissions(): array
        {
            $root = dirname(__DIR__, 4) . '/app';
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
            $emissions = [];

            foreach ($iterator as $file) {
                if (!$file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }

                $contents = file_get_contents($file->getPathname());
                if ($contents === false || !str_contains($contents, 'do_action')) {
                    continue;
                }

                $tokens = token_get_all($contents);
                $count = count($tokens);

                for ($index = 0; $index < $count; $index++) {
                    $token = $tokens[$index];
                    if (!is_array($token) || $token[0] !== T_STRING || strtolower($token[1]) !== 'do_action') {
                        continue;
                    }

                    $openIndex = $index + 1;
                    while ($openIndex < $count && is_array($tokens[$openIndex])) {
                        $openIndex++;
                    }

                    if (($tokens[$openIndex] ?? null) !== '(') {
                        continue;
                    }

                    [$hook, $arity, $endIndex] = $this->parseActionCall($tokens, $openIndex);
                    $index = $endIndex;

                    if ($hook === null || !str_starts_with($hook, 'fchub_memberships/')) {
                        continue;
                    }

                    $emissions[$hook][] = $arity;
                }
            }

            foreach ($emissions as &$arities) {
                $arities = array_values(array_unique($arities));
                sort($arities);
            }

            return $emissions;
        }

        /**
         * @param list<array|string> $tokens
         * @return array{?string, int, int}
         */
        private function parseActionCall(array $tokens, int $openIndex): array
        {
            $parenthesisDepth = 0;
            $bracketDepth = 0;
            $braceDepth = 0;
            $commaCount = 0;
            $hasArgument = false;
            $hook = null;
            $count = count($tokens);

            for ($index = $openIndex; $index < $count; $index++) {
                $token = $tokens[$index];

                if (is_array($token)) {
                    if ($parenthesisDepth === 1 && $bracketDepth === 0 && $braceDepth === 0) {
                        if ($hook === null && $token[0] === T_CONSTANT_ENCAPSED_STRING) {
                            $hook = stripcslashes(substr($token[1], 1, -1));
                        }

                        if (!in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                            $hasArgument = true;
                        }
                    }

                    continue;
                }

                if ($token === '(') {
                    $parenthesisDepth++;
                } elseif ($token === ')') {
                    if ($parenthesisDepth === 1) {
                        return [$hook, $hasArgument ? $commaCount : 0, $index];
                    }
                    $parenthesisDepth--;
                } elseif ($token === '[') {
                    $bracketDepth++;
                } elseif ($token === ']') {
                    $bracketDepth--;
                } elseif ($token === '{') {
                    $braceDepth++;
                } elseif ($token === '}') {
                    $braceDepth--;
                } elseif (
                    $token === ','
                    && $parenthesisDepth === 1
                    && $bracketDepth === 0
                    && $braceDepth === 0
                ) {
                    $commaCount++;
                }
            }

            return [$hook, 0, $count - 1];
        }
    }
}

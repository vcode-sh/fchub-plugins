<?php

declare(strict_types=1);

namespace FluentCrm\App\Services\Funnel {
    if (!class_exists(BaseAction::class)) {
        class BaseAction
        {
            public string $actionName = '';
            public int $priority = 10;

            public function __construct()
            {
            }
        }
    }

    if (!class_exists(FunnelHelper::class)) {
        class FunnelHelper
        {
            public static function changeFunnelSubSequenceStatus(
                mixed $funnelSubscriberId,
                mixed $sequenceId,
                string $status
            ): void {
                $GLOBALS['_fchub_test_funnel_statuses'][] = [$funnelSubscriberId, $sequenceId, $status];
            }
        }
    }
}

namespace FluentCrm\Framework\Support {
    if (!class_exists(Arr::class)) {
        class Arr
        {
            public static function get(array $values, string $key, mixed $default = null): mixed
            {
                return array_key_exists($key, $values) ? $values[$key] : $default;
            }
        }
    }
}

namespace FChubMemberships\Tests\Unit\FluentCRM\Actions {

    use FChubMemberships\FluentCRM\Actions\ChangeMembershipPlanAction;
    use FChubMemberships\FluentCRM\Actions\Contracts\MembershipActionRuntimeInterface;
    use FChubMemberships\FluentCRM\Actions\MembershipActionOutcome;
    use FChubMemberships\Domain\MembershipPlanChangePublisher;
    use FChubMemberships\Tests\Unit\PluginTestCase;
    use PHPUnit\Framework\Attributes\DataProvider;

    final class ChangeMembershipPlanActionTest extends PluginTestCase
    {
        protected function setUp(): void
        {
            parent::setUp();
            $GLOBALS['_fchub_test_funnel_statuses'] = [];
        }

        public function test_invalid_destination_plan_is_skipped(): void
        {
            $runtime = new ChangeMembershipPlanRuntimeSpy();

            (new ChangeMembershipPlanAction($runtime))->handle(
                $this->subscriber(),
                $this->sequence(['to_plan_id' => '']),
                77,
                (object) []
            );

            self::assertSame([[77, 91, 'skipped']], $GLOBALS['_fchub_test_funnel_statuses']);
            self::assertSame([], $runtime->revokeCalls);
            self::assertSame([], $runtime->grantCalls);
        }

        public function test_stale_destination_plan_is_skipped_before_source_lookup_or_revoke(): void
        {
            $runtime = $this->runtimeWithActiveGrant();
            $runtime->planExists = false;

            (new ChangeMembershipPlanAction($runtime))->handle(
                $this->subscriber(),
                $this->sequence(['from_plan_id' => 5, 'to_plan_id' => 404]),
                77,
                (object) []
            );

            self::assertSame([], $runtime->activeGrantCalls);
            self::assertSame([], $runtime->revokeCalls);
            self::assertSame([], $runtime->grantCalls);
            self::assertSame([[77, 91, 'skipped']], $GLOBALS['_fchub_test_funnel_statuses']);
        }

        public function test_invalid_contact_is_skipped(): void
        {
            $runtime = $this->runtimeWithActiveGrant();
            $subscriber = (object) ['user_id' => 0, 'email' => 'missing@example.test'];

            (new ChangeMembershipPlanAction($runtime))->handle(
                $subscriber,
                $this->sequence(['from_plan_id' => 5, 'to_plan_id' => 8]),
                77,
                (object) []
            );

            self::assertSame([], $runtime->activeGrantCalls);
            self::assertSame([], $runtime->revokeCalls);
            self::assertSame([], $runtime->grantCalls);
            self::assertSame([[77, 91, 'skipped']], $GLOBALS['_fchub_test_funnel_statuses']);
        }

        public function test_missing_source_grant_is_skipped(): void
        {
            $runtime = new ChangeMembershipPlanRuntimeSpy();

            (new ChangeMembershipPlanAction($runtime))->handle(
                $this->subscriber(),
                $this->sequence(['from_plan_id' => 5, 'to_plan_id' => 8]),
                77,
                (object) []
            );

            self::assertSame([], $runtime->revokeCalls);
            self::assertSame([], $runtime->grantCalls);
            self::assertSame([[77, 91, 'skipped']], $GLOBALS['_fchub_test_funnel_statuses']);
        }

        public function test_destination_plan_lookup_exception_is_skipped_and_reported_without_secret_text(): void
        {
            $runtime = $this->runtimeWithActiveGrant();
            $runtime->planExistsFailure = new \RuntimeException('api_key=super-secret');
            $reported = null;

            add_action(
                'fchub_memberships/fluentcrm_action_failed',
                static function (string $actionName, MembershipActionOutcome $outcome) use (&$reported): void {
                    $reported = [$actionName, $outcome];
                },
                10,
                2
            );

            (new ChangeMembershipPlanAction($runtime))->handle(
                $this->subscriber(),
                $this->sequence(['from_plan_id' => 5, 'to_plan_id' => 8]),
                77,
                (object) []
            );

            self::assertSame([[77, 91, 'skipped']], $GLOBALS['_fchub_test_funnel_statuses']);
            self::assertSame([], $runtime->activeGrantCalls);
            self::assertSame([], $runtime->revokeCalls);
            self::assertSame([], $runtime->grantCalls);
            self::assertNotNull($reported);
            self::assertSame('fchub_change_membership_plan', $reported[0]);
            self::assertSame('destinationPlanLookup', $reported[1]->details['stage']);
            self::assertSame('runtime_exception', $reported[1]->reason);
            self::assertStringNotContainsString('super-secret', serialize($reported));
        }

        #[DataProvider('incompleteRevokeResults')]
        public function test_incomplete_revocation_is_skipped_and_never_grants(array $revokeResult): void
        {
            $runtime = $this->runtimeWithActiveGrant();
            $runtime->revokeResult = $revokeResult;

            (new ChangeMembershipPlanAction($runtime))->handle(
                $this->subscriber(),
                $this->sequence(['from_plan_id' => 5, 'to_plan_id' => 8]),
                77,
                (object) []
            );

            self::assertSame([], $runtime->grantCalls);
            self::assertSame([[77, 91, 'skipped']], $GLOBALS['_fchub_test_funnel_statuses']);
        }

        #[DataProvider('incompleteGrantResults')]
        public function test_incomplete_destination_grant_is_skipped_after_complete_revocation(array $grantResult): void
        {
            $runtime = $this->runtimeWithActiveGrant();
            $runtime->revokeResult = self::completeRevokeResult();
            $runtime->grantResult = $grantResult;

            (new ChangeMembershipPlanAction($runtime))->handle(
                $this->subscriber(),
                $this->sequence(['from_plan_id' => 5, 'to_plan_id' => 8]),
                77,
                (object) []
            );

            self::assertCount(1, $runtime->grantCalls);
            self::assertSame([[77, 91, 'skipped']], $GLOBALS['_fchub_test_funnel_statuses']);
        }

        #[DataProvider('runtimeFailureStages')]
        public function test_runtime_exceptions_are_skipped_and_reported_without_secret_text(string $stage): void
        {
            $runtime = $this->runtimeWithActiveGrant();
            $runtime->revokeResult = self::completeRevokeResult();
            $runtime->grantResult = ['created' => 1, 'updated' => 0, 'total' => 1, 'failed' => 0];
            $runtime->{$stage . 'Failure'} = new \RuntimeException('api_key=super-secret');
            $reported = null;

            add_action(
                'fchub_memberships/fluentcrm_action_failed',
                static function (string $actionName, MembershipActionOutcome $outcome) use (&$reported): void {
                    $reported = [$actionName, $outcome];
                },
                10,
                2
            );

            (new ChangeMembershipPlanAction($runtime))->handle(
                $this->subscriber(),
                $this->sequence(['from_plan_id' => 5, 'to_plan_id' => 8]),
                77,
                (object) []
            );

            self::assertSame([[77, 91, 'skipped']], $GLOBALS['_fchub_test_funnel_statuses']);
            self::assertNotNull($reported);
            self::assertSame('fchub_change_membership_plan', $reported[0]);
            self::assertSame($stage, $reported[1]->details['stage']);
            self::assertSame('runtime_exception', $reported[1]->reason);
            self::assertStringNotContainsString('super-secret', serialize($reported));
        }

        public function test_complete_revocation_and_grant_succeed_without_sequence_source_ids(): void
        {
            $runtime = $this->runtimeWithActiveGrant();
            $runtime->revokeResult = self::completeRevokeResult();
            $runtime->grantResult = ['created' => 1, 'updated' => 0, 'total' => 1, 'failed' => 0];

            (new ChangeMembershipPlanAction($runtime))->handle(
                $this->subscriber(),
                $this->sequence([
                    'from_plan_id' => 5,
                    'to_plan_id' => 8,
                    'keep_expiry' => 'yes',
                ]),
                77,
                (object) []
            );

            self::assertSame([[
                21,
                5,
                [
                    'reason' => 'Changed to plan #8',
                    'grace_period_days' => 0,
                ],
            ]], $runtime->revokeCalls);
            self::assertSame([[
                21,
                8,
                [
                    'source_type' => 'automation',
                    'plan_change' => [
                        'change_type' => 'automation_change',
                        'from_plan_ids' => [5],
                    ],
                    'expires_at' => '2026-09-01 00:00:00',
                ],
            ]], $runtime->grantCalls);
            self::assertSame([], $GLOBALS['_fchub_test_funnel_statuses']);
        }

        public function test_sequence_id_never_reaches_the_published_plan_change_envelope(): void
        {
            $runtime = $this->runtimeWithActiveGrant();
            $runtime->revokeResult = self::completeRevokeResult();
            $runtime->grantResult = ['created' => 1, 'updated' => 0, 'total' => 1, 'failed' => 0];
            $runtime->publishPlanChange = true;
            $published = null;
            add_action(
                'fchub_memberships/plan_changed',
                static function (array $envelope) use (&$published): void {
                    $published = $envelope;
                }
            );

            (new ChangeMembershipPlanAction($runtime))->handle(
                $this->subscriber(),
                $this->sequence(['from_plan_id' => 5, 'to_plan_id' => 8]),
                77,
                (object) []
            );

            self::assertNotNull($published);
            self::assertSame('automation', $published['source_type']);
            self::assertSame('automation_change', $published['change_type']);
            self::assertSame(0, $published['source_id']);
            self::assertNotSame(91, $published['source_id']);
        }

        public static function incompleteRevokeResults(): array
        {
            return [
                'failed' => [[
                    'success' => false,
                    'partial' => false,
                    'revoked' => 0,
                    'retained' => 0,
                    'failed' => 1,
                ]],
                'partial' => [[
                    'success' => false,
                    'partial' => true,
                    'revoked' => 1,
                    'retained' => 0,
                    'failed' => 1,
                ]],
                'retained old plan' => [[
                    'success' => true,
                    'partial' => false,
                    'revoked' => 0,
                    'retained' => 1,
                    'failed' => 0,
                ]],
                'zero revoked' => [[
                    'success' => true,
                    'partial' => false,
                    'revoked' => 0,
                    'retained' => 0,
                    'failed' => 0,
                ]],
            ];
        }

        public static function incompleteGrantResults(): array
        {
            return [
                'failed' => [['created' => 0, 'updated' => 0, 'total' => 1, 'failed' => 1]],
                'blocked' => [[
                    'created' => 0,
                    'updated' => 0,
                    'total' => 0,
                    'blocked' => true,
                    'reason' => 'downgrade_blocked',
                ]],
                'partial' => [[
                    'created' => 1,
                    'updated' => 0,
                    'total' => 2,
                    'failed' => 1,
                    'partial' => true,
                ]],
            ];
        }

        public static function runtimeFailureStages(): array
        {
            return [
                'active grant lookup' => ['activeGrantLookup'],
                'revocation' => ['revoke'],
                'destination grant' => ['grant'],
            ];
        }

        private function runtimeWithActiveGrant(): ChangeMembershipPlanRuntimeSpy
        {
            $runtime = new ChangeMembershipPlanRuntimeSpy();
            $runtime->activeGrants = [['plan_id' => 5, 'expires_at' => '2026-09-01 00:00:00']];

            return $runtime;
        }

        private function subscriber(): object
        {
            return (object) ['user_id' => 21, 'email' => 'member@example.test'];
        }

        private function sequence(array $settings): object
        {
            return (object) ['id' => 91, 'settings' => $settings];
        }

        private static function completeRevokeResult(): array
        {
            return [
                'success' => true,
                'partial' => false,
                'revoked' => 1,
                'retained' => 0,
                'failed' => 0,
            ];
        }
    }

    final class ChangeMembershipPlanRuntimeSpy implements MembershipActionRuntimeInterface
    {
        public bool $planExists = true;
        public array $activeGrants = [];
        public array $revokeResult = [];
        public array $grantResult = [];
        public ?\Throwable $planExistsFailure = null;
        public ?\Throwable $activeGrantLookupFailure = null;
        public ?\Throwable $revokeFailure = null;
        public ?\Throwable $grantFailure = null;
        public array $activeGrantCalls = [];
        public array $revokeCalls = [];
        public array $grantCalls = [];
        public bool $publishPlanChange = false;

        public function planExists(int $planId): bool
        {
            if ($this->planExistsFailure) {
                throw $this->planExistsFailure;
            }

            return $this->planExists;
        }

        public function getActiveGrants(int $userId, ?int $planId): array
        {
            $this->activeGrantCalls[] = [$userId, $planId];
            if ($this->activeGrantLookupFailure) {
                throw $this->activeGrantLookupFailure;
            }

            return $this->activeGrants;
        }

        public function getPausedGrants(int $userId, ?int $planId): array
        {
            return [];
        }

        public function revokePlan(int $userId, int $planId, array $context): array
        {
            if ($this->revokeFailure) {
                throw $this->revokeFailure;
            }
            $this->revokeCalls[] = [$userId, $planId, $context];

            return $this->revokeResult;
        }

        public function grantPlan(int $userId, int $planId, array $context): array
        {
            if ($this->grantFailure) {
                throw $this->grantFailure;
            }
            $this->grantCalls[] = [$userId, $planId, $context];
            if ($this->publishPlanChange && !empty($context['plan_change'])) {
                (new MembershipPlanChangePublisher())->publish(
                    $userId,
                    $context['plan_change']['from_plan_ids'],
                    $planId,
                    $context['plan_change']['change_type'],
                    $context
                );
            }

            return $this->grantResult;
        }

        public function pauseGrant(int $grantId, string $reason): array
        {
            return ['success' => true];
        }

        public function resumeGrant(int $grantId): array
        {
            return ['success' => true];
        }

        public function extendExpiry(int $userId, int $planId, string $newExpiresAt): int
        {
            return 1;
        }
    }
}

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
    use FChubMemberships\FluentCRM\Actions\ExtendMembershipAction;
    use FChubMemberships\FluentCRM\Actions\GrantMembershipAction;
    use FChubMemberships\FluentCRM\Actions\MembershipActionOutcome;
    use FChubMemberships\FluentCRM\Actions\PauseMembershipAction;
    use FChubMemberships\FluentCRM\Actions\ResumeMembershipAction;
    use FChubMemberships\FluentCRM\Actions\RevokeMembershipAction;
    use FChubMemberships\Tests\Unit\PluginTestCase;
    use PHPUnit\Framework\Attributes\DataProvider;

    final class MembershipActionsTest extends PluginTestCase
    {
        protected function setUp(): void
        {
            parent::setUp();
            $GLOBALS['_fchub_test_funnel_statuses'] = [];
        }

        #[DataProvider('invalidInputActions')]
        public function test_invalid_contact_or_required_plan_input_is_skipped(string $actionClass, array $settings): void
        {
            $runtime = new MembershipActionsRuntimeSpy();

            (new $actionClass($runtime))->handle(
                (object) ['user_id' => 0, 'email' => 'missing@example.test'],
                $this->sequence($settings),
                77,
                (object) []
            );

            self::assertSame([[77, 91, 'skipped']], $GLOBALS['_fchub_test_funnel_statuses']);
            self::assertSame([], $runtime->mutations);
        }

        #[DataProvider('serviceFailureActions')]
        public function test_service_failure_is_skipped(string $actionClass, array $settings, string $failure): void
        {
            $runtime = new MembershipActionsRuntimeSpy();
            $runtime->failure = $failure;

            (new $actionClass($runtime))->handle(
                $this->subscriber(),
                $this->sequence($settings),
                77,
                (object) []
            );

            self::assertSame([[77, 91, 'skipped']], $GLOBALS['_fchub_test_funnel_statuses']);
        }

        #[DataProvider('successfulActions')]
        public function test_successful_mutation_is_not_changed_to_skipped(string $actionClass, array $settings): void
        {
            $runtime = new MembershipActionsRuntimeSpy();

            (new $actionClass($runtime))->handle(
                $this->subscriber(),
                $this->sequence($settings),
                77,
                (object) []
            );

            self::assertNotEmpty($runtime->mutations);
            self::assertSame([], $GLOBALS['_fchub_test_funnel_statuses']);
        }

        #[DataProvider('partiallyThrowingStatusActions')]
        public function test_status_exception_after_an_earlier_mutation_reports_partial_without_secrets(
            string $actionClass,
            string $failure
        ): void {
            $runtime = new MembershipActionsRuntimeSpy();
            $runtime->failure = $failure;
            $runtime->multipleGrants = true;
            $reported = null;
            add_action(
                'fchub_memberships/fluentcrm_action_failed',
                static function (string $actionName, MembershipActionOutcome $outcome) use (&$reported): void {
                    $reported = [$actionName, $outcome];
                },
                10,
                2
            );

            (new $actionClass($runtime))->handle(
                $this->subscriber(),
                $this->sequence([]),
                77,
                (object) []
            );

            self::assertSame([[77, 91, 'skipped']], $GLOBALS['_fchub_test_funnel_statuses']);
            self::assertNotNull($reported);
            self::assertTrue($reported[1]->partial);
            self::assertSame(1, $reported[1]->details['affected']);
            self::assertStringNotContainsString('super-secret', serialize($reported));
            self::assertStringNotContainsString('private/trace.php', serialize($reported));
        }

        public static function invalidInputActions(): array
        {
            return [
                'grant invalid contact' => [GrantMembershipAction::class, ['plan_id' => 5]],
                'revoke invalid contact' => [RevokeMembershipAction::class, ['plan_id' => 5]],
                'pause invalid contact' => [PauseMembershipAction::class, []],
                'resume invalid contact' => [ResumeMembershipAction::class, []],
                'extend invalid contact' => [ExtendMembershipAction::class, ['plan_id' => 5, 'extend_days' => 10]],
                'change invalid contact' => [ChangeMembershipPlanAction::class, ['from_plan_id' => 5, 'to_plan_id' => 8]],
                'grant invalid plan' => [GrantMembershipAction::class, ['plan_id' => '']],
                'revoke invalid plan' => [RevokeMembershipAction::class, ['plan_id' => '']],
                'extend invalid plan' => [ExtendMembershipAction::class, ['plan_id' => '', 'extend_days' => 10]],
            ];
        }

        public static function serviceFailureActions(): array
        {
            return [
                'grant' => [GrantMembershipAction::class, ['plan_id' => 5], 'grant'],
                'revoke' => [RevokeMembershipAction::class, ['plan_id' => 5], 'revoke'],
                'pause' => [PauseMembershipAction::class, [], 'pause'],
                'resume' => [ResumeMembershipAction::class, [], 'resume'],
                'extend' => [ExtendMembershipAction::class, ['plan_id' => 5, 'extend_days' => 10], 'extend'],
                'change' => [ChangeMembershipPlanAction::class, ['from_plan_id' => 5, 'to_plan_id' => 8], 'revoke'],
            ];
        }

        public static function successfulActions(): array
        {
            return [
                'grant' => [GrantMembershipAction::class, ['plan_id' => 5]],
                'revoke' => [RevokeMembershipAction::class, ['plan_id' => 5]],
                'pause' => [PauseMembershipAction::class, []],
                'resume' => [ResumeMembershipAction::class, []],
                'extend' => [ExtendMembershipAction::class, ['plan_id' => 5, 'extend_days' => 10]],
                'change' => [ChangeMembershipPlanAction::class, ['from_plan_id' => 5, 'to_plan_id' => 8]],
            ];
        }

        public static function partiallyThrowingStatusActions(): array
        {
            return [
                'pause' => [PauseMembershipAction::class, 'pause_throw_second'],
                'resume' => [ResumeMembershipAction::class, 'resume_throw_second'],
            ];
        }

        private function subscriber(): object
        {
            return (object) ['user_id' => 21, 'email' => 'member@example.test'];
        }

        private function sequence(array $settings): object
        {
            return (object) ['id' => 91, 'settings' => $settings];
        }
    }

    final class MembershipActionsRuntimeSpy implements MembershipActionRuntimeInterface
    {
        public string $failure = '';
        public array $mutations = [];
        public bool $multipleGrants = false;

        public function planExists(int $planId): bool
        {
            return true;
        }

        public function getActiveGrants(int $userId, ?int $planId): array
        {
            $grants = [['id' => 12, 'plan_id' => $planId ?? 5, 'expires_at' => '2026-09-01 00:00:00']];
            if ($this->multipleGrants) {
                $grants[] = ['id' => 13, 'plan_id' => $planId ?? 5, 'expires_at' => '2026-09-01 00:00:00'];
            }

            return $grants;
        }

        public function getPausedGrants(int $userId, ?int $planId): array
        {
            return $this->getActiveGrants($userId, $planId);
        }

        public function revokePlan(int $userId, int $planId, array $context): array
        {
            if ($this->failure === 'revoke') {
                return ['success' => false, 'failed' => 1];
            }

            $this->mutations[] = ['revoke', $userId, $planId, $context];
            return ['success' => true, 'revoked' => 1, 'retained' => 0, 'failed' => 0];
        }

        public function grantPlan(int $userId, int $planId, array $context): array
        {
            if ($this->failure === 'grant') {
                return ['created' => 0, 'failed' => 1];
            }

            $this->mutations[] = ['grant', $userId, $planId, $context];
            return ['created' => 1, 'updated' => 0, 'total' => 1];
        }

        public function pauseGrant(int $grantId, string $reason): array
        {
            if ($this->failure === 'pause_throw_second' && count($this->mutations) === 1) {
                throw new \RuntimeException('token=super-secret at /private/trace.php:42');
            }
            if ($this->failure === 'pause') {
                return ['success' => false];
            }

            $this->mutations[] = ['pause', $grantId, $reason];
            return ['success' => true];
        }

        public function resumeGrant(int $grantId): array
        {
            if ($this->failure === 'resume_throw_second' && count($this->mutations) === 1) {
                throw new \RuntimeException('token=super-secret at /private/trace.php:42');
            }
            if ($this->failure === 'resume') {
                return ['success' => false];
            }

            $this->mutations[] = ['resume', $grantId];
            return ['success' => true];
        }

        public function extendExpiry(int $userId, int $planId, string $newExpiresAt): int
        {
            if ($this->failure === 'extend') {
                return 0;
            }

            $this->mutations[] = ['extend', $userId, $planId, $newExpiresAt];
            return 1;
        }
    }
}

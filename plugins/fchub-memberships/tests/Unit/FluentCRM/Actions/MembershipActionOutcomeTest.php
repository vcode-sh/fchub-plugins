<?php

declare(strict_types=1);

namespace FluentCrm\App\Services\Funnel {
    if (!class_exists(FunnelHelper::class)) {
        final class FunnelHelper
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

namespace FChubMemberships\Tests\Unit\FluentCRM\Actions {

    use FChubMemberships\FluentCRM\Actions\MembershipActionOutcome;
    use FChubMemberships\Tests\Unit\PluginTestCase;
    use PHPUnit\Framework\Attributes\DataProvider;

    final class MembershipActionOutcomeTest extends PluginTestCase
    {
        protected function setUp(): void
        {
            parent::setUp();
            $GLOBALS['_fchub_test_funnel_statuses'] = [];
        }

        #[DataProvider('grantResultMatrix')]
        public function test_grant_result_matrix_is_truthful(array $result, bool $successful, string $reason): void
        {
            $outcome = MembershipActionOutcome::fromGrantResult($result);

            self::assertSame($successful, $outcome->isSuccessful());
            self::assertSame($reason, $outcome->reason);
        }

        #[DataProvider('revokeResultMatrix')]
        public function test_revoke_result_matrix_is_truthful(array $result, bool $successful, string $reason): void
        {
            $outcome = MembershipActionOutcome::fromRevokeResult($result);

            self::assertSame($successful, $outcome->isSuccessful());
            self::assertSame($reason, $outcome->reason);
        }

        public function test_affected_rows_requires_at_least_one_mutation(): void
        {
            self::assertTrue(MembershipActionOutcome::fromAffectedRows(1)->isSuccessful());
            self::assertFalse(MembershipActionOutcome::fromAffectedRows(0)->isSuccessful());
        }

        public function test_failure_details_drop_arbitrary_error_text(): void
        {
            $outcome = MembershipActionOutcome::fromGrantResult([
                'created' => 0,
                'updated' => 0,
                'failed' => 1,
                'errors' => [[
                    'message' => 'api_key=super-secret at /private/path/trace.php:42',
                    'trace' => 'do not leak this trace',
                ]],
            ]);

            self::assertFalse($outcome->isSuccessful());
            self::assertStringNotContainsString('super-secret', serialize($outcome->details));
            self::assertStringNotContainsString('trace.php', serialize($outcome->details));
        }

        public function test_thrown_service_outcome_drops_exception_message_and_trace(): void
        {
            $outcome = MembershipActionOutcome::fromThrowable(
                new \RuntimeException('token=super-secret at /private/path/trace.php:42')
            );

            self::assertFalse($outcome->isSuccessful());
            self::assertSame('runtime_exception', $outcome->reason);
            self::assertStringNotContainsString('super-secret', serialize($outcome->details));
            self::assertStringNotContainsString('trace.php', serialize($outcome->details));
        }

        public function test_skip_marks_sequence_logs_sanitised_payload_and_emits_outcome(): void
        {
            $reported = null;
            add_action(
                'fchub_memberships/fluentcrm_action_failed',
                static function (string $actionName, MembershipActionOutcome $outcome) use (&$reported): void {
                    $reported = [$actionName, $outcome];
                },
                10,
                2
            );
            $outcome = MembershipActionOutcome::fromGrantResult([
                'created' => 0,
                'failed' => 1,
                'errors' => ['token=super-secret'],
            ]);

            $outcome->skip(70, 91, 'fchub_grant_membership');

            self::assertSame([[70, 91, 'skipped']], $GLOBALS['_fchub_test_funnel_statuses']);
            self::assertSame('fchub_grant_membership', $reported[0]);
            self::assertSame($outcome, $reported[1]);
            self::assertStringNotContainsString('super-secret', serialize($GLOBALS['_fchub_test_fc_error_logs']));
        }

        public static function grantResultMatrix(): array
        {
            return [
                'complete' => [[
                    'created' => 1,
                    'updated' => 0,
                    'total' => 1,
                ], true, 'complete'],
                'partial' => [[
                    'created' => 1,
                    'updated' => 0,
                    'total' => 2,
                    'partial' => true,
                ], false, 'partial'],
                'blocked' => [[
                    'created' => 0,
                    'updated' => 0,
                    'blocked' => true,
                ], false, 'blocked'],
                'failed' => [[
                    'created' => 0,
                    'updated' => 0,
                    'failed' => 1,
                ], false, 'failed'],
                'zero affected rows' => [[
                    'created' => 0,
                    'updated' => 0,
                ], false, 'zero_rows'],
            ];
        }

        public static function revokeResultMatrix(): array
        {
            return [
                'complete' => [[
                    'success' => true,
                    'revoked' => 1,
                    'retained' => 0,
                    'failed' => 0,
                ], true, 'complete'],
                'partial' => [[
                    'success' => false,
                    'partial' => true,
                    'revoked' => 1,
                    'failed' => 1,
                ], false, 'partial'],
                'retained only' => [[
                    'success' => true,
                    'revoked' => 0,
                    'retained' => 1,
                    'failed' => 0,
                ], false, 'retained'],
                'deferred grace' => [[
                    'success' => true,
                    'revoked' => 0,
                    'grace_started' => 2,
                    'retained' => 0,
                    'failed' => 0,
                ], true, 'deferred'],
                'failed' => [[
                    'success' => false,
                    'revoked' => 0,
                    'failed' => 1,
                ], false, 'failed'],
                'zero revoked' => [[
                    'success' => true,
                    'revoked' => 0,
                    'retained' => 0,
                    'failed' => 0,
                ], false, 'zero_rows'],
            ];
        }
    }
}

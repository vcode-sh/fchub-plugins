<?php

declare(strict_types=1);

namespace FChubMemberships\Tests\Unit\Integration {

    use FChubMemberships\Http\Controllers\MemberController;
    use FChubMemberships\Http\Controllers\ProviderReconciliationController;
    use FChubMemberships\Integration\MembershipAccessIntegration;
    use FChubMemberships\Support\Clock;
    use FChubMemberships\Tests\Unit\PluginTestCase;

    final class ProviderFailureCallerContractTest extends PluginTestCase
    {
        public function test_membership_access_duration_writer_uses_site_local_clock(): void
        {
            $this->ensureIntegrationBaseClass();
            $timezone = new \DateTimeZone('Europe/Warsaw');
            $clock = new Clock(new \DateTimeImmutable('2026-03-28 12:30:00', $timezone), $timezone);
            $integration = new MembershipAccessIntegration($clock);
            $method = new \ReflectionMethod($integration, 'calculateExpiresAt');

            $expiresAt = $method->invoke(
                $integration,
                ['validity_mode' => 'fixed_duration', 'validity_days' => 1],
                new \stdClass()
            );

            self::assertSame('2026-03-29 12:30:00', $expiresAt);
        }

        public function test_member_controller_grant_response_exposes_total_and_partial_provider_failures(): void
        {
            self::assertTrue(method_exists(MemberController::class, 'grantResultResponse'));
            if (!method_exists(MemberController::class, 'grantResultResponse')) {
                return;
            }

            $failed = MemberController::grantResultResponse([
                'created' => 0,
                'updated' => 0,
                'total' => 1,
                'failed' => 1,
                'errors' => [['message' => 'Provider unavailable']],
            ]);
            $partial = MemberController::grantResultResponse([
                'created' => 1,
                'updated' => 0,
                'total' => 2,
                'failed' => 1,
                'errors' => [['message' => 'Provider unavailable']],
            ]);

            self::assertSame(502, $failed->get_status());
            self::assertStringContainsString('could not be granted', $failed->get_data()['message']);
            self::assertSame(207, $partial->get_status());
            self::assertStringContainsString('partially granted', $partial->get_data()['message']);
            self::assertSame('Provider unavailable', $partial->get_data()['data']['errors'][0]['message']);
        }

        public function test_member_controller_revoke_response_exposes_total_and_partial_provider_failures(): void
        {
            self::assertTrue(method_exists(MemberController::class, 'revokeResultResponse'));
            if (!method_exists(MemberController::class, 'revokeResultResponse')) {
                return;
            }

            $failed = MemberController::revokeResultResponse([
                'success' => false,
                'partial' => false,
                'revoked' => 0,
                'retained' => 0,
                'failed' => 1,
                'errors' => [['message' => 'Provider unavailable']],
            ]);
            $partial = MemberController::revokeResultResponse([
                'success' => false,
                'partial' => true,
                'revoked' => 1,
                'retained' => 0,
                'failed' => 1,
                'errors' => [['message' => 'Provider unavailable']],
            ]);

            self::assertSame(502, $failed->get_status());
            self::assertStringContainsString('could not be revoked', $failed->get_data()['message']);
            self::assertSame(207, $partial->get_status());
            self::assertStringContainsString('partially revoked', $partial->get_data()['message']);
        }

        public function test_member_controller_reports_deferred_grace_without_claiming_access_ended(): void
        {
            $response = MemberController::revokeResultResponse([
                'success' => true,
                'partial' => false,
                'revoked' => 0,
                'grace_started' => 2,
                'retained' => 0,
                'failed' => 0,
                'errors' => [],
            ]);

            self::assertSame(200, $response->get_status());
            self::assertStringContainsString('scheduled', strtolower($response->get_data()['message']));
            self::assertStringNotContainsString('access revoked', strtolower($response->get_data()['message']));
        }

        public function test_provider_reconciliation_checks_the_memberships_capability_before_running(): void
        {
            $GLOBALS['_fchub_test_current_user_can'] = false;

            self::assertFalse(ProviderReconciliationController::permission(new \WP_REST_Request()));
            self::assertContains(
                'manage_fchub_memberships',
                $GLOBALS['_fchub_test_current_user_can_checks'],
            );
            self::assertSame([], $GLOBALS['_fchub_test_safe_remote_posts'] ?? []);
            self::assertSame([], $GLOBALS['_fchub_test_remote_posts'] ?? []);
        }

        public function test_integration_logs_failed_and_partial_grants_without_success_copy(): void
        {
            $this->ensureIntegrationBaseClass();
            self::assertTrue(method_exists(MembershipAccessIntegration::class, 'logGrantResult'));
            if (!method_exists(MembershipAccessIntegration::class, 'logGrantResult')) {
                return;
            }
            $order = new CallerContractOrder();

            MembershipAccessIntegration::logGrantResult(
                $order,
                ['created' => 0, 'updated' => 0, 'failed' => 1, 'errors' => [['message' => 'Provider unavailable']]],
                'Gold',
                17,
                null,
                'order',
                99
            );
            MembershipAccessIntegration::logGrantResult(
                $order,
                ['created' => 1, 'updated' => 0, 'failed' => 1, 'errors' => [['message' => 'Second provider unavailable']]],
                'Gold',
                17,
                null,
                'order',
                99
            );

            self::assertSame('Membership access grant failed', $order->logs[0][0]);
            self::assertSame('error', $order->logs[0][2]);
            self::assertStringContainsString('Provider unavailable', $order->logs[0][1]);
            self::assertSame('Membership access partially granted', $order->logs[1][0]);
            self::assertSame('warning', $order->logs[1][2]);
        }

        public function test_integration_logs_failed_and_partial_revocations_without_success_copy(): void
        {
            $this->ensureIntegrationBaseClass();
            self::assertTrue(method_exists(MembershipAccessIntegration::class, 'logRevokeResult'));
            if (!method_exists(MembershipAccessIntegration::class, 'logRevokeResult')) {
                return;
            }
            $order = new CallerContractOrder();

            MembershipAccessIntegration::logRevokeResult($order, 99, 0, 1, [
                ['message' => 'Provider unavailable'],
            ]);
            MembershipAccessIntegration::logRevokeResult($order, 99, 1, 1, [
                ['message' => 'Second provider unavailable'],
            ]);

            self::assertSame('Membership access revoke failed', $order->logs[0][0]);
            self::assertSame('error', $order->logs[0][2]);
            self::assertStringContainsString('Provider unavailable', $order->logs[0][1]);
            self::assertSame('Membership access partially revoked', $order->logs[1][0]);
            self::assertSame('warning', $order->logs[1][2]);
        }

        public function test_integration_logs_deferred_and_mixed_revocations_truthfully(): void
        {
            $this->ensureIntegrationBaseClass();
            $order = new CallerContractOrder();

            MembershipAccessIntegration::logRevokeResult($order, 99, 0, 0, [], 2);
            MembershipAccessIntegration::logRevokeResult($order, 99, 1, 0, [], 1);

            self::assertSame('Membership access revocation scheduled', $order->logs[0][0]);
            self::assertStringContainsString('2 grant(s) scheduled', $order->logs[0][1]);
            self::assertStringNotContainsString('access revoked', strtolower($order->logs[0][0]));
            self::assertSame('Membership access revocation processed', $order->logs[1][0]);
            self::assertStringContainsString('1 revoked, 1 scheduled', $order->logs[1][1]);

            $source = file_get_contents(dirname(__DIR__, 3) . '/app/Integration/MembershipAccessIntegration.php');
            self::assertIsString($source);
            self::assertStringContainsString("$" . "totalGraceStarted = (int) ($" . "result['grace_started'] ?? 0)", $source);
        }

        private function ensureIntegrationBaseClass(): void
        {
            if (class_exists('FluentCart\\App\\Modules\\Integrations\\BaseIntegrationManager')) {
                return;
            }

            eval('namespace FluentCart\\App\\Modules\\Integrations; class BaseIntegrationManager { public $description; public $logo; public $category; public $scopes; public $hasGlobalMenu; public $disableGlobalSettings; public function __construct(...$args) {} }');
        }
    }

    final class CallerContractOrder
    {
        /** @var list<array{string, string, string, string}> */
        public array $logs = [];

        public function addLog(string $title, string $description, string $type, string $module): void
        {
            $this->logs[] = [$title, $description, $type, $module];
        }
    }
}

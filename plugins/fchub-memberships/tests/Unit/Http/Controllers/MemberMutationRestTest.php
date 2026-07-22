<?php

declare(strict_types=1);

namespace FChubMemberships\Tests\Unit\Http\Controllers;

use FChubMemberships\Domain\AccessGrantService;
use FChubMemberships\Http\Controllers\MemberController;
use FChubMemberships\Tests\Unit\PluginTestCase;

final class MemberMutationRestTest extends PluginTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $GLOBALS['_fchub_test_current_user_id'] = 44;
        $GLOBALS['_fchub_test_users'][21] = (object) [
            'ID' => 21,
            'display_name' => 'Alice Example',
            'user_email' => 'alice@example.com',
        ];
    }

    protected function tearDown(): void
    {
        MemberController::setAccessGrantServiceFactory(null);
        parent::tearDown();
    }

    public function test_all_eight_mutations_execute_the_service_once_then_replay_exactly(): void
    {
        $cases = [
            ['grant', ['user_id' => 21, 'plan_id' => 5], 'manualGrant', ['created' => 1, 'updated' => 0, 'failed' => 0]],
            ['revoke', ['user_id' => 21, 'plan_id' => 5], 'revokePlan', ['success' => true, 'revoked' => 1, 'retained' => 0, 'failed' => 0]],
            ['pause', ['grant_id' => 91], 'pauseGrant', ['success' => true, 'grant_id' => 91]],
            ['resume', ['grant_id' => 91], 'resumeGrant', ['success' => true, 'grant_id' => 91]],
            ['extend', ['user_id' => 21, 'plan_id' => 5, 'expires_at' => '2027-01-01 00:00:00'], 'extendExpiry', 1],
            ['bulkGrant', ['user_ids' => [21], 'plan_id' => 5], 'bulkGrant', ['granted' => 1, 'failed' => 0, 'errors' => []]],
            ['bulkRevoke', ['user_ids' => [21], 'plan_id' => 5], 'bulkRevoke', ['revoked' => 1, 'failed' => 0, 'errors' => []]],
            ['bulkExtend', ['user_ids' => [21], 'plan_id' => 5, 'expires_at' => '2027-01-01 00:00:00'], 'extendExpiry', 1],
        ];

        foreach ($cases as $index => [$controllerMethod, $body, $serviceMethod, $serviceResult]) {
            $this->usePersistentRequests();
            $service = $this->createMock(AccessGrantService::class);
            $service->expects(self::once())->method($serviceMethod)->willReturn($serviceResult);
            MemberController::setAccessGrantServiceFactory(static fn(): AccessGrantService => $service);
            $request = $this->request('operation-' . $index, $body);

            $first = MemberController::{$controllerMethod}($request);
            $replay = MemberController::{$controllerMethod}($request);

            self::assertSame(200, $first->get_status(), $controllerMethod);
            self::assertSame($first->get_status(), $replay->get_status(), $controllerMethod);
            self::assertSame($first->get_data(), $replay->get_data(), $controllerMethod);
            self::assertSame('true', $replay->get_headers()['Idempotency-Replayed'] ?? null, $controllerMethod);
        }
    }

    public function test_replays_partial_and_complete_service_failures(): void
    {
        $cases = [
            ['bulkGrant', ['user_ids' => [21, 22], 'plan_id' => 5], 'bulkGrant', ['granted' => 1, 'failed' => 1, 'errors' => ['failed']], 207],
            ['bulkRevoke', ['user_ids' => [21], 'plan_id' => 5], 'bulkRevoke', ['revoked' => 0, 'failed' => 1, 'errors' => ['provider failed']], 502],
        ];

        foreach ($cases as $index => [$controllerMethod, $body, $serviceMethod, $serviceResult, $status]) {
            $this->usePersistentRequests();
            $service = $this->createMock(AccessGrantService::class);
            $service->expects(self::once())->method($serviceMethod)->willReturn($serviceResult);
            MemberController::setAccessGrantServiceFactory(static fn(): AccessGrantService => $service);
            $request = $this->request('status-' . $index, $body);

            $first = MemberController::{$controllerMethod}($request);
            $replay = MemberController::{$controllerMethod}($request);

            self::assertSame($status, $first->get_status());
            self::assertSame($first->get_status(), $replay->get_status());
            self::assertSame($first->get_data(), $replay->get_data());
        }
    }

    public function test_changed_body_conflicts_without_repeating_the_service_call(): void
    {
        $this->usePersistentRequests();
        $service = $this->createMock(AccessGrantService::class);
        $service->expects(self::once())->method('extendExpiry')->willReturn(1);
        MemberController::setAccessGrantServiceFactory(static fn(): AccessGrantService => $service);

        $first = MemberController::extend($this->request('changed-body', [
            'user_id' => 21,
            'plan_id' => 5,
            'expires_at' => '2027-01-01 00:00:00',
        ]));
        $conflict = MemberController::extend($this->request('changed-body', [
            'user_id' => 21,
            'plan_id' => 5,
            'expires_at' => '2028-01-01 00:00:00',
        ]));

        self::assertSame(200, $first->get_status());
        self::assertSame(409, $conflict->get_status());
        self::assertSame('fchub_idempotency_conflict', $conflict->get_data()['code']);
    }

    public function test_bulk_extend_distinguishes_domain_zero_mixed_and_complete_provider_failure(): void
    {
        $cases = [
            [[0, 0], 404],
            [[1, 0], 207],
            [[new \RuntimeException('provider failed')], 502],
        ];

        foreach ($cases as $index => [$outcomes, $expectedStatus]) {
            $this->usePersistentRequests();
            $service = $this->createMock(AccessGrantService::class);
            $invocation = $service->expects(self::exactly(count($outcomes)))->method('extendExpiry');
            $invocation->willReturnCallback(static function () use (&$outcomes): int {
                $outcome = array_shift($outcomes);
                if ($outcome instanceof \Throwable) {
                    throw $outcome;
                }
                return $outcome;
            });
            MemberController::setAccessGrantServiceFactory(static fn(): AccessGrantService => $service);

            $response = MemberController::bulkExtend($this->request('bulk-extend-status-' . $index, [
                'user_ids' => range(21, 20 + count($outcomes)),
                'plan_id' => 5,
                'expires_at' => '2027-01-01 00:00:00',
            ]));

            self::assertSame($expectedStatus, $response->get_status());
        }
    }

    public function test_validation_and_permission_rejections_do_not_store_or_execute(): void
    {
        $inserts = 0;
        $this->usePersistentRequests($inserts);
        $service = $this->createMock(AccessGrantService::class);
        $service->expects(self::never())->method('manualGrant');
        MemberController::setAccessGrantServiceFactory(static fn(): AccessGrantService => $service);

        $invalid = MemberController::grant($this->request('invalid', ['user_id' => 21]));

        $GLOBALS['_fchub_test_current_user_can'] = false;
        MemberController::registerRoutes();
        $route = $GLOBALS['_fchub_test_routes']['fchub-memberships/v1/admin/members/grant'];
        $denied = ($route['permission_callback'])($this->request('denied', ['user_id' => 21, 'plan_id' => 5]));

        self::assertSame(422, $invalid->get_status());
        self::assertFalse($denied);
        self::assertSame(0, $inserts);
    }

    private function usePersistentRequests(?int &$inserts = null): void
    {
        $rows = [];
        $inserts = 0;
        $GLOBALS['_fchub_test_wpdb_overrides']['get_row'] = static function (string $query) use (&$rows): ?array {
            preg_match("/request_key = '([^']+)'/", $query, $matches);
            return $rows[$matches[1] ?? ''] ?? null;
        };
        $GLOBALS['_fchub_test_wpdb_overrides']['insert'] = static function (string $table, array $data) use (&$rows, &$inserts): int|false {
            $inserts++;
            if (isset($rows[$data['request_key']])) {
                return false;
            }
            $rows[$data['request_key']] = $data;
            return 1;
        };
        $GLOBALS['_fchub_test_wpdb_overrides']['update'] = static function (string $table, array $data, array $where) use (&$rows): int {
            $rows[$where['request_key']] = array_merge($rows[$where['request_key']], $data);
            return 1;
        };
    }

    /** @param array<string, mixed> $body */
    private function request(string $key, array $body): \WP_REST_Request
    {
        $request = new \WP_REST_Request('POST', '/fchub-memberships/v1/admin/members', $body);
        $request->set_header('Idempotency-Key', $key);
        return $request;
    }
}

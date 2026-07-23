<?php

declare(strict_types=1);

namespace FChubMemberships\Tests\Unit\Http\Controllers;

use FChubMemberships\Http\ApplicationPasswordRequestContext;
use FChubMemberships\Http\Controllers\ProviderReconciliationController;
use FChubMemberships\Http\IdempotentMutation;
use FChubMemberships\Tests\Unit\PluginTestCase;

final class ProviderReconciliationControllerTest extends PluginTestCase
{
    public function test_routes_are_separate_from_crm_projection_health_and_share_admin_capability(): void
    {
        ProviderReconciliationController::registerRoutes();

        $scan = $GLOBALS['_fchub_test_routes']['fchub-memberships/v1/admin/provider-reconciliation'];
        $repair = $GLOBALS['_fchub_test_routes']['fchub-memberships/v1/admin/provider-reconciliation/repair'];
        self::assertSame('GET', $scan['methods']);
        self::assertSame('POST', $repair['methods']);
        self::assertSame('integer', $scan['args']['limit']['type']);
        self::assertSame('integer', $repair['args']['user_id']['type']);
        self::assertTrue($repair['args']['expected_classification']['required']);

        $GLOBALS['_fchub_test_current_user_can'] = false;
        self::assertFalse(($scan['permission_callback'])(new \WP_REST_Request()));
        self::assertFalse(($repair['permission_callback'])(new \WP_REST_Request()));
    }

    public function test_get_is_read_only_and_returns_one_bounded_cursor_page(): void
    {
        $calls = [];
        $controller = new ProviderReconciliationController(
            static function (?string $cursor, int $limit) use (&$calls): array {
                $calls[] = [$cursor, $limit];
                return ['items' => [['classification' => 'healthy']], 'next_cursor' => null];
            },
            static fn(): never => throw new \LogicException('GET must not repair.')
        );

        $response = $controller->scan(new \WP_REST_Request('GET', '', ['cursor' => 'page-2', 'limit' => 25]));

        self::assertSame(200, $response->get_status());
        self::assertSame([['page-2', 25]], $calls);
        self::assertSame('healthy', $response->get_data()['data']['items'][0]['classification']);
    }

    public function test_repair_requires_idempotency_key_and_uses_it_as_request_id(): void
    {
        $repairs = [];
        $idempotency = new RecordingIdempotentMutation();
        $controller = new ProviderReconciliationController(
            static fn(): array => ['items' => [], 'next_cursor' => null],
            static function (array $resource, string $requestId, string $expectedClassification) use (&$repairs): array {
                $repairs[] = [$resource, $requestId, $expectedClassification];
                return ['success' => true, 'status' => 'scheduled', 'operation_id' => 91];
            },
            $idempotency
        );
        $params = [
            'user_id' => 17,
            'provider' => 'fluentcrm',
            'resource_type' => 'fluentcrm_tag',
            'resource_id' => '41',
            'expected_classification' => 'internal_active_provider_absent',
        ];

        $missing = $controller->repair(new \WP_REST_Request('POST', '', $params));
        self::assertSame(400, $missing->get_status());
        self::assertSame('fchub_idempotency_key_required', $missing->get_data()['code']);

        $request = new \WP_REST_Request('POST', '', $params);
        $request->set_header('Idempotency-Key', 'repair-001');
        $response = $controller->repair($request);

        self::assertSame(202, $response->get_status());
        self::assertSame('provider_reconciliation_repair', $idempotency->operation);
        $resource = $params;
        unset($resource['expected_classification']);
        self::assertSame([[$resource, 'repair-001', 'internal_active_provider_absent']], $repairs);
        self::assertSame(hash('sha256', 'repair-001'), $idempotency->requestKey);
    }

    public function test_application_password_repair_normalises_a_missing_key_to_precondition_required(): void
    {
        $controller = new ProviderReconciliationController(
            static fn(): array => ['items' => [], 'next_cursor' => null],
            static fn(): never => throw new \LogicException('Missing key must not repair.')
        );
        $GLOBALS['_fchub_test_current_user_id'] = 44;
        $user = new \WP_User();
        $user->ID = 44;
        ApplicationPasswordRequestContext::authenticated($user, []);

        try {
            $response = $controller->repair(new \WP_REST_Request('POST', '', [
                'user_id' => 17,
                'provider' => 'fluentcrm',
                'resource_type' => 'fluentcrm_tag',
                'resource_id' => '41',
                'expected_classification' => 'internal_active_provider_absent',
            ]));
        } finally {
            ApplicationPasswordRequestContext::clear();
        }

        self::assertSame(428, $response->get_status());
        self::assertSame('fchub_idempotency_key_required', $response->get_data()['code']);
    }

    public function test_long_idempotency_token_is_digested_and_replays_durably(): void
    {
        $GLOBALS['_fchub_test_current_user_id'] = 44;
        $rows = [];
        $GLOBALS['_fchub_test_wpdb_overrides']['get_row'] = static function (string $query) use (&$rows): ?array {
            preg_match("/request_key = '([^']+)'/", $query, $matches);
            return $rows[$matches[1] ?? ''] ?? null;
        };
        $GLOBALS['_fchub_test_wpdb_overrides']['insert'] = static function (string $table, array $data) use (&$rows): int|false {
            if (isset($rows[$data['request_key']])) {
                return false;
            }
            $rows[$data['request_key']] = $data;
            return 1;
        };
        $GLOBALS['_fchub_test_wpdb_overrides']['query'] = static function (string $query, \wpdb $wpdb) use (&$rows): int {
            preg_match("/request_key = '([^']+)'/", $query, $keyMatch);
            preg_match("/SET state = '([^']+)'/", $query, $stateMatch);
            preg_match('/response_status = ([0-9]+)/', $query, $statusMatch);
            preg_match("/response_body = '([^']*)'/", $query, $bodyMatch);
            $key = $keyMatch[1] ?? '';
            $rows[$key] = array_merge($rows[$key], [
                'state' => $stateMatch[1] ?? 'reserved',
                'response_status' => isset($statusMatch[1]) ? (int) $statusMatch[1] : null,
                'response_body' => $bodyMatch[1] ?? null,
                'lease_token' => null,
                'lease_expires_at' => null,
            ]);
            $wpdb->rows_affected = 1;
            return 1;
        };
        $runs = 0;
        $controller = new ProviderReconciliationController(
            static fn(): array => ['items' => [], 'next_cursor' => null],
            static function () use (&$runs): array {
                $runs++;
                return ['success' => true, 'status' => 'scheduled', 'operation_id' => 91];
            }
        );
        $token = str_repeat('x', 191);
        $params = [
            'user_id' => 17,
            'provider' => 'fluentcrm',
            'resource_type' => 'fluentcrm_tag',
            'resource_id' => '41',
            'expected_classification' => 'internal_active_provider_absent',
        ];
        $request = static function () use ($params, $token): \WP_REST_Request {
            $request = new \WP_REST_Request('POST', '', $params);
            $request->set_header('Idempotency-Key', $token);
            return $request;
        };

        $first = $controller->repair($request());
        $replay = $controller->repair($request());

        self::assertSame(1, $runs);
        self::assertSame($first->get_data(), $replay->get_data());
        self::assertSame('true', $replay->get_headers()['Idempotency-Replayed'] ?? null);
        self::assertSame([hash('sha256', $token)], array_keys($rows));
        self::assertStringNotContainsString($token, json_encode($rows));
    }
}

final class RecordingIdempotentMutation extends IdempotentMutation
{
    public string $operation = '';
    public string $requestKey = '';

    public function execute(\WP_REST_Request $request, string $operation, callable $mutation): \WP_REST_Response
    {
        $this->operation = $operation;
        $this->requestKey = (string) $request->get_header('Idempotency-Key');
        return $mutation();
    }
}

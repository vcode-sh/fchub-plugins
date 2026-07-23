<?php

declare(strict_types=1);

namespace FChubMemberships\Tests\Unit\Http;

use FChubMemberships\Http\ApplicationPasswordRequestContext;
use FChubMemberships\Http\IdempotentMutation;
use FChubMemberships\Tests\Unit\PluginTestCase;

final class IdempotentMutationTest extends PluginTestCase
{
    public function test_application_password_write_requires_an_idempotency_header_before_mutation_or_storage(): void
    {
        $GLOBALS['_fchub_test_current_user_id'] = 44;
        $user = new \WP_User();
        $user->ID = 44;
        ApplicationPasswordRequestContext::authenticated($user, ['uuid' => 'not-retained']);
        $runs = 0;
        $inserts = 0;
        $GLOBALS['_fchub_test_wpdb_overrides']['insert'] = static function () use (&$inserts): int {
            $inserts++;
            return 1;
        };

        try {
            $response = (new IdempotentMutation())->execute(
                new \WP_REST_Request('POST', '/grant', ['plan_id' => 2]),
                'grant',
                static function () use (&$runs): \WP_REST_Response {
                    $runs++;
                    return new \WP_REST_Response(['data' => []]);
                }
            );
        } finally {
            ApplicationPasswordRequestContext::clear();
        }

        self::assertSame(428, $response->get_status());
        self::assertSame('fchub_idempotency_key_required', $response->get_data()['code']);
        self::assertSame(0, $runs);
        self::assertSame(0, $inserts);
    }

    public function test_application_password_write_with_a_key_is_stored_and_replayed(): void
    {
        $this->persistentRows();
        $GLOBALS['_fchub_test_current_user_id'] = 44;
        $user = new \WP_User();
        $user->ID = 44;
        ApplicationPasswordRequestContext::authenticated($user, []);
        $request = $this->request('external-replay', ['plan_id' => 2]);
        $runs = 0;
        $coordinator = new IdempotentMutation();

        try {
            $first = $coordinator->execute($request, 'grant', static function () use (&$runs): \WP_REST_Response {
                $runs++;
                return new \WP_REST_Response(['data' => ['runs' => $runs]], 207);
            });
            $replay = $coordinator->execute($request, 'grant', static function () use (&$runs): \WP_REST_Response {
                $runs++;
                return new \WP_REST_Response(['data' => ['runs' => $runs]]);
            });
        } finally {
            ApplicationPasswordRequestContext::clear();
        }

        self::assertSame(1, $runs);
        self::assertSame(207, $first->get_status());
        self::assertSame($first->get_data(), $replay->get_data());
        self::assertSame('true', $replay->get_headers()['Idempotency-Replayed'] ?? null);
    }

    public function test_executes_without_an_idempotency_header(): void
    {
        $runs = 0;

        $response = (new IdempotentMutation())->execute(
            new \WP_REST_Request('POST', '/grant', ['plan_id' => 2]),
            'grant',
            static function () use (&$runs): \WP_REST_Response {
                $runs++;
                return new \WP_REST_Response(['data' => ['runs' => $runs]]);
            }
        );

        self::assertSame(1, $runs);
        self::assertSame(['data' => ['runs' => 1]], $response->get_data());
    }

    public function test_replays_a_completed_matching_request_exactly(): void
    {
        $rows = $this->persistentRows();
        $GLOBALS['_fchub_test_current_user_id'] = 44;
        $runs = 0;
        $request = $this->request('same-request', ['user_id' => 8, 'plan_id' => 2]);
        $coordinator = new IdempotentMutation();

        $first = $coordinator->execute($request, 'grant', static function () use (&$runs): \WP_REST_Response {
            $runs++;
            return new \WP_REST_Response(['data' => ['created' => 1]], 207);
        });
        $replay = $coordinator->execute($request, 'grant', static function () use (&$runs): \WP_REST_Response {
            $runs++;
            return new \WP_REST_Response(['data' => ['created' => 2]], 200);
        });

        self::assertSame(1, $runs);
        self::assertSame(207, $first->get_status());
        self::assertSame($first->get_status(), $replay->get_status());
        self::assertSame($first->get_data(), $replay->get_data());
        self::assertSame('true', $replay->get_headers()['Idempotency-Replayed'] ?? null);
    }

    public function test_rejects_a_reused_key_for_a_changed_payload_or_operation(): void
    {
        $this->persistentRows();
        $GLOBALS['_fchub_test_current_user_id'] = 44;
        $coordinator = new IdempotentMutation();

        $coordinator->execute($this->request('conflict', ['user_id' => 8, 'plan_id' => 2]), 'grant', static fn(): \WP_REST_Response => new \WP_REST_Response(['data' => []]));
        $changedBody = $coordinator->execute($this->request('conflict', ['user_id' => 8, 'plan_id' => 3]), 'grant', static fn(): \WP_REST_Response => new \WP_REST_Response(['data' => []]));
        $changedOperation = $coordinator->execute($this->request('conflict', ['user_id' => 8, 'plan_id' => 2]), 'revoke', static fn(): \WP_REST_Response => new \WP_REST_Response(['data' => []]));

        self::assertSame(409, $changedBody->get_status());
        self::assertSame('fchub_idempotency_conflict', $changedBody->get_data()['code']);
        self::assertSame(409, $changedOperation->get_status());
        self::assertSame('fchub_idempotency_conflict', $changedOperation->get_data()['code']);
    }

    public function test_returns_in_progress_when_another_request_reserved_the_key(): void
    {
        $this->persistentRows();
        $GLOBALS['_fchub_test_current_user_id'] = 44;
        $request = $this->request('in-progress', ['user_id' => 8, 'plan_id' => 2]);
        $coordinator = new IdempotentMutation();
        $inProgress = null;

        $coordinator->execute($request, 'grant', function () use ($coordinator, $request, &$inProgress): \WP_REST_Response {
            $inProgress = $coordinator->execute($request, 'grant', static fn(): \WP_REST_Response => new \WP_REST_Response(['data' => []]));
            return new \WP_REST_Response(['data' => []]);
        });

        self::assertInstanceOf(\WP_REST_Response::class, $inProgress);
        self::assertSame(409, $inProgress->get_status());
        self::assertSame('fchub_idempotency_in_progress', $inProgress->get_data()['code']);
    }

    public function test_replays_partial_failure_and_exception_responses(): void
    {
        $this->persistentRows();
        $GLOBALS['_fchub_test_current_user_id'] = 44;
        $coordinator = new IdempotentMutation();

        foreach ([207, 502] as $status) {
            $runs = 0;
            $request = $this->request('status-' . $status, ['user_id' => 8, 'plan_id' => 2]);
            $first = $coordinator->execute($request, 'bulk_extend', static function () use (&$runs, $status): \WP_REST_Response {
                $runs++;
                return new \WP_REST_Response(['data' => ['status' => $status]], $status);
            });
            $replay = $coordinator->execute($request, 'bulk_extend', static function () use (&$runs): \WP_REST_Response {
                $runs++;
                return new \WP_REST_Response(['data' => []]);
            });

            self::assertSame(1, $runs);
            self::assertSame($first->get_status(), $replay->get_status());
            self::assertSame($first->get_data(), $replay->get_data());
        }

        $runs = 0;
        $request = $this->request('exception', ['user_id' => 8, 'plan_id' => 2]);
        $first = $coordinator->execute($request, 'grant', static function () use (&$runs): \WP_REST_Response {
            $runs++;
            throw new \RuntimeException('provider exploded');
        });
        $replay = $coordinator->execute($request, 'grant', static function () use (&$runs): \WP_REST_Response {
            $runs++;
            return new \WP_REST_Response(['data' => []]);
        });

        self::assertSame(1, $runs);
        self::assertSame(500, $first->get_status());
        self::assertSame($first->get_data(), $replay->get_data());
    }

    public function test_records_and_replays_mutation_exceptions_as_failed_requests(): void
    {
        $states = [];
        $this->persistentRows(static function (array $data) use (&$states): bool {
            $states[] = $data['state'];
            return true;
        });
        $GLOBALS['_fchub_test_current_user_id'] = 44;
        $request = $this->request('failed-exception', ['plan_id' => 2]);
        $runs = 0;
        $coordinator = new IdempotentMutation();

        $first = $coordinator->execute($request, 'grant', static function () use (&$runs): \WP_REST_Response {
            $runs++;
            throw new \RuntimeException('database password must not leak');
        });
        $replay = $coordinator->execute($request, 'grant', static function () use (&$runs): \WP_REST_Response {
            $runs++;
            return new \WP_REST_Response(['unexpected' => true]);
        });

        self::assertSame(['failed'], $states);
        self::assertSame(1, $runs);
        self::assertSame(500, $first->get_status());
        self::assertSame('fchub_idempotency_mutation_failed', $first->get_data()['code']);
        self::assertStringNotContainsString('password', json_encode($first->get_data()));
        self::assertSame($first->get_data(), $replay->get_data());
        self::assertSame('true', $replay->get_headers()['Idempotency-Replayed'] ?? null);
    }

    public function test_complete_failure_returns_and_replays_a_durable_sanitised_failure(): void
    {
        $states = [];
        $this->persistentRows(static function (array $data) use (&$states): bool {
            $states[] = $data['state'];
            return $data['state'] === 'failed';
        });
        $GLOBALS['_fchub_test_current_user_id'] = 44;
        $request = $this->request('complete-failed', ['plan_id' => 2]);
        $runs = 0;
        $coordinator = new IdempotentMutation();

        $first = $coordinator->execute($request, 'grant', static function () use (&$runs): \WP_REST_Response {
            $runs++;
            return new \WP_REST_Response(['data' => ['created' => 1]], 200);
        });
        $replay = $coordinator->execute($request, 'grant', static function () use (&$runs): \WP_REST_Response {
            $runs++;
            return new \WP_REST_Response(['unexpected' => true]);
        });

        self::assertSame(['complete', 'failed'], $states);
        self::assertSame(1, $runs);
        self::assertSame(500, $first->get_status());
        self::assertSame('fchub_idempotency_persistence_failed', $first->get_data()['code']);
        self::assertSame($first->get_status(), $replay->get_status());
        self::assertSame($first->get_data(), $replay->get_data());
    }

    public function test_zero_row_completion_is_not_reported_as_success_and_is_durably_replayed(): void
    {
        $states = [];
        $this->persistentRows(static function (array $data) use (&$states): int {
            $states[] = $data['state'];
            return $data['state'] === 'complete' ? 0 : 1;
        });
        $GLOBALS['_fchub_test_current_user_id'] = 44;
        $request = $this->request('reservation-vanished', ['plan_id' => 2]);
        $runs = 0;
        $coordinator = new IdempotentMutation();

        $first = $coordinator->execute($request, 'grant', static function () use (&$runs): \WP_REST_Response {
            $runs++;
            return new \WP_REST_Response(['data' => ['created' => 1]], 200);
        });
        $replay = $coordinator->execute($request, 'grant', static function () use (&$runs): \WP_REST_Response {
            $runs++;
            return new \WP_REST_Response(['unexpected' => true]);
        });

        self::assertSame(['complete', 'failed'], $states);
        self::assertSame(1, $runs);
        self::assertSame(500, $first->get_status());
        self::assertSame('fchub_idempotency_persistence_failed', $first->get_data()['code']);
        self::assertSame($first->get_data(), $replay->get_data());
    }

    public function test_replays_scalar_and_null_bodies_without_preserving_arbitrary_headers(): void
    {
        $this->persistentRows();
        $GLOBALS['_fchub_test_current_user_id'] = 44;
        $coordinator = new IdempotentMutation();

        foreach (['plain body', null] as $index => $body) {
            $request = $this->request('body-' . $index, ['plan_id' => 2]);
            $first = $coordinator->execute($request, 'grant', static fn(): \WP_REST_Response => new \WP_REST_Response($body, 200, ['X-Mutation-Header' => 'not-persisted']));
            $replay = $coordinator->execute($request, 'grant', static fn(): \WP_REST_Response => new \WP_REST_Response('unexpected'));

            self::assertSame($first->get_data(), $replay->get_data());
            self::assertArrayNotHasKey('X-Mutation-Header', $replay->get_headers());
            self::assertSame('true', $replay->get_headers()['Idempotency-Replayed'] ?? null);
        }
    }

    public function test_expired_matching_reservation_is_reclaimed_and_executes_at_least_once(): void
    {
        $GLOBALS['_fchub_test_current_user_id'] = 44;
        $request = $this->request('abandoned', ['user_id' => 8, 'plan_id' => 2]);
        $coordinator = new IdempotentMutation();
        $fingerprint = $coordinator->fingerprint($request, 'grant');
        $row = [
            'request_key' => 'abandoned',
            'fingerprint' => $fingerprint,
            'user_id' => 44,
            'state' => 'reserved',
            'lease_token' => str_repeat('1', 64),
            'lease_expires_at' => '2026-03-13 21:59:59',
            'attempt_count' => 1,
            'response_status' => null,
            'response_body' => null,
        ];
        $runs = 0;
        $GLOBALS['_fchub_test_wpdb_overrides']['get_row'] = static fn(): array => $row;
        $GLOBALS['_fchub_test_wpdb_overrides']['insert'] = static fn(): false => false;
        $GLOBALS['_fchub_test_wpdb_overrides']['query'] = static function (string $query, \wpdb $wpdb): int {
            $wpdb->rows_affected = 1;
            return 1;
        };

        $response = $coordinator->execute($request, 'grant', static function () use (&$runs): \WP_REST_Response {
            $runs++;
            return new \WP_REST_Response(['data' => ['reclaimed' => true]], 200);
        });

        self::assertSame(1, $runs);
        self::assertSame(200, $response->get_status());
        self::assertSame(['data' => ['reclaimed' => true]], $response->get_data());
    }

    private function persistentRows(?callable $updatePolicy = null): void
    {
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
        $GLOBALS['_fchub_test_wpdb_overrides']['query'] = static function (string $query, \wpdb $wpdb) use (&$rows, $updatePolicy): int|false {
            if (!str_contains($query, 'UPDATE wp_fchub_membership_mutation_requests')) {
                return 0;
            }

            preg_match("/request_key = '([^']+)'/", $query, $keyMatch);
            preg_match("/SET state = '([^']+)'/", $query, $stateMatch);
            preg_match('/response_status = ([0-9]+)/', $query, $statusMatch);
            preg_match("/response_body = '([^']*)'/", $query, $bodyMatch);
            $key = $keyMatch[1] ?? '';
            $data = [
                'state' => $stateMatch[1] ?? 'reserved',
                'response_status' => isset($statusMatch[1]) ? (int) $statusMatch[1] : null,
                'response_body' => $bodyMatch[1] ?? null,
                'lease_token' => null,
                'lease_expires_at' => null,
                'completed_at' => '2026-03-13 22:00:00',
                'updated_at' => '2026-03-13 22:00:00',
            ];
            if ($updatePolicy) {
                $policyResult = $updatePolicy($data);
                if ($policyResult === false || $policyResult === 0) {
                    $wpdb->rows_affected = 0;
                    return $policyResult;
                }
            }

            $rows[$key] = array_merge($rows[$key], $data);
            $wpdb->rows_affected = 1;
            return 1;
        };
    }

    /** @param array<string, mixed> $body */
    private function request(string $key, array $body): \WP_REST_Request
    {
        $request = new \WP_REST_Request('POST', '/fchub-memberships/v1/admin/members/grant', $body);
        $request->set_header('Idempotency-Key', $key);
        return $request;
    }
}

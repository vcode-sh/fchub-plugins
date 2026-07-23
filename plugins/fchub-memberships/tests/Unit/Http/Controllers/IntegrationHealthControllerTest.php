<?php

declare(strict_types=1);

namespace FChubMemberships\Tests\Unit\Http\Controllers;

use FChubMemberships\Http\ApplicationPasswordRequestContext;
use FChubMemberships\Http\Controllers\IntegrationHealthController;
use FChubMemberships\Integration\FluentCrmIntegrationHealth;
use FChubMemberships\Tests\Unit\PluginTestCase;

final class IntegrationHealthControllerTest extends PluginTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['_fchub_test_current_user_caps'] = [];
        $GLOBALS['_fchub_test_current_user_can_checks'] = [];
    }

    public function test_registers_a_read_only_health_route_and_post_only_reconciliation_route(): void
    {
        IntegrationHealthController::registerRoutes();

        self::assertSame(
            'GET',
            $GLOBALS['_fchub_test_routes']['fchub-memberships/v1/admin/integrations/fluentcrm/health']['methods']
        );
        self::assertSame(
            'POST',
            $GLOBALS['_fchub_test_routes']['fchub-memberships/v1/admin/integrations/fluentcrm/reconcile']['methods']
        );
        self::assertSame('integer', $GLOBALS['_fchub_test_routes']['fchub-memberships/v1/admin/integrations/fluentcrm/reconcile']['args']['user_id']['type']);
        self::assertSame(['all'], $GLOBALS['_fchub_test_routes']['fchub-memberships/v1/admin/integrations/fluentcrm/reconcile']['args']['scope']['enum']);
        self::assertSame('boolean', $GLOBALS['_fchub_test_routes']['fchub-memberships/v1/admin/integrations/fluentcrm/reconcile']['args']['dry_run']['type']);
        self::assertTrue($GLOBALS['_fchub_test_routes']['fchub-memberships/v1/admin/integrations/fluentcrm/reconcile']['args']['dry_run']['default']);
        self::assertSame('integer', $GLOBALS['_fchub_test_routes']['fchub-memberships/v1/admin/integrations/fluentcrm/reconcile']['args']['cursor']['type']);
        self::assertSame(0, $GLOBALS['_fchub_test_routes']['fchub-memberships/v1/admin/integrations/fluentcrm/reconcile']['args']['cursor']['minimum']);
        self::assertSame(0, $GLOBALS['_fchub_test_routes']['fchub-memberships/v1/admin/integrations/fluentcrm/reconcile']['args']['cursor']['default']);
        self::assertSame('integer', $GLOBALS['_fchub_test_routes']['fchub-memberships/v1/admin/integrations/fluentcrm/reconcile']['args']['watermark']['type']);
        self::assertSame(0, $GLOBALS['_fchub_test_routes']['fchub-memberships/v1/admin/integrations/fluentcrm/reconcile']['args']['watermark']['minimum']);
    }

    public function test_health_requires_manage_options(): void
    {
        $GLOBALS['_fchub_test_current_user_caps'] = ['manage_options' => false];

        self::assertFalse(IntegrationHealthController::healthPermission(new \WP_REST_Request()));
        self::assertSame(['manage_options'], $GLOBALS['_fchub_test_current_user_can_checks']);
    }

    public function test_single_user_dry_run_uses_the_dedicated_capability_and_does_not_mutate(): void
    {
        $calls = [];
        $controller = $this->controller(
            static fn(): array => [
                'status' => 'healthy',
                'action' => 'No action required.',
            ],
            static function (int $userId, bool $dryRun) use (&$calls): array {
                $calls[] = [$userId, $dryRun];
                return [
                    'success' => true,
                    'drift' => 1,
                    'desired' => ['tag_names' => ['member:gold'], 'tag_ids' => [], 'list_ids' => []],
                    'current' => ['owned_tag_ids' => [], 'owned_list_ids' => []],
                    'errors' => [],
                ];
            }
        );
        $GLOBALS['_fchub_test_current_user_caps'] = ['manage_fchub_memberships' => true];

        $request = new \WP_REST_Request('POST', '', ['user_id' => 21, 'dry_run' => true]);
        self::assertTrue($controller->reconcilePermission($request));

        $response = $controller->reconcile($request)->get_data();

        self::assertSame([[21, true]], $calls);
        self::assertTrue($response['data']['dry_run']);
        self::assertSame(1, $response['data']['drift']);
        self::assertSame([[
            'user_id' => 21,
            'success' => true,
            'drift' => 1,
            'applied_drift' => 0,
            'remaining_drift' => 1,
            'desired' => ['tag_names' => ['member:gold'], 'tag_ids' => [], 'list_ids' => []],
            'current' => ['owned_tag_ids' => [], 'owned_list_ids' => []],
            'postflight' => [
                'success' => true,
                'drift' => 0,
                'errors' => [],
            ],
            'outcome' => [
                'success' => true,
                'degraded' => false,
                'attached_tags' => [],
                'detached_tags' => [],
                'attached_lists' => [],
                'detached_lists' => [],
                'custom_fields' => [],
                'errors' => [],
            ],
            'errors' => [],
        ]], $response['data']['results']);
    }

    public function test_application_password_apply_requires_a_key_but_dry_run_and_health_remain_key_free(): void
    {
        $calls = [];
        $controller = $this->controller(
            static fn(): array => ['status' => 'healthy'],
            static function (int $userId, bool $dryRun) use (&$calls): array {
                $calls[] = [$userId, $dryRun];
                return ['success' => true, 'drift' => 0, 'errors' => []];
            }
        );
        $GLOBALS['_fchub_test_current_user_id'] = 44;
        $user = new \WP_User();
        $user->ID = 44;
        ApplicationPasswordRequestContext::authenticated($user, []);

        try {
            $missing = $controller->reconcile(new \WP_REST_Request('POST', '', [
                'user_id' => 21,
                'dry_run' => false,
            ]));
            $preview = $controller->reconcile(new \WP_REST_Request('POST', '', [
                'user_id' => 21,
                'dry_run' => true,
            ]));
            $health = $controller->healthResponse();
        } finally {
            ApplicationPasswordRequestContext::clear();
        }

        self::assertSame(428, $missing->get_status());
        self::assertSame('fchub_idempotency_key_required', $missing->get_data()['code']);
        self::assertSame(200, $preview->get_status());
        self::assertSame(200, $health->get_status());
        self::assertSame([[21, true]], $calls);
    }

    public function test_application_password_apply_with_a_key_replays_without_reapplying(): void
    {
        $this->usePersistentMutationRequests();
        $calls = [];
        $controller = $this->controller(
            static fn(): array => ['status' => 'healthy'],
            static function (int $userId, bool $dryRun) use (&$calls): array {
                $calls[] = [$userId, $dryRun];
                return ['success' => true, 'drift' => 0, 'errors' => []];
            }
        );
        $GLOBALS['_fchub_test_current_user_id'] = 44;
        $user = new \WP_User();
        $user->ID = 44;
        ApplicationPasswordRequestContext::authenticated($user, []);
        $request = new \WP_REST_Request('POST', '', ['user_id' => 21, 'dry_run' => false]);
        $request->set_header('Idempotency-Key', 'crm-apply-replay');

        try {
            $first = $controller->reconcile($request);
            $replay = $controller->reconcile($request);
        } finally {
            ApplicationPasswordRequestContext::clear();
        }

        self::assertSame([[21, true], [21, false], [21, true]], $calls);
        self::assertSame($first->get_status(), $replay->get_status());
        self::assertSame($first->get_data(), $replay->get_data());
        self::assertSame('true', $replay->get_headers()['Idempotency-Replayed'] ?? null);
    }

    public function test_all_scope_processes_only_one_keyset_page_of_one_hundred_users(): void
    {
        $pages = [];
        $calls = [];
        $controller = $this->controller(
            static fn(): array => ['status' => 'healthy', 'action' => 'No action required.'],
            static function (int $userId, bool $dryRun) use (&$calls): array {
                $calls[] = [$userId, $dryRun];
                return ['success' => $userId !== 2, 'drift' => $userId === 1 ? 1 : 0, 'errors' => $userId === 2 ? ['contact_resolve_failed'] : []];
            },
            static function (int $cursor, int $watermark, int $limit) use (&$pages): array {
                $pages[] = [$cursor, $watermark, $limit];
                return array_slice(range($cursor + 1, $watermark), 0, $limit);
            },
            static fn(): int => 205
        );
        $GLOBALS['_fchub_test_current_user_caps'] = [
            'manage_fchub_memberships' => true,
            'manage_options' => true,
        ];

        $request = new \WP_REST_Request('POST', '', ['scope' => 'all']);
        self::assertTrue($controller->reconcilePermission($request));

        $data = $controller->reconcile($request)->get_data()['data'];

        self::assertSame([[0, 205, 101]], $pages);
        self::assertCount(100, $calls);
        self::assertSame([1, true], $calls[0]);
        self::assertSame([100, true], $calls[99]);
        self::assertSame(100, $data['processed']);
        self::assertSame(1, $data['failed']);
        self::assertSame(1, $data['drift']);
        self::assertSame(0, $data['cursor']);
        self::assertSame(205, $data['watermark']);
        self::assertSame(100, $data['next_cursor']);
        self::assertFalse($data['complete']);
        self::assertCount(100, $data['results']);
    }

    public function test_all_scope_resumes_after_a_failed_user_and_completes_at_the_fixed_watermark(): void
    {
        $pages = [];
        $controller = $this->controller(
            static fn(): array => [],
            static fn(int $userId): array => [
                'success' => $userId !== 102,
                'drift' => 0,
                'errors' => $userId === 102 ? ['contact_resolve_failed'] : [],
            ],
            static function (int $cursor, int $watermark, int $limit) use (&$pages): array {
                $pages[] = [$cursor, $watermark, $limit];
                return array_slice(range($cursor + 1, $watermark), 0, $limit);
            }
        );

        $second = $controller->reconcile(new \WP_REST_Request('POST', '', [
            'scope' => 'all',
            'cursor' => 100,
            'watermark' => 205,
        ]))->get_data()['data'];
        $last = $controller->reconcile(new \WP_REST_Request('POST', '', [
            'scope' => 'all',
            'cursor' => 200,
            'watermark' => 205,
        ]))->get_data()['data'];

        self::assertSame([[100, 205, 101], [200, 205, 101]], $pages);
        self::assertSame(100, $second['processed']);
        self::assertSame(1, $second['failed']);
        self::assertSame(200, $second['next_cursor']);
        self::assertFalse($second['complete']);
        self::assertSame(5, $last['processed']);
        self::assertSame(0, $last['failed']);
        self::assertNull($last['next_cursor']);
        self::assertTrue($last['complete']);
    }

    public function test_all_scope_rejects_resume_without_the_original_watermark_or_past_it(): void
    {
        $calls = 0;
        $controller = $this->controller(
            static fn(): array => [],
            static function () use (&$calls): array {
                $calls++;
                return [];
            }
        );

        $missing = $controller->reconcile(new \WP_REST_Request('POST', '', [
            'scope' => 'all',
            'cursor' => 10,
        ]));
        $past = $controller->reconcile(new \WP_REST_Request('POST', '', [
            'scope' => 'all',
            'cursor' => 11,
            'watermark' => 10,
        ]));

        self::assertSame(400, $missing->get_status());
        self::assertSame('reconciliation_watermark_required', $missing->get_data()['code']);
        self::assertSame(400, $past->get_status());
        self::assertSame('invalid_reconciliation_cursor', $past->get_data()['code']);
        self::assertSame(0, $calls);
    }

    public function test_first_page_rejects_a_caller_supplied_watermark(): void
    {
        $resolved = 0;
        $controller = $this->controller(
            static fn(): array => [],
            static fn(): array => ['success' => true, 'drift' => 0, 'errors' => []],
            static function () use (&$resolved): array {
                $resolved++;
                return [];
            },
            static function () use (&$resolved): int {
                $resolved++;
                return 205;
            }
        );

        $response = $controller->reconcile(new \WP_REST_Request('POST', '', [
            'scope' => 'all',
            'watermark' => 100,
        ]));

        self::assertSame(400, $response->get_status());
        self::assertSame('reconciliation_watermark_not_allowed', $response->get_data()['code']);
        self::assertSame(0, $resolved);
    }

    public function test_all_scope_apply_queues_projection_jobs_without_claiming_provider_success(): void
    {
        $previews = [];
        $queued = [];
        $stored = [];
        $health = $this->persistentHealth($stored);
        $controller = $this->controller(
            static fn(): array => [],
            static function (int $userId, bool $dryRun) use (&$previews): array {
                $previews[] = [$userId, $dryRun];
                return [
                    'success' => $userId !== 31,
                    'drift' => $userId === 31 ? 1 : 0,
                    'errors' => $userId === 31 ? ['contact_resolve_failed'] : [],
                ];
            },
            static fn(int $cursor, int $watermark, int $limit): array => [31, 47],
            static fn(): int => 47,
            static function (int $userId) use (&$queued): array {
                $queued[] = $userId;
                return [
                    'accepted' => true,
                    'user_id' => $userId,
                    'request_version' => 1,
                    'status' => 'pending',
                    'scheduled' => true,
                ];
            },
            $health
        );

        $data = $controller->reconcile(new \WP_REST_Request('POST', '', [
            'scope' => 'all',
            'dry_run' => false,
        ]))->get_data()['data'];

        self::assertSame([[31, true], [47, true]], $previews);
        self::assertSame([31, 47], $queued);
        self::assertSame(2, $data['processed']);
        self::assertSame(0, $data['failed']);
        self::assertTrue($data['complete']);
        self::assertSame(1, $data['drift']);
        self::assertSame(0, $data['applied_drift']);
        self::assertSame(1, $data['remaining_drift']);
        self::assertSame('pending', $data['results'][0]['status']);
        self::assertTrue($data['results'][0]['accepted']);
        self::assertArrayNotHasKey('success', $data['results'][0]);
        self::assertSame(['contact_resolve_failed'], $data['results'][0]['errors']);
        self::assertSame([
            'watermark' => 47,
            'cursor' => 47,
            'complete' => true,
            'processed' => 2,
            'failed' => 0,
            'drift' => 1,
            'updated_at' => '2026-07-23 09:00:00',
        ], $data['aggregate']);
        self::assertArrayNotHasKey('results', $stored['reconciliation'] ?? []);
    }

    public function test_apply_summary_resumes_after_process_restart_and_keeps_failures_cumulative(): void
    {
        $stored = [];
        $queued = [];
        $queue = static function (int $userId) use (&$queued): array {
            $queued[] = $userId;
            if ($userId === 102) {
                throw new \RuntimeException('provider credentials private');
            }

            return [
                'accepted' => true,
                'user_id' => $userId,
                'request_version' => 1,
                'status' => 'pending',
                'scheduled' => true,
            ];
        };
        $users = static fn(int $cursor, int $watermark, int $limit): array => array_slice(
            range($cursor + 1, $watermark),
            0,
            $limit
        );
        $first = $this->controller(
            static fn(): array => [],
            static fn(): array => ['success' => true, 'drift' => 0, 'errors' => []],
            $users,
            static fn(): int => 105,
            $queue,
            $this->persistentHealth($stored)
        );

        $pageOne = $first->reconcile(new \WP_REST_Request('POST', '', [
            'scope' => 'all',
            'dry_run' => false,
        ]))->get_data()['data'];

        $restarted = $this->controller(
            static fn(): array => [],
            static fn(): array => ['success' => true, 'drift' => 0, 'errors' => []],
            $users,
            null,
            $queue,
            $this->persistentHealth($stored)
        );
        $pageTwo = $restarted->reconcile(new \WP_REST_Request('POST', '', [
            'scope' => 'all',
            'dry_run' => false,
            'cursor' => 100,
            'watermark' => 105,
        ]))->get_data()['data'];

        self::assertSame(100, $pageOne['aggregate']['processed']);
        self::assertSame(0, $pageOne['aggregate']['failed']);
        self::assertSame(100, $pageOne['aggregate']['cursor']);
        self::assertFalse($pageOne['aggregate']['complete']);
        self::assertSame(5, $pageTwo['processed']);
        self::assertSame(1, $pageTwo['failed']);
        self::assertSame(105, $pageTwo['aggregate']['processed']);
        self::assertSame(1, $pageTwo['aggregate']['failed']);
        self::assertSame(105, $pageTwo['aggregate']['cursor']);
        self::assertTrue($pageTwo['aggregate']['complete']);
        self::assertStringNotContainsString('private', serialize($pageTwo));
    }

    public function test_real_reconciliation_returns_the_pre_apply_preview_and_truthful_mutation_outcome(): void
    {
        $calls = [];
        $records = [];
        $health = new FluentCrmIntegrationHealth(
            static fn(): array => ['active' => true, 'version' => '3.1.8'],
            static fn(): bool => true,
            static fn(): array => ['fluentcrm_enabled' => 'yes'],
            static fn(): array => [],
            static fn(): array => [],
            static function (array $summary) use (&$records): bool {
                $records[] = $summary;
                return true;
            },
            static fn(): string => '2026-07-22 12:00:00'
        );
        $controller = new IntegrationHealthController(
            $health,
            static function (int $userId, bool $dryRun) use (&$calls): array {
                $calls[] = [$userId, $dryRun];

                return $dryRun
                    ? [
                        'success' => true,
                        'drift' => 3,
                        'desired' => ['tag_names' => ['member:gold'], 'tag_ids' => [9], 'list_ids' => [4]],
                        'current' => ['owned_tag_ids' => [7], 'owned_list_ids' => []],
                        'errors' => [],
                    ]
                    : [
                        'success' => false,
                        'degraded' => true,
                        'attached_tags' => [9],
                        'detached_tags' => [7],
                        'attached_lists' => [4],
                        'detached_lists' => [],
                        'custom_fields' => ['membership_status' => 'active'],
                        'errors' => [
                            'tag_rollback_unconfirmed',
                            'custom_field_read_failed',
                            'tag_compensation_verification_failed',
                            'tag_compensation_attach_unconfirmed',
                            'tag_compensation_attach_failed',
                            'list_compensation_verification_failed',
                            'list_compensation_attach_unconfirmed',
                            'list_compensation_attach_failed',
                        ],
                    ];
            }
        );

        $data = $controller->reconcile(new \WP_REST_Request('POST', '', ['user_id' => 21, 'dry_run' => false]))->get_data()['data'];

        self::assertSame([[21, true], [21, false], [21, true]], $calls);
        self::assertSame(3, $data['drift']);
        self::assertSame(0, $data['applied_drift']);
        self::assertSame(3, $data['remaining_drift']);
        self::assertSame(['member:gold'], $data['results'][0]['desired']['tag_names']);
        self::assertSame([9], $data['results'][0]['outcome']['attached_tags']);
        self::assertTrue($data['results'][0]['outcome']['degraded']);
        self::assertSame([
            'tag_rollback_unconfirmed',
            'custom_field_read_failed',
            'tag_compensation_verification_failed',
            'tag_compensation_attach_unconfirmed',
            'tag_compensation_attach_failed',
            'list_compensation_verification_failed',
            'list_compensation_attach_unconfirmed',
            'list_compensation_attach_failed',
        ], $data['results'][0]['outcome']['errors']);
        self::assertSame([['last_reconciliation' => '2026-07-22 12:00:00', 'processed' => 1, 'failed' => 1, 'drift' => 3]], $records);
    }

    public function test_all_scope_uses_the_membership_user_resolver_and_never_receives_unrelated_wordpress_users(): void
    {
        $memberPages = [];
        $calls = [];
        $controller = $this->controller(
            static fn(): array => [],
            static function (int $userId, bool $dryRun) use (&$calls): array {
                $calls[] = $userId;
                return ['success' => true, 'drift' => 0, 'errors' => []];
            },
            static function (int $cursor, int $watermark, int $limit) use (&$memberPages): array {
                $memberPages[] = [$cursor, $watermark, $limit];
                return [31, 47];
            },
            static fn(): int => 47
        );

        $data = $controller->reconcile(new \WP_REST_Request('POST', '', ['scope' => 'all']))->get_data()['data'];

        self::assertSame([[0, 47, 101]], $memberPages);
        self::assertSame([31, 47], $calls);
        self::assertSame(2, $data['processed']);
    }

    public function test_successful_real_reconciliation_records_zero_remaining_drift_after_applying_the_preview(): void
    {
        $records = [];
        $health = new FluentCrmIntegrationHealth(
            static fn(): array => ['active' => true, 'version' => '3.1.8'],
            static fn(): bool => true,
            static fn(): array => ['fluentcrm_enabled' => 'yes'],
            static fn(): array => [],
            static fn(): array => [],
            static function (array $summary) use (&$records): bool {
                $records[] = $summary;
                return true;
            },
            static fn(): string => '2026-07-22 12:30:00'
        );
        $previewCalls = 0;
        $controller = new IntegrationHealthController(
            $health,
            static function (int $userId, bool $dryRun) use (&$previewCalls): array {
                if (!$dryRun) {
                    return ['success' => true, 'errors' => []];
                }

                $previewCalls++;

                return [
                    'success' => true,
                    'drift' => $previewCalls === 1 ? 2 : 0,
                    'desired' => [],
                    'current' => [],
                    'errors' => [],
                ];
            }
        );

        $data = $controller->reconcile(new \WP_REST_Request('POST', '', ['user_id' => 21, 'dry_run' => false]))->get_data()['data'];

        self::assertSame(2, $data['drift']);
        self::assertSame(2, $data['applied_drift']);
        self::assertSame(0, $data['remaining_drift']);
        self::assertSame(0, $records[0]['drift']);
    }

    public function test_successful_reconciliation_with_unresolved_postflight_drift_records_the_remaining_drift(): void
    {
        $records = [];
        $health = new FluentCrmIntegrationHealth(
            static fn(): array => ['active' => true, 'version' => '3.1.8'],
            static fn(): bool => true,
            static fn(): array => ['fluentcrm_enabled' => 'yes'],
            static fn(): array => [],
            static fn(): array => [],
            static function (array $summary) use (&$records): bool {
                $records[] = $summary;
                return true;
            },
            static fn(): string => '2026-07-22 12:45:00'
        );
        $calls = 0;
        $controller = new IntegrationHealthController(
            $health,
            static function (int $userId, bool $dryRun) use (&$calls): array {
                $calls++;
                if (!$dryRun) {
                    return ['success' => true, 'attached_tags' => [], 'errors' => []];
                }

                return [
                    'success' => true,
                    'drift' => 1,
                    'desired' => ['tag_names' => ['member:gold'], 'tag_ids' => [], 'list_ids' => []],
                    'current' => ['owned_tag_ids' => [], 'owned_list_ids' => []],
                    'errors' => [],
                ];
            }
        );

        $data = $controller->reconcile(new \WP_REST_Request('POST', '', ['user_id' => 21, 'dry_run' => false]))->get_data()['data'];

        self::assertSame(3, $calls);
        self::assertSame(1, $data['drift']);
        self::assertSame(0, $data['applied_drift']);
        self::assertSame(1, $data['remaining_drift']);
        self::assertSame(1, $records[0]['drift']);
    }

    public function test_rejects_an_ambiguous_or_invalid_reconciliation_request(): void
    {
        $controller = $this->controller(static fn(): array => [], static fn(): array => []);

        $response = $controller->reconcile(new \WP_REST_Request('POST', '', ['user_id' => 21, 'scope' => 'all']))->get_data();

        self::assertSame('invalid_reconciliation_scope', $response['code']);
    }

    private function controller(
        callable $status,
        callable $reconciler,
        ?callable $users = null,
        ?callable $watermark = null,
        ?callable $queue = null,
        ?FluentCrmIntegrationHealth $health = null
    ): IntegrationHealthController
    {
        return new IntegrationHealthController(
            $health ?? new FluentCrmIntegrationHealth(
                static fn(): array => ['active' => true, 'version' => '2.9.0'],
                static fn(): bool => true,
                static fn(): array => ['fluentcrm_enabled' => 'yes'],
                static fn(): array => ['triggers' => 1, 'actions' => 1, 'benchmarks' => 1],
                $status
            ),
            $reconciler,
            $users ?? static fn(int $cursor, int $watermark, int $limit): array => [],
            $watermark ?? static fn(): int => 0,
            $queue
        );
    }

    /** @param array<string, mixed> $stored */
    private function persistentHealth(array &$stored): FluentCrmIntegrationHealth
    {
        return new FluentCrmIntegrationHealth(
            static fn(): array => ['active' => true, 'version' => '3.1.8'],
            static fn(): bool => true,
            static fn(): array => ['fluentcrm_enabled' => 'yes'],
            static fn(): array => [],
            static function () use (&$stored): array {
                return $stored;
            },
            static function (array $summary) use (&$stored): bool {
                $stored = $summary;
                return true;
            },
            static fn(): string => '2026-07-23 09:00:00',
            static fn(): array => []
        );
    }

    private function usePersistentMutationRequests(): void
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
    }
}

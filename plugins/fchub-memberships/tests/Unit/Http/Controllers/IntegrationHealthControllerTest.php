<?php

declare(strict_types=1);

namespace FChubMemberships\Tests\Unit\Http\Controllers;

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

    public function test_all_scope_requires_manage_options_and_pages_users_in_batches_of_one_hundred(): void
    {
        $pages = [];
        $calls = [];
        $controller = $this->controller(
            static fn(): array => ['status' => 'healthy', 'action' => 'No action required.'],
            static function (int $userId, bool $dryRun) use (&$calls): array {
                $calls[] = [$userId, $dryRun];
                return ['success' => $userId !== 2, 'drift' => $userId === 1 ? 1 : 0, 'errors' => $userId === 2 ? ['contact_resolve_failed'] : []];
            },
            static function (int $offset, int $limit) use (&$pages): array {
                $pages[] = [$offset, $limit];
                return match ($offset) {
                    0 => range(1, 100),
                    default => [],
                };
            }
        );
        $GLOBALS['_fchub_test_current_user_caps'] = [
            'manage_fchub_memberships' => true,
            'manage_options' => true,
        ];

        $request = new \WP_REST_Request('POST', '', ['scope' => 'all', 'dry_run' => false]);
        self::assertTrue($controller->reconcilePermission($request));

        $data = $controller->reconcile($request)->get_data()['data'];

        self::assertSame([[0, 100], [100, 100]], $pages);
        self::assertCount(300, $calls);
        self::assertSame([1, true], $calls[0]);
        self::assertSame([1, false], $calls[1]);
        self::assertSame([1, true], $calls[2]);
        self::assertSame([100, true], $calls[299]);
        self::assertSame(100, $data['processed']);
        self::assertSame(1, $data['failed']);
        self::assertSame(1, $data['drift']);
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
                        'attached_tags' => [9],
                        'detached_tags' => [7],
                        'attached_lists' => [4],
                        'detached_lists' => [],
                        'custom_fields' => ['membership_status' => 'active'],
                        'errors' => ['tag_rollback_unconfirmed'],
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
        self::assertSame(['tag_rollback_unconfirmed'], $data['results'][0]['outcome']['errors']);
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
            static function (int $offset, int $limit) use (&$memberPages): array {
                $memberPages[] = [$offset, $limit];
                return $offset === 0 ? [31, 47] : [];
            }
        );

        $data = $controller->reconcile(new \WP_REST_Request('POST', '', ['scope' => 'all']))->get_data()['data'];

        self::assertSame([[0, 100]], $memberPages);
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

    private function controller(callable $status, callable $reconciler, ?callable $users = null): IntegrationHealthController
    {
        return new IntegrationHealthController(
            new FluentCrmIntegrationHealth(
                static fn(): array => ['active' => true, 'version' => '2.9.0'],
                static fn(): bool => true,
                static fn(): array => ['fluentcrm_enabled' => 'yes'],
                static fn(): array => ['triggers' => 1, 'actions' => 1, 'benchmarks' => 1],
                $status
            ),
            $reconciler,
            $users ?? static fn(int $offset, int $limit): array => []
        );
    }
}

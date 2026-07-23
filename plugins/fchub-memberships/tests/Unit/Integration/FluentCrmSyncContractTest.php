<?php

declare(strict_types=1);

namespace {
    if (!function_exists('FluentCrmApi')) {
        function FluentCrmApi(string $resource): object
        {
            return $GLOBALS['_fchub_test_fluentcrm_api'][$resource];
        }
    }
}

namespace FChubMemberships\Tests\Unit\Integration {

    use FChubMemberships\Integration\FluentCrmSync;
    use FChubMemberships\Storage\FluentCrmProjectionJobRepository;
    use FChubMemberships\Support\Clock;
    use FChubMemberships\Tests\Unit\PluginTestCase;
    use PHPUnit\Framework\Attributes\DataProvider;

    if (!defined('FLUENTCRM')) {
        define('FLUENTCRM', 'fluentcrm');
    }

    final class FluentCrmSyncContractTest extends PluginTestCase
    {
        private ContractContact $contact;

        protected function setUp(): void
        {
            parent::setUp();

            $this->contact = new ContractContact();
            $GLOBALS['_fchub_test_fluentcrm_api'] = [
                'contacts' => new ContractContactsApi($this->contact),
                'tags' => new ContractTagsApi(),
            ];
            $GLOBALS['_fchub_test_wpdb_overrides']['get_row'] = static fn(): array => [
                'id' => 5,
                'title' => 'Gold Plan',
                'status' => 'active',
                'level' => 0,
                'includes_plan_ids' => '[]',
                'settings' => '{}',
                'meta' => '{}',
            ];
        }

        /** @param callable(FluentCrmSync): void $dispatch */
        #[DataProvider('lifecycleEvents')]
        public function test_lifecycle_events_delegate_the_affected_user_to_the_projector(callable $dispatch): void
        {
            $reconciled = [];
            $sync = $this->sync(
                static function (int $userId) use (&$reconciled): array {
                    $reconciled[] = $userId;
                    return ['success' => true, 'errors' => []];
                }
            );

            $dispatch($sync);

            self::assertSame([21], $reconciled);
        }

        public function test_returned_projection_failure_is_persisted_and_schedules_attempt_two(): void
        {
            $jobs = new RecordingProjectionJobs();
            $scheduled = [];
            $sync = $this->sync(
                static fn(): array => [
                    'success' => false,
                    'errors' => ['contact_resolve_failed', 'secret=do-not-store'],
                ],
                $jobs,
                static fn(): array => ['success' => false, 'drift' => 1, 'errors' => []],
                $scheduled
            );

            $sync->onGrantCreated(21, 5);

            self::assertSame([[21, 1, 1, 'worker-a', 'projection_contact_failed']], $jobs->failures);
            self::assertSame([
                1773491700,
                FluentCrmSync::WORKER_HOOK,
                [21, 1, 2],
                'fchub-memberships-crm-projection-21-v1-a2',
                true,
            ], $scheduled[0]);
            self::assertStringNotContainsString('secret', serialize($jobs->failures));
        }

        #[DataProvider('projectionErrorCodes')]
        public function test_returned_projection_errors_are_reduced_to_the_allow_list(
            array $result,
            string $expectedCode
        ): void {
            $jobs = new RecordingProjectionJobs();
            $scheduled = [];
            $sync = $this->sync(
                static fn(): array => $result,
                $jobs,
                static fn(): array => ['success' => false, 'drift' => 1, 'errors' => []],
                $scheduled
            );

            $sync->onGrantCreated(21, 5);

            self::assertSame($expectedCode, $jobs->failures[0][4]);
        }

        public static function projectionErrorCodes(): array
        {
            return [
                'invalid user' => [['success' => false, 'errors' => ['invalid_user']], 'projection_invalid_user'],
                'load' => [['success' => false, 'errors' => ['projection_load_failed']], 'projection_load_failed'],
                'tag' => [['success' => false, 'errors' => ['tag_resolve_failed']], 'projection_tag_failed'],
                'relation read' => [['success' => false, 'errors' => ['lists_read_failed']], 'projection_relation_read_failed'],
                'relation write' => [['success' => false, 'errors' => ['tag_attach_failed']], 'projection_relation_write_failed'],
                'state commit' => [['success' => false, 'errors' => ['state_save_failed']], 'projection_state_commit_failed'],
                'compensation error' => [[
                    'success' => false,
                    'degraded' => false,
                    'errors' => ['list_compensation_attach_failed'],
                ], 'projection_compensation_failed'],
                'degraded compensation' => [[
                    'success' => false,
                    'degraded' => true,
                    'errors' => [],
                ], 'projection_compensation_failed'],
            ];
        }

        public function test_thrown_projection_failure_is_durable_and_sanitised(): void
        {
            $jobs = new RecordingProjectionJobs();
            $scheduled = [];
            $sync = $this->sync(
                static fn(): array => throw new \RuntimeException('token=private'),
                $jobs,
                static fn(): array => ['success' => false, 'drift' => 1, 'errors' => []],
                $scheduled
            );

            $sync->onGrantCreated(21, 5);

            self::assertSame('projection_unexpected_failure', $jobs->failures[0][4]);
            self::assertStringNotContainsString('private', serialize($jobs->failures));
            self::assertCount(1, $scheduled);
        }

        public function test_success_is_cleared_only_after_pure_postflight_confirms_custom_fields_and_relations(): void
        {
            $jobs = new RecordingProjectionJobs();
            $scheduled = [];
            $sync = $this->sync(
                static fn(): array => ['success' => true, 'errors' => []],
                $jobs,
                static fn(): array => [
                    'success' => true,
                    'drift' => 0,
                    'errors' => [],
                    'current' => ['custom_fields' => ['membership_status' => 'active']],
                ],
                $scheduled
            );

            $sync->onGrantCreated(21, 5);

            self::assertSame([[21, 1, 1, 'worker-a']], $jobs->successes);
            self::assertSame([], $jobs->failures);
            self::assertSame([], $scheduled);
        }

        public function test_postflight_drift_remains_pending_instead_of_clearing_degraded_state(): void
        {
            $jobs = new RecordingProjectionJobs();
            $scheduled = [];
            $sync = $this->sync(
                static fn(): array => ['success' => true, 'errors' => []],
                $jobs,
                static fn(): array => ['success' => true, 'drift' => 1, 'errors' => []],
                $scheduled
            );

            $sync->onGrantCreated(21, 5);

            self::assertSame('projection_postflight_failed', $jobs->failures[0][4]);
            self::assertSame([], $jobs->successes);
        }

        public function test_newer_request_version_wins_over_an_older_worker_completion(): void
        {
            $jobs = new RecordingProjectionJobs();
            $jobs->currentVersion = 2;
            $jobs->attemptCount = 0;
            $scheduled = [];
            $sync = $this->sync(
                static fn(): array => ['success' => true, 'errors' => []],
                $jobs,
                static fn(): array => ['success' => true, 'drift' => 0, 'errors' => []],
                $scheduled
            );

            $sync->processProjectionJob(21, 1, 1);

            self::assertSame([], $jobs->successes);
            self::assertSame([], $jobs->failures);
            self::assertSame([], $scheduled);
        }

        public function test_completion_cas_loss_after_a_stale_success_requeues_the_latest_aggregate(): void
        {
            $jobs = new RecordingProjectionJobs();
            $jobs->currentVersion = 1;
            $scheduled = [];
            $sync = $this->sync(
                static function () use ($jobs): array {
                    $jobs->currentVersion = 2;
                    $jobs->status = 'succeeded';
                    return ['success' => true, 'errors' => []];
                },
                $jobs,
                static fn(): array => ['success' => true, 'drift' => 0, 'errors' => []],
                $scheduled
            );

            $sync->processProjectionJob(21, 1, 1);

            self::assertSame(3, $jobs->currentVersion);
            self::assertSame('pending', $jobs->status);
            self::assertSame([21, 3, 1], $scheduled[0][2]);
        }

        public function test_completion_cas_loss_after_a_stale_failure_requeues_the_latest_aggregate(): void
        {
            $jobs = new RecordingProjectionJobs();
            $jobs->currentVersion = 1;
            $scheduled = [];
            $sync = $this->sync(
                static function () use ($jobs): array {
                    $jobs->currentVersion = 2;
                    $jobs->status = 'succeeded';
                    return ['success' => false, 'errors' => ['contact_resolve_failed']];
                },
                $jobs,
                static fn(): array => ['success' => false, 'drift' => 1, 'errors' => []],
                $scheduled
            );

            $sync->processProjectionJob(21, 1, 1);

            self::assertSame(3, $jobs->currentVersion);
            self::assertSame('pending', $jobs->status);
            self::assertSame([21, 3, 1], $scheduled[0][2]);
        }

        public function test_fourth_failure_is_terminal_and_schedules_no_fifth_attempt(): void
        {
            $jobs = new RecordingProjectionJobs();
            $jobs->currentVersion = 1;
            $jobs->attemptCount = 3;
            $scheduled = [];
            $sync = $this->sync(
                static fn(): array => ['success' => false, 'errors' => ['custom_field_sync_failed']],
                $jobs,
                static fn(): array => ['success' => false, 'drift' => 1, 'errors' => []],
                $scheduled
            );

            $sync->processProjectionJob(21, 1, 4);

            self::assertSame('projection_custom_fields_failed', $jobs->failures[0][4]);
            self::assertSame([], $scheduled);
            self::assertSame('failed', $jobs->status);
        }

        public function test_recovery_schedules_at_most_fifty_due_or_stale_jobs(): void
        {
            $jobs = new RecordingProjectionJobs();
            $jobs->recoverable = array_map(static fn(int $userId): array => [
                'user_id' => $userId,
                'status' => $userId % 2 === 0 ? 'processing' : 'pending',
                'request_version' => 3,
                'attempt_count' => 1,
                'next_retry_at' => null,
            ], range(1, 50));
            $scheduled = [];
            $sync = $this->sync(
                static fn(): array => ['success' => true, 'errors' => []],
                $jobs,
                static fn(): array => ['success' => true, 'drift' => 0, 'errors' => []],
                $scheduled
            );

            self::assertSame(50, $sync->recoverDue(500));
            self::assertCount(50, $scheduled);
            self::assertSame(50, $jobs->lastRecoveryLimit);
            self::assertSame([1, 3, 2], $scheduled[0][2]);
        }

        public function test_recovery_reclaims_a_stale_fourth_attempt_without_scheduling_attempt_five(): void
        {
            $jobs = new RecordingProjectionJobs();
            $jobs->recoverable = [[
                'user_id' => 21,
                'status' => 'processing',
                'request_version' => 3,
                'attempt_count' => 4,
                'lease_expires_at' => '2026-03-14 12:00:00',
                'next_retry_at' => null,
            ]];
            $scheduled = [];
            $sync = $this->sync(
                static fn(): array => ['success' => true, 'errors' => []],
                $jobs,
                static fn(): array => ['success' => true, 'drift' => 0, 'errors' => []],
                $scheduled
            );

            self::assertSame(1, $sync->recoverDue());
            self::assertSame([21, 3, 4], $scheduled[0][2]);
            self::assertSame('fchub-memberships-crm-projection-21-v3-a4', $scheduled[0][3]);
        }

        public function test_queue_projection_persists_and_schedules_without_running_the_provider(): void
        {
            $jobs = new RecordingProjectionJobs();
            $scheduled = [];
            $sync = $this->sync(
                static fn(): array => throw new \RuntimeException('must not reconcile in the request'),
                $jobs,
                null,
                $scheduled
            );

            $result = $sync->queueProjection(21);

            self::assertSame([
                'accepted' => true,
                'user_id' => 21,
                'request_version' => 1,
                'status' => 'pending',
                'scheduled' => true,
            ], $result);
            self::assertSame([
                1773491400,
                FluentCrmSync::WORKER_HOOK,
                [21, 1, 1],
                'fchub-memberships-crm-projection-21-v1-a1',
                true,
            ], $scheduled[0]);
            self::assertSame([], $jobs->successes);
            self::assertSame([], $jobs->failures);
        }

        public function test_queue_projection_remains_accepted_when_recovery_must_schedule_it_later(): void
        {
            $jobs = new RecordingProjectionJobs();
            $sync = new FluentCrmSync(
                static fn(): array => ['success' => true],
                $jobs,
                static fn(): array => ['success' => true, 'drift' => 0],
                new Clock(
                    new \DateTimeImmutable('2026-03-14 12:30:00', new \DateTimeZone('UTC')),
                    new \DateTimeZone('UTC')
                ),
                static fn(): int => 0,
                static fn(): string => 'worker-a'
            );

            $result = $sync->queueProjection(21);

            self::assertTrue($result['accepted']);
            self::assertSame('pending', $result['status']);
            self::assertFalse($result['scheduled']);
            self::assertSame(1, $result['request_version']);
        }

        public function test_queue_projection_rejects_a_non_positive_user_id_before_storage(): void
        {
            $jobs = new RecordingProjectionJobs();
            $sync = $this->sync(static fn(): array => ['success' => true], $jobs);

            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessage('greater than zero');

            $sync->queueProjection(0);
        }

        public function test_registering_the_same_sync_twice_keeps_lifecycle_and_worker_hooks_single(): void
        {
            $GLOBALS['_fchub_test_options']['fchub_memberships_settings'] = ['fluentcrm_enabled' => 'yes'];
            $sync = $this->sync(static fn(): array => ['success' => true, 'errors' => []]);
            $arguments = [
                static fn(string $function): bool => true,
                static fn(string $class): bool => true,
                static fn(string $class, string $method): bool => true,
                static fn(string $resource): object => $GLOBALS['_fchub_test_fluentcrm_api'][$resource],
            ];

            $sync->register(...$arguments);
            $sync->register(...$arguments);

            self::assertCount(1, $GLOBALS['_fchub_test_action_registrations']['fchub_memberships/grant_created']);
            self::assertCount(1, $GLOBALS['_fchub_test_action_registrations'][FluentCrmSync::WORKER_HOOK]);
            self::assertSame(3, $GLOBALS['_fchub_test_action_registrations'][FluentCrmSync::WORKER_HOOK][0]['accepted_args']);
        }

        /** @return array<string, array{callable(FluentCrmSync): void}> */
        public static function lifecycleEvents(): array
        {
            $grant = [
                'user_id' => 21,
                'plan_id' => 5,
            ];

            return [
                'grant' => [
                    static fn(FluentCrmSync $sync) => $sync->onGrantCreated(21, 5, ['expires_at' => '2026-04-01 00:00:00']),
                ],
                'pause' => [
                    static fn(FluentCrmSync $sync) => $sync->onGrantPaused($grant),
                ],
                'resume' => [
                    static fn(FluentCrmSync $sync) => $sync->onGrantResumed($grant),
                ],
                'revoke' => [
                    static fn(FluentCrmSync $sync) => $sync->onGrantRevoked([], 5, 21),
                ],
                'expiry' => [
                    static fn(FluentCrmSync $sync) => $sync->onGrantExpired($grant),
                ],
                'renewal' => [
                    static fn(FluentCrmSync $sync) => $sync->onGrantRenewed($grant, 4),
                ],
            ];
        }

        public function test_lifecycle_capability_gate_requires_every_consumed_api(): void
        {
            self::assertTrue(method_exists(FluentCrmSync::class, 'hasRequiredCapabilities'));
            if (!method_exists(FluentCrmSync::class, 'hasRequiredCapabilities')) {
                return;
            }

            $classExists = static fn(string $class): bool => true;
            $allMethods = static fn(string $class, string $method): bool => true;

            self::assertTrue(FluentCrmSync::hasRequiredCapabilities(
                'lifecycle',
                static fn(string $function): bool => true,
                $classExists,
                $allMethods
            ));
            self::assertFalse(FluentCrmSync::hasRequiredCapabilities(
                'lifecycle',
                static fn(string $function): bool => true,
                $classExists,
                static fn(string $class, string $method): bool => $method !== 'syncCustomFieldValues'
            ));
        }

        #[DataProvider('declaredLifecycleMethods')]
        public function test_register_rejects_each_missing_declared_lifecycle_method(string $missingClass, string $missingMethod): void
        {
            $GLOBALS['_fchub_test_options']['fchub_memberships_settings'] = ['fluentcrm_enabled' => 'yes'];
            $methodExists = static fn(string $class, string $method): bool => !(
                $class === $missingClass && $method === $missingMethod
            );
            $resolver = static fn(string $resource): object => $GLOBALS['_fchub_test_fluentcrm_api'][$resource];

            self::assertFalse(FluentCrmSync::hasRequiredCapabilities(
                'lifecycle',
                static fn(string $function): bool => true,
                static fn(string $class): bool => true,
                $methodExists,
                $resolver
            ));
            (new FluentCrmSync())->register(
                static fn(string $function): bool => true,
                static fn(string $class): bool => true,
                $methodExists,
                $resolver
            );

            self::assertArrayNotHasKey('fchub_memberships/grant_created', $GLOBALS['_fchub_test_actions']);
        }

        /** @return array<string, array{string, string}> */
        public static function declaredLifecycleMethods(): array
        {
            return [
                'contacts getContactByUserRef' => ['FluentCrm\\App\\Api\\Classes\\Contacts', 'getContactByUserRef'],
                'contacts getContactByUserId' => ['FluentCrm\\App\\Api\\Classes\\Contacts', 'getContactByUserId'],
                'contacts createOrUpdate' => ['FluentCrm\\App\\Api\\Classes\\Contacts', 'createOrUpdate'],
                'contacts getCustomFields' => ['FluentCrm\\App\\Api\\Classes\\Contacts', 'getCustomFields'],
                'tags getInstance' => ['FluentCrm\\App\\Api\\Classes\\Tags', 'getInstance'],
                'tags importBulk' => ['FluentCrm\\App\\Api\\Classes\\Tags', 'importBulk'],
                'subscriber attachTags' => ['FluentCrm\\App\\Models\\Subscriber', 'attachTags'],
                'subscriber detachTags' => ['FluentCrm\\App\\Models\\Subscriber', 'detachTags'],
                'subscriber attachLists' => ['FluentCrm\\App\\Models\\Subscriber', 'attachLists'],
                'subscriber detachLists' => ['FluentCrm\\App\\Models\\Subscriber', 'detachLists'],
                'subscriber syncCustomFieldValues' => ['FluentCrm\\App\\Models\\Subscriber', 'syncCustomFieldValues'],
                'subscriber custom_fields' => ['FluentCrm\\App\\Models\\Subscriber', 'custom_fields'],
            ];
        }

        #[DataProvider('runtimeApiFailures')]
        public function test_lifecycle_capability_gate_rejects_each_missing_runtime_api_chain(callable $apiResolver): void
        {
            $GLOBALS['_fchub_test_options']['fchub_memberships_settings'] = ['fluentcrm_enabled' => 'yes'];
            self::assertFalse(FluentCrmSync::hasRequiredCapabilities(
                'lifecycle',
                static fn(string $function): bool => true,
                static fn(string $class): bool => true,
                static fn(string $class, string $method): bool => true,
                $apiResolver
            ));
            (new FluentCrmSync())->register(
                static fn(string $function): bool => true,
                static fn(string $class): bool => true,
                static fn(string $class, string $method): bool => true,
                $apiResolver
            );
            self::assertArrayNotHasKey('fchub_memberships/grant_created', $GLOBALS['_fchub_test_actions']);
        }

        /** @return array<string, array{callable(string): object}> */
        public static function runtimeApiFailures(): array
        {
            $contact = new ContractContact();
            $contacts = new ContractContactsApi($contact);

            return [
                'contacts return missing consumed methods' => [
                    static fn(string $resource): object => $resource === 'contacts'
                        ? new MissingContactsApi()
                        : new ContractTagsApi(),
                ],
                'getInstance returns no model' => [
                    static fn(string $resource): object => $resource === 'contacts'
                        ? $contacts
                        : new NullTagModelApi(),
                ],
                'tag model misses newQuery' => [
                    static fn(string $resource): object => $resource === 'contacts'
                        ? $contacts
                        : new BrokenTagQueryApi(new MissingNewQueryTagModel()),
                ],
                'query misses where' => [
                    static fn(string $resource): object => $resource === 'contacts'
                        ? $contacts
                        : new BrokenTagQueryApi(new TagModelWithQuery(new MissingWhereQuery())),
                ],
                'query misses first' => [
                    static fn(string $resource): object => $resource === 'contacts'
                        ? $contacts
                        : new BrokenTagQueryApi(new TagModelWithQuery(new MissingFirstQuery())),
                ],
            ];
        }

        public function test_register_uses_the_complete_runtime_capability_probe(): void
        {
            $GLOBALS['_fchub_test_options']['fchub_memberships_settings'] = ['fluentcrm_enabled' => 'yes'];
            $resolver = static fn(string $resource): object => $GLOBALS['_fchub_test_fluentcrm_api'][$resource];

            (new FluentCrmSync())->register(
                static fn(string $function): bool => true,
                static fn(string $class): bool => true,
                static fn(string $class, string $method): bool => true,
                $resolver
            );

            self::assertArrayHasKey('fchub_memberships/grant_created', $GLOBALS['_fchub_test_actions']);
            self::assertArrayHasKey('fchub_memberships/grant_revoked', $GLOBALS['_fchub_test_actions']);
            self::assertArrayHasKey('fchub_memberships/grant_paused', $GLOBALS['_fchub_test_actions']);
            self::assertArrayHasKey('fchub_memberships/grant_resumed', $GLOBALS['_fchub_test_actions']);
            self::assertArrayHasKey('fchub_memberships/grant_expired', $GLOBALS['_fchub_test_actions']);
            self::assertArrayHasKey('fchub_memberships/grant_renewed', $GLOBALS['_fchub_test_actions']);
            self::assertSame(
                2,
                $GLOBALS['_fchub_test_action_registrations']['fchub_memberships/grant_renewed'][0]['accepted_args']
            );
        }

        private function sync(
            callable $reconciler,
            ?RecordingProjectionJobs $jobs = null,
            ?callable $postflight = null,
            array &$scheduled = []
        ): FluentCrmSync {
            $jobs ??= new RecordingProjectionJobs();
            $postflight ??= static fn(): array => ['success' => true, 'drift' => 0, 'errors' => []];
            $clock = new Clock(
                new \DateTimeImmutable('2026-03-14 12:30:00', new \DateTimeZone('UTC')),
                new \DateTimeZone('UTC')
            );

            return new FluentCrmSync(
                $reconciler,
                $jobs,
                $postflight,
                $clock,
                static function (
                    int $timestamp,
                    string $hook,
                    array $args,
                    string $group,
                    bool $unique
                ) use (&$scheduled): int {
                    $scheduled[] = [$timestamp, $hook, $args, $group, $unique];
                    return 1;
                },
                static fn(): string => 'worker-a'
            );
        }
    }

    final class RecordingProjectionJobs extends FluentCrmProjectionJobRepository
    {
        public int $currentVersion = 0;
        public int $attemptCount = 0;
        public string $status = 'pending';
        public int $lastRecoveryLimit = 0;
        /** @var list<array<int, int|string>> */
        public array $successes = [];
        /** @var list<array<int, int|string>> */
        public array $failures = [];
        /** @var list<array<string, mixed>> */
        public array $recoverable = [];

        public function __construct()
        {
        }

        public function request(int $userId): array
        {
            $this->currentVersion++;
            $this->attemptCount = 0;
            $this->status = 'pending';

            return $this->row($userId);
        }

        public function claim(
            int $userId,
            int $requestVersion,
            int $attempt,
            string $owner,
            int $leaseSeconds = 300
        ): ?array {
            $expectedAttempt = $this->status === 'processing'
                ? $this->attemptCount
                : $this->attemptCount + 1;
            if ($requestVersion !== $this->currentVersion || $attempt !== $expectedAttempt) {
                return null;
            }
            $this->attemptCount = $attempt;
            $this->status = 'processing';

            return $this->row($userId, ['lease_owner' => $owner]);
        }

        public function completeSuccess(int $userId, int $requestVersion, int $attempt, string $owner): bool
        {
            if ($requestVersion !== $this->currentVersion || $attempt !== $this->attemptCount) {
                return false;
            }
            $this->successes[] = [$userId, $requestVersion, $attempt, $owner];
            $this->status = 'succeeded';
            return true;
        }

        public function completeFailure(
            int $userId,
            int $requestVersion,
            int $attempt,
            string $owner,
            string $errorCode
        ): ?array {
            if ($requestVersion !== $this->currentVersion || $attempt !== $this->attemptCount) {
                return null;
            }
            $this->failures[] = [$userId, $requestVersion, $attempt, $owner, $errorCode];
            $this->status = $attempt >= 4 ? 'failed' : 'pending';

            return $this->row($userId, [
                'next_retry_at' => $attempt >= 4 ? null : '2026-03-14 12:35:00',
            ]);
        }

        public function findRecoverable(int $limit = 50): array
        {
            $this->lastRecoveryLimit = $limit;
            return array_slice($this->recoverable, 0, $limit);
        }

        private function row(int $userId, array $overrides = []): array
        {
            return array_merge([
                'user_id' => $userId,
                'status' => $this->status,
                'request_version' => $this->currentVersion,
                'attempt_count' => $this->attemptCount,
                'next_retry_at' => null,
            ], $overrides);
        }
    }

    final class ContractContact
    {
        /** @var list<array{array<string, string>, bool}> */
        public array $syncCalls = [];

        public function attachTags(array $tagIds): void
        {
        }

        public function detachTags(array $tagIds): void
        {
        }

        public function attachLists(array $listIds): void
        {
        }

        public function detachLists(array $listIds): void
        {
        }

        /** @param array<string, string> $values */
        public function syncCustomFieldValues(array $values, bool $deleteOtherValues = true): void
        {
            $this->syncCalls[] = [$values, $deleteOtherValues];
        }
    }

    final class ContractContactsApi
    {
        public function __construct(private ContractContact $contact)
        {
        }

        public function getContactByUserRef(int $userId): ContractContact
        {
            return $this->contact;
        }

        public function getContactByUserId(int $userId): ContractContact
        {
            return $this->contact;
        }

        public function createOrUpdate(array $data): ContractContact
        {
            return $this->contact;
        }

        /** @return list<array{slug: string}> */
        public function getCustomFields(): array
        {
            return [
                ['slug' => 'membership_plan'],
                ['slug' => 'membership_status'],
                ['slug' => 'membership_expires'],
            ];
        }
    }

    final class ContractTagsApi
    {
        public function getInstance(): ContractTagsQuery
        {
            return new ContractTagsQuery();
        }

        /** @return list<object> */
        public function importBulk(array $tags): array
        {
            return [(object) ['id' => 1]];
        }
    }

    final class ContractTagsQuery
    {
        public function newQuery(): self
        {
            return $this;
        }

        public function where(string $column, string $value): self
        {
            return $this;
        }

        public function first(): ?object
        {
            return null;
        }
    }

    final class MissingContactsApi
    {
    }

    final class NullTagModelApi
    {
        public function getInstance(): mixed
        {
            return null;
        }

        public function importBulk(array $tags): array
        {
            return [];
        }
    }

    final class BrokenTagQueryApi
    {
        public function __construct(private object $model)
        {
        }

        public function getInstance(): object
        {
            return $this->model;
        }

        public function importBulk(array $tags): array
        {
            return [];
        }
    }

    final class MissingNewQueryTagModel
    {
    }

    final class TagModelWithQuery
    {
        public function __construct(private object $query)
        {
        }

        public function newQuery(): object
        {
            return $this->query;
        }
    }

    final class MissingWhereQuery
    {
        public function first(): ?object
        {
            return null;
        }
    }

    final class MissingFirstQuery
    {
        public function where(string $column, string $value): self
        {
            return $this;
        }
    }
}

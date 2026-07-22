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
            $sync = new FluentCrmSync(static function (int $userId) use (&$reconciled): array {
                $reconciled[] = $userId;
                return ['success' => true];
            });

            $dispatch($sync);

            self::assertSame([21], $reconciled);
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
                'contacts createOrUpdate' => ['FluentCrm\\App\\Api\\Classes\\Contacts', 'createOrUpdate'],
                'contacts getCustomFields' => ['FluentCrm\\App\\Api\\Classes\\Contacts', 'getCustomFields'],
                'tags getInstance' => ['FluentCrm\\App\\Api\\Classes\\Tags', 'getInstance'],
                'tags importBulk' => ['FluentCrm\\App\\Api\\Classes\\Tags', 'importBulk'],
                'subscriber attachTags' => ['FluentCrm\\App\\Models\\Subscriber', 'attachTags'],
                'subscriber detachTags' => ['FluentCrm\\App\\Models\\Subscriber', 'detachTags'],
                'subscriber attachLists' => ['FluentCrm\\App\\Models\\Subscriber', 'attachLists'],
                'subscriber detachLists' => ['FluentCrm\\App\\Models\\Subscriber', 'detachLists'],
                'subscriber syncCustomFieldValues' => ['FluentCrm\\App\\Models\\Subscriber', 'syncCustomFieldValues'],
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

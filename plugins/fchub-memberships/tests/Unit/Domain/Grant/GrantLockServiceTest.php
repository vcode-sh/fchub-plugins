<?php

declare(strict_types=1);

namespace FChubMemberships\Tests\Unit\Domain\Grant;

use FChubMemberships\Domain\Event\EventClaimResult;
use FChubMemberships\Domain\Grant\GrantLockService;
use FChubMemberships\Storage\EventLockRepository;
use FChubMemberships\Tests\Unit\PluginTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class GrantLockServiceTest extends PluginTestCase
{
    public function test_order_hash_uses_the_exact_canonical_key(): void
    {
        $service = new GrantLockService($this->repository($calls));
        $this->requireMethod('orderEventHash');

        $hash = $service->orderEventHash(91, 'product', 7, 'order_paid', 'grant');

        self::assertSame(
            hash('sha256', 'order:91|scope:product|feed:7|trigger:order_paid|mode:grant'),
            $hash
        );
        self::assertSame($hash, $service->orderEventHash(91, 'product', 7, 'order_paid', 'grant'));
    }

    public function test_order_hash_separates_feeds(): void
    {
        $service = new GrantLockService($this->repository($calls));
        $this->requireMethod('orderEventHash');

        self::assertNotSame(
            $service->orderEventHash(91, 'product', 7, 'order_paid', 'grant'),
            $service->orderEventHash(91, 'product', 8, 'order_paid', 'grant')
        );
    }

    public function test_order_hash_separates_equal_numeric_ids_across_feed_scopes(): void
    {
        $service = new GrantLockService($this->repository($calls));
        $this->requireMethod('orderEventHash');

        self::assertNotSame(
            $service->orderEventHash(91, 'product', 7, 'order_paid', 'grant'),
            $service->orderEventHash(91, 'global', 7, 'order_paid', 'grant')
        );
    }

    public function test_order_hash_separates_grant_and_revoke_modes(): void
    {
        $service = new GrantLockService($this->repository($calls));
        $this->requireMethod('orderEventHash');

        self::assertNotSame(
            $service->orderEventHash(91, 'product', 7, 'order_paid', 'grant'),
            $service->orderEventHash(91, 'product', 7, 'order_paid', 'revoke')
        );
    }

    public function test_order_hash_separates_triggers(): void
    {
        $service = new GrantLockService($this->repository($calls));
        $this->requireMethod('orderEventHash');

        self::assertNotSame(
            $service->orderEventHash(91, 'product', 7, 'order_paid', 'grant'),
            $service->orderEventHash(91, 'product', 7, 'order_refunded', 'grant')
        );
    }

    public function test_renewal_hash_uses_subscription_and_installed_renewal_order(): void
    {
        $service = new GrantLockService($this->repository($calls));
        $this->requireMethod('subscriptionRenewalEventHash');
        $payload = $this->renewalPayload(88, 1201);

        $hash = $service->subscriptionRenewalEventHash($payload);

        self::assertSame(
            hash('sha256', 'subscription:88|renewal_order:1201|trigger:subscription_renewed'),
            $hash
        );
        self::assertSame(
            $hash,
            $service->subscriptionRenewalEventHash($this->renewalPayload(88, 1201))
        );
        self::assertSame([], $calls, 'Renewal key preparation must not claim or mutate an event lock.');
    }

    public function test_next_renewal_order_has_a_distinct_hash(): void
    {
        $service = new GrantLockService($this->repository($calls));
        $this->requireMethod('subscriptionRenewalEventHash');

        self::assertNotSame(
            $service->subscriptionRenewalEventHash($this->renewalPayload(88, 1201)),
            $service->subscriptionRenewalEventHash($this->renewalPayload(88, 1202))
        );
    }

    #[DataProvider('invalidRenewalPayloadProvider')]
    public function test_renewal_hash_rejects_missing_or_invalid_installed_payload(array $payload): void
    {
        $service = new GrantLockService($this->repository($calls));
        $this->requireMethod('subscriptionRenewalEventHash');
        $this->expectException(\InvalidArgumentException::class);

        $service->subscriptionRenewalEventHash($payload);
    }

    public static function invalidRenewalPayloadProvider(): array
    {
        return [
            'missing subscription' => [['order' => (object) ['id' => 1201]]],
            'subscription is not an object' => [[
                'subscription' => ['id' => 88],
                'order' => (object) ['id' => 1201],
            ]],
            'missing subscription id' => [[
                'subscription' => (object) [],
                'order' => (object) ['id' => 1201],
            ]],
            'invalid subscription id' => [[
                'subscription' => (object) ['id' => 0],
                'order' => (object) ['id' => 1201],
            ]],
            'missing renewal order' => [['subscription' => (object) ['id' => 88]]],
            'renewal order is not an object' => [[
                'subscription' => (object) ['id' => 88],
                'order' => ['id' => 1201],
            ]],
            'missing renewal order id' => [[
                'subscription' => (object) ['id' => 88],
                'order' => (object) [],
            ]],
            'invalid renewal order id' => [[
                'subscription' => (object) ['id' => 88],
                'order' => (object) ['id' => -1],
            ]],
        ];
    }

    public function test_missing_renewal_order_reports_the_installed_payload_contract(): void
    {
        $service = new GrantLockService($this->repository($calls));
        $this->requireMethod('subscriptionRenewalEventHash');
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('payload must expose order as an object');

        $service->subscriptionRenewalEventHash([
            'subscription' => (object) ['id' => 88],
        ]);
    }

    public function test_order_hash_rejects_modes_outside_grant_and_revoke(): void
    {
        $service = new GrantLockService($this->repository($calls));
        $this->requireMethod('orderEventHash');
        $this->expectException(\InvalidArgumentException::class);

        $service->orderEventHash(91, 'product', 7, 'order_paid', 'renew');
    }

    #[DataProvider('invalidFeedScopeProvider')]
    public function test_order_hash_rejects_invalid_or_missing_feed_scope(string $scope): void
    {
        $service = new GrantLockService($this->repository($calls));
        $this->requireMethod('orderEventHash');
        $this->expectException(\InvalidArgumentException::class);

        $service->orderEventHash(91, $scope, 7, 'order_paid', 'grant');
    }

    public static function invalidFeedScopeProvider(): array
    {
        return [
            'missing scope' => [''],
            'unknown scope' => ['site'],
            'scope is case sensitive' => ['PRODUCT'],
        ];
    }

    #[DataProvider('invalidOrderEventInputProvider')]
    public function test_order_hash_rejects_invalid_storage_input_before_repository_mutation(
        int $orderId,
        int $integrationId,
        string $trigger
    ): void {
        $service = new GrantLockService($this->repository($calls));
        $thrown = false;

        try {
            $service->orderEventHash($orderId, 'product', $integrationId, $trigger, 'grant');
        } catch (\InvalidArgumentException) {
            $thrown = true;
        }

        self::assertSame([], $calls, 'Invalid hash input must not reach the repository.');
        self::assertTrue($thrown, 'Invalid hash input must fail closed.');
    }

    #[DataProvider('invalidOrderEventInputProvider')]
    public function test_claim_rejects_invalid_storage_input_before_repository_mutation(
        int $orderId,
        int $integrationId,
        string $trigger
    ): void {
        $service = new GrantLockService($this->repository($calls));
        $thrown = false;

        try {
            $service->claimOrderEvent(
                $orderId,
                'product',
                $integrationId,
                $trigger,
                'grant',
                'owner-a'
            );
        } catch (\InvalidArgumentException) {
            $thrown = true;
        }

        self::assertSame([], $calls, 'Invalid claim input must not mutate the repository.');
        self::assertTrue($thrown, 'Invalid claim input must fail closed.');
    }

    public static function invalidOrderEventInputProvider(): array
    {
        return [
            'zero order id' => [0, 7, 'order_paid'],
            'negative order id' => [-1, 7, 'order_paid'],
            'zero integration id' => [91, 0, 'order_paid'],
            'negative integration id' => [91, -1, 'order_paid'],
            'empty trigger' => [91, 7, ''],
            'trigger over 100 characters' => [91, 7, str_repeat('ą', 101)],
        ];
    }

    public function test_order_hash_accepts_a_100_character_multibyte_trigger(): void
    {
        $service = new GrantLockService($this->repository($calls));
        $trigger = str_repeat('ą', 100);

        self::assertSame(
            hash('sha256', "order:91|scope:product|feed:7|trigger:{$trigger}|mode:grant"),
            $service->orderEventHash(91, 'product', 7, $trigger, 'grant')
        );
        self::assertSame([], $calls);
    }

    public function test_claim_order_event_delegates_typed_result_and_exact_context(): void
    {
        $calls = [];
        $service = new GrantLockService($this->repository($calls));
        $this->requireMethod('claimOrderEvent');

        $result = $service->claimOrderEvent(91, 'global', 7, 'order_paid', 'grant', 'owner-a', 180);

        self::assertSame(EventClaimResult::ACQUIRED, $result->outcome);
        self::assertSame([[
            'method' => 'claim',
            'event_hash' => hash('sha256', 'order:91|scope:global|feed:7|trigger:order_paid|mode:grant'),
            'context' => [
                'order_id' => 91,
                'feed_id' => 7,
                'trigger' => 'order_paid',
            ],
            'owner_token' => 'owner-a',
            'lease_seconds' => 180,
        ]], $calls);
    }

    public function test_claim_subscription_renewal_uses_the_exact_verified_receipt_and_context(): void
    {
        $calls = [];
        $service = new GrantLockService($this->repository($calls));
        $payload = $this->renewalPayload(88, 1201);

        $result = $service->claimSubscriptionRenewalEvent($payload, 'owner-a', 180);

        self::assertSame(EventClaimResult::ACQUIRED, $result->outcome);
        self::assertSame([[
            'method' => 'claim',
            'event_hash' => hash('sha256', 'subscription:88|renewal_order:1201|trigger:subscription_renewed'),
            'context' => [
                'order_id' => 1201,
                'subscription_id' => 88,
                'feed_id' => 0,
                'trigger' => 'subscription_renewed',
            ],
            'owner_token' => 'owner-a',
            'lease_seconds' => 180,
        ]], $calls);
    }

    public function test_claim_rejects_invalid_scope_before_repository_mutation(): void
    {
        $calls = [];
        $service = new GrantLockService($this->repository($calls));
        $this->requireMethod('claimOrderEvent');

        try {
            $service->claimOrderEvent(91, '', 7, 'order_paid', 'grant', 'owner-a', 180);
            self::fail('A missing feed scope must fail closed.');
        } catch (\InvalidArgumentException) {
            self::assertSame([], $calls);
        }
    }

    public function test_success_and_failure_delegate_by_hash_and_owner(): void
    {
        $calls = [];
        $service = new GrantLockService($this->repository($calls));
        $this->requireMethod('succeedEventLock');
        $this->requireMethod('failEventLock');

        self::assertTrue($service->succeedEventLock('event-hash', 'owner-a'));
        self::assertTrue($service->failEventLock('event-hash', 'owner-b', 'Provider timeout', false));
        self::assertSame([
            [
                'method' => 'succeed',
                'event_hash' => 'event-hash',
                'owner_token' => 'owner-a',
            ],
            [
                'method' => 'fail',
                'event_hash' => 'event-hash',
                'owner_token' => 'owner-b',
                'error' => 'Provider timeout',
                'retryable' => false,
            ],
        ], $calls);
    }

    private function repository(?array &$calls): EventLockRepository
    {
        $calls = [];

        return new class($calls) extends EventLockRepository {
            public function __construct(private array &$calls)
            {
            }

            public function claim(
                string $eventHash,
                array $context,
                string $ownerToken,
                int $leaseSeconds = 300
            ): EventClaimResult {
                $this->calls[] = [
                    'method' => 'claim',
                    'event_hash' => $eventHash,
                    'context' => $context,
                    'owner_token' => $ownerToken,
                    'lease_seconds' => $leaseSeconds,
                ];

                return EventClaimResult::acquired();
            }

            public function succeed(string $eventHash, string $ownerToken): bool
            {
                $this->calls[] = [
                    'method' => 'succeed',
                    'event_hash' => $eventHash,
                    'owner_token' => $ownerToken,
                ];

                return true;
            }

            public function fail(
                string $eventHash,
                string $ownerToken,
                string $error,
                bool $retryable = true
            ): bool {
                $this->calls[] = [
                    'method' => 'fail',
                    'event_hash' => $eventHash,
                    'owner_token' => $ownerToken,
                    'error' => $error,
                    'retryable' => $retryable,
                ];

                return true;
            }
        };
    }

    private function renewalPayload(int $subscriptionId, int $orderId): array
    {
        return [
            'subscription' => (object) ['id' => $subscriptionId],
            'order' => (object) ['id' => $orderId],
        ];
    }

    private function requireMethod(string $method): void
    {
        self::assertTrue(
            method_exists(GrantLockService::class, $method),
            "GrantLockService must expose {$method}()."
        );
    }
}

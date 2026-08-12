<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\Domain\Transfer\Execution;

use CartShift\Domain\Transfer\Customer\CustomerAddressRecord;
use CartShift\Domain\Transfer\Customer\CustomerRecord;
use CartShift\Domain\Transfer\Customer\CustomerReconciler;
use CartShift\Domain\Transfer\Customer\CustomerTargetGateway;
use CartShift\Domain\Transfer\Decision\TransferDecisionSet;
use CartShift\Domain\Transfer\Execution\CustomerEnvelopeReconciler;
use CartShift\Domain\Transfer\Execution\TargetRecordPlanFactory;
use CartShift\Domain\Transfer\Identity\CheckedMappingStore;
use CartShift\Domain\Transfer\Identity\MapState;
use CartShift\Domain\Transfer\Identity\MappingRecord;
use CartShift\Domain\Transfer\ReconcileContext;
use CartShift\Domain\Transfer\SourceIdentity;
use CartShift\Support\CanonicalJson;
use CartShift\Tests\Unit\PluginTestCase;

final class CustomerEnvelopeReconcilerTest extends PluginTestCase
{
    private string $package;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->package = sys_get_temp_dir() . '/cartshift-customer-envelope-reconciler-' . bin2hex(random_bytes(6));
        mkdir($this->package, 0700);
    }

    #[\Override]
    protected function tearDown(): void
    {
        rmdir($this->package);
        parent::tearDown();
    }

    public function testExplicitReuseReconcilesOnlyTheSavedCustomerRootWithoutClaimingSourceAddresses(): void
    {
        $record = $this->record();
        $envelope = $record->envelope();
        $snapshot = [
            'customer' => [
                'user_id' => 99,
                'email' => 'saved@example.test',
                'first_name' => 'Saved',
                'last_name' => 'Customer',
                'status' => 'active',
                'uuid' => 'saved-customer',
                'created_at' => '2025-01-01 00:00:00',
                'updated_at' => '2025-01-01 00:00:00',
            ],
            'addresses' => [[
                'customer_id' => 51,
                'is_primary' => 1,
                'type' => 'billing',
                'status' => 'active',
                'label' => 'Saved',
                'name' => 'Saved Customer',
                'address_1' => 'Existing Road',
                'address_2' => '',
                'city' => 'Existing City',
                'state' => '',
                'phone' => '',
                'email' => 'saved@example.test',
                'postcode' => '',
                'country' => 'GB',
                'meta' => null,
            ]],
        ];
        $targetFingerprint = CanonicalJson::fingerprint($snapshot);
        $decisions = TransferDecisionSet::fromArray([[
            'identity' => $record->identity->canonical(),
            'scope' => 'record',
            'action' => 'reuse_explicit_target_customer',
            'target_id' => 51,
            'source_fingerprint' => $envelope->sourceContentDigest,
            'target_fingerprint' => $targetFingerprint,
            'operator' => 'wp-user:9',
            'reason' => 'The owner chose the saved FluentCart customer.',
            'decided_at' => '2026-08-13T10:00:00Z',
        ]]);
        $maps = new EmptyCustomerEnvelopeMaps();
        $plans = new TargetRecordPlanFactory(
            $decisions,
            $maps,
            $this->package,
            [$envelope],
            [],
            ['standard', 'none'],
            [],
            ['none' => 0],
            'live',
            false,
            static fn (): array => [],
        );
        $reconciler = new CustomerEnvelopeReconciler(
            $plans,
            new SnapshotCustomerGateway($snapshot),
            $maps,
            new CustomerReconciler(),
        );

        $result = $reconciler->reconcile(
            $envelope,
            new ReconcileContext(['primary' => 51], $targetFingerprint, 'run-customer-21', 1),
        );

        self::assertTrue($result->matches);
        self::assertSame($targetFingerprint, $result->actualFingerprint);
        self::assertSame([], $result->failures);
    }

    private function record(): CustomerRecord
    {
        return CustomerRecord::create(
            new SourceIdentity('shop-alpha', 'customer', '7'),
            7,
            'registered',
            'Ada',
            'Lovelace',
            'ada@example.test',
            'active',
            [new CustomerAddressRecord(
                new SourceIdentity('shop-alpha', 'customer', '7:billing'),
                'billing',
                true,
                'active',
                'Billing',
                'Ada Lovelace',
                '',
                '1 Logic Lane',
                '',
                'London',
                '',
                'N1',
                'GB',
                '+44',
                'ada@example.test',
            )],
            null,
            null,
            ['origin' => 'source_user'],
            [],
        );
    }
}

final class EmptyCustomerEnvelopeMaps implements CheckedMappingStore
{
    public function get(SourceIdentity $identity): ?MappingRecord
    {
        return null;
    }

    public function storeOrThrow(
        SourceIdentity $identity,
        int $targetId,
        string $migrationId,
        string $sourceFingerprint,
        string $targetFingerprint,
        MapState $state,
        bool $createdByMigration,
        int $generation = 1,
    ): MappingRecord {
        throw new \LogicException('unused');
    }

    public function transitionOrThrow(
        SourceIdentity $identity,
        MapState $expected,
        MapState $next,
        string $expectedTargetFingerprint,
        string $nextTargetFingerprint,
    ): MappingRecord {
        throw new \LogicException('unused');
    }
}

final class SnapshotCustomerGateway implements CustomerTargetGateway
{
    /** @param array<string,mixed> $snapshot */
    public function __construct(private array $snapshot) {}

    public function createCustomer(array $fields): int
    {
        throw new \LogicException('Existing customer must not be created.');
    }

    public function createAddress(int $customerId, array $fields): int
    {
        throw new \LogicException('Existing address must not be created.');
    }

    public function exists(int $customerId): bool
    {
        return $customerId === 51;
    }

    public function snapshot(int $customerId): array
    {
        return $customerId === 51 ? $this->snapshot : ['customer' => null, 'addresses' => []];
    }
}

<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\Domain\Transfer\Customer;

use CartShift\Domain\Transfer\Customer\CustomerAddressRecord;
use CartShift\Domain\Transfer\Customer\CustomerAssessment;
use CartShift\Domain\Transfer\Customer\CustomerRecord;
use CartShift\Domain\Transfer\Customer\CustomerReconciler;
use CartShift\Domain\Transfer\Customer\CustomerTargetGateway;
use CartShift\Domain\Transfer\Customer\CustomerWriter;
use CartShift\Domain\Transfer\Identity\CheckedMappingStore;
use CartShift\Domain\Transfer\Identity\MapState;
use CartShift\Domain\Transfer\Identity\MappingRecord;
use CartShift\Domain\Transfer\SourceIdentity;
use CartShift\Domain\Transfer\StageContext;
use CartShift\Support\DatabaseTransaction;
use CartShift\Tests\Unit\PluginTestCase;

final class CustomerWriterTest extends PluginTestCase
{
    private string $package;
    protected function setUp(): void { parent::setUp(); $this->package = sys_get_temp_dir() . '/cartshift-customer-writer-' . bin2hex(random_bytes(8)); mkdir($this->package, 0700); }
    protected function tearDown(): void { rmdir($this->package); parent::tearDown(); }

    public function testAddressFailureRollsBackCustomerAddressesAndCheckedMaps(): void
    {
        $gateway = new MemoryCustomerGateway(failAddress: true);
        $maps = new MemoryCustomerMaps();
        $writer = new CustomerWriter($gateway, $maps, new CustomerReconciler());

        try {
            $writer->stage($this->record(), new CustomerAssessment('create_target_customer_unlinked'), $this->context());
            self::fail('Address failure left a partial customer graph.');
        } catch (\RuntimeException) {
            self::assertSame([], $gateway->customers);
            self::assertSame([], $gateway->addresses);
            self::assertSame([], $maps->records);
            self::assertSame(0, DatabaseTransaction::depth());
        }
    }

    public function testExactRetryReconcilesWithoutWritingOrCreatingWordPressUser(): void
    {
        $gateway = new MemoryCustomerGateway();
        $maps = new MemoryCustomerMaps();
        $writer = new CustomerWriter($gateway, $maps, new CustomerReconciler());
        $record = $this->record();
        $first = $writer->stage($record, new CustomerAssessment('create_target_customer_unlinked'), $this->context());
        $before = [$gateway->customers, $gateway->addresses, $gateway->writes];
        $second = $writer->stage($record, new CustomerAssessment('create_target_customer_unlinked'), $this->context());

        self::assertSame($first->targetId, $second->targetId);
        self::assertTrue($second->reused);
        self::assertSame($before, [$gateway->customers, $gateway->addresses, $gateway->writes]);
        self::assertNull($gateway->customers[$first->targetId]['user_id']);
        self::assertSame([], $GLOBALS['_cartshift_test_users_created'] ?? []);
    }

    public function testExplicitTargetReuseVerifiesFingerprintAndNeverMutatesSavedCustomerOrAddresses(): void
    {
        $gateway = new MemoryCustomerGateway();
        $targetId = $gateway->createCustomer(['user_id' => 99, 'email' => 'existing@example.test', 'first_name' => 'Existing', 'last_name' => 'Owner', 'status' => 'active', 'uuid' => 'existing', 'created_at' => '2020-01-01 00:00:00', 'updated_at' => '2020-01-01 00:00:00']);
        $gateway->createAddress($targetId, ['is_primary' => 1, 'type' => 'billing', 'status' => 'active', 'label' => 'Saved', 'name' => 'Existing Owner', 'address_1' => 'Saved Road', 'address_2' => '', 'city' => 'Saved City', 'state' => '', 'phone' => '', 'email' => '', 'postcode' => '', 'country' => 'GB', 'meta' => null]);
        $before = $gateway->snapshot($targetId);
        $fingerprint = \CartShift\Support\CanonicalJson::fingerprint($before);
        $maps = new MemoryCustomerMaps();

        $result = (new CustomerWriter($gateway, $maps, new CustomerReconciler()))->stage(
            $this->record(), new CustomerAssessment('reuse_explicit_target_customer', ['target_id' => $targetId, 'target_fingerprint' => $fingerprint]), $this->context(),
        );

        self::assertTrue($result->reused);
        self::assertSame($before, $gateway->snapshot($targetId));
        self::assertNotNull($maps->get($this->record()->identity));
        self::assertFalse($maps->createdByMigration[$this->record()->identity->canonical()]);
        self::assertNull($maps->get($this->record()->addresses[0]->identity), 'Saved addresses require their own decision and are not silently claimed.');
    }

    public function testExactMappedRetryUsesCheckedTargetFingerprintWithoutForgettingSameSiteUserLink(): void
    {
        $gateway = new MemoryCustomerGateway();
        $maps = new MemoryCustomerMaps();
        $writer = new CustomerWriter($gateway, $maps, new CustomerReconciler());
        $record = $this->record();
        $first = $writer->stage($record, new CustomerAssessment('attach_exact_same_site_user', ['user_id' => 7]), $this->context());

        $retry = $writer->stage($record, new CustomerAssessment('reuse_exact_customer_map', ['target_id' => $first->targetId]), $this->context());

        self::assertTrue($retry->reused);
        self::assertSame(7, $gateway->customers[$first->targetId]['user_id']);
    }

    public function testIndependentReconciliationRetainsTheApprovedSameSiteUserLinkAfterMapReuse(): void
    {
        $gateway = new MemoryCustomerGateway();
        $maps = new MemoryCustomerMaps();
        $reconciler = new CustomerReconciler();
        $record = $this->record();
        $first = (new CustomerWriter($gateway, $maps, $reconciler))->stage(
            $record,
            new CustomerAssessment('attach_exact_same_site_user', ['user_id' => 7]),
            $this->context(),
        );
        $address = $record->addresses[0];
        $addressMapping = $maps->get($address->identity);
        self::assertNotNull($addressMapping);

        $result = $reconciler->reconcile(
            $record,
            new CustomerAssessment('reuse_exact_customer_map', ['target_id' => $first->targetId, 'user_id' => 7]),
            $gateway->snapshot($first->targetId),
            ['customer_id' => $first->targetId, $address->identity->canonical() => $addressMapping->targetId],
        );

        self::assertTrue($result->matches);
    }

    private function record(): CustomerRecord
    {
        $identity = new SourceIdentity('shop-alpha', 'customer', '7');
        return CustomerRecord::create($identity, 7, 'registered', 'Ada', 'Lovelace', 'ada@example.test', 'active', [
            new CustomerAddressRecord(new SourceIdentity('shop-alpha', 'customer', '7:billing'), 'billing', true, 'active', 'Billing', 'Ada Lovelace', '', '1 Logic Lane', '', 'London', '', 'N1', 'GB', '+44', 'ada@example.test'),
        ], '2020-01-01T00:00:00Z', null, ['origin' => 'source_user'], []);
    }
    private function context(): StageContext { return new StageContext($this->package, 'migration-21', str_repeat('f', 64)); }
}

final class MemoryCustomerGateway implements CustomerTargetGateway
{
    public array $customers = []; public array $addresses = []; public int $writes = 0; private int $next = 1;
    public function __construct(private bool $failAddress = false) {}
    public function createCustomer(array $fields): int { $id = $this->next++; $this->customers[$id] = $fields; ++$this->writes; DatabaseTransaction::afterRollback(function () use ($id): void { unset($this->customers[$id]); }); return $id; }
    public function createAddress(int $customerId, array $fields): int { if ($this->failAddress) throw new \RuntimeException('fixture address failure'); $id = $this->next++; $this->addresses[$id] = ['customer_id' => $customerId] + $fields; ++$this->writes; DatabaseTransaction::afterRollback(function () use ($id): void { unset($this->addresses[$id]); }); return $id; }
    public function exists(int $customerId): bool { return isset($this->customers[$customerId]); }
    public function snapshot(int $customerId): array { return ['customer' => $this->customers[$customerId] ?? null, 'addresses' => array_values(array_filter($this->addresses, static fn (array $a): bool => $a['customer_id'] === $customerId))]; }
}

final class MemoryCustomerMaps implements CheckedMappingStore
{
    public array $records = [];
    public array $createdByMigration = [];
    public function get(SourceIdentity $identity): ?MappingRecord { return $this->records[$identity->canonical()] ?? null; }
    public function storeOrThrow(SourceIdentity $identity, int $targetId, string $migrationId, string $sourceFingerprint, string $targetFingerprint, MapState $state, bool $createdByMigration, int $generation = 1): MappingRecord { $record = new MappingRecord($identity, $targetId, $sourceFingerprint, $targetFingerprint, $state); $this->records[$identity->canonical()] = $record; $this->createdByMigration[$identity->canonical()] = $createdByMigration; DatabaseTransaction::afterRollback(function () use ($identity): void { unset($this->records[$identity->canonical()], $this->createdByMigration[$identity->canonical()]); }); return $record; }
    public function transitionOrThrow(SourceIdentity $identity, MapState $expected, MapState $next, string $expectedTargetFingerprint, string $nextTargetFingerprint): MappingRecord { $current = $this->records[$identity->canonical()]; $record = new MappingRecord($identity, $current->targetId, $current->sourceFingerprint, $nextTargetFingerprint, $next); $this->records[$identity->canonical()] = $record; return $record; }
}

<?php

declare(strict_types=1);

namespace CartShift\Migrator;

defined('ABSPATH') || exit;

use CartShift\Domain\Mapping\CustomerMapper;
use CartShift\State\MigrationState;
use CartShift\Storage\IdMapRepository;
use CartShift\Storage\MigrationLogRepository;
use CartShift\Support\Constants;
use FluentCart\App\Models\Customer;
use FluentCart\App\Models\CustomerAddresses;

final class CustomerMigrator extends AbstractMigrator
{
    private readonly CustomerMapper $customerMapper;

    /** @var int|null Cached registered customer count */
    private ?int $registeredCount = null;

    public function __construct(
        IdMapRepository $idMap,
        MigrationLogRepository $log,
        MigrationState $migrationState,
        string $migrationId,
        int $batchSize = Constants::DEFAULT_BATCH_SIZE,
    ) {
        parent::__construct($idMap, $log, $migrationState, $migrationId, $batchSize);
        $this->customerMapper = new CustomerMapper($idMap);
    }

    #[\Override]
    protected function getEntityType(): string
    {
        return Constants::ENTITY_CUSTOMER;
    }

    /**
     * FIX H4: query users by order history, not just 'customer' role.
     * FIX H5: count unique guest emails directly via SQL.
     */
    #[\Override]
    protected function countTotal(): int
    {
        return $this->countRegisteredCustomers() + $this->countGuestCustomers();
    }

    #[\Override]
    public function fetchBatch(int $offset, int $limit): array
    {
        $batch = [];
        $registeredTotal = $this->countRegisteredCustomers();

        if ($offset < $registeredTotal) {
            $batch = $this->fetchRegisteredBatch($offset, $limit);

            if (count($batch) >= $limit) {
                return $batch;
            }
        }

        $remaining = $limit - count($batch);
        $guestOffset = max(0, $offset - $registeredTotal);

        $guestBatch = $this->fetchGuestBatch($guestOffset, $remaining);
        $batch = array_merge($batch, $guestBatch);

        return $batch;
    }

    /**
     * Validate a customer record without creating any FC records.
     */
    #[\Override]
    public function validateRecord(mixed $record): bool
    {
        $type = $record['type'];
        $data = $record['data'];

        return match ($type) {
            'registered' => $this->validateRegistered($data),
            'guest'      => $this->validateGuest($data),
            default      => false,
        };
    }

    #[\Override]
    public function processRecord(mixed $record): int|false
    {
        $type = $record['type'];
        $data = $record['data'];

        return match ($type) {
            'registered' => $this->processRegistered($data),
            'guest'      => $this->processGuest($data),
            default      => false,
        };
    }

    #[\Override]
    public function getRecordId(mixed $record): string
    {
        if ($record['type'] === 'registered') {
            return (string) $record['data']['user_id'];
        }

        return $record['data']['email'];
    }

    /**
     * Validate a registered customer without creating any FC records.
     */
    private function validateRegistered(array $userData): bool
    {
        $userId = (int) $userData['user_id'];

        if ($this->idMap->getFcId(Constants::ENTITY_CUSTOMER, (string) $userId)) {
            $this->writeLog($userId, 'dry-run', 'dry-run: already migrated, would skip.');
            return false;
        }

        $user = get_userdata($userId);
        if (!$user) {
            $this->writeLog($userId, 'dry-run', 'dry-run: user not found, would fail.');
            return false;
        }

        if (empty($user->user_email)) {
            $this->writeLog($userId, 'dry-run', 'dry-run: user has no email, would fail.');
            return false;
        }

        $this->writeLog($userId, 'dry-run', sprintf(
            'dry-run: would create customer "%s".',
            $user->user_email,
        ));

        return true;
    }

    /**
     * Validate a guest customer without creating any FC records.
     */
    private function validateGuest(array $guestData): bool
    {
        $email = $guestData['email'];

        if (empty($email)) {
            $this->writeLog($email, 'dry-run', 'dry-run: guest email is empty, would fail.');
            return false;
        }

        if ($this->idMap->getFcId(Constants::ENTITY_GUEST_CUSTOMER, $email)) {
            $this->writeLog($email, 'dry-run', 'dry-run: guest already migrated, would skip.');
            return false;
        }

        $this->writeLog($email, 'dry-run', sprintf(
            'dry-run: would create customer "%s".',
            $email,
        ));

        return true;
    }

    /**
     * Process a registered WP customer user.
     */
    private function processRegistered(array $userData): int|false
    {
        $userId = (int) $userData['user_id'];

        if ($this->idMap->getFcId(Constants::ENTITY_CUSTOMER, (string) $userId)) {
            $this->writeLog($userId, 'skipped', 'Already migrated.');
            return false;
        }

        $user = get_userdata($userId);
        if (!$user) {
            $this->writeLog($userId, 'error', 'User not found.');
            return false;
        }

        // FIX C9: when mapping existing FC customer, store with created_by_migration=false.
        $existing = Customer::query()->where('email', $user->user_email)->first();
        if ($existing) {
            $this->idMap->store(
                Constants::ENTITY_CUSTOMER,
                (string) $userId,
                $existing->id,
                $this->migrationId,
                false,
            );
            $this->writeLog($userId, 'skipped', 'Customer already exists in FluentCart.');
            return false;
        }

        $mapped = $this->customerMapper->mapRegistered($user);

        $customer = Customer::query()->create($mapped['customer']);
        $this->idMap->store(
            Constants::ENTITY_CUSTOMER,
            (string) $userId,
            $customer->id,
            $this->migrationId,
            true,
        );

        // FIX C7: compound keys for addresses.
        foreach ($mapped['addresses'] as $addressData) {
            $addressData['customer_id'] = $customer->id;
            $address = CustomerAddresses::query()->create($addressData);
            $addressKey = "{$userId}_{$addressData['type']}";
            $this->idMap->store(
                Constants::ENTITY_CUSTOMER_ADDRESS,
                $addressKey,
                $address->id,
                $this->migrationId,
                true,
            );
        }

        $this->writeLog($userId, 'success', sprintf(
            'Migrated customer "%s" (FC ID: %d).',
            $user->user_email,
            $customer->id,
        ));

        return $customer->id;
    }

    /**
     * Process a guest customer.
     * FIX C6: use email string as wc_id (VARCHAR), not crc32 hash.
     */
    private function processGuest(array $guestData): int|false
    {
        $email = $guestData['email'];

        if ($this->idMap->getFcId(Constants::ENTITY_GUEST_CUSTOMER, $email)) {
            $this->writeLog($email, 'skipped', 'Guest customer already migrated.');
            return false;
        }

        // FIX C9: when mapping existing FC customer, store with created_by_migration=false.
        $existing = Customer::query()->where('email', $email)->first();
        if ($existing) {
            $this->idMap->store(
                Constants::ENTITY_GUEST_CUSTOMER,
                $email,
                $existing->id,
                $this->migrationId,
                false,
            );
            $this->writeLog($email, 'skipped', 'Guest customer already exists in FluentCart.');
            return false;
        }

        // Find the first order for this guest to build mapped data.
        $order = $this->findFirstGuestOrder($email);
        if (!$order) {
            $this->writeLog($email, 'error', 'No order found for guest email.');
            return false;
        }

        $mapped = $this->customerMapper->mapGuest($order);

        $customer = Customer::query()->create($mapped['customer']);
        $this->idMap->store(
            Constants::ENTITY_GUEST_CUSTOMER,
            $email,
            $customer->id,
            $this->migrationId,
            true,
        );

        // FIX C7: compound keys for addresses.
        foreach ($mapped['addresses'] as $addressData) {
            $addressData['customer_id'] = $customer->id;
            $address = CustomerAddresses::query()->create($addressData);
            $addressKey = "{$email}_{$addressData['type']}";
            $this->idMap->store(
                Constants::ENTITY_CUSTOMER_ADDRESS,
                $addressKey,
                $address->id,
                $this->migrationId,
                true,
            );
        }

        $this->writeLog($email, 'success', sprintf(
            'Migrated guest customer "%s" (FC ID: %d).',
            $email,
            $customer->id,
        ));

        return $customer->id;
    }

    /**
     * FIX H4: count registered customers by order history, not just 'customer' role.
     * Users who have placed orders (customer_id > 0 in wc_orders).
     */
    private function countRegisteredCustomers(): int
    {
        if ($this->registeredCount !== null) {
            return $this->registeredCount;
        }

        global $wpdb;

        $this->registeredCount = (int) $wpdb->get_var(
            "SELECT COUNT(DISTINCT customer_id)
             FROM {$wpdb->prefix}wc_orders
             WHERE customer_id > 0
               AND type = 'shop_order'",
        );

        return $this->registeredCount;
    }

    /**
     * FIX H5: count unique guest emails directly via SQL.
     */
    private function countGuestCustomers(): int
    {
        global $wpdb;

        return (int) $wpdb->get_var(
            "SELECT COUNT(DISTINCT billing_email)
             FROM {$wpdb->prefix}wc_orders
             WHERE (customer_id IS NULL OR customer_id = 0)
               AND billing_email != ''
               AND type = 'shop_order'",
        );
    }

    /**
     * FIX H4: fetch registered customers by order history with LIMIT/OFFSET.
     */
    private function fetchRegisteredBatch(int $offset, int $limit): array
    {
        global $wpdb;

        $userIds = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT customer_id
             FROM {$wpdb->prefix}wc_orders
             WHERE customer_id > 0
               AND type = 'shop_order'
             ORDER BY customer_id ASC
             LIMIT %d OFFSET %d",
            $limit,
            $offset,
        ));

        return array_map(
            fn (string $id): array => ['type' => 'registered', 'data' => ['user_id' => (int) $id]],
            $userIds,
        );
    }

    /**
     * FIX H5: fetch unique guest emails directly via SQL with LIMIT/OFFSET.
     * Uses isset() for O(1) dedup instead of in_array().
     */
    private function fetchGuestBatch(int $offset, int $limit): array
    {
        global $wpdb;

        $emails = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT billing_email
             FROM {$wpdb->prefix}wc_orders
             WHERE (customer_id IS NULL OR customer_id = 0)
               AND billing_email != ''
               AND type = 'shop_order'
             ORDER BY billing_email ASC
             LIMIT %d OFFSET %d",
            $limit,
            $offset,
        ));

        return array_map(
            fn (string $email): array => ['type' => 'guest', 'data' => ['email' => $email]],
            $emails,
        );
    }

    /**
     * Find the first WC order for a guest email to extract customer data.
     */
    private function findFirstGuestOrder(string $email): ?\WC_Order
    {
        $orders = wc_get_orders([
            'billing_email' => $email,
            'customer_id'   => 0,
            'limit'         => 1,
            'orderby'       => 'ID',
            'order'         => 'ASC',
            'type'          => 'shop_order',
        ]);

        return $orders[0] ?? null;
    }
}

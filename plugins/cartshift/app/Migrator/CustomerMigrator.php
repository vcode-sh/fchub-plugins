<?php

namespace CartShift\Migrator;

defined('ABSPATH') or die;

use CartShift\Mapper\CustomerMapper;
use FluentCart\App\Models\Customer;
use FluentCart\App\Models\CustomerAddresses;

class CustomerMigrator extends AbstractMigrator
{
    protected string $entityType = 'customers';

    /** @var array Tracks guest emails already processed. */
    private array $processedGuestEmails = [];

    protected function countTotal(): int
    {
        $registered = count(get_users([
            'role'   => 'customer',
            'fields' => 'ID',
        ]));

        $guests = $this->countGuestCustomers();

        return $registered + $guests;
    }

    protected function fetchBatch(int $page): array
    {
        $offset = ($page - 1) * $this->batchSize;
        $batch = [];

        // First: registered customers.
        $users = get_users([
            'role'    => 'customer',
            'number'  => $this->batchSize,
            'offset'  => $offset,
            'orderby' => 'ID',
            'order'   => 'ASC',
        ]);

        foreach ($users as $user) {
            $batch[] = ['type' => 'registered', 'data' => $user];
        }

        // If we got a full batch of registered users, return them.
        if (count($users) >= $this->batchSize) {
            return $batch;
        }

        // Otherwise, fill remaining slots with guest customers.
        $remaining = $this->batchSize - count($users);
        $guestOffset = max(0, $offset - $this->countRegistered());

        if ($guestOffset < 0) {
            $guestOffset = 0;
        }

        $guestOrders = $this->fetchGuestOrders($remaining, $guestOffset);

        foreach ($guestOrders as $order) {
            $email = $order->get_billing_email();
            if (empty($email) || in_array($email, $this->processedGuestEmails, true)) {
                continue;
            }
            $this->processedGuestEmails[] = $email;
            $batch[] = ['type' => 'guest', 'data' => $order];
        }

        return $batch;
    }

    protected function processRecord($record)
    {
        $type = $record['type'];
        $data = $record['data'];

        if ($type === 'registered') {
            return $this->processRegistered($data);
        }

        return $this->processGuest($data);
    }

    protected function getRecordId($record): int
    {
        if ($record['type'] === 'registered') {
            return $record['data']->ID;
        }
        return $record['data']->get_id();
    }

    /**
     * Process a registered WP customer user.
     */
    private function processRegistered(\WP_User $user): int
    {
        // Skip if already migrated.
        if ($this->idMap->getFcId('customer', $user->ID)) {
            $this->log($user->ID, 'skipped', 'Already migrated.');
            return false;
        }

        // Check if FC already has this customer by email.
        $existing = Customer::query()->where('email', $user->user_email)->first();
        if ($existing) {
            $this->idMap->store('customer', $user->ID, $existing->id);
            $this->log($user->ID, 'skipped', 'Customer already exists in FluentCart.');
            return false;
        }

        $mapped = CustomerMapper::mapRegistered($user);

        if ($this->dryRun) {
            $this->log($user->ID, 'success', sprintf(
                '[DRY RUN] Would migrate customer "%s" with %d address(es).',
                $user->user_email,
                count($mapped['addresses'])
            ));
            return 0;
        }

        $customer = Customer::query()->create($mapped['customer']);
        $this->idMap->store('customer', $user->ID, $customer->id);

        // Create addresses.
        foreach ($mapped['addresses'] as $addressData) {
            $addressData['customer_id'] = $customer->id;
            $address = CustomerAddresses::query()->create($addressData);
            $this->idMap->store('customer_address', $user->ID, $address->id);
        }

        $this->log($user->ID, 'success', sprintf(
            'Migrated customer "%s" (FC ID: %d).',
            $user->user_email,
            $customer->id
        ));

        return $customer->id;
    }

    /**
     * Process a guest customer from an order.
     */
    private function processGuest(\WC_Order $order): int
    {
        $email = $order->get_billing_email();
        $guestKey = crc32($email);

        // Skip if already migrated.
        if ($this->idMap->getFcId('guest_customer', $guestKey)) {
            $this->log($order->get_id(), 'skipped', 'Guest customer already migrated.');
            return false;
        }

        // Check if FC already has this customer by email.
        $existing = Customer::query()->where('email', $email)->first();
        if ($existing) {
            $this->idMap->store('guest_customer', $guestKey, $existing->id);
            $this->log($order->get_id(), 'skipped', 'Guest customer already exists in FluentCart.');
            return false;
        }

        $mapped = CustomerMapper::mapGuest($order);

        if ($this->dryRun) {
            $this->log($order->get_id(), 'success', sprintf(
                '[DRY RUN] Would migrate guest customer "%s" with %d address(es).',
                $email,
                count($mapped['addresses'])
            ));
            return 0;
        }

        $customer = Customer::query()->create($mapped['customer']);
        $this->idMap->store('guest_customer', $guestKey, $customer->id);

        // Create addresses.
        foreach ($mapped['addresses'] as $addressData) {
            $addressData['customer_id'] = $customer->id;
            $address = CustomerAddresses::query()->create($addressData);
            $this->idMap->store('customer_address', $order->get_id(), $address->id);
        }

        $this->log($order->get_id(), 'success', sprintf(
            'Migrated guest customer "%s" (FC ID: %d).',
            $email,
            $customer->id
        ));

        return $customer->id;
    }

    /**
     * Count registered customers.
     */
    private function countRegistered(): int
    {
        return count(get_users([
            'role'   => 'customer',
            'fields' => 'ID',
        ]));
    }

    /**
     * Count unique guest emails (HPOS + legacy compatible).
     */
    private function countGuestCustomers(): int
    {
        global $wpdb;

        $hposTable = $wpdb->prefix . 'wc_orders';
        $hposExists = $wpdb->get_var("SHOW TABLES LIKE '{$hposTable}'");

        if ($hposExists) {
            return (int) $wpdb->get_var(
                "SELECT COUNT(DISTINCT billing_email)
                 FROM {$hposTable}
                 WHERE type = 'shop_order'
                   AND billing_email != ''
                   AND (customer_id IS NULL OR customer_id = 0)"
            );
        }

        return (int) $wpdb->get_var(
            "SELECT COUNT(DISTINCT pm.meta_value)
             FROM {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
             WHERE p.post_type = 'shop_order'
               AND pm.meta_key = '_billing_email'
               AND pm.post_id NOT IN (
                   SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_customer_user' AND meta_value > 0
               )"
        );
    }

    /**
     * Fetch guest orders (HPOS + legacy compatible).
     */
    private function fetchGuestOrders(int $limit, int $offset): array
    {
        global $wpdb;

        $hposTable = $wpdb->prefix . 'wc_orders';
        $hposExists = $wpdb->get_var("SHOW TABLES LIKE '{$hposTable}'");

        if ($hposExists) {
            $orderIds = $wpdb->get_col($wpdb->prepare(
                "SELECT id FROM {$hposTable}
                 WHERE type = 'shop_order'
                   AND billing_email != ''
                   AND (customer_id IS NULL OR customer_id = 0)
                 ORDER BY id ASC
                 LIMIT %d OFFSET %d",
                $limit,
                $offset
            ));
        } else {
            $orderIds = $wpdb->get_col($wpdb->prepare(
                "SELECT DISTINCT p.ID
                 FROM {$wpdb->posts} p
                 INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_billing_email'
                 WHERE p.post_type = 'shop_order'
                   AND p.ID NOT IN (
                       SELECT post_id FROM {$wpdb->postmeta}
                       WHERE meta_key = '_customer_user' AND meta_value > 0
                   )
                 ORDER BY p.ID ASC
                 LIMIT %d OFFSET %d",
                $limit,
                $offset
            ));
        }

        $orders = [];
        foreach ($orderIds as $orderId) {
            $order = wc_get_order($orderId);
            if ($order) {
                $orders[] = $order;
            }
        }

        return $orders;
    }
}

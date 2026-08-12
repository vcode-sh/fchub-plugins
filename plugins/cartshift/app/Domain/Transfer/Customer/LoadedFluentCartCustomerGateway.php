<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\Customer;

defined('ABSPATH') || exit;

final class LoadedFluentCartCustomerGateway implements CustomerTargetGateway
{
    public function createCustomer(array $fields): int
    {
        global $wpdb;
        $table = $wpdb->prefix . 'fct_customers';
        if ($wpdb->insert($table, $fields) === false || (int) $wpdb->insert_id <= 0) throw new \RuntimeException('target_write_failed: FluentCart customer direct insert');
        return (int) $wpdb->insert_id;
    }

    public function createAddress(int $customerId, array $fields): int
    {
        global $wpdb;
        $fields['customer_id'] = $customerId;
        $fields['meta'] = $fields['meta'] === null ? null : wp_json_encode($fields['meta']);
        if ($wpdb->insert($wpdb->prefix . 'fct_customer_addresses', $fields) === false || (int) $wpdb->insert_id <= 0) throw new \RuntimeException('target_write_failed: FluentCart customer address direct insert');
        return (int) $wpdb->insert_id;
    }

    public function exists(int $customerId): bool
    {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}fct_customers WHERE id = %d", $customerId)) === 1;
    }

    public function snapshot(int $customerId): array
    {
        global $wpdb;
        $customer = $wpdb->get_row($wpdb->prepare("SELECT user_id,email,first_name,last_name,status,uuid,created_at,updated_at FROM {$wpdb->prefix}fct_customers WHERE id = %d", $customerId), ARRAY_A);
        if (!is_array($customer)) return ['customer' => null, 'addresses' => []];
        $customer['user_id'] = $customer['user_id'] === null ? null : (int) $customer['user_id'];
        $addresses = $wpdb->get_results($wpdb->prepare("SELECT customer_id,is_primary,type,status,label,name,address_1,address_2,city,state,phone,email,postcode,country,meta FROM {$wpdb->prefix}fct_customer_addresses WHERE customer_id = %d ORDER BY id ASC", $customerId), ARRAY_A);
        foreach ($addresses as &$address) {
            $address['customer_id'] = (int) $address['customer_id'];
            $address['is_primary'] = (int) $address['is_primary'];
            $address['meta'] = $address['meta'] === null || $address['meta'] === '' ? null : json_decode((string) $address['meta'], true, 32, JSON_THROW_ON_ERROR);
        }
        unset($address);
        return ['customer' => $customer, 'addresses' => $addresses];
    }
}

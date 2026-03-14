<?php

namespace CartShift\Mapper;

defined('ABSPATH') or die;

class CustomerMapper
{
    /**
     * Map a WP_User (WC customer) to FluentCart customer data.
     *
     * @param \WP_User $user
     * @return array{customer: array, addresses: array}
     */
    public static function mapRegistered(\WP_User $user): array
    {
        $customer = [
            'user_id'    => $user->ID,
            'email'      => $user->user_email,
            'first_name' => get_user_meta($user->ID, 'billing_first_name', true) ?: $user->first_name,
            'last_name'  => get_user_meta($user->ID, 'billing_last_name', true) ?: $user->last_name,
            'status'     => 'active',
            'notes'      => '',
            'country'    => get_user_meta($user->ID, 'billing_country', true) ?: '',
            'city'       => get_user_meta($user->ID, 'billing_city', true) ?: '',
            'state'      => get_user_meta($user->ID, 'billing_state', true) ?: '',
            'postcode'   => get_user_meta($user->ID, 'billing_postcode', true) ?: '',
        ];

        $addresses = [];

        // Billing address.
        $billingAddress = self::buildAddressFromUserMeta($user->ID, 'billing');
        if ($billingAddress) {
            $addresses[] = $billingAddress;
        }

        // Shipping address.
        $shippingAddress = self::buildAddressFromUserMeta($user->ID, 'shipping');
        if ($shippingAddress) {
            $addresses[] = $shippingAddress;
        }

        return [
            'customer'  => $customer,
            'addresses' => $addresses,
        ];
    }

    /**
     * Map a guest order to FluentCart customer data.
     *
     * @param \WC_Order $order
     * @return array{customer: array, addresses: array}
     */
    public static function mapGuest(\WC_Order $order): array
    {
        $customer = [
            'user_id'    => null,
            'email'      => $order->get_billing_email(),
            'first_name' => $order->get_billing_first_name(),
            'last_name'  => $order->get_billing_last_name(),
            'status'     => 'active',
            'notes'      => '',
            'country'    => $order->get_billing_country(),
            'city'       => $order->get_billing_city(),
            'state'      => $order->get_billing_state(),
            'postcode'   => $order->get_billing_postcode(),
        ];

        $addresses = [];

        $billingAddress = self::buildAddressFromOrder($order, 'billing');
        if ($billingAddress) {
            $addresses[] = $billingAddress;
        }

        $shippingAddress = self::buildAddressFromOrder($order, 'shipping');
        if ($shippingAddress) {
            $addresses[] = $shippingAddress;
        }

        return [
            'customer'  => $customer,
            'addresses' => $addresses,
        ];
    }

    /**
     * Build an address array from WP user meta.
     */
    private static function buildAddressFromUserMeta(int $userId, string $type): ?array
    {
        $firstName = get_user_meta($userId, "{$type}_first_name", true);
        $lastName  = get_user_meta($userId, "{$type}_last_name", true);
        $address1  = get_user_meta($userId, "{$type}_address_1", true);
        $country   = get_user_meta($userId, "{$type}_country", true);

        // Skip if no meaningful address data.
        if (empty($firstName) && empty($address1) && empty($country)) {
            return null;
        }

        $name = trim("{$firstName} {$lastName}");
        $phone = get_user_meta($userId, "{$type}_phone", true);
        $company = get_user_meta($userId, "{$type}_company", true);

        $meta = [];
        if ($phone) {
            $meta['other_data']['phone'] = $phone;
        }
        if ($company) {
            $meta['other_data']['company_name'] = $company;
        }

        return [
            'type'       => $type,
            'is_primary' => 1,
            'status'     => 'active',
            'label'      => ucfirst($type),
            'name'       => $name,
            'address_1'  => $address1 ?: '',
            'address_2'  => get_user_meta($userId, "{$type}_address_2", true) ?: '',
            'city'       => get_user_meta($userId, "{$type}_city", true) ?: '',
            'state'      => get_user_meta($userId, "{$type}_state", true) ?: '',
            'postcode'   => get_user_meta($userId, "{$type}_postcode", true) ?: '',
            'country'    => $country ?: '',
            'phone'      => $phone ?: '',
            'email'      => get_user_meta($userId, "{$type}_email", true) ?: '',
            'meta'       => !empty($meta) ? json_encode($meta) : null,
        ];
    }

    /**
     * Build an address array from a WC_Order.
     */
    private static function buildAddressFromOrder(\WC_Order $order, string $type): ?array
    {
        $getter = $type === 'billing' ? 'get_billing_' : 'get_shipping_';

        $firstName = call_user_func([$order, $getter . 'first_name']);
        $lastName  = call_user_func([$order, $getter . 'last_name']);
        $address1  = call_user_func([$order, $getter . 'address_1']);
        $country   = call_user_func([$order, $getter . 'country']);

        if (empty($firstName) && empty($address1) && empty($country)) {
            return null;
        }

        $name  = trim("{$firstName} {$lastName}");
        $phone = '';
        $company = '';

        if ($type === 'billing') {
            $phone   = $order->get_billing_phone();
            $company = $order->get_billing_company();
        } else {
            $company = $order->get_shipping_company();
        }

        $meta = [];
        if ($phone) {
            $meta['other_data']['phone'] = $phone;
        }
        if ($company) {
            $meta['other_data']['company_name'] = $company;
        }

        return [
            'type'       => $type,
            'is_primary' => 1,
            'status'     => 'active',
            'label'      => ucfirst($type),
            'name'       => $name,
            'address_1'  => $address1 ?: '',
            'address_2'  => call_user_func([$order, $getter . 'address_2']) ?: '',
            'city'       => call_user_func([$order, $getter . 'city']) ?: '',
            'state'      => call_user_func([$order, $getter . 'state']) ?: '',
            'postcode'   => call_user_func([$order, $getter . 'postcode']) ?: '',
            'country'    => $country ?: '',
            'phone'      => $phone ?: '',
            'email'      => $type === 'billing' ? $order->get_billing_email() : '',
            'meta'       => !empty($meta) ? json_encode($meta) : null,
        ];
    }
}

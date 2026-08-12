<?php

declare(strict_types=1);

/** @return array{subscription_id:int,parent_order_id:int,renewal_order_id:int,product_id:int} */
function cartshift_contract_seed_wcs_subscription(): array
{
    if (!function_exists('wcs_create_subscription') || !function_exists('wcs_create_renewal_order')) {
        throw new RuntimeException('The checksum-pinned WCS public fixture API is unavailable.');
    }

    $product = new WC_Product_Subscription();
    $product->set_name('WCS relationship contract product');
    $product->set_status('publish');
    $product->set_regular_price('20.00');
    $product->set_price('20.00');
    $product->update_meta_data('_subscription_price', '20.00');
    $product->update_meta_data('_subscription_period', 'month');
    $product->update_meta_data('_subscription_period_interval', '1');
    $product->update_meta_data('_subscription_length', '5');
    $product->update_meta_data('_subscription_sign_up_fee', '');
    $product->update_meta_data('_subscription_trial_length', '0');
    $product->update_meta_data('_subscription_trial_period', 'day');
    $productId = $product->save();
    if ($productId <= 0) {
        throw new RuntimeException('WooCommerce rejected the WCS product fixture.');
    }

    $parent = wc_create_order();
    if (!$parent instanceof WC_Order) {
        throw new RuntimeException('WooCommerce rejected the WCS parent-order fixture.');
    }
    $parent->set_currency('PLN');
    $parent->set_billing_email('wcs-contract@example.test');
    $customer = get_user_by('email', 'wcs-contract@example.test');
    $customerId = $customer instanceof WP_User ? $customer->ID : wp_insert_user([
        'user_login' => 'wcs-contract-customer',
        'user_email' => 'wcs-contract@example.test',
        'user_pass' => wp_generate_password(32, true, true),
        'role' => 'customer',
    ]);
    if (is_wp_error($customerId) || (int) $customerId <= 0) {
        throw new RuntimeException('WordPress rejected the WCS customer fixture.');
    }
    $customerId = (int) $customerId;
    $parent->set_customer_id($customerId);
    $parent->set_payment_method('bacs');
    $parent->add_product($product, 1);
    $parent->calculate_totals();
    $parent->save();

    $start = gmdate('Y-m-d H:i:s', time() - DAY_IN_SECONDS);
    $subscription = wcs_create_subscription([
        'order_id' => $parent->get_id(),
        'customer_id' => $customerId,
        'status' => 'on-hold',
        'billing_period' => 'month',
        'billing_interval' => 1,
        'start_date' => $start,
        'customer_note' => 'Installed contract fixture',
    ]);
    if (is_wp_error($subscription) || !$subscription instanceof WC_Subscription) {
        throw new RuntimeException(
            is_wp_error($subscription) ? $subscription->get_error_message() : 'WCS rejected the subscription fixture.',
        );
    }
    $subscription->set_currency('PLN');
    $subscription->set_billing_email('wcs-contract@example.test');
    $subscription->set_payment_method('bacs');
    $subscription->add_product($product, 1);
    $subscription->calculate_totals();
    $subscription->update_dates([
        'start' => $start,
        'trial_end' => 0,
        'next_payment' => 0,
        'end' => 0,
    ]);
    $subscription->save();

    $renewal = wcs_create_renewal_order($subscription);
    if (is_wp_error($renewal) || !$renewal instanceof WC_Order) {
        throw new RuntimeException(
            is_wp_error($renewal) ? $renewal->get_error_message() : 'WCS rejected the renewal-order fixture.',
        );
    }
    $renewal->set_status('processing');
    $renewal->save();

    // Renewal creation loads and mutates its own subscription instance. Delete
    // dates on the public instance WCS will subsequently return, otherwise its
    // object cache can keep the recalculated schedule even after another
    // instance has persisted the deletion.
    $subscription = wcs_get_subscription($subscription->get_id());
    if (!$subscription instanceof WC_Subscription) {
        throw new RuntimeException('Installed WCS could not reload its subscription fixture after renewal creation.');
    }
    $subscription->delete_date('next_payment');
    $subscription->delete_date('end');
    $subscription->save();
    if ($subscription->get_date('next_payment') !== 0 || $subscription->get_date('end') !== 0) {
        throw new RuntimeException('Installed WCS did not retain the deliberately absent schedule dates.');
    }

    return [
        'subscription_id' => $subscription->get_id(),
        'parent_order_id' => $parent->get_id(),
        'renewal_order_id' => $renewal->get_id(),
        'product_id' => $productId,
    ];
}

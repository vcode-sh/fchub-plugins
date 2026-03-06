<?php
/**
 * Plugin Name: FCHub - Przelewy24
 * Plugin URI: https://fchub.co
 * Description: Przelewy24 payment gateway for FluentCart
 * Version: 1.0.3
 * Author: Vibe Code
 * Author URI: https://x.com/vcode_sh
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: fchub-p24
 * Domain Path: /languages
 * Requires at least: 6.4
 * Tested up to:    6.7
 * Requires PHP: 8.1
 * Update URI: https://fchub.co/fchub-p24
 */

defined('ABSPATH') || exit;

define('FCHUB_P24_VERSION', '1.0.3');
define('FCHUB_P24_FILE', __FILE__);
define('FCHUB_P24_PATH', plugin_dir_path(__FILE__));
define('FCHUB_P24_URL', plugin_dir_url(__FILE__));

require_once __DIR__ . '/lib/GitHubUpdater.php';
FCHub_GitHub_Updater::register('fchub-p24', plugin_basename(__FILE__), FCHUB_P24_VERSION);

// Ensure urlStatus points to the active FluentCart IPN route.
add_filter('fluent_cart_ipn_url_przelewy24', function () {
    return [
        'listener_url' => site_url('?fluent-cart=fct_payment_listener_ipn&method=przelewy24'),
    ];
});

add_action('fluent_cart/register_payment_methods', function ($data) {
    if (!defined('FLUENTCART_VERSION')) {
        return;
    }

    require_once FCHUB_P24_PATH . 'app/Gateway/Przelewy24Settings.php';
    require_once FCHUB_P24_PATH . 'app/Gateway/Przelewy24Handler.php';
    require_once FCHUB_P24_PATH . 'app/API/Przelewy24API.php';
    require_once FCHUB_P24_PATH . 'app/Subscription/Przelewy24RenewalHandler.php';
    require_once FCHUB_P24_PATH . 'app/Subscription/Przelewy24SubscriptionModule.php';
    require_once FCHUB_P24_PATH . 'app/Gateway/Przelewy24Gateway.php';

    $data['gatewayManager']->register('przelewy24', new FChubP24\Gateway\Przelewy24Gateway());
});

// Fix receipt page when FluentCart regenerates transaction UUID on checkout retry.
// P24 redirects back with the old UUID (p24_session_id) which no longer matches
// the current transaction UUID. We intercept early and redirect to the correct URL.
add_action('template_redirect', function () {
    if (!defined('FLUENTCART_VERSION')) {
        return;
    }

    if (empty($_GET['trx_hash']) || empty($_GET['method']) || $_GET['method'] !== 'przelewy24') {
        return;
    }

    $trxHash = sanitize_text_field($_GET['trx_hash']);

    // Check if the transaction exists with this UUID
    $transaction = \FluentCart\App\Models\OrderTransaction::query()
        ->where('uuid', $trxHash)
        ->first();

    if ($transaction) {
        return; // Transaction found, FluentCart will handle it
    }

    // Transaction not found - look up by p24_session_id in order config
    $orders = \FluentCart\App\Models\Order::query()
        ->where('payment_method', 'przelewy24')
        ->orderBy('id', 'desc')
        ->limit(20)
        ->get();

    foreach ($orders as $order) {
        $config = $order->config;
        if (is_array($config) && isset($config['p24_session_id']) && $config['p24_session_id'] === $trxHash) {
            // Found the order - get its current transaction UUID
            $currentTransaction = \FluentCart\App\Models\OrderTransaction::query()
                ->where('order_id', $order->id)
                ->latest()
                ->first();

            if ($currentTransaction) {
                $redirectUrl = add_query_arg('trx_hash', $currentTransaction->uuid);
                wp_safe_redirect($redirectUrl);
                exit;
            }
        }
    }
});

// Action Scheduler hook for processing subscription renewals
add_action('fchub_p24_process_renewal', function ($subscriptionId) {
    if (!defined('FLUENTCART_VERSION')) {
        return;
    }

    require_once FCHUB_P24_PATH . 'app/Gateway/Przelewy24Settings.php';
    require_once FCHUB_P24_PATH . 'app/API/Przelewy24API.php';
    require_once FCHUB_P24_PATH . 'app/Subscription/Przelewy24RenewalHandler.php';

    FChubP24\Subscription\Przelewy24RenewalHandler::processRenewal((int) $subscriptionId);
});

register_activation_hook(__FILE__, function () {
    if (!defined('FLUENTCART_VERSION')) {
        set_transient('fchub_p24_activation_notice', true, 30);
    }
});

register_deactivation_hook(__FILE__, function () {
    if (function_exists('as_unschedule_all_actions')) {
        as_unschedule_all_actions('fchub_p24_process_renewal');
    }

    delete_transient('fchub_p24_methods_pl_test');
    delete_transient('fchub_p24_methods_en_test');
    delete_transient('fchub_p24_methods_pl_live');
    delete_transient('fchub_p24_methods_en_live');
});

add_action('admin_notices', function () {
    if (!defined('FLUENTCART_VERSION')) {
        echo '<div class="notice notice-error"><p>';
        echo esc_html__('FCHub - Przelewy24 requires FluentCart to be installed and activated.', 'fchub-p24');
        echo '</p></div>';
    }
});

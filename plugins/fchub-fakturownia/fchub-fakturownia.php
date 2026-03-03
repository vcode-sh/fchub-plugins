<?php
/**
 * Plugin Name: FCHub - Fakturownia
 * Plugin URI: https://fchub.co
 * Description: Fakturownia invoice integration with KSeF 2.0 support for FluentCart
 * Version: 1.0.1
 * Author: Vibe Code
 * Author URI: https://x.com/vcode_sh
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: fchub-fakturownia
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Tested up to:    6.7
 */

defined('ABSPATH') || exit;

define('FCHUB_FAKTUROWNIA_VERSION', '1.0.1');
define('FCHUB_FAKTUROWNIA_FILE', __FILE__);
define('FCHUB_FAKTUROWNIA_PATH', plugin_dir_path(__FILE__));
define('FCHUB_FAKTUROWNIA_URL', plugin_dir_url(__FILE__));

register_deactivation_hook(__FILE__, function () {
    wp_clear_scheduled_hook('fchub_fakturownia_check_ksef_status');
});

// Autoloader
spl_autoload_register(function ($class) {
    $prefix = 'FChubFakturownia\\';
    $baseDir = FCHUB_FAKTUROWNIA_PATH . 'app/';

    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }

    $relativeClass = substr($class, $len);
    $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';

    if (file_exists($file)) {
        require_once $file;
    }
});

/**
 * Register the Fakturownia integration with FluentCart.
 * FluentCart registers its integrations on 'init' priority 2,
 * so we use priority 3 to ensure BaseIntegrationManager is available.
 */
add_action('init', function () {
    if (!defined('FLUENTCART_VERSION')) {
        return;
    }

    // Register global settings hooks
    FChubFakturownia\Integration\FakturowniaSettings::register();

    // Register the integration module
    $integration = new FChubFakturownia\Integration\FakturowniaIntegration();
    $integration->register();

    // Register checkout NIP fields
    FChubFakturownia\Checkout\CheckoutFields::register();

    // Register in the "Integration Modules" UI list
    add_filter('fluent_cart/integration/addons', function ($addons) {
        $addons['fakturownia'] = [
            'title'       => __('Fakturownia', 'fchub-fakturownia'),
            'description' => __('Automatically create invoices in Fakturownia with KSeF 2.0 support when orders are paid.', 'fchub-fakturownia'),
            'logo'        => FCHUB_FAKTUROWNIA_URL . 'assets/fakturownia.webp',
            'enabled'     => FChubFakturownia\Integration\FakturowniaSettings::isConfigured(),
            'config_url'  => admin_url('admin.php?page=fluent-cart#/integrations/fakturownia'),
            'categories'  => ['core'],
        ];
        return $addons;
    });
}, 3);

// Register KSeF status check cron handler
add_action('fchub_fakturownia_check_ksef_status', function ($orderId, $fakturowniaInvoiceId) {
    if (!defined('FLUENTCART_VERSION')) {
        return;
    }

    $order = \FluentCart\App\Models\Order::find($orderId);
    if (!$order) {
        return;
    }

    $settings = FChubFakturownia\Integration\FakturowniaSettings::getSettings();
    $api = new FChubFakturownia\API\FakturowniaAPI($settings['domain'], $settings['api_token']);

    $invoice = $api->getInvoice((int) $fakturowniaInvoiceId);

    if (isset($invoice['error'])) {
        return;
    }

    $govStatus = $invoice['gov_status'] ?? null;
    if ($govStatus) {
        $order->updateMeta('_fakturownia_ksef_status', $govStatus);
    }

    $govId = $invoice['gov_id'] ?? null;
    if ($govId) {
        $order->updateMeta('_fakturownia_ksef_id', $govId);
    }

    $govLink = $invoice['gov_verification_link'] ?? null;
    if ($govLink) {
        $order->updateMeta('_fakturownia_ksef_link', $govLink);
    }

    // If still processing, check again in 2 minutes
    if ($govStatus === 'processing') {
        wp_schedule_single_event(
            time() + 120,
            'fchub_fakturownia_check_ksef_status',
            [$orderId, $fakturowniaInvoiceId]
        );
    }

    // Log KSeF result
    if ($govStatus === 'ok' && $govId) {
        $order->addLog(
            __('Fakturownia: KSeF submission successful', 'fchub-fakturownia'),
            sprintf(__('KSeF number: %s', 'fchub-fakturownia'), $govId),
            'info',
            'Fakturownia'
        );
    } elseif ($govStatus === 'send_error') {
        $errors = $invoice['gov_error_messages'] ?? [];
        $errorText = is_array($errors) ? implode('; ', $errors) : (string) $errors;
        $order->addLog(
            __('Fakturownia: KSeF submission failed', 'fchub-fakturownia'),
            $errorText,
            'error',
            'Fakturownia'
        );
    }
}, 10, 2);

/**
 * Admin notice if FluentCart is not active
 */
add_action('admin_notices', function () {
    if (!defined('FLUENTCART_VERSION')) {
        echo '<div class="notice notice-error"><p>';
        echo esc_html__('FCHub - Fakturownia requires FluentCart to be installed and activated.', 'fchub-fakturownia');
        echo '</p></div>';
    }
});

/**
 * Make the Fakturownia integration card clickable on the Integrations page.
 * FluentCart's Vue template doesn't support config_url on addon tiles,
 * so we inject a small script to add click-to-configure behavior.
 */
add_action('admin_enqueue_scripts', function ($hook) {
    if (strpos($hook, 'fluent-cart') === false) {
        return;
    }

    $js = <<<'JS'
(function() {
    var observer = new MutationObserver(function() {
        var cards = document.querySelectorAll('.fct-integration-card');
        cards.forEach(function(card) {
            if (card.dataset.fchubLinked) return;
            var title = card.querySelector('.title');
            if (title && title.textContent.indexOf('Fakturownia') !== -1) {
                card.dataset.fchubLinked = '1';
                card.style.cursor = 'pointer';
                card.addEventListener('click', function(e) {
                    if (e.target.tagName === 'A' || e.target.tagName === 'BUTTON') return;
                    window.location.hash = '#/integrations/fakturownia';
                });
            }
        });
    });
    observer.observe(document.body, { childList: true, subtree: true });
})();
JS;

    wp_register_script('fchub-fakturownia-admin', '', [], FCHUB_FAKTUROWNIA_VERSION, true);
    wp_enqueue_script('fchub-fakturownia-admin');
    wp_add_inline_script('fchub-fakturownia-admin', $js);
});

/**
 * Add Fakturownia info to order admin view
 */
add_action('fluent_cart/after_receipt', function ($order) {
    if (!$order || !is_object($order)) {
        return;
    }

    $invoiceId = $order->getMeta('_fakturownia_invoice_id');
    if (!$invoiceId) {
        return;
    }

    $invoiceNumber = $order->getMeta('_fakturownia_invoice_number');
    $invoiceUrl = $order->getMeta('_fakturownia_invoice_url');
    $ksefStatus = $order->getMeta('_fakturownia_ksef_status');
    $ksefId = $order->getMeta('_fakturownia_ksef_id');
    $ksefLink = $order->getMeta('_fakturownia_ksef_link');
    $correctionId = $order->getMeta('_fakturownia_correction_id');
    $correctionNumber = $order->getMeta('_fakturownia_correction_number');

    echo '<div class="fchub-fakturownia-info" style="margin-top: 15px; padding: 10px; border: 1px solid #ddd; border-radius: 4px;">';
    echo '<h4 style="margin-top: 0;">' . esc_html__('Fakturownia Invoice', 'fchub-fakturownia') . '</h4>';

    // Invoice number + link
    if ($invoiceNumber) {
        echo '<p><strong>' . esc_html__('Invoice:', 'fchub-fakturownia') . '</strong> ';
        if ($invoiceUrl) {
            echo '<a href="' . esc_url($invoiceUrl) . '" target="_blank">' . esc_html($invoiceNumber) . '</a>';
        } else {
            echo esc_html($invoiceNumber);
        }

        // PDF link
        $settings = FChubFakturownia\Integration\FakturowniaSettings::getSettings();
        if (!empty($settings['domain']) && !empty($settings['api_token'])) {
            $api = new FChubFakturownia\API\FakturowniaAPI($settings['domain'], $settings['api_token']);
            $pdfUrl = $api->getInvoicePdfUrl((int) $invoiceId);
            echo ' | <a href="' . esc_url($pdfUrl) . '" target="_blank">' . esc_html__('PDF', 'fchub-fakturownia') . '</a>';
        }
        echo '</p>';
    }

    // KSeF status
    if ($ksefStatus) {
        $statusLabels = [
            'ok'             => __('Sent', 'fchub-fakturownia'),
            'processing'     => __('Processing...', 'fchub-fakturownia'),
            'send_error'     => __('Error', 'fchub-fakturownia'),
            'server_error'   => __('Server Error', 'fchub-fakturownia'),
            'not_applicable' => __('N/A', 'fchub-fakturownia'),
            'not_connected'  => __('Not Connected', 'fchub-fakturownia'),
        ];

        $statusColors = [
            'ok'           => '#28a745',
            'processing'   => '#ffc107',
            'send_error'   => '#dc3545',
            'server_error' => '#dc3545',
        ];

        $label = $statusLabels[$ksefStatus] ?? $ksefStatus;
        $color = $statusColors[$ksefStatus] ?? '#6c757d';

        echo '<p><strong>KSeF:</strong> <span style="color: ' . esc_attr($color) . ';">' . esc_html($label) . '</span>';

        if ($ksefId) {
            echo ' - ' . esc_html($ksefId);
        }
        if ($ksefLink) {
            echo ' (<a href="' . esc_url($ksefLink) . '" target="_blank">' . esc_html__('Verify', 'fchub-fakturownia') . '</a>)';
        }
        echo '</p>';
    }

    // Correction invoice
    if ($correctionId) {
        echo '<p><strong>' . esc_html__('Correction:', 'fchub-fakturownia') . '</strong> ';
        echo esc_html($correctionNumber ?: '#' . $correctionId);
        echo '</p>';
    }

    echo '</div>';
});

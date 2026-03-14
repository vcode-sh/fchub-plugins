<?php
/**
 * Plugin Name: FCHub - Fakturownia
 * Plugin URI: https://fchub.co
 * Description: Fakturownia invoice integration with KSeF 2.0 support for FluentCart
 * Version: 1.1.1
 * Author: Vibe Code
 * Author URI: https://x.com/vcode_sh
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: fchub-fakturownia
 * Domain Path: /languages
 * Requires at least: 6.4
 * Requires PHP: 8.1
 * Tested up to:    6.7
 * Update URI: https://fchub.co/fchub-fakturownia
 */

defined('ABSPATH') || exit;

define('FCHUB_FAKTUROWNIA_VERSION', '1.1.1');
define('FCHUB_FAKTUROWNIA_FILE', __FILE__);
define('FCHUB_FAKTUROWNIA_PATH', plugin_dir_path(__FILE__));
define('FCHUB_FAKTUROWNIA_URL', plugin_dir_url(__FILE__));

require_once __DIR__ . '/lib/GitHubUpdater.php';
FCHub_GitHub_Updater::register('fchub-fakturownia', plugin_basename(__FILE__), FCHUB_FAKTUROWNIA_VERSION);

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

    if (empty($settings['domain']) || empty($settings['api_token'])) {
        return;
    }

    $api = new FChubFakturownia\API\FakturowniaAPI($settings['domain'], $settings['api_token']);

    $invoice = $api->getInvoice((int) $fakturowniaInvoiceId);

    if (isset($invoice['error'])) {
        return;
    }

    // Determine if this is a correction invoice to use the right meta keys
    $isCorrection = ($fakturowniaInvoiceId == $order->getMeta('_fakturownia_correction_id'));
    $metaPrefix = $isCorrection ? '_fakturownia_correction_ksef' : '_fakturownia_ksef';

    $govStatus = $invoice['gov_status'] ?? null;

    // Normalize demo/sandbox status prefixes (demo_ok → ok, demo_processing → processing, etc.)
    if ($govStatus && str_starts_with($govStatus, 'demo_')) {
        $govStatus = substr($govStatus, 5);
    }

    if ($govStatus) {
        $order->updateMeta($metaPrefix . '_status', $govStatus);
    }

    $govId = $invoice['gov_id'] ?? null;
    if ($govId) {
        $order->updateMeta($metaPrefix . '_id', $govId);
    }

    $govLink = $invoice['gov_verification_link'] ?? null;
    if ($govLink) {
        $order->updateMeta($metaPrefix . '_link', $govLink);
    }

    $retryKey = $metaPrefix . '_retry_count';

    // If still processing or KSeF had a transient server error, retry with a cap
    if ($govStatus === 'processing' || $govStatus === 'server_error') {
        $retryCount = (int) $order->getMeta($retryKey, 0);
        if ($retryCount >= 30) {
            $order->addLog(
                __('Fakturownia: KSeF status check timed out', 'fchub-fakturownia'),
                __('Gave up after 30 attempts (~1 hour). Check Fakturownia manually.', 'fchub-fakturownia'),
                'warning',
                'Fakturownia'
            );
            return;
        }
        $order->updateMeta($retryKey, $retryCount + 1);
        wp_schedule_single_event(
            time() + 120,
            'fchub_fakturownia_check_ksef_status',
            [$orderId, $fakturowniaInvoiceId]
        );
    }

    $invoiceType = $isCorrection ? __('correction', 'fchub-fakturownia') : __('invoice', 'fchub-fakturownia');

    // Log KSeF result and clean up retry counter on terminal states
    if ($govStatus === 'ok' && $govId) {
        $order->deleteMeta($retryKey);
        $order->addLog(
            __('Fakturownia: KSeF submission successful', 'fchub-fakturownia'),
            sprintf(__('KSeF number (%s): %s', 'fchub-fakturownia'), $invoiceType, $govId),
            'info',
            'Fakturownia'
        );
    } elseif ($govStatus === 'send_error') {
        $order->deleteMeta($retryKey);
        $errors = $invoice['gov_error_messages'] ?? [];
        $errorText = is_array($errors) ? implode('; ', $errors) : (string) $errors;
        $order->addLog(
            sprintf(__('Fakturownia: KSeF %s submission failed', 'fchub-fakturownia'), $invoiceType),
            $errorText,
            'error',
            'Fakturownia'
        );
    }
}, 10, 2);

/**
 * PDF proxy endpoint — streams invoice PDF without exposing API token in HTML
 */
add_action('rest_api_init', function () {
    register_rest_route('fchub-fakturownia/v1', '/invoice-pdf/(?P<order_id>\d+)', [
        'methods'             => 'GET',
        'callback'            => function (\WP_REST_Request $request) {
            $orderId = (int) $request->get_param('order_id');
            $order = \FluentCart\App\Models\Order::find($orderId);

            if (!$order) {
                return new \WP_Error('not_found', 'Order not found', ['status' => 404]);
            }

            $invoiceId = $order->getMeta('_fakturownia_invoice_id');
            if (!$invoiceId) {
                return new \WP_Error('no_invoice', 'No invoice for this order', ['status' => 404]);
            }

            $settings = FChubFakturownia\Integration\FakturowniaSettings::getSettings();
            if (empty($settings['domain']) || empty($settings['api_token'])) {
                return new \WP_Error('not_configured', 'Fakturownia not configured', ['status' => 500]);
            }

            $api = new FChubFakturownia\API\FakturowniaAPI($settings['domain'], $settings['api_token']);
            $pdf = $api->downloadInvoicePdf((int) $invoiceId);

            if (isset($pdf['error'])) {
                return new \WP_Error('pdf_error', $pdf['error'], ['status' => 502]);
            }

            $invoiceNumber = $order->getMeta('_fakturownia_invoice_number') ?: 'invoice';
            $filename = sanitize_file_name($invoiceNumber) . '.pdf';

            header('Content-Type: application/pdf');
            header('Content-Disposition: inline; filename="' . $filename . '"');
            header('Content-Length: ' . strlen($pdf['body']));
            header('Cache-Control: private, max-age=300');

            echo $pdf['body']; // @codingStandardsIgnoreLine
            exit;
        },
        'permission_callback' => function () {
            return current_user_can('manage_options');
        },
    ]);
});

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

    $settingsLabel = esc_js(__('Settings', 'fchub-fakturownia'));

    $js = <<<JS
(function() {
    var SETTINGS_LABEL = '{$settingsLabel}';
    var observer = new MutationObserver(function() {
        var cards = document.querySelectorAll('.fct-integration-card');
        cards.forEach(function(card) {
            if (card.dataset.fchubLinked) return;
            var title = card.querySelector('.title');
            if (title && title.textContent.indexOf('Fakturownia') !== -1) {
                card.dataset.fchubLinked = '1';
                card.style.cursor = 'pointer';

                // Add a visible Settings button matching FluentCart's native style
                var desc = card.querySelector('.desc');
                if (desc) {
                    var btnWrap = document.createElement('div');
                    btnWrap.className = 'addon-setting-btn';
                    btnWrap.style.cssText = 'margin-top: 8px;';
                    var btn = document.createElement('button');
                    btn.type = 'button';
                    btn.textContent = SETTINGS_LABEL;
                    btn.style.cssText = 'display: inline-flex; align-items: center; gap: 4px; padding: 5px 12px; font-size: 13px; font-weight: 500; border-radius: 4px; border: 1px solid #d0d5dd; background: #fff; color: #344054; cursor: pointer; line-height: 1.5;';
                    btn.addEventListener('mouseenter', function() { btn.style.background = '#f9fafb'; });
                    btn.addEventListener('mouseleave', function() { btn.style.background = '#fff'; });
                    btn.addEventListener('click', function(e) {
                        e.stopPropagation();
                        window.location.hash = '#/integrations/fakturownia';
                    });
                    btnWrap.appendChild(btn);
                    desc.parentNode.insertBefore(btnWrap, desc.nextSibling);
                }

                card.addEventListener('click', function(e) {
                    if (e.target.tagName === 'A' || e.target.tagName === 'BUTTON') return;
                    window.location.hash = '#/integrations/fakturownia';
                });
                observer.disconnect();
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
add_action('fluent_cart/after_receipt', function ($data) {
    // Only show Fakturownia details to admins — not on customer-facing receipts
    if (!current_user_can('manage_options')) {
        return;
    }

    // FluentCart passes an array ['order' => $order, 'is_first_time' => bool, ...]
    $order = is_array($data) ? ($data['order'] ?? null) : $data;
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

        // PDF link via proxy (API token never exposed in HTML)
        $pdfUrl = rest_url('fchub-fakturownia/v1/invoice-pdf/' . $order->id);
        echo ' | <a href="' . esc_url($pdfUrl) . '" target="_blank">' . esc_html__('PDF', 'fchub-fakturownia') . '</a>';
        echo '</p>';
    }

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

    // KSeF status
    if ($ksefStatus) {
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

        $corrKsefStatus = $order->getMeta('_fakturownia_correction_ksef_status');
        $corrKsefId = $order->getMeta('_fakturownia_correction_ksef_id');
        if ($corrKsefStatus) {
            $corrLabel = $statusLabels[$corrKsefStatus] ?? $corrKsefStatus;
            $corrColor = $statusColors[$corrKsefStatus] ?? '#6c757d';
            echo ' <span style="color: ' . esc_attr($corrColor) . ';">(KSeF: ' . esc_html($corrLabel) . ')</span>';
            if ($corrKsefId) {
                echo ' ' . esc_html($corrKsefId);
            }
        }

        echo '</p>';
    }

    echo '</div>';
});

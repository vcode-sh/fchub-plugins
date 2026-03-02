<?php

namespace FChubFakturownia\Checkout;

defined('ABSPATH') || exit;

use FChubFakturownia\Integration\FakturowniaSettings;

class CheckoutFields
{
    /**
     * Register checkout field hooks
     */
    public static function register(): void
    {
        if (!FakturowniaSettings::isNipToggleEnabled()) {
            return;
        }

        add_filter('fluent_cart/checkout_page_name_fields_schema', [self::class, 'addNipFields'], 20, 2);
        add_filter('fluent_cart/fields/address_base_fields', [self::class, 'addNipToAddressFields'], 10, 2);
        add_action('wp_enqueue_scripts', [self::class, 'enqueueCheckoutScript']);
    }

    /**
     * Add NIP toggle + field to checkout personal info section
     */
    public static function addNipFields($fields, $args = []): array
    {
        // Add "I want a company invoice" toggle after company_name or at end
        $fields['billing_wants_company_invoice'] = [
            'name'        => 'billing_wants_company_invoice',
            'id'          => 'billing_wants_company_invoice',
            'type'        => 'checkbox',
            'data-type'   => 'checkbox',
            'label'       => esc_attr__('I want a company invoice', 'fchub-fakturownia'),
            'required'    => '',
            'value'       => '',
            'aria-label'  => esc_attr__('I want a company invoice', 'fchub-fakturownia'),
            'wrapper_class' => 'fchub-nip-toggle-wrapper',
        ];

        // Add NIP field (hidden by default, shown when toggle is checked)
        $fields['billing_nip'] = [
            'name'        => 'billing_nip',
            'id'          => 'billing_nip',
            'type'        => 'text',
            'data-type'   => 'text',
            'label'       => '',
            'required'    => '',
            'placeholder' => esc_attr__('NIP (Tax ID)', 'fchub-fakturownia'),
            'aria-label'  => esc_attr__('NIP Tax Identification Number', 'fchub-fakturownia'),
            'autocomplete' => 'off',
            'value'       => '',
            'wrapper_class' => 'fchub-nip-field-wrapper',
            'wrapper_atts' => [
                'style' => 'display:none',
                'data-nip-field' => '1',
            ],
        ];

        return $fields;
    }

    /**
     * Add NIP to address fields for saved address display
     */
    public static function addNipToAddressFields($fields, $args = []): array
    {
        $config = $args['config'] ?? [];
        $type = $config['type'] ?? 'billing';

        if ($type !== 'billing') {
            return $fields;
        }

        $fields['nip'] = [
            'name'        => $type . '_nip',
            'id'          => $type . '_nip',
            'type'        => 'text',
            'data-type'   => 'text',
            'label'       => '',
            'aria-label'  => esc_attr__('NIP', 'fchub-fakturownia'),
            'placeholder' => esc_attr__('NIP (Tax ID)', 'fchub-fakturownia'),
            'value'       => '',
        ];

        return $fields;
    }

    /**
     * Enqueue checkout JS for toggle behavior
     */
    public static function enqueueCheckoutScript(): void
    {
        if (!self::isCheckoutPage()) {
            return;
        }

        $js = self::getToggleScript();
        wp_register_script('fchub-fakturownia-checkout', '', [], FCHUB_FAKTUROWNIA_VERSION, true);
        wp_enqueue_script('fchub-fakturownia-checkout');
        wp_add_inline_script('fchub-fakturownia-checkout', $js);
    }

    /**
     * Check if current page has FluentCart checkout
     */
    private static function isCheckoutPage(): bool
    {
        // Enqueue on all frontend pages - the JS itself checks for the elements
        return !is_admin();
    }

    /**
     * JS for toggle show/hide NIP field
     */
    private static function getToggleScript(): string
    {
        return <<<'JS'
(function() {
    function initNipToggle() {
        var toggle = document.getElementById('billing_wants_company_invoice');
        if (!toggle) return;

        var nipWrapper = toggle.closest('form')
            ? toggle.closest('form').querySelector('[data-nip-field]')
            : document.querySelector('[data-nip-field]');

        if (!nipWrapper) return;

        function updateVisibility() {
            nipWrapper.style.display = toggle.checked ? '' : 'none';
            var nipInput = nipWrapper.querySelector('input');
            if (nipInput && !toggle.checked) {
                nipInput.value = '';
            }
        }

        toggle.addEventListener('change', updateVisibility);
        updateVisibility();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initNipToggle);
    } else {
        initNipToggle();
    }

    // Re-init on dynamic content (FluentCart renders checkout via JS)
    var observer = new MutationObserver(function(mutations) {
        for (var i = 0; i < mutations.length; i++) {
            if (mutations[i].addedNodes.length) {
                initNipToggle();
                break;
            }
        }
    });
    observer.observe(document.body, { childList: true, subtree: true });
})();
JS;
    }

    /**
     * Validate Polish NIP checksum
     */
    public static function validateNip(string $nip): bool
    {
        $nip = preg_replace('/[^0-9]/', '', $nip);

        if (strlen($nip) !== 10) {
            return false;
        }

        $weights = [6, 5, 7, 2, 3, 4, 5, 6, 7];
        $sum = 0;

        for ($i = 0; $i < 9; $i++) {
            $sum += (int) $nip[$i] * $weights[$i];
        }

        return ($sum % 11) === (int) $nip[9];
    }
}

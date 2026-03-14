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
        add_action('wp_enqueue_scripts', [self::class, 'enqueueCheckoutScript']);
    }

    /**
     * Add NIP text field to checkout personal info section.
     * FluentCart's checkout Vue only renders 'text' and 'email' types,
     * so the toggle checkbox is injected via JS instead.
     */
    public static function addNipFields($fields, $args = []): array
    {
        $fields['billing_nip'] = [
            'name'         => 'billing_nip',
            'id'           => 'billing_nip',
            'type'         => 'text',
            'data-type'    => 'text',
            'label'        => '',
            'required'     => '',
            'placeholder'  => esc_attr__('NIP (Tax ID)', 'fchub-fakturownia'),
            'aria-label'   => esc_attr__('NIP Tax Identification Number', 'fchub-fakturownia'),
            'autocomplete' => 'off',
            'value'        => '',
            'wrapper_class' => 'fchub-nip-field-wrapper',
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
     * JS that injects a toggle checkbox before the NIP field and handles show/hide.
     * FluentCart's checkout Vue doesn't render checkbox fields, so we inject one via DOM.
     */
    private static function getToggleScript(): string
    {
        $toggleLabel = esc_js(__('I want a company invoice', 'fchub-fakturownia'));

        return <<<JS
(function() {
    var TOGGLE_LABEL = '{$toggleLabel}';

    function initNipToggle() {
        var nipField = document.getElementById('billing_nip');
        if (!nipField) return;

        var wrapper = nipField.closest('.fchub-nip-field-wrapper') || nipField.parentElement;
        if (!wrapper || wrapper.dataset.fchubNipInit) return;
        wrapper.dataset.fchubNipInit = '1';

        // Hide NIP field by default
        wrapper.style.display = 'none';

        // Inject toggle checkbox before the NIP wrapper
        var toggleWrapper = document.createElement('div');
        toggleWrapper.className = 'fchub-nip-toggle-wrapper';
        toggleWrapper.style.cssText = 'padding: 4px 0;';

        var label = document.createElement('label');
        label.style.cssText = 'display: flex; align-items: center; gap: 6px; cursor: pointer; font-size: 14px;';

        var checkbox = document.createElement('input');
        checkbox.type = 'checkbox';
        checkbox.id = 'billing_wants_company_invoice';

        label.appendChild(checkbox);
        label.appendChild(document.createTextNode(TOGGLE_LABEL));
        toggleWrapper.appendChild(label);

        wrapper.parentNode.insertBefore(toggleWrapper, wrapper);

        checkbox.addEventListener('change', function() {
            wrapper.style.display = this.checked ? '' : 'none';
            if (!this.checked) {
                nipField.value = '';
            }
        });
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

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
        add_filter('fluent_cart/checkout/validate_data', [self::class, 'validateCheckoutNip'], 20, 2);
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

        wp_register_script(
            'fchub-fakturownia-checkout',
            FCHUB_FAKTUROWNIA_URL . 'assets/checkout.js',
            [],
            FCHUB_FAKTUROWNIA_VERSION,
            true
        );
        wp_localize_script('fchub-fakturownia-checkout', 'FCHubFakturowniaCheckout', [
            'toggleLabel' => __('I want a company invoice', 'fchub-fakturownia'),
        ]);
        wp_enqueue_script('fchub-fakturownia-checkout');
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
     * Validate NIP at checkout — if provided, it must pass the checksum.
     * FluentCart passes ['data' => $formData, 'cart' => $cart] as second arg.
     */
    public static function validateCheckoutNip($errors, $data): mixed
    {
        // FluentCart wraps checkout data: $data = ['data' => [...], 'cart' => [...]]
        $formData = $data['data'] ?? $data;
        $nip = $formData['billing_nip'] ?? '';
        if (empty($nip)) {
            return $errors;
        }

        if (!self::validateNip($nip)) {
            if (is_wp_error($errors)) {
                $errors->add('billing_nip', __('Invalid NIP (Tax ID). Please check the number.', 'fchub-fakturownia'));
            } else {
                $errors = new \WP_Error('billing_nip', __('Invalid NIP (Tax ID). Please check the number.', 'fchub-fakturownia'));
            }
        }

        return $errors;
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

        $checkDigit = $sum % 11;

        // Mod-11 = 10 means the NIP is invalid (no valid check digit exists)
        if ($checkDigit === 10) {
            return false;
        }

        return $checkDigit === (int) $nip[9];
    }
}

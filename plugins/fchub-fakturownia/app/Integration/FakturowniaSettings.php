<?php

namespace FChubFakturownia\Integration;

defined('ABSPATH') || exit;

use FluentCart\Framework\Support\Arr;

class FakturowniaSettings
{
    private static ?array $cachedSettings = null;

    /**
     * Register settings hooks
     */
    public static function register(): void
    {
        add_filter(
            'fluent_cart/integration/global_integration_settings_fakturownia',
            [self::class, 'getGlobalSettings'],
            10, 2
        );

        add_filter(
            'fluent_cart/integration/global_integration_fields_fakturownia',
            [self::class, 'getGlobalFields'],
            10, 2
        );

        add_action(
            'fluent_cart/integration/save_global_integration_settings_fakturownia',
            [self::class, 'saveGlobalSettings'],
            10, 1
        );

        add_action(
            'fluent_cart/integration/authenticate_global_credentials_fakturownia',
            [self::class, 'authenticateCredentials'],
            10, 1
        );
    }

    /**
     * Get saved settings (with environment variable overrides)
     */
    public static function getSettings(): array
    {
        if (self::$cachedSettings !== null) {
            return self::$cachedSettings;
        }

        $settings = fluent_cart_get_option('_integration_api_fakturownia', []);

        if (!is_array($settings)) {
            $settings = [];
        }

        $defaults = [
            'domain'          => '',
            'api_token'       => '',
            'status'          => false,
            'department_id'   => '',
            'invoice_kind'    => 'vat',
            'payment_type'    => 'transfer',
            'invoice_lang'    => 'pl',
            'ksef_auto_send'  => 'no',
            'show_nip_toggle' => 'yes',
        ];

        $settings = wp_parse_args($settings, $defaults);

        // Environment variable overrides
        if (defined('FCHUB_FAKTUROWNIA_DOMAIN') && FCHUB_FAKTUROWNIA_DOMAIN) {
            $settings['domain'] = FCHUB_FAKTUROWNIA_DOMAIN;
        }
        if (defined('FCHUB_FAKTUROWNIA_API_TOKEN') && FCHUB_FAKTUROWNIA_API_TOKEN) {
            $settings['api_token'] = FCHUB_FAKTUROWNIA_API_TOKEN;
        }
        if (defined('FCHUB_FAKTUROWNIA_DEPARTMENT_ID') && FCHUB_FAKTUROWNIA_DEPARTMENT_ID) {
            $settings['department_id'] = FCHUB_FAKTUROWNIA_DEPARTMENT_ID;
        }

        self::$cachedSettings = $settings;
        return $settings;
    }

    /**
     * Check if integration is properly configured
     */
    public static function isConfigured(): bool
    {
        $settings = self::getSettings();
        return !empty($settings['domain']) && !empty($settings['api_token']) && !empty($settings['status']);
    }

    public static function getDomain(): string
    {
        return self::getSettings()['domain'];
    }

    public static function getApiToken(): string
    {
        return self::getSettings()['api_token'];
    }

    public static function getDepartmentId(): string
    {
        return self::getSettings()['department_id'];
    }

    public static function getInvoiceKind(): string
    {
        return self::getSettings()['invoice_kind'];
    }

    public static function getPaymentType(): string
    {
        return self::getSettings()['payment_type'];
    }

    public static function getInvoiceLang(): string
    {
        return self::getSettings()['invoice_lang'];
    }

    public static function isKsefAutoSend(): bool
    {
        return self::getSettings()['ksef_auto_send'] === 'yes';
    }

    public static function isNipToggleEnabled(): bool
    {
        return self::getSettings()['show_nip_toggle'] === 'yes';
    }

    /**
     * Return saved settings for the global integration UI
     */
    public static function getGlobalSettings($settings, $args): array
    {
        return self::getSettings();
    }

    /**
     * Return field definitions for the global settings form.
     *
     * FluentCart's getGlobalSettingsData() calls wp_send_json() which bypasses
     * the framework's response wrapping. The Vue component expects t.data.X
     * (framework-wrapped) but gets raw JSON (unwrapped). We intercept here
     * by sending the properly-wrapped response ourselves and dying before
     * FluentCart's wp_send_json can fire.
     */
    public static function getGlobalFields($fields, $args): array
    {
        // Send the full response ourselves with proper {data: ...} wrapping.
        // FluentCart's getGlobalSettingsData() uses wp_send_json() which bypasses
        // the framework's response wrapping — the Vue expects t.data.X but gets
        // raw JSON. By sending here and dying, we preempt the broken handler.
        $settings = self::getSettings();
        $fieldSettings = self::buildGlobalFields();

        // Connection status — shown in description and as instruction banner
        if ($settings['status']) {
            $fieldSettings['menu_description'] = '<span style="color:#065f46;font-weight:500;">&#10003; '
                . sprintf(__('Connected to %s.fakturownia.pl', 'fchub-fakturownia'), esc_html($settings['domain']))
                . '</span>';
        } elseif (!empty($settings['domain']) && !empty($settings['api_token'])) {
            $fieldSettings['config_instruction'] = '<div style="padding:10px 14px;background:#fef2f2;border:1px solid #fecaca;border-radius:6px;color:#991b1b;margin-bottom:8px;">'
                . '<strong>&#10007; ' . __('Not Connected', 'fchub-fakturownia') . '</strong> — '
                . __('API connection failed. Please check your domain and API token, then save again.', 'fchub-fakturownia')
                . '</div>';
        }

        wp_send_json([
            'data' => [
                'integration' => $settings,
                'settings'    => $fieldSettings,
            ],
        ], 200);
        // wp_send_json dies — code below never runs, but PHP requires a return type match
        return [];
    }

    /**
     * Build the global settings field definitions
     */
    private static function buildGlobalFields(): array
    {
        $fieldSettings = [
            'logo'              => FCHUB_FAKTUROWNIA_URL . 'assets/fakturownia.webp',
            'menu_title'        => __('Fakturownia Integration', 'fchub-fakturownia'),
            'menu_description'  => __('Configure your Fakturownia account to automatically create invoices with KSeF 2.0 support.', 'fchub-fakturownia'),
            'save_button_text'  => __('Save Settings', 'fchub-fakturownia'),
            'valid_message'     => __('Your Fakturownia API connection is valid', 'fchub-fakturownia'),
            'invalid_message'   => __('Your Fakturownia API connection is not valid', 'fchub-fakturownia'),
            'fields'            => [
                'domain' => [
                    'type'        => 'text',
                    'placeholder' => __('e.g. mojafirma', 'fchub-fakturownia'),
                    'label'       => __('Fakturownia Domain', 'fchub-fakturownia'),
                    'tips'        => __('Your Fakturownia subdomain (e.g. "mojafirma" from mojafirma.fakturownia.pl)', 'fchub-fakturownia'),
                ],
                'api_token' => [
                    'type'        => 'password',
                    'placeholder' => __('API Token', 'fchub-fakturownia'),
                    'label'       => __('API Token', 'fchub-fakturownia'),
                    'tips'        => __('Find in Fakturownia: Settings > Account Settings > Integration > API Authorization Code', 'fchub-fakturownia'),
                ],
                'department_id' => [
                    'type'        => 'text',
                    'placeholder' => __('Department ID (optional)', 'fchub-fakturownia'),
                    'label'       => __('Department ID', 'fchub-fakturownia'),
                    'tips'        => __('Fakturownia department ID. Seller data (name, address, bank account) will be pulled from this department.', 'fchub-fakturownia'),
                ],
                'invoice_kind' => [
                    'type'    => 'select',
                    'label'   => __('Invoice Type', 'fchub-fakturownia'),
                    'tips'    => __('Type of invoice to create in Fakturownia.', 'fchub-fakturownia'),
                    'options' => [
                        'vat'      => 'VAT',
                        'proforma' => 'Proforma',
                        'bill'     => __('Bill (Rachunek)', 'fchub-fakturownia'),
                    ],
                ],
                'payment_type' => [
                    'type'    => 'select',
                    'label'   => __('Payment Type', 'fchub-fakturownia'),
                    'tips'    => __('Payment method shown on the invoice.', 'fchub-fakturownia'),
                    'options' => [
                        'transfer' => __('Bank Transfer', 'fchub-fakturownia'),
                        'card'     => __('Card', 'fchub-fakturownia'),
                        'cash'     => __('Cash', 'fchub-fakturownia'),
                        'paypal'   => 'PayPal',
                    ],
                ],
                'invoice_lang' => [
                    'type'    => 'select',
                    'label'   => __('Invoice Language', 'fchub-fakturownia'),
                    'options' => [
                        'pl'    => 'Polski',
                        'en'    => 'English',
                        'de'    => 'Deutsch',
                        'fr'    => 'Français',
                        'pl/en' => 'Polski / English',
                    ],
                ],
                'ksef_auto_send' => [
                    'type'    => 'select',
                    'label'   => __('KSeF Auto Send', 'fchub-fakturownia'),
                    'tips'    => __('When enabled, invoices will be automatically submitted to KSeF via Fakturownia.', 'fchub-fakturownia'),
                    'options' => [
                        'no'  => __('No', 'fchub-fakturownia'),
                        'yes' => __('Yes', 'fchub-fakturownia'),
                    ],
                ],
                'show_nip_toggle' => [
                    'type'    => 'select',
                    'label'   => __('Checkout NIP Field', 'fchub-fakturownia'),
                    'tips'    => __('Adds a "I want a company invoice" checkbox to checkout that reveals NIP field.', 'fchub-fakturownia'),
                    'options' => [
                        'yes' => __('Yes', 'fchub-fakturownia'),
                        'no'  => __('No', 'fchub-fakturownia'),
                    ],
                ],
            ],
        ];

        return $fieldSettings;
    }

    /**
     * Test API credentials
     */
    public static function authenticateCredentials($args): void
    {
        $integration = Arr::get($args, 'integration', []);
        $domain = Arr::get($integration, 'domain', '');
        $apiToken = Arr::get($integration, 'api_token', '');

        if (empty($domain) || empty($apiToken)) {
            wp_send_json([
                'message' => __('Please provide both domain and API token.', 'fchub-fakturownia'),
                'status'  => false,
            ], 422);
            return; // wp_send_json dies, but return for safety
        }

        $api = new \FChubFakturownia\API\FakturowniaAPI($domain, $apiToken);
        $result = $api->testConnection();

        if (isset($result['error'])) {
            wp_send_json([
                'message' => __('Connection failed: ', 'fchub-fakturownia') . $result['error'],
                'status'  => false,
            ], 422);
            return;
        }

        // Save validated settings
        $settings = self::getSettings();
        $settings['domain'] = sanitize_text_field($domain);
        $settings['api_token'] = sanitize_text_field($apiToken);
        $settings['status'] = true;

        fluent_cart_update_option('_integration_api_fakturownia', $settings);
        self::$cachedSettings = null;

        wp_send_json([
            'data' => [
                'message' => __('Connection successful! Your Fakturownia account is connected.', 'fchub-fakturownia'),
                'status'  => true,
            ],
        ], 200);
    }

    /**
     * Save global settings
     */
    public static function saveGlobalSettings($args): void
    {
        $integration = Arr::get($args, 'integration', []);

        $settings = self::getSettings();
        $settings['domain'] = sanitize_text_field(Arr::get($integration, 'domain', $settings['domain']));
        $settings['api_token'] = sanitize_text_field(Arr::get($integration, 'api_token', $settings['api_token']));
        $rawDeptId = sanitize_text_field(Arr::get($integration, 'department_id', ''));
        $settings['department_id'] = ($rawDeptId !== '' && ctype_digit($rawDeptId)) ? $rawDeptId : '';

        $invoiceKind = sanitize_text_field(Arr::get($integration, 'invoice_kind', 'vat'));
        $settings['invoice_kind'] = in_array($invoiceKind, ['vat', 'proforma', 'bill'], true) ? $invoiceKind : 'vat';

        $paymentType = sanitize_text_field(Arr::get($integration, 'payment_type', 'transfer'));
        $settings['payment_type'] = in_array($paymentType, ['transfer', 'card', 'cash', 'paypal'], true) ? $paymentType : 'transfer';

        $invoiceLang = sanitize_text_field(Arr::get($integration, 'invoice_lang', 'pl'));
        $settings['invoice_lang'] = in_array($invoiceLang, ['pl', 'en', 'de', 'fr', 'pl/en'], true) ? $invoiceLang : 'pl';

        $settings['ksef_auto_send'] = Arr::get($integration, 'ksef_auto_send', 'no') === 'yes' ? 'yes' : 'no';
        $settings['show_nip_toggle'] = Arr::get($integration, 'show_nip_toggle', 'yes') === 'yes' ? 'yes' : 'no';

        // Auto-validate API connection when saving with credentials
        if (!empty($settings['domain']) && !empty($settings['api_token'])) {
            $api = new \FChubFakturownia\API\FakturowniaAPI($settings['domain'], $settings['api_token']);
            $result = $api->testConnection();
            $settings['status'] = !isset($result['error']);
        } else {
            $settings['status'] = false;
        }

        fluent_cart_update_option('_integration_api_fakturownia', $settings);
        self::$cachedSettings = null;

        if (!$settings['status']) {
            wp_send_json([
                'message' => __('Settings saved, but API connection failed. Please check your domain and API token.', 'fchub-fakturownia'),
                'status'  => false,
            ], 422);
            return;
        }

        wp_send_json([
            'data' => [
                'message' => __('Settings saved and API connection verified.', 'fchub-fakturownia'),
                'status'  => true,
            ],
        ], 200);
    }
}

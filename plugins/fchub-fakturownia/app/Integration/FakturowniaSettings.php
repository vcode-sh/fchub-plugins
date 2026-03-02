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
     * Note: FluentCart's Vue component (GeneralIntegrationSettings) expects responses
     * wrapped in a 'data' key (response.data.integration / response.data.settings)
     * but Rest.js resolves with raw JSON. We send the complete response ourselves
     * to work around this mismatch.
     */
    public static function getGlobalFields($fields, $args): array
    {
        $fieldSettings = [
            'logo'             => FCHUB_FAKTUROWNIA_URL . 'assets/fakturownia.webp',
            'save_button_text' => __('Save Settings', 'fchub-fakturownia'),
            'valid_message'    => __('Your Fakturownia API connection is valid', 'fchub-fakturownia'),
            'invalid_message'  => __('Your Fakturownia API connection is not valid', 'fchub-fakturownia'),
            'fields'           => [
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

        // Send the complete response with 'data' wrapper expected by the Vue component
        wp_send_json([
            'data' => [
                'integration' => self::getSettings(),
                'settings'    => $fieldSettings,
            ],
        ], 200);

        return $fieldSettings; // Never reached
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
        }

        $api = new \FChubFakturownia\API\FakturowniaAPI($domain, $apiToken);
        $result = $api->testConnection();

        if (isset($result['error'])) {
            wp_send_json([
                'message' => __('Connection failed: ', 'fchub-fakturownia') . $result['error'],
                'status'  => false,
            ], 422);
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
        $settings['department_id'] = sanitize_text_field(Arr::get($integration, 'department_id', ''));
        $settings['invoice_kind'] = sanitize_text_field(Arr::get($integration, 'invoice_kind', 'vat'));
        $settings['payment_type'] = sanitize_text_field(Arr::get($integration, 'payment_type', 'transfer'));
        $settings['invoice_lang'] = sanitize_text_field(Arr::get($integration, 'invoice_lang', 'pl'));
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
        }

        wp_send_json([
            'data' => [
                'message' => __('Settings saved and API connection verified.', 'fchub-fakturownia'),
                'status'  => true,
            ],
        ], 200);
    }
}

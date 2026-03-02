<?php

namespace FChubMemberships\Integration;

defined('ABSPATH') || exit;

use FluentCart\Framework\Support\Arr;

class MembershipSettings
{
    private static ?array $cachedSettings = null;

    /**
     * Register settings hooks.
     */
    public static function register(): void
    {
        add_filter(
            'fluent_cart/integration/global_integration_settings_memberships',
            [self::class, 'getGlobalSettings'],
            10, 2
        );

        add_filter(
            'fluent_cart/integration/global_integration_fields_memberships',
            [self::class, 'getGlobalFields'],
            10, 2
        );

        add_action(
            'fluent_cart/integration/save_global_integration_settings_memberships',
            [self::class, 'saveGlobalSettings'],
            10, 1
        );
    }

    /**
     * Get saved settings.
     */
    public static function getSettings(): array
    {
        if (self::$cachedSettings !== null) {
            return self::$cachedSettings;
        }

        $settings = get_option('fchub_memberships_settings', []);

        if (!is_array($settings)) {
            $settings = [];
        }

        $defaults = [
            'status'                  => true,
            'default_protection_mode' => 'redirect',
            'default_redirect_url'    => '',
            'admin_bypass'            => 'yes',
            'auto_create_user'        => 'yes',
        ];

        $settings = wp_parse_args($settings, $defaults);

        self::$cachedSettings = $settings;
        return $settings;
    }

    /**
     * Get a specific setting value.
     */
    public static function get(string $key, $default = null)
    {
        return self::getSettings()[$key] ?? $default;
    }

    /**
     * Return saved settings for the global integration UI.
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
            'logo'             => FCHUB_MEMBERSHIPS_URL . 'assets/icons/memberships.svg',
            'save_button_text' => __('Save Settings', 'fchub-memberships'),
            'valid_message'    => __('Memberships integration is active', 'fchub-memberships'),
            'invalid_message'  => __('Memberships integration is not configured', 'fchub-memberships'),
            'fields'           => [
                'default_protection_mode' => [
                    'type'    => 'select',
                    'label'   => __('Default Protection Mode', 'fchub-memberships'),
                    'tips'    => __('How restricted content is handled for unauthorized users.', 'fchub-memberships'),
                    'options' => [
                        'redirect'        => __('Redirect to URL', 'fchub-memberships'),
                        'content_replace' => __('Replace Content with Message', 'fchub-memberships'),
                        '403'             => __('Show 403 Forbidden', 'fchub-memberships'),
                    ],
                ],
                'default_redirect_url' => [
                    'type'        => 'text',
                    'label'       => __('Default Redirect URL', 'fchub-memberships'),
                    'placeholder' => __('e.g. /membership-required/', 'fchub-memberships'),
                    'tips'        => __('URL to redirect unauthorized users to when protection mode is "Redirect". Leave empty to redirect to the home page.', 'fchub-memberships'),
                ],
                'admin_bypass' => [
                    'type'    => 'select',
                    'label'   => __('Admin Bypass', 'fchub-memberships'),
                    'tips'    => __('Allow administrators to view all protected content without a membership.', 'fchub-memberships'),
                    'options' => [
                        'yes' => __('Yes', 'fchub-memberships'),
                        'no'  => __('No', 'fchub-memberships'),
                    ],
                ],
                'auto_create_user' => [
                    'type'    => 'select',
                    'label'   => __('Auto-Create User', 'fchub-memberships'),
                    'tips'    => __('Automatically create a WordPress user account from the order email if one does not exist.', 'fchub-memberships'),
                    'options' => [
                        'yes' => __('Yes', 'fchub-memberships'),
                        'no'  => __('No', 'fchub-memberships'),
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
     * Save global settings.
     */
    public static function saveGlobalSettings($args): void
    {
        $integration = Arr::get($args, 'integration', []);

        $settings = self::getSettings();

        $allowedModes = ['redirect', 'content_replace', '403'];
        $mode = sanitize_text_field(Arr::get($integration, 'default_protection_mode', 'redirect'));
        $settings['default_protection_mode'] = in_array($mode, $allowedModes, true) ? $mode : 'redirect';

        $settings['default_redirect_url'] = esc_url_raw(Arr::get($integration, 'default_redirect_url', ''));
        $settings['admin_bypass'] = Arr::get($integration, 'admin_bypass', 'yes') === 'yes' ? 'yes' : 'no';
        $settings['auto_create_user'] = Arr::get($integration, 'auto_create_user', 'yes') === 'yes' ? 'yes' : 'no';
        $settings['status'] = true;

        update_option('fchub_memberships_settings', $settings);
        self::$cachedSettings = null;

        wp_send_json([
            'data' => [
                'message' => __('Settings saved successfully.', 'fchub-memberships'),
                'status'  => true,
            ],
        ], 200);
    }
}

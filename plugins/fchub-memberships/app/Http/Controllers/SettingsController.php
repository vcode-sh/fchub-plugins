<?php

namespace FChubMemberships\Http\Controllers;

defined('ABSPATH') || exit;

class SettingsController
{
    public static function registerRoutes(): void
    {
        $ns = 'fchub-memberships/v1';

        register_rest_route($ns, '/admin/settings', [
            [
                'methods'             => 'GET',
                'callback'            => [self::class, 'get'],
                'permission_callback' => [self::class, 'adminPermission'],
            ],
            [
                'methods'             => 'PUT,POST',
                'callback'            => [self::class, 'save'],
                'permission_callback' => [self::class, 'adminPermission'],
            ],
        ]);

        register_rest_route($ns, '/admin/settings/generate-api-key', [
            'methods'             => 'POST',
            'callback'            => [self::class, 'generateApiKey'],
            'permission_callback' => [self::class, 'adminPermission'],
        ]);

        register_rest_route($ns, '/admin/settings/regenerate-webhook-secret', [
            'methods'             => 'POST',
            'callback'            => [self::class, 'regenerateWebhookSecret'],
            'permission_callback' => [self::class, 'adminPermission'],
        ]);

        register_rest_route($ns, '/admin/settings/test-webhook', [
            'methods'             => 'POST',
            'callback'            => [self::class, 'testWebhook'],
            'permission_callback' => [self::class, 'adminPermission'],
        ]);
    }

    public static function get(\WP_REST_Request $request): \WP_REST_Response
    {
        $settings = self::getSettings();
        return new \WP_REST_Response(['data' => $settings]);
    }

    public static function save(\WP_REST_Request $request): \WP_REST_Response
    {
        $data = $request->get_json_params();
        $settings = self::getSettings();

        // General
        $textFields = [
            'default_protection_mode', 'default_redirect_url', 'default_redirect_url_unauthorized',
            'no_access_page_id', 'admin_bypass', 'auto_create_user',
            'membership_mode',
            'fluentcrm_tag_prefix', 'fluentcrm_default_list',
        ];
        foreach ($textFields as $field) {
            if (isset($data[$field])) {
                $settings[$field] = sanitize_text_field($data[$field]);
            }
        }

        // Restriction messages
        $messageFields = [
            'restriction_message_logged_out', 'restriction_message_no_access',
            'restriction_message_expired', 'restriction_message_drip_locked',
        ];
        foreach ($messageFields as $field) {
            if (isset($data[$field])) {
                $settings[$field] = sanitize_textarea_field($data[$field]);
            }
        }

        // Toggles
        $toggleFields = [
            'show_teaser', 'hide_protected_in_archive', 'debug_mode',
            'email_access_granted', 'email_access_expiring', 'email_access_revoked', 'email_drip_unlocked',
            'email_membership_paused', 'email_membership_resumed',
            'email_trial_expiring', 'email_trial_converted',
            'uninstall_remove_data',
            'fluentcrm_enabled', 'fluentcrm_auto_create_tags',
            'fc_enabled', 'fc_remove_badge_on_revoke',
            'webhook_enabled',
        ];
        foreach ($toggleFields as $field) {
            if (isset($data[$field])) {
                $settings[$field] = $data[$field] === 'yes' ? 'yes' : 'no';
            }
        }

        // Numbers
        $numberFields = ['expiry_warning_days', 'trial_expiry_notice_days', 'cron_validity_interval', 'cron_drip_interval', 'purge_expired_days'];
        foreach ($numberFields as $field) {
            if (isset($data[$field])) {
                $settings[$field] = max(0, (int) $data[$field]);
            }
        }

        // Email templates
        if (isset($data['email_templates'])) {
            $settings['email_templates'] = $data['email_templates'];
        }

        // Webhook URLs (textarea, one per line)
        if (isset($data['webhook_urls'])) {
            $settings['webhook_urls'] = sanitize_textarea_field($data['webhook_urls']);
        }

        // FluentCommunity mappings
        if (isset($data['fc_space_mappings']) && is_array($data['fc_space_mappings'])) {
            $settings['fc_space_mappings'] = array_map('sanitize_text_field', $data['fc_space_mappings']);
        }
        if (isset($data['fc_badge_mappings']) && is_array($data['fc_badge_mappings'])) {
            $settings['fc_badge_mappings'] = array_map('sanitize_text_field', $data['fc_badge_mappings']);
        }

        update_option('fchub_memberships_settings', $settings);

        return new \WP_REST_Response([
            'data'    => $settings,
            'message' => __('Settings saved.', 'fchub-memberships'),
        ]);
    }

    public static function generateApiKey(\WP_REST_Request $request): \WP_REST_Response
    {
        $settings = self::getSettings();
        $settings['api_key'] = wp_generate_password(40, false);
        update_option('fchub_memberships_settings', $settings);

        return new \WP_REST_Response([
            'data'    => ['api_key' => $settings['api_key']],
            'message' => __('API key generated.', 'fchub-memberships'),
        ]);
    }

    public static function regenerateWebhookSecret(\WP_REST_Request $request): \WP_REST_Response
    {
        $settings = self::getSettings();
        $settings['webhook_secret'] = wp_generate_password(40, false);
        update_option('fchub_memberships_settings', $settings);

        return new \WP_REST_Response([
            'data'    => ['webhook_secret' => $settings['webhook_secret']],
            'message' => __('Webhook secret regenerated.', 'fchub-memberships'),
        ]);
    }

    public static function testWebhook(\WP_REST_Request $request): \WP_REST_Response
    {
        $dispatcher = new \FChubMemberships\Integration\WebhookDispatcher();
        $result = $dispatcher->sendTest();

        return new \WP_REST_Response([
            'data'    => $result,
            'message' => $result['success']
                ? __('Test webhook sent.', 'fchub-memberships')
                : ($result['message'] ?? __('Failed to send test webhook.', 'fchub-memberships')),
        ]);
    }

    public static function getSettings(): array
    {
        $defaults = [
            'default_protection_mode'             => 'content_replace',
            'default_redirect_url'                => '',
            'default_redirect_url_unauthorized'    => '',
            'no_access_page_id'                   => '',
            'admin_bypass'                        => 'yes',
            'auto_create_user'                    => 'yes',
            'membership_mode'                     => 'stack',
            'restriction_message_logged_out'       => __('This content is available to members only. Please log in to access.', 'fchub-memberships'),
            'restriction_message_no_access'        => __('You don\'t have access to this content. View membership options to learn more.', 'fchub-memberships'),
            'restriction_message_expired'          => __('Your access to this content has expired. Renew your subscription to continue.', 'fchub-memberships'),
            'restriction_message_drip_locked'      => __('This content will be available to you on {unlock_date}.', 'fchub-memberships'),
            'show_teaser'                         => 'no',
            'hide_protected_in_archive'           => 'no',
            'debug_mode'                          => 'no',
            'email_access_granted'                => 'yes',
            'email_access_expiring'               => 'yes',
            'email_access_revoked'                => 'yes',
            'email_drip_unlocked'                 => 'yes',
            'email_membership_paused'             => 'yes',
            'email_membership_resumed'            => 'yes',
            'email_trial_expiring'                => 'yes',
            'email_trial_converted'               => 'yes',
            'trial_expiry_notice_days'            => 3,
            'expiry_warning_days'                 => 7,
            'cron_validity_interval'              => 5,
            'cron_drip_interval'                  => 60,
            'purge_expired_days'                  => 90,
            'api_key'                             => '',
            'uninstall_remove_data'               => 'no',
            'email_templates'                     => [],
            // FluentCRM
            'fluentcrm_enabled'                   => 'no',
            'fluentcrm_tag_prefix'                => 'member:',
            'fluentcrm_default_list'              => '',
            'fluentcrm_auto_create_tags'          => 'yes',
            // Webhooks
            'webhook_enabled'                     => 'no',
            'webhook_urls'                        => '',
            'webhook_secret'                      => '',
            // FluentCommunity
            'fc_enabled'                          => 'no',
            'fc_space_mappings'                   => [],
            'fc_badge_mappings'                   => [],
            'fc_remove_badge_on_revoke'           => 'no',
        ];

        $settings = get_option('fchub_memberships_settings', []);
        return wp_parse_args($settings, $defaults);
    }

    public static function adminPermission(): bool
    {
        return current_user_can('manage_options');
    }
}

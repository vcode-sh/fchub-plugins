<?php

namespace FChubMemberships\Http\Controllers;

use FChubMemberships\Email\NotificationCatalog;
use FChubMemberships\Email\NotificationTemplateRenderer;

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

        register_rest_route($ns, '/admin/email-notifications', [
            'methods'             => 'GET',
            'callback'            => [self::class, 'emailNotifications'],
            'permission_callback' => [self::class, 'adminPermission'],
        ]);

        register_rest_route($ns, '/admin/email-notifications/preview', [
            'methods'             => 'POST',
            'callback'            => [self::class, 'previewEmailNotification'],
            'permission_callback' => [self::class, 'adminPermission'],
        ]);

        register_rest_route($ns, '/admin/email-notifications/test', [
            'methods'             => 'POST',
            'callback'            => [self::class, 'testEmailNotification'],
            'permission_callback' => [self::class, 'adminPermission'],
        ]);

        register_rest_route($ns, '/admin/email-notifications/brand-template', [
            'methods'             => 'POST',
            'callback'            => [self::class, 'saveEmailBrandTemplate'],
            'permission_callback' => [self::class, 'adminPermission'],
        ]);

        register_rest_route($ns, '/admin/email-notifications/(?P<key>[a-z_]+)', [
            'methods'             => 'POST',
            'callback'            => [self::class, 'saveEmailNotification'],
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
            'restriction_message_paused',
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

        // Native email studio. Every value is normalised before persistence because
        // the global settings endpoint can be called without using the visual editor.
        $renderer = new NotificationTemplateRenderer();
        if (isset($data['email_templates']) && is_array($data['email_templates'])) {
            $templates = [];
            foreach (NotificationCatalog::all() as $key => $definition) {
                if (array_key_exists($key, $data['email_templates'])) {
                    $templates[$key] = $renderer->normaliseTemplate($key, $data['email_templates'][$key]);
                }
            }
            $settings['email_templates'] = $templates;
        }
        if (isset($data['email_theme']) && is_array($data['email_theme'])) {
            $settings['email_theme'] = $renderer->normaliseTheme($data['email_theme']);
        }
        if (isset($data['email_delivery']) && is_array($data['email_delivery'])) {
            $deliveries = [];
            foreach (NotificationCatalog::all() as $key => $definition) {
                $delivery = $data['email_delivery'][$key] ?? null;
                if (!in_array($delivery, ['built_in', 'fluentcrm', 'off'], true)) {
                    continue;
                }
                if ($delivery === 'fluentcrm' && !self::fluentCrmAvailable()) {
                    $delivery = 'built_in';
                }
                $deliveries[$key] = $delivery;
                $settings[$definition['setting_key']] = $delivery === 'built_in' ? 'yes' : 'no';
            }
            $settings['email_delivery'] = $deliveries;
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

    public static function emailNotifications(\WP_REST_Request $request): \WP_REST_Response
    {
        $settings = self::getSettings();
        $templates = is_array($settings['email_templates'] ?? null) ? $settings['email_templates'] : [];
        $deliveries = is_array($settings['email_delivery'] ?? null) ? $settings['email_delivery'] : [];
        $themeOverrides = is_array($settings['email_theme_overrides'] ?? null) ? $settings['email_theme_overrides'] : [];
        $renderer = new NotificationTemplateRenderer();
        $fluentCrmAvailable = self::fluentCrmAvailable();
        $notifications = [];

        foreach (NotificationCatalog::all() as $key => $definition) {
            $delivery = $deliveries[$key] ?? (($settings[$definition['setting_key']] ?? 'yes') === 'yes' ? 'built_in' : 'off');
            if ($delivery === 'fluentcrm' && !$fluentCrmAvailable) {
                $delivery = 'built_in';
            }

            $notifications[] = [
                'key' => $key,
                'label' => $definition['label'],
                'description' => $definition['description'],
                'group' => $definition['group'],
                'setting_key' => $definition['setting_key'],
                'variables' => $definition['variables'],
                'delivery' => $delivery,
                'template' => $renderer->normaliseTemplate($key, $templates[$key] ?? null),
                'default_template' => $renderer->normaliseTemplate($key, null),
                'theme_override' => is_array($themeOverrides[$key] ?? null)
                    ? $renderer->normaliseTheme($themeOverrides[$key])
                    : null,
            ];
        }

        $brandTemplate = $renderer->normaliseTheme(is_array($settings['email_theme'] ?? null) ? $settings['email_theme'] : []);

        return new \WP_REST_Response(['data' => [
            'notifications' => $notifications,
            'theme' => $brandTemplate,
            'brand_template' => $brandTemplate,
            'fluentcrm_available' => $fluentCrmAvailable,
        ]]);
    }

    public static function saveEmailNotification(\WP_REST_Request $request): \WP_REST_Response
    {
        $data = $request->get_json_params();
        $key = sanitize_text_field((string) ($request->get_param('key') ?? $data['key'] ?? ''));
        $definition = NotificationCatalog::get($key);
        if (!$definition) {
            return new \WP_REST_Response(['message' => __('Unknown email notification type.', 'fchub-memberships')], 404);
        }

        $renderer = new NotificationTemplateRenderer();
        $template = $renderer->normaliseTemplate($key, $data['template'] ?? null);
        $settings = self::getSettings();
        $theme = $renderer->normaliseTheme(is_array($settings['email_theme'] ?? null) ? $settings['email_theme'] : []);
        if (isset($data['theme']) && is_array($data['theme'])) {
            $theme = $renderer->normaliseTheme($data['theme']);
        }
        $themeOverride = isset($data['theme_override']) && is_array($data['theme_override'])
            ? $renderer->normaliseTheme(array_replace($theme, $data['theme_override']))
            : null;
        $delivery = in_array(($data['delivery'] ?? ''), ['built_in', 'fluentcrm', 'off'], true)
            ? $data['delivery']
            : 'built_in';

        if ($delivery === 'fluentcrm' && !self::fluentCrmAvailable()) {
            return new \WP_REST_Response([
                'message' => __('FluentCRM is not available. Keep this email built in or turn it off.', 'fchub-memberships'),
            ], 422);
        }

        $settings['email_templates'] = is_array($settings['email_templates'] ?? null) ? $settings['email_templates'] : [];
        $settings['email_templates'][$key] = $template;
        if (isset($data['theme']) && is_array($data['theme'])) {
            $settings['email_theme'] = $theme;
        }
        $settings['email_theme_overrides'] = is_array($settings['email_theme_overrides'] ?? null)
            ? $settings['email_theme_overrides']
            : [];
        if ($themeOverride !== null) {
            $settings['email_theme_overrides'][$key] = $themeOverride;
        } else {
            unset($settings['email_theme_overrides'][$key]);
        }
        $settings['email_delivery'] = is_array($settings['email_delivery'] ?? null) ? $settings['email_delivery'] : [];
        $settings['email_delivery'][$key] = $delivery;
        $settings[$definition['setting_key']] = $delivery === 'built_in' ? 'yes' : 'no';
        update_option('fchub_memberships_settings', $settings);

        return new \WP_REST_Response([
            'data' => [
                'key' => $key,
                'template' => $template,
                'theme' => $theme,
                'theme_override' => $themeOverride,
                'delivery' => $delivery,
            ],
            'message' => __('Email notification saved.', 'fchub-memberships'),
        ]);
    }

    public static function saveEmailBrandTemplate(\WP_REST_Request $request): \WP_REST_Response
    {
        $data = $request->get_json_params();
        if (!isset($data['theme']) || !is_array($data['theme'])) {
            return new \WP_REST_Response([
                'message' => __('A valid brand template is required.', 'fchub-memberships'),
            ], 422);
        }

        $theme = (new NotificationTemplateRenderer())->normaliseTheme($data['theme']);
        $settings = self::getSettings();
        $settings['email_theme'] = $theme;
        update_option('fchub_memberships_settings', $settings);

        return new \WP_REST_Response([
            'data' => ['brand_template' => $theme],
            'message' => __('Email brand template saved.', 'fchub-memberships'),
        ]);
    }

    public static function previewEmailNotification(\WP_REST_Request $request): \WP_REST_Response
    {
        $data = $request->get_json_params();
        $key = sanitize_text_field((string) ($data['key'] ?? ''));
        if (!NotificationCatalog::get($key)) {
            return new \WP_REST_Response(['message' => __('Unknown email notification type.', 'fchub-memberships')], 404);
        }

        $renderer = new NotificationTemplateRenderer();
        $result = $renderer->compose(
            $key,
            $data['template'] ?? null,
            NotificationCatalog::sampleCodes($key),
            is_array($data['theme'] ?? null) ? $data['theme'] : null,
            is_array($data['theme_override'] ?? null) ? $data['theme_override'] : null
        );

        return new \WP_REST_Response(['data' => [
            'subject' => $result['subject'],
            'preheader' => $result['preheader'],
            'html' => $result['html'],
        ]]);
    }

    public static function testEmailNotification(\WP_REST_Request $request): \WP_REST_Response
    {
        $data = $request->get_json_params();
        $to = filter_var((string) ($data['to'] ?? ''), FILTER_VALIDATE_EMAIL);
        if (!$to) {
            return new \WP_REST_Response(['message' => __('Enter a valid test email address.', 'fchub-memberships')], 422);
        }

        $preview = self::previewEmailNotification($request);
        if ($preview->get_status() !== 200) {
            return $preview;
        }

        $message = $preview->get_data()['data'];
        $sent = wp_mail($to, $message['subject'], $message['html'], ['Content-Type: text/html; charset=UTF-8']);

        return new \WP_REST_Response([
            'data' => ['sent' => (bool) $sent, 'to' => $to],
            'message' => $sent
                ? __('Test email sent.', 'fchub-memberships')
                : __('WordPress could not send the test email.', 'fchub-memberships'),
        ], $sent ? 200 : 502);
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
            'restriction_message_paused'           => __('Your membership is currently paused. Resume your membership to access this content.', 'fchub-memberships'),
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
            'email_theme'                         => [],
            'email_theme_overrides'               => [],
            'email_delivery'                      => [],
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

    private static function fluentCrmAvailable(): bool
    {
        return defined('FLUENTCRM_PLUGIN_VERSION') || defined('FLUENTCRM');
    }
}

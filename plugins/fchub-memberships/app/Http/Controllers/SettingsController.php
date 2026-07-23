<?php

namespace FChubMemberships\Http\Controllers;

use FChubMemberships\Email\NotificationCatalog;
use FChubMemberships\Email\NotificationTemplateRenderer;
use FChubMemberships\Http\AccessApiCredential;
use FChubMemberships\Integration\MembershipSettingsOptionCoordinator;
use FChubMemberships\Integration\WebhookEndpointPolicy;
use FChubMemberships\Integration\WebhookSecret;
use FChubMemberships\Storage\WebhookDeliveryRepository;
use FChubMemberships\Support\MembershipSettingsSchema;
use FChubMemberships\Support\Migrations;

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

        register_rest_route($ns, '/admin/settings/revoke-api-key', [
            'methods'             => 'POST',
            'callback'            => [self::class, 'revokeApiKey'],
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
        $settings = self::publicSettings(self::getSettings());
        return new \WP_REST_Response(['data' => $settings]);
    }

    public static function save(\WP_REST_Request $request): \WP_REST_Response
    {
        $submitted = $request->get_json_params();
        if (!is_array($submitted)) {
            return self::settingsValidationFailureResponse(self::invalidSetting('request'));
        }

        $data = array_intersect_key(
            $submitted,
            array_flip(self::globalInputKeys())
        );
        if ($data === []) {
            return self::settingsValidationFailureResponse(self::invalidSetting('request'));
        }

        $validated = self::validateGlobalSettingsPayload($data);
        if ($validated instanceof \WP_Error) {
            return self::settingsValidationFailureResponse($validated);
        }
        $normalisedSimple = $validated['simple'];

        $webhookError = null;
        $policy = new WebhookEndpointPolicy();
        $result = (new MembershipSettingsOptionCoordinator())->mutate(static function (array $settings) use (
            $data,
            $normalisedSimple,
            $policy,
            &$webhookError
        ): array {
            $original = $settings;

            foreach ($normalisedSimple as $field => $value) {
                if ($field === 'webhook_enabled') {
                    continue;
                }
                $settings[$field] = $value;
            }

            // Native email studio. Every value is normalised before persistence because
            // the global settings endpoint can be called without using the visual editor.
            $renderer = new NotificationTemplateRenderer();
            if (array_key_exists('email_templates', $data)) {
                $templates = is_array($settings['email_templates'] ?? null)
                    ? $settings['email_templates']
                    : [];
                foreach (NotificationCatalog::all() as $key => $definition) {
                    if (array_key_exists($key, $data['email_templates'])) {
                        $templates[$key] = $renderer->normaliseTemplate($key, $data['email_templates'][$key]);
                    }
                }
                $settings['email_templates'] = $templates;
            }
            if (array_key_exists('email_theme', $data)) {
                $settings['email_theme'] = $renderer->normaliseTheme($data['email_theme']);
            }
            if (array_key_exists('email_delivery', $data)) {
                $deliveries = is_array($settings['email_delivery'] ?? null)
                    ? $settings['email_delivery']
                    : [];
                foreach (NotificationCatalog::all() as $key => $definition) {
                    if (!array_key_exists($key, $data['email_delivery'])) {
                        continue;
                    }
                    $delivery = $data['email_delivery'][$key];
                    if ($delivery === 'fluentcrm' && !self::fluentCrmAvailable()) {
                        $delivery = 'built_in';
                    }
                    $deliveries[$key] = $delivery;
                    $settings[$definition['setting_key']] = $delivery === 'built_in' ? 'yes' : 'no';
                }
                $settings['email_delivery'] = $deliveries;
            }

            // Webhook configuration is server-authoritative. Invalid submissions
            // reject the complete settings mutation rather than partially saving it.
            $effectiveWebhookState = $normalisedSimple['webhook_enabled']
                ?? (($settings['webhook_enabled'] ?? 'no') === 'yes' ? 'yes' : 'no');
            if (array_key_exists('webhook_urls', $data)) {
                $rawUrls = $data['webhook_urls'];
                $validation = $policy->validate($rawUrls);
                if ($validation instanceof \WP_Error) {
                    $storedUrls = (string) ($settings['webhook_urls'] ?? '');
                    $isDisabledLegacyRoundTrip = $effectiveWebhookState === 'no'
                        && $policy->equivalent($rawUrls, $storedUrls);
                    if (!$isDisabledLegacyRoundTrip) {
                        $webhookError = $validation;
                        return $original;
                    }
                } else {
                    $settings['webhook_urls'] = implode("\n", $policy->normalise($rawUrls));
                }
            }

            if (array_key_exists('webhook_urls', $data) || array_key_exists('webhook_enabled', $data)) {
                $effectiveUrls = (string) ($settings['webhook_urls'] ?? '');
                $effectiveValidation = $policy->validate($effectiveUrls);
                if ($effectiveWebhookState === 'yes'
                    && ($effectiveValidation instanceof \WP_Error
                        || $policy->normalise($effectiveUrls) === []
                        || empty($settings['webhook_secret']))
                ) {
                    $webhookError = new \WP_Error(
                        'fchub_webhook_not_ready',
                        __('Configure a safe webhook destination and generate a signing secret before enabling webhooks.', 'fchub-memberships'),
                        ['status' => 422]
                    );
                    return $original;
                }
            }
            if (array_key_exists('webhook_enabled', $data)) {
                $settings['webhook_enabled'] = $effectiveWebhookState;
            }

            // FluentCommunity mappings
            if (array_key_exists('fc_space_mappings', $data)) {
                $settings['fc_space_mappings'] = array_map(
                    static fn(string|int $value): string => sanitize_text_field((string) $value),
                    $data['fc_space_mappings']
                );
            }
            return $settings;
        });

        if ($webhookError instanceof \WP_Error) {
            return self::webhookValidationFailureResponse($webhookError);
        }

        if (!$result['success']) {
            return self::settingsWriteFailureResponse($result['reason'] ?? 'write_failed');
        }

        $settings = self::publicSettings(wp_parse_args($result['settings'], self::getSettings()));

        return new \WP_REST_Response([
            'data'    => $settings,
            'message' => __('Settings saved.', 'fchub-memberships'),
        ]);
    }

    public static function generateApiKey(\WP_REST_Request $request): \WP_REST_Response
    {
        $credential = AccessApiCredential::generate();
        $result = (new MembershipSettingsOptionCoordinator())->mutate(static function (array $settings) use ($credential): array {
            $settings = AccessApiCredential::revoke($settings);
            $settings['access_api_key_hash'] = $credential['hash'];
            $settings['access_api_key_prefix'] = $credential['prefix'];
            $settings['access_api_key_rotated_at'] = $credential['rotated_at'];
            return $settings;
        });
        if (!$result['success']) {
            return self::settingsWriteFailureResponse($result['reason'] ?? 'write_failed');
        }

        return new \WP_REST_Response([
            'data'    => [
                'api_key' => $credential['secret'],
                'access_api' => AccessApiCredential::metadata($result['settings']),
            ],
            'message' => __('API key generated.', 'fchub-memberships'),
        ]);
    }

    public static function revokeApiKey(\WP_REST_Request $request): \WP_REST_Response
    {
        $result = (new MembershipSettingsOptionCoordinator())->mutate(
            static fn(array $settings): array => AccessApiCredential::revoke($settings)
        );
        if (!$result['success']) {
            return self::settingsWriteFailureResponse($result['reason'] ?? 'write_failed');
        }

        return new \WP_REST_Response([
            'data' => ['access_api' => AccessApiCredential::metadata($result['settings'])],
            'message' => __('API key revoked.', 'fchub-memberships'),
        ]);
    }

    public static function regenerateWebhookSecret(\WP_REST_Request $request): \WP_REST_Response
    {
        $coordinator = new MembershipSettingsOptionCoordinator();
        $result = $coordinator->synchronized(static function (
            MembershipSettingsOptionCoordinator $coordinator
        ): array {
            $settings = $coordinator->read();
            if (!self::webhookDeliveryStorageReady()) {
                return ['status' => 'unavailable'];
            }

            try {
                $summary = (new WebhookDeliveryRepository())->summary();
            } catch (\Throwable) {
                return ['status' => 'unavailable'];
            }

            $blockingCount = (int) ($summary['pending'] ?? 0)
                + (int) ($summary['processing'] ?? 0)
                + (int) ($summary['retrying'] ?? 0);
            if ($blockingCount > 0) {
                return ['status' => 'blocked', 'blocking_count' => $blockingCount];
            }

            $secret = WebhookSecret::generate();
            $next = $settings;
            $next['webhook_secret'] = $secret;
            $swapped = $coordinator->compareAndSwap($settings, $next);
            $storedSecret = $swapped['settings']['webhook_secret'] ?? null;
            if (!$swapped['success']
                || !is_string($storedSecret)
                || !hash_equals($secret, $storedSecret)
            ) {
                return ['status' => 'unavailable'];
            }

            return ['status' => 'rotated', 'secret' => $secret];
        });

        if (!$result['success'] || !is_array($result['value'] ?? null)) {
            return self::webhookRotationUnavailableResponse();
        }

        $outcome = $result['value'];
        if (($outcome['status'] ?? '') === 'blocked') {
            return new \WP_REST_Response([
                'code' => 'fchub_webhook_rotation_blocked',
                'message' => __('Webhook secret rotation is blocked while deliveries are active.', 'fchub-memberships'),
                'data' => ['blocking_count' => (int) ($outcome['blocking_count'] ?? 0)],
            ], 409);
        }
        if (($outcome['status'] ?? '') !== 'rotated' || !is_string($outcome['secret'] ?? null)) {
            return self::webhookRotationUnavailableResponse();
        }

        return new \WP_REST_Response([
            'data'    => ['webhook_secret' => $outcome['secret']],
            'message' => __('Webhook secret regenerated.', 'fchub-memberships'),
        ]);
    }

    public static function testWebhook(\WP_REST_Request $request): \WP_REST_Response
    {
        return WebhookController::test($request);
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
        $delivery = in_array(($data['delivery'] ?? ''), ['built_in', 'fluentcrm', 'off'], true)
            ? $data['delivery']
            : 'built_in';

        if ($delivery === 'fluentcrm' && !self::fluentCrmAvailable()) {
            return new \WP_REST_Response([
                'message' => __('FluentCRM is not available. Keep this email built in or turn it off.', 'fchub-memberships'),
            ], 422);
        }

        $theme = [];
        $themeOverride = null;
        $result = (new MembershipSettingsOptionCoordinator())->mutate(static function (array $settings) use (
            $data,
            $definition,
            $delivery,
            $key,
            $renderer,
            $template,
            &$theme,
            &$themeOverride
        ): array {
            $theme = $renderer->normaliseTheme(is_array($settings['email_theme'] ?? null) ? $settings['email_theme'] : []);
            if (isset($data['theme']) && is_array($data['theme'])) {
                $theme = $renderer->normaliseTheme($data['theme']);
            }
            $themeOverride = isset($data['theme_override']) && is_array($data['theme_override'])
                ? $renderer->normaliseTheme(array_replace($theme, $data['theme_override']))
                : null;

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
            return $settings;
        });
        if (!$result['success']) {
            return self::settingsWriteFailureResponse($result['reason'] ?? 'write_failed');
        }

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
        $result = (new MembershipSettingsOptionCoordinator())->mutate(static function (array $settings) use ($theme): array {
            $settings['email_theme'] = $theme;
            return $settings;
        });
        if (!$result['success']) {
            return self::settingsWriteFailureResponse($result['reason'] ?? 'write_failed');
        }

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
        $settings = get_option('fchub_memberships_settings', []);
        return wp_parse_args($settings, MembershipSettingsSchema::defaults());
    }

    /**
     * @return list<string>
     */
    public static function globalInputKeys(): array
    {
        return MembershipSettingsSchema::globalInputKeys();
    }

    public static function adminPermission(): bool
    {
        return current_user_can('manage_options');
    }

    /**
     * @param array<string, mixed> $data
     * @return array{simple: array<string, mixed>}|\WP_Error
     */
    private static function validateGlobalSettingsPayload(array $data): array|\WP_Error
    {
        $normalisedSimple = [];
        foreach (MembershipSettingsSchema::simpleGlobalInputs() as $field => $definition) {
            if (!array_key_exists($field, $data)) {
                continue;
            }

            $normalised = MembershipSettingsSchema::normaliseSimpleInput($field, $data[$field]);
            if (!$normalised['accepted']) {
                return self::invalidSetting($field);
            }
            $normalisedSimple[$field] = $normalised['value'];
        }

        $hasEffectiveInput = $normalisedSimple !== [];
        $catalog = NotificationCatalog::all();

        if (array_key_exists('email_templates', $data)) {
            if (!is_array($data['email_templates'])) {
                return self::invalidSetting('email_templates');
            }
            $knownCount = 0;
            foreach ($data['email_templates'] as $key => $template) {
                if (!array_key_exists((string) $key, $catalog)) {
                    continue;
                }
                $knownCount++;
                if (!is_array($template) && !is_string($template)) {
                    return self::invalidSetting('email_templates');
                }
            }
            if ($data['email_templates'] !== [] && $knownCount === 0) {
                return self::invalidSetting('email_templates');
            }
            $hasEffectiveInput = $hasEffectiveInput || $knownCount > 0;
        }

        if (array_key_exists('email_theme', $data)) {
            if (!is_array($data['email_theme'])) {
                return self::invalidSetting('email_theme');
            }
            foreach ($data['email_theme'] as $value) {
                if (!is_scalar($value) && $value !== null) {
                    return self::invalidSetting('email_theme');
                }
            }
            $hasEffectiveInput = true;
        }

        if (array_key_exists('email_delivery', $data)) {
            if (!is_array($data['email_delivery'])) {
                return self::invalidSetting('email_delivery');
            }
            $knownCount = 0;
            foreach ($data['email_delivery'] as $key => $delivery) {
                if (!array_key_exists((string) $key, $catalog)) {
                    continue;
                }
                $knownCount++;
                if (!in_array($delivery, ['built_in', 'fluentcrm', 'off'], true)) {
                    return self::invalidSetting('email_delivery');
                }
            }
            if ($data['email_delivery'] !== [] && $knownCount === 0) {
                return self::invalidSetting('email_delivery');
            }
            $hasEffectiveInput = $hasEffectiveInput || $knownCount > 0;
        }

        if (array_key_exists('fc_space_mappings', $data)) {
            if (!is_array($data['fc_space_mappings'])) {
                return self::invalidSetting('fc_space_mappings');
            }
            foreach ($data['fc_space_mappings'] as $value) {
                if (!is_string($value) && !is_int($value)) {
                    return self::invalidSetting('fc_space_mappings');
                }
            }
            $hasEffectiveInput = true;
        }

        if (array_key_exists('webhook_urls', $data)) {
            if (!is_string($data['webhook_urls'])) {
                return self::invalidSetting('webhook_urls');
            }
            $hasEffectiveInput = true;
        }

        if (!$hasEffectiveInput) {
            return self::invalidSetting('request');
        }

        return ['simple' => $normalisedSimple];
    }

    private static function invalidSetting(string $field): \WP_Error
    {
        return new \WP_Error(
            'fchub_invalid_settings',
            __('One or more Memberships settings are invalid.', 'fchub-memberships'),
            ['status' => 422, 'field' => $field]
        );
    }

    private static function settingsValidationFailureResponse(\WP_Error $error): \WP_REST_Response
    {
        $errorData = (array) $error->get_error_data();

        return new \WP_REST_Response([
            'code' => $error->get_error_code(),
            'message' => $error->get_error_message(),
            'data' => ['field' => $errorData['field'] ?? null],
        ], (int) ($errorData['status'] ?? 422));
    }

    private static function fluentCrmAvailable(): bool
    {
        return defined('FLUENTCRM_PLUGIN_VERSION') || defined('FLUENTCRM');
    }

    private static function publicSettings(array $settings): array
    {
        $public = array_intersect_key($settings, array_flip(MembershipSettingsSchema::publicKeys()));
        $public['access_api'] = AccessApiCredential::metadata($settings);
        $public = array_merge($public, WebhookSecret::metadata($settings));
        $public['webhook_destinations_configured'] = self::webhookDestinationsConfigured($settings);
        $public['webhook_status'] = self::webhookStatus($settings);

        return $public;
    }

    private static function webhookStatus(array $settings): string
    {
        $rawUrls = (string) ($settings['webhook_urls'] ?? '');
        $ready = self::webhookDestinationsConfigured($settings)
            && !empty($settings['webhook_secret']);

        if (($settings['webhook_enabled'] ?? 'no') === 'yes') {
            return $ready ? 'ready' : 'needs_setup';
        }

        return trim($rawUrls) !== '' && !$ready ? 'needs_setup' : 'off';
    }

    private static function webhookDestinationsConfigured(array $settings): bool
    {
        $rawUrls = (string) ($settings['webhook_urls'] ?? '');
        $policy = new WebhookEndpointPolicy();

        return $policy->validate($rawUrls) === true
            && $policy->normalise($rawUrls) !== [];
    }

    private static function webhookValidationFailureResponse(\WP_Error $error): \WP_REST_Response
    {
        return new \WP_REST_Response([
            'code' => $error->get_error_code(),
            'message' => $error->get_error_message(),
        ], (int) ($error->get_error_data()['status'] ?? 422));
    }

    private static function settingsWriteFailureResponse(string $reason): \WP_REST_Response
    {
        return new \WP_REST_Response([
            'message' => __('Membership settings could not be saved. Please retry.', 'fchub-memberships'),
            'data' => ['reason' => $reason],
        ], 503);
    }

    private static function webhookDeliveryStorageReady(): bool
    {
        if (version_compare((string) get_option('fchub_memberships_db_version', '0'), '1.8.0', '<')) {
            return false;
        }

        return Migrations::verifyWebhookSchema() === [];
    }

    private static function webhookRotationUnavailableResponse(): \WP_REST_Response
    {
        return new \WP_REST_Response([
            'code' => 'fchub_webhook_storage_unavailable',
            'message' => __('Webhook storage is unavailable. Please retry after the database is ready.', 'fchub-memberships'),
        ], 503);
    }
}

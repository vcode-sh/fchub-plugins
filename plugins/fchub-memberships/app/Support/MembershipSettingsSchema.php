<?php

namespace FChubMemberships\Support;

defined('ABSPATH') || exit;

final class MembershipSettingsSchema
{
    public const ACTIVE = 'active';
    public const LEGACY_READ_ONLY = 'legacy_read_only';
    public const REMOVED = 'removed';

    /**
     * @return array<string, array{
     *     classification: string,
     *     consumer: string,
     *     global_input?: bool,
     *     public?: bool,
     *     input?: string,
     *     allowed?: list<string>
     * }>
     */
    public static function definitions(): array
    {
        return [
            'default_protection_mode' => self::active(
                'ContentProtection',
                'enum',
                ['content_replace', 'redirect', '403']
            ),
            'default_redirect_url' => self::active('AccessEvaluator', 'url'),
            'admin_bypass' => self::active('AccessEvaluator', 'toggle'),
            'auto_create_user' => self::active('MembershipAccessIntegration', 'toggle'),
            'membership_mode' => self::active(
                'MembershipModeService',
                'enum',
                ['stack', 'exclusive', 'upgrade_only']
            ),
            'restriction_message_logged_out' => self::active('AccessEvaluator', 'textarea'),
            'restriction_message_no_access' => self::active('AccessEvaluator', 'textarea'),
            'restriction_message_drip_locked' => self::active('AccessEvaluator', 'textarea'),
            'restriction_message_paused' => self::active('AccessEvaluator', 'textarea'),
            'hide_protected_in_archive' => self::active('ContentProtection', 'toggle'),
            'debug_mode' => self::active('Logger', 'toggle'),
            'email_access_granted' => self::active('GrantNotificationService', 'toggle'),
            'email_access_expiring' => self::active('AccessExpiringEmail', 'toggle'),
            'email_access_revoked' => self::active('GrantNotificationService', 'toggle'),
            'email_drip_unlocked' => self::active('DripScheduleService', 'toggle'),
            'email_membership_paused' => self::active('MembershipPausedEmail', 'toggle'),
            'email_membership_resumed' => self::active('MembershipResumedEmail', 'toggle'),
            'email_trial_expiring' => self::active('TrialExpiringEmail', 'toggle'),
            'email_trial_converted' => self::active('TrialConvertedEmail', 'toggle'),
            'trial_expiry_notice_days' => self::active('TrialLifecycleService', 'non_negative_int'),
            'expiry_warning_days' => self::active('AccessExpiringEmail', 'non_negative_int'),
            'uninstall_remove_data' => self::active('uninstall.php', 'toggle'),
            'email_templates' => self::active('NotificationEmailComposer', 'structured'),
            'email_theme' => self::active('NotificationTemplateRenderer', 'structured'),
            'email_theme_overrides' => self::managed('NotificationTemplateRenderer'),
            'email_delivery' => self::active('NotificationEmailComposer', 'structured'),
            'fluentcrm_enabled' => self::active('FluentCrmSync', 'toggle'),
            'fluentcrm_tag_prefix' => self::active('MembershipContactProjector', 'text'),
            'fluentcrm_default_list' => self::active('MembershipContactProjector', 'text'),
            'fluentcrm_auto_create_tags' => self::active('MembershipContactProjector', 'toggle'),
            'webhook_enabled' => self::active('WebhookDispatcher', 'toggle'),
            'webhook_urls' => self::active('WebhookDispatcher', 'structured'),
            'webhook_secret' => self::managed('WebhookSecret'),
            'access_api_key_hash' => self::managed('AccessApiCredential'),
            'access_api_key_prefix' => self::managed('AccessApiCredential'),
            'access_api_key_rotated_at' => self::managed('AccessApiCredential'),
            'fc_enabled' => self::active('FluentCommunitySync', 'toggle'),
            'fc_space_mappings' => self::active('FluentCommunitySync', 'structured'),
            'status' => self::managed('MembershipSettings'),

            'expiry_notice_days' => self::legacy('AccessExpiringEmail'),
            'restriction_message_membership_paused' => self::legacy('AccessEvaluator'),
            'api_key' => self::legacy('AccessApiCredential'),
            'account_page_id' => self::legacy('Account compatibility'),
            'pricing_page_url' => self::legacy('ContentProtection smart code'),
            'messages' => self::legacy('Content protection compatibility'),
            'inherit_comment_protection' => self::legacy('CommentProtection'),
            'default_comment_mode' => self::legacy('CommentProtection'),
            'comment_restriction_message' => self::legacy('CommentProtection'),
            'fc_badge_mappings' => self::legacy('FluentCommunity migration compatibility'),
            'fc_remove_badge_on_revoke' => self::legacy('FluentCommunity migration compatibility'),

            'default_redirect_url_unauthorized' => self::removed(),
            'no_access_page_id' => self::removed(),
            'restriction_message_expired' => self::removed(),
            'show_teaser' => self::removed(),
            'cron_validity_interval' => self::removed(),
            'cron_drip_interval' => self::removed(),
            'purge_expired_days' => self::removed(),
        ];
    }

    public static function classification(string $key): ?string
    {
        return self::definitions()[$key]['classification'] ?? null;
    }

    public static function consumer(string $key): string
    {
        return self::definitions()[$key]['consumer'] ?? '';
    }

    /**
     * @return list<string>
     */
    public static function globalInputKeys(): array
    {
        return array_keys(array_filter(
            self::definitions(),
            static fn(array $definition): bool => ($definition['global_input'] ?? false) === true
        ));
    }

    /**
     * @return list<string>
     */
    public static function publicKeys(): array
    {
        return array_keys(array_filter(
            self::definitions(),
            static fn(array $definition): bool => ($definition['public'] ?? false) === true
        ));
    }

    /**
     * @return array<string, array{
     *     classification: string,
     *     consumer: string,
     *     global_input?: bool,
     *     public?: bool,
     *     input?: string,
     *     allowed?: list<string>
     * }>
     */
    public static function simpleGlobalInputs(): array
    {
        return array_filter(
            self::definitions(),
            static fn(array $definition): bool =>
                ($definition['global_input'] ?? false) === true
                && in_array(
                    $definition['input'] ?? '',
                    ['enum', 'url', 'text', 'textarea', 'toggle', 'non_negative_int'],
                    true
                )
        );
    }

    /**
     * @return array{accepted: bool, value?: mixed}
     */
    public static function normaliseSimpleInput(string $key, mixed $value): array
    {
        $definition = self::simpleGlobalInputs()[$key] ?? null;
        if ($definition === null) {
            return ['accepted' => false];
        }

        $input = $definition['input'];
        if ($input === 'enum') {
            $normalised = is_scalar($value) ? sanitize_text_field((string) $value) : '';
            return in_array($normalised, $definition['allowed'] ?? [], true)
                ? ['accepted' => true, 'value' => $normalised]
                : ['accepted' => false];
        }
        if ($input === 'toggle') {
            return in_array($value, ['yes', 'no'], true)
                ? ['accepted' => true, 'value' => $value]
                : ['accepted' => false];
        }
        if ($input === 'non_negative_int') {
            if (!is_int($value) && !(is_string($value) && preg_match('/^\d+$/', $value) === 1)) {
                return ['accepted' => false];
            }
            $normalised = (int) $value;
            return $normalised >= 0 && $normalised <= 365
                ? ['accepted' => true, 'value' => $normalised]
                : ['accepted' => false];
        }
        if (!is_string($value)) {
            return ['accepted' => false];
        }
        if ($input === 'url') {
            return ['accepted' => true, 'value' => esc_url_raw((string) $value)];
        }
        if ($input === 'textarea') {
            return ['accepted' => true, 'value' => sanitize_textarea_field((string) $value)];
        }

        return ['accepted' => true, 'value' => sanitize_text_field((string) $value)];
    }

    /**
     * @return array<string, mixed>
     */
    public static function defaults(): array
    {
        return [
            'default_protection_mode' => 'content_replace',
            'default_redirect_url' => '',
            'admin_bypass' => 'yes',
            'auto_create_user' => 'yes',
            'membership_mode' => 'stack',
            'restriction_message_logged_out' => __(
                'This content is available to members only. Please log in to access.',
                'fchub-memberships'
            ),
            'restriction_message_no_access' => __(
                'You don\'t have access to this content. View membership options to learn more.',
                'fchub-memberships'
            ),
            'restriction_message_drip_locked' => __(
                'This content will be available to you on {unlock_date}.',
                'fchub-memberships'
            ),
            'restriction_message_paused' => __(
                'Your membership is currently paused. Resume your membership to access this content.',
                'fchub-memberships'
            ),
            'hide_protected_in_archive' => 'no',
            'debug_mode' => 'no',
            'email_access_granted' => 'yes',
            'email_access_expiring' => 'yes',
            'email_access_revoked' => 'yes',
            'email_drip_unlocked' => 'yes',
            'email_membership_paused' => 'yes',
            'email_membership_resumed' => 'yes',
            'email_trial_expiring' => 'yes',
            'email_trial_converted' => 'yes',
            'trial_expiry_notice_days' => 3,
            'expiry_warning_days' => 7,
            'uninstall_remove_data' => 'no',
            'email_templates' => [],
            'email_theme' => [],
            'email_theme_overrides' => [],
            'email_delivery' => [],
            'fluentcrm_enabled' => 'no',
            'fluentcrm_tag_prefix' => 'member:',
            'fluentcrm_default_list' => '',
            'fluentcrm_auto_create_tags' => 'yes',
            'webhook_enabled' => 'no',
            'webhook_urls' => '',
            'fc_enabled' => 'no',
            'fc_space_mappings' => [],
        ];
    }

    /**
     * @param list<string> $allowed
     * @return array{
     *     classification: string,
     *     consumer: string,
     *     global_input: true,
     *     public: true,
     *     input: string,
     *     allowed?: list<string>
     * }
     */
    private static function active(string $consumer, string $input, array $allowed = []): array
    {
        $definition = [
            'classification' => self::ACTIVE,
            'consumer' => $consumer,
            'global_input' => true,
            'public' => true,
            'input' => $input,
        ];
        if ($allowed !== []) {
            $definition['allowed'] = $allowed;
        }
        return $definition;
    }

    /**
     * @return array{classification: string, consumer: string}
     */
    private static function managed(string $consumer): array
    {
        return [
            'classification' => self::ACTIVE,
            'consumer' => $consumer,
        ];
    }

    /**
     * @return array{classification: string, consumer: string}
     */
    private static function legacy(string $consumer): array
    {
        return [
            'classification' => self::LEGACY_READ_ONLY,
            'consumer' => $consumer,
        ];
    }

    /**
     * @return array{classification: string, consumer: string}
     */
    private static function removed(): array
    {
        return [
            'classification' => self::REMOVED,
            'consumer' => '',
        ];
    }
}

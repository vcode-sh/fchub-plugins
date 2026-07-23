<?php

declare(strict_types=1);

namespace FChubMemberships\Tests\Unit\Support;

use FChubMemberships\Support\MembershipSettingsSchema;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MembershipSettingsSchemaTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function classificationProvider(): iterable
    {
        $active = [
            'default_protection_mode',
            'default_redirect_url',
            'admin_bypass',
            'auto_create_user',
            'membership_mode',
            'restriction_message_logged_out',
            'restriction_message_no_access',
            'restriction_message_drip_locked',
            'restriction_message_paused',
            'hide_protected_in_archive',
            'debug_mode',
            'email_access_granted',
            'email_access_expiring',
            'email_access_revoked',
            'email_drip_unlocked',
            'email_membership_paused',
            'email_membership_resumed',
            'email_trial_expiring',
            'email_trial_converted',
            'trial_expiry_notice_days',
            'expiry_warning_days',
            'uninstall_remove_data',
            'email_templates',
            'email_theme',
            'email_theme_overrides',
            'email_delivery',
            'fluentcrm_enabled',
            'fluentcrm_tag_prefix',
            'fluentcrm_default_list',
            'fluentcrm_auto_create_tags',
            'webhook_enabled',
            'webhook_urls',
            'webhook_secret',
            'access_api_key_hash',
            'access_api_key_prefix',
            'access_api_key_rotated_at',
            'fc_enabled',
            'fc_space_mappings',
            'status',
        ];
        foreach ($active as $key) {
            yield $key => [$key, MembershipSettingsSchema::ACTIVE];
        }

        $legacyReadOnly = [
            'expiry_notice_days',
            'restriction_message_membership_paused',
            'api_key',
            'account_page_id',
            'pricing_page_url',
            'messages',
            'inherit_comment_protection',
            'default_comment_mode',
            'comment_restriction_message',
            'fc_badge_mappings',
            'fc_remove_badge_on_revoke',
        ];
        foreach ($legacyReadOnly as $key) {
            yield $key => [$key, MembershipSettingsSchema::LEGACY_READ_ONLY];
        }

        $removed = [
            'default_redirect_url_unauthorized',
            'no_access_page_id',
            'restriction_message_expired',
            'show_teaser',
            'cron_validity_interval',
            'cron_drip_interval',
            'purge_expired_days',
        ];
        foreach ($removed as $key) {
            yield $key => [$key, MembershipSettingsSchema::REMOVED];
        }
    }

    #[DataProvider('classificationProvider')]
    public function test_every_known_key_has_its_reconciled_classification(
        string $key,
        string $classification
    ): void {
        self::assertSame($classification, MembershipSettingsSchema::classification($key));
    }

    public function test_every_global_input_is_active_and_has_a_named_consumer(): void
    {
        foreach (MembershipSettingsSchema::globalInputKeys() as $key) {
            self::assertSame(MembershipSettingsSchema::ACTIVE, MembershipSettingsSchema::classification($key), $key);
            self::assertNotSame('', MembershipSettingsSchema::consumer($key), $key);
        }
    }

    public function test_every_active_or_public_key_has_a_named_production_consumer(): void
    {
        $publicKeys = array_flip(MembershipSettingsSchema::publicKeys());
        foreach (MembershipSettingsSchema::definitions() as $key => $definition) {
            self::assertContains(
                $definition['classification'],
                [
                    MembershipSettingsSchema::ACTIVE,
                    MembershipSettingsSchema::LEGACY_READ_ONLY,
                    MembershipSettingsSchema::REMOVED,
                ],
                $key
            );
            if ($definition['classification'] === MembershipSettingsSchema::ACTIVE) {
                self::assertNotSame('', $definition['consumer'], $key);
            }
            if (isset($publicKeys[$key])) {
                self::assertSame(MembershipSettingsSchema::ACTIVE, $definition['classification'], $key);
            }
        }
    }

    public function test_unknown_keys_are_not_silently_classified(): void
    {
        self::assertNull(MembershipSettingsSchema::classification('future_unclassified_key'));
    }
}

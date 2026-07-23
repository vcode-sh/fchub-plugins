<?php

declare(strict_types=1);

namespace FChubMemberships\Tests\Unit\Http\Controllers;

use FChubMemberships\Http\Controllers\SettingsController;
use FChubMemberships\Support\MembershipSettingsSchema;
use FChubMemberships\Tests\Unit\PluginTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class SettingsControllerSchemaTest extends PluginTestCase
{
    public function test_legacy_removed_unknown_and_secret_values_are_preserved_but_not_public(): void
    {
        $preserved = [
            'expiry_notice_days' => 11,
            'restriction_message_membership_paused' => 'Legacy paused message',
            'fc_badge_mappings' => ['4' => 29],
            'fc_remove_badge_on_revoke' => 'yes',
            'cron_validity_interval' => 41,
            'show_teaser' => 'yes',
            'future_setting' => ['keep' => true],
            'webhook_secret' => 'server-owned-secret',
            'access_api_key_hash' => 'server-owned-hash',
        ];
        $GLOBALS['_fchub_test_options']['fchub_memberships_settings'] = $preserved + [
            'debug_mode' => 'no',
        ];

        $response = SettingsController::save(new \WP_REST_Request('POST', '/settings', [
            'debug_mode' => 'yes',
            'expiry_notice_days' => 2,
            'restriction_message_membership_paused' => 'Submitted legacy value',
            'fc_badge_mappings' => ['4' => 99],
            'fc_remove_badge_on_revoke' => 'no',
            'cron_validity_interval' => 5,
            'show_teaser' => 'no',
            'future_setting' => 'submitted replacement',
            'webhook_secret' => 'submitted secret',
            'access_api_key_hash' => 'submitted hash',
        ]));

        self::assertSame(200, $response->get_status());
        $stored = $GLOBALS['_fchub_test_options']['fchub_memberships_settings'];
        self::assertSame($preserved, array_intersect_key($stored, $preserved));
        self::assertSame('yes', $stored['debug_mode']);

        $public = $response->get_data()['data'];
        foreach (array_keys($preserved) as $key) {
            self::assertArrayNotHasKey($key, $public, $key);
        }
        self::assertSame('yes', $public['debug_mode']);
    }

    public function test_global_endpoint_accepts_exactly_the_schema_allowlist(): void
    {
        self::assertSame(
            MembershipSettingsSchema::globalInputKeys(),
            SettingsController::globalInputKeys()
        );
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function malformedSimpleInputProvider(): iterable
    {
        yield 'protection enum' => [['default_protection_mode' => 'execute_php']];
        yield 'membership enum' => [['membership_mode' => 'anything_goes']];
        yield 'toggle string' => [['admin_bypass' => 'sometimes']];
        yield 'toggle boolean' => [['debug_mode' => true]];
        yield 'negative expiry' => [['expiry_warning_days' => -1]];
        yield 'excessive expiry' => [['expiry_warning_days' => 366]];
        yield 'fractional expiry' => [['expiry_warning_days' => 3.5]];
        yield 'negative trial expiry' => [['trial_expiry_notice_days' => '-1']];
        yield 'excessive trial expiry' => [['trial_expiry_notice_days' => '366']];
    }

    #[DataProvider('malformedSimpleInputProvider')]
    public function test_invalid_simple_input_returns_422_without_mutating_any_option(array $payload): void
    {
        $before = [
            'default_protection_mode' => 'redirect',
            'membership_mode' => 'upgrade_only',
            'admin_bypass' => 'yes',
            'debug_mode' => 'no',
            'expiry_warning_days' => 7,
            'trial_expiry_notice_days' => 3,
            'future_setting' => ['preserve' => true],
            'webhook_secret' => 'preserve-secret',
        ];
        $GLOBALS['_fchub_test_options']['fchub_memberships_settings'] = $before;

        $response = SettingsController::save(new \WP_REST_Request('POST', '/settings', $payload + [
            'fluentcrm_tag_prefix' => 'must-not-save:',
        ]));

        self::assertSame(422, $response->get_status());
        self::assertSame('fchub_invalid_settings', $response->get_data()['code']);
        self::assertSame(array_key_first($payload), $response->get_data()['data']['field']);
        self::assertSame($before, $GLOBALS['_fchub_test_options']['fchub_memberships_settings']);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function malformedWebhookToggleProvider(): iterable
    {
        yield 'boolean false' => [false];
        yield 'boolean true' => [true];
        yield 'garbage' => ['disabled'];
        yield 'integer' => [0];
    }

    #[DataProvider('malformedWebhookToggleProvider')]
    public function test_webhook_enabled_requires_an_exact_yes_or_no_value(mixed $value): void
    {
        $before = [
            'webhook_enabled' => 'yes',
            'webhook_urls' => 'https://example.com/hook',
            'webhook_secret' => 'preserve-secret',
        ];
        $GLOBALS['_fchub_test_options']['fchub_memberships_settings'] = $before;

        $response = SettingsController::save(new \WP_REST_Request('POST', '/settings', [
            'webhook_enabled' => $value,
        ]));

        self::assertSame(422, $response->get_status());
        self::assertSame('webhook_enabled', $response->get_data()['data']['field']);
        self::assertSame($before, $GLOBALS['_fchub_test_options']['fchub_memberships_settings']);
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function malformedStructuredInputProvider(): iterable
    {
        yield 'templates top-level string' => [['email_templates' => 'invalid']];
        yield 'template entry scalar' => [['email_templates' => ['access_granted' => 42]]];
        yield 'theme top-level string' => [['email_theme' => 'invalid']];
        yield 'theme nested value' => [['email_theme' => ['primary_color' => ['invalid']]]];
        yield 'delivery top-level string' => [['email_delivery' => 'invalid']];
        yield 'delivery value' => [['email_delivery' => ['access_granted' => 'eventually']]];
        yield 'space mappings top-level string' => [['fc_space_mappings' => 'invalid']];
        yield 'space mapping nested value' => [['fc_space_mappings' => ['5' => ['invalid']]]];
        yield 'webhook URLs array' => [['webhook_urls' => ['https://example.com/hook']]];
    }

    #[DataProvider('malformedStructuredInputProvider')]
    public function test_invalid_structured_input_returns_422_atomically(array $payload): void
    {
        $before = [
            'debug_mode' => 'no',
            'email_templates' => ['access_revoked' => ['subject' => 'Preserved']],
            'email_delivery' => ['access_revoked' => 'off'],
            'future_setting' => 'preserve',
        ];
        $GLOBALS['_fchub_test_options']['fchub_memberships_settings'] = $before;

        $response = SettingsController::save(new \WP_REST_Request('POST', '/settings', $payload + [
            'debug_mode' => 'yes',
        ]));

        self::assertSame(422, $response->get_status());
        self::assertSame('fchub_invalid_settings', $response->get_data()['code']);
        self::assertSame(array_key_first($payload), $response->get_data()['data']['field']);
        self::assertSame($before, $GLOBALS['_fchub_test_options']['fchub_memberships_settings']);
    }

    public function test_partial_email_maps_merge_without_erasing_omitted_known_entries(): void
    {
        $preservedTemplate = [
            'version' => 1,
            'subject' => 'Your access ended',
            'preheader' => 'Preserved',
            'blocks' => [['id' => 'preserved', 'type' => 'rich_text', 'content' => '<p>Preserved</p>']],
        ];
        $GLOBALS['_fchub_test_options']['fchub_memberships_settings'] = [
            'email_templates' => ['access_revoked' => $preservedTemplate],
            'email_delivery' => [
                'access_revoked' => 'off',
                'access_expiring' => 'built_in',
            ],
            'email_access_revoked' => 'no',
            'email_access_expiring' => 'yes',
        ];

        $response = SettingsController::save(new \WP_REST_Request('POST', '/settings', [
            'email_templates' => [
                'access_granted' => [
                    'subject' => 'Welcome',
                    'preheader' => 'Hello',
                    'blocks' => [['id' => 'new', 'type' => 'rich_text', 'content' => '<p>New</p>']],
                ],
            ],
            'email_delivery' => ['access_granted' => 'off'],
        ]));

        self::assertSame(200, $response->get_status());
        $stored = SettingsController::getSettings();
        self::assertSame($preservedTemplate, $stored['email_templates']['access_revoked']);
        self::assertSame('Welcome', $stored['email_templates']['access_granted']['subject']);
        self::assertSame('off', $stored['email_delivery']['access_revoked']);
        self::assertSame('built_in', $stored['email_delivery']['access_expiring']);
        self::assertSame('off', $stored['email_delivery']['access_granted']);
        self::assertSame('no', $stored['email_access_revoked']);
        self::assertSame('yes', $stored['email_access_expiring']);
        self::assertSame('no', $stored['email_access_granted']);
    }

    public function test_dead_or_unknown_only_submission_is_rejected_but_mixed_unknown_is_ignored(): void
    {
        $before = [
            'debug_mode' => 'no',
            'show_teaser' => 'yes',
            'future_setting' => 'preserve',
        ];
        $GLOBALS['_fchub_test_options']['fchub_memberships_settings'] = $before;

        foreach ([
            [],
            ['show_teaser' => 'no'],
            ['future_setting' => 'replace'],
            ['show_teaser' => 'no', 'future_setting' => 'replace'],
        ] as $payload) {
            $response = SettingsController::save(new \WP_REST_Request('POST', '/settings', $payload));
            self::assertSame(422, $response->get_status());
            self::assertSame('request', $response->get_data()['data']['field']);
            self::assertSame($before, $GLOBALS['_fchub_test_options']['fchub_memberships_settings']);
        }

        $mixed = SettingsController::save(new \WP_REST_Request('POST', '/settings', [
            'debug_mode' => 'yes',
            'show_teaser' => 'no',
            'future_setting' => 'replace',
        ]));

        self::assertSame(200, $mixed->get_status());
        self::assertSame('yes', $GLOBALS['_fchub_test_options']['fchub_memberships_settings']['debug_mode']);
        self::assertSame('yes', $GLOBALS['_fchub_test_options']['fchub_memberships_settings']['show_teaser']);
        self::assertSame('preserve', $GLOBALS['_fchub_test_options']['fchub_memberships_settings']['future_setting']);
    }
}

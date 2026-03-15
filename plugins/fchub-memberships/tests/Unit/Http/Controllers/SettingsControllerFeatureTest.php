<?php

declare(strict_types=1);

namespace FChubMemberships\Tests\Unit\Http\Controllers;

use FChubMemberships\Http\Controllers\SettingsController;
use FChubMemberships\Tests\Unit\PluginTestCase;

final class SettingsControllerFeatureTest extends PluginTestCase
{
    public function test_register_routes_get_save_and_secret_generation_cover_settings_controller(): void
    {
        SettingsController::registerRoutes();

        foreach ([
            'fchub-memberships/v1/admin/settings',
            'fchub-memberships/v1/admin/settings/generate-api-key',
            'fchub-memberships/v1/admin/settings/regenerate-webhook-secret',
            'fchub-memberships/v1/admin/settings/test-webhook',
        ] as $route) {
            self::assertArrayHasKey($route, $GLOBALS['_fchub_test_routes']);
        }

        $save = SettingsController::save(new \WP_REST_Request('POST', '/settings', [
            'default_protection_mode' => 'redirect',
            'default_redirect_url' => 'https://example.com/join',
            'membership_mode' => 'exclusive',
            'restriction_message_logged_out' => 'Log in',
            'restriction_message_no_access' => 'No access',
            'show_teaser' => 'yes',
            'debug_mode' => 'yes',
            'expiry_warning_days' => -5,
            'trial_expiry_notice_days' => 4,
            'fc_space_mappings' => ['5' => 'space-1'],
            'fc_badge_mappings' => ['5' => 'badge-1'],
            'webhook_urls' => "https://example.com/hook\nhttps://example.com/hook-two",
        ]))->get_data();

        $get = SettingsController::get(new \WP_REST_Request('GET', '/settings'))->get_data();
        $apiKey = SettingsController::generateApiKey(new \WP_REST_Request('POST', '/settings/generate-api-key'))->get_data();
        $secret = SettingsController::regenerateWebhookSecret(new \WP_REST_Request('POST', '/settings/regenerate-webhook-secret'))->get_data();

        self::assertSame('redirect', $save['data']['default_protection_mode']);
        self::assertSame('https://example.com/join', $save['data']['default_redirect_url']);
        self::assertSame('exclusive', $save['data']['membership_mode']);
        self::assertSame('yes', $save['data']['show_teaser']);
        self::assertSame('yes', $save['data']['debug_mode']);
        self::assertSame(0, $save['data']['expiry_warning_days']);
        self::assertSame(['5' => 'space-1'], $save['data']['fc_space_mappings']);
        self::assertSame(['5' => 'badge-1'], $save['data']['fc_badge_mappings']);
        self::assertSame($save['data']['default_redirect_url'], $get['data']['default_redirect_url']);
        self::assertSame(str_repeat('a', 40), $apiKey['data']['api_key']);
        self::assertSame(str_repeat('a', 40), $secret['data']['webhook_secret']);
    }

    public function test_test_webhook_reports_per_url_results(): void
    {
        $GLOBALS['_fchub_test_options']['fchub_memberships_settings'] = [
            'webhook_urls' => "https://example.com/hook\nhttps://example.com/hook-two",
            'webhook_secret' => 'secret',
        ];
        $GLOBALS['_fchub_test_remote_post_result'] = ['response' => ['code' => 204]];

        $response = SettingsController::testWebhook(new \WP_REST_Request('POST', '/settings/test-webhook'))->get_data();

        self::assertTrue($response['data']['success']);
        self::assertCount(2, $response['data']['results']);
        self::assertTrue($response['data']['results'][0]['success']);
        self::assertCount(2, $GLOBALS['_fchub_test_remote_posts']);
    }
}

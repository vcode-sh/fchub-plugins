<?php

declare(strict_types=1);

namespace FChubMemberships\Tests\Unit\Http\Controllers;

use FChubMemberships\Http\Controllers\SettingsController;
use FChubMemberships\Tests\Unit\PluginTestCase;

final class NotificationStudioControllerTest extends PluginTestCase
{
    public function test_routes_list_and_save_all_native_notification_templates(): void
    {
        SettingsController::registerRoutes();

        foreach ([
            'fchub-memberships/v1/admin/email-notifications',
            'fchub-memberships/v1/admin/email-notifications/(?P<key>[a-z_]+)',
            'fchub-memberships/v1/admin/email-notifications/preview',
            'fchub-memberships/v1/admin/email-notifications/test',
            'fchub-memberships/v1/admin/email-notifications/brand-template',
        ] as $route) {
            self::assertArrayHasKey($route, $GLOBALS['_fchub_test_routes']);
        }

        $index = SettingsController::emailNotifications(new \WP_REST_Request('GET', '/email-notifications'))
            ->get_data();

        self::assertCount(8, $index['data']['notifications']);
        self::assertSame(
            defined('FLUENTCRM_PLUGIN_VERSION') || defined('FLUENTCRM'),
            $index['data']['fluentcrm_available']
        );
        self::assertArrayHasKey('default_template', $index['data']['notifications'][0]);
        self::assertArrayHasKey('theme_override', $index['data']['notifications'][0]);
        self::assertArrayHasKey('brand_template', $index['data']);
        self::assertSame(
            'Welcome to {plan_name}!',
            $index['data']['notifications'][0]['default_template']['subject']
        );

        $save = SettingsController::saveEmailNotification(new \WP_REST_Request(
            'POST',
            '/email-notifications/access_granted',
            [
                'key' => 'access_granted',
                'template' => [
                    'subject' => 'Hello {user_name}',
                    'preheader' => 'Your access is active.',
                    'blocks' => [
                        ['id' => 'copy', 'type' => 'rich_text', 'content' => '<p>Welcome to {plan_name}</p>'],
                    ],
                ],
                'theme' => [
                    'primary_color' => '#7c3aed',
                    'background_color' => '#f5f3ff',
                    'content_width' => 640,
                ],
                'theme_override' => [
                    'primary_color' => '#db2777',
                    'header_style' => 'text',
                    'header_text' => 'Member Desk',
                ],
            ]
        ))->get_data();

        self::assertSame('Hello {user_name}', $save['data']['template']['subject']);
        self::assertSame('#7c3aed', SettingsController::getSettings()['email_theme']['primary_color']);
        self::assertSame('Hello {user_name}', SettingsController::getSettings()['email_templates']['access_granted']['subject']);
        self::assertSame('#db2777', SettingsController::getSettings()['email_theme_overrides']['access_granted']['primary_color']);
    }

    public function test_brand_template_is_saved_once_and_used_as_the_global_email_shell(): void
    {
        $response = SettingsController::saveEmailBrandTemplate(new \WP_REST_Request(
            'POST',
            '/email-notifications/brand-template',
            [
                'theme' => [
                    'header_style' => 'logo',
                    'logo_url' => 'https://example.com/brand.png',
                    'logo_width' => 144,
                    'header_background' => '#111827',
                    'panel_color' => '#ffffff',
                    'border_radius' => 18,
                    'content_padding' => 40,
                    'footer_html' => '<p>Questions? <a href="https://example.com/help">Contact us</a>.</p>',
                ],
            ]
        ));

        self::assertSame(200, $response->get_status());
        $theme = SettingsController::getSettings()['email_theme'];
        self::assertSame('logo', $theme['header_style']);
        self::assertSame(144, $theme['logo_width']);
        self::assertSame(18, $theme['border_radius']);
        self::assertStringContainsString('Contact us', $theme['footer_html']);

        $index = SettingsController::emailNotifications(new \WP_REST_Request('GET', '/email-notifications'))
            ->get_data();
        self::assertSame('#111827', $index['data']['brand_template']['header_background']);
    }

    public function test_global_settings_save_sanitises_templates_theme_and_delivery_owners(): void
    {
        $response = SettingsController::save(new \WP_REST_Request('POST', '/admin/settings', [
            'email_templates' => [
                'access_granted' => [
                    'subject' => 'Hello {user_name}',
                    'preheader' => 'Ready',
                    'blocks' => [[
                        'id' => 'copy',
                        'type' => 'rich_text',
                        'content' => '<p>Safe</p><script>alert(1)</script>',
                    ], [
                        'id' => 'image',
                        'type' => 'image',
                        'url' => 'javascript:alert(1)',
                    ]],
                ],
                'unknown_event' => ['subject' => 'Ignore me'],
            ],
            'email_theme' => [
                'primary_color' => 'not-a-colour',
                'content_width' => 900,
                'logo_url' => 'javascript:alert(1)',
            ],
            'email_delivery' => [
                'access_granted' => 'off',
                'access_expiring' => 'fluentcrm',
                'unknown_event' => 'built_in',
            ],
        ]));

        self::assertSame(200, $response->get_status());
        $settings = SettingsController::getSettings();

        self::assertArrayHasKey('access_granted', $settings['email_templates']);
        self::assertArrayNotHasKey('unknown_event', $settings['email_templates']);
        self::assertStringNotContainsString('<script', $settings['email_templates']['access_granted']['blocks'][0]['content']);
        self::assertSame('', $settings['email_templates']['access_granted']['blocks'][1]['url']);
        self::assertSame('#2563eb', $settings['email_theme']['primary_color']);
        self::assertSame(680, $settings['email_theme']['content_width']);
        self::assertSame('', $settings['email_theme']['logo_url']);
        self::assertSame('off', $settings['email_delivery']['access_granted']);
        self::assertSame(
            defined('FLUENTCRM_PLUGIN_VERSION') || defined('FLUENTCRM') ? 'fluentcrm' : 'built_in',
            $settings['email_delivery']['access_expiring']
        );
        self::assertArrayNotHasKey('unknown_event', $settings['email_delivery']);
        self::assertSame('no', $settings['email_access_granted']);
        self::assertSame(
            defined('FLUENTCRM_PLUGIN_VERSION') || defined('FLUENTCRM') ? 'no' : 'yes',
            $settings['email_access_expiring']
        );
    }

    public function test_preview_and_test_send_use_the_saved_renderer_contract(): void
    {
        $payload = [
            'key' => 'access_granted',
            'template' => [
                'subject' => 'Welcome {user_name}',
                'preheader' => 'Membership ready',
                'blocks' => [
                    ['id' => 'heading', 'type' => 'heading', 'content' => 'Hello {user_name}'],
                    ['id' => 'button', 'type' => 'button', 'label' => 'Open account', 'url' => '{account_url}'],
                ],
            ],
        ];

        $preview = SettingsController::previewEmailNotification(
            new \WP_REST_Request('POST', '/email-notifications/preview', $payload)
        )->get_data();

        self::assertStringContainsString('Jamie Member', $preview['data']['html']);
        self::assertSame('Welcome Jamie Member', $preview['data']['subject']);

        $payload['to'] = 'owner@example.com';
        $test = SettingsController::testEmailNotification(
            new \WP_REST_Request('POST', '/email-notifications/test', $payload)
        )->get_data();

        self::assertTrue($test['data']['sent']);
        self::assertCount(1, $GLOBALS['_fchub_test_mails']);
        self::assertSame('owner@example.com', $GLOBALS['_fchub_test_mails'][0][0]);
        self::assertSame('Welcome Jamie Member', $GLOBALS['_fchub_test_mails'][0][1]);
    }
}

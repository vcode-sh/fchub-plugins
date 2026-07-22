<?php

declare(strict_types=1);

namespace FChubMemberships\Tests\Unit\Email;

use FChubMemberships\Email\NotificationCatalog;
use FChubMemberships\Email\NotificationTemplateRenderer;
use FChubMemberships\Email\AccessGrantedEmail;
use FChubMemberships\Tests\Unit\PluginTestCase;

final class NotificationTemplateRendererTest extends PluginTestCase
{
    public function test_catalog_exposes_every_native_notification_without_requiring_fluentcrm(): void
    {
        $notifications = NotificationCatalog::all();

        self::assertSame([
            'access_granted',
            'access_expiring',
            'access_revoked',
            'membership_paused',
            'membership_resumed',
            'trial_expiring',
            'trial_converted',
            'drip_content_unlocked',
        ], array_keys($notifications));
        self::assertSame('rich', $notifications['access_granted']['variables']['{resources_list}']['type']);
        self::assertSame('url', $notifications['access_granted']['variables']['{account_url}']['type']);
        self::assertFalse($notifications['access_granted']['requires_fluentcrm']);
    }

    public function test_renderer_uses_the_same_structured_template_for_preview_and_delivery(): void
    {
        $renderer = new NotificationTemplateRenderer();
        $template = [
            'subject' => 'Welcome to {plan_name}, {user_name}',
            'preheader' => 'Your membership is ready.',
            'blocks' => [
                ['id' => 'heading-1', 'type' => 'heading', 'content' => 'Welcome, {user_name}'],
                ['id' => 'copy-1', 'type' => 'rich_text', 'content' => '<p>Your <strong>{plan_name}</strong> access is active.</p>'],
                ['id' => 'resources-1', 'type' => 'dynamic', 'variable' => '{resources_list}'],
                ['id' => 'button-1', 'type' => 'button', 'label' => 'Open account', 'url' => '{account_url}'],
            ],
        ];

        $result = $renderer->compose('access_granted', $template, [
            '{user_name}' => 'Alex <Admin>',
            '{plan_name}' => 'Premium & Plus',
            '{resources_list}' => '<ul><li>Course One</li></ul>',
            '{account_url}' => 'https://example.com/account/?tab=membership',
            '{site_name}' => 'Example Site',
        ], [
            'footer_html' => '<p>Sent by {site_name}</p>',
        ]);

        self::assertSame('Welcome to Premium & Plus, Alex', $result['subject']);
        self::assertStringContainsString('Your membership is ready.', $result['html']);
        self::assertStringContainsString('Welcome, Alex', $result['html']);
        self::assertStringContainsString('<ul><li>Course One</li></ul>', $result['html']);
        self::assertStringContainsString('href="https://example.com/account/?tab=membership"', $result['html']);
        self::assertStringNotContainsString('<Admin>', $result['html']);
        self::assertStringNotContainsString('&lt;Admin&gt;', $result['html']);
        self::assertStringContainsString('Sent by Example Site', $result['html']);
    }

    public function test_normalise_template_migrates_legacy_html_and_rejects_unsupported_blocks(): void
    {
        $renderer = new NotificationTemplateRenderer();

        $legacy = $renderer->normaliseTemplate('access_granted', '<p>Hello {user_name}</p>');
        self::assertSame('rich_text', $legacy['blocks'][0]['type']);
        self::assertSame('<p>Hello {user_name}</p>', $legacy['blocks'][0]['content']);

        $normalised = $renderer->normaliseTemplate('access_granted', [
            'subject' => '<script>alert(1)</script> Welcome',
            'preheader' => 'A useful preview',
            'blocks' => [
                ['id' => 'bad', 'type' => 'script', 'content' => '<script>alert(1)</script>'],
                ['id' => 'good', 'type' => 'rich_text', 'content' => '<p>Hello <script>alert(1)</script></p>'],
            ],
        ]);

        self::assertSame('alert(1) Welcome', $normalised['subject']);
        self::assertCount(1, $normalised['blocks']);
        self::assertSame('rich_text', $normalised['blocks'][0]['type']);
    }

    public function test_actual_native_email_delivery_uses_the_saved_structured_template_and_theme(): void
    {
        $user = new \WP_User();
        $user->ID = 21;
        $user->display_name = 'Jamie Member';
        $user->user_email = 'jamie@example.com';
        $user->user_login = 'jamie';
        $GLOBALS['_fchub_test_users'][21] = $user;
        $GLOBALS['_fchub_test_options']['fchub_memberships_settings'] = [
            'email_access_granted' => 'yes',
            'email_theme' => ['primary_color' => '#7c3aed'],
            'email_theme_overrides' => [
                'access_granted' => [
                    'primary_color' => '#db2777',
                    'header_style' => 'text',
                    'header_text' => 'Member Desk',
                ],
            ],
            'email_templates' => [
                'access_granted' => [
                    'subject' => 'Hello {user_name}',
                    'preheader' => 'Your {plan_name} access is active.',
                    'blocks' => [
                        ['id' => 'heading', 'type' => 'heading', 'content' => 'Welcome to {plan_name}'],
                        ['id' => 'button', 'type' => 'button', 'label' => 'Open account', 'url' => '{account_url}'],
                    ],
                ],
            ],
        ];

        (new AccessGrantedEmail())->send(21, ['plan_title' => 'Gold Plan']);

        self::assertCount(1, $GLOBALS['_fchub_test_mails']);
        self::assertSame('Hello Jamie Member', $GLOBALS['_fchub_test_mails'][0][1]);
        self::assertStringContainsString('Welcome to Gold Plan', $GLOBALS['_fchub_test_mails'][0][2]);
        self::assertStringContainsString('background:#db2777', $GLOBALS['_fchub_test_mails'][0][2]);
        self::assertStringContainsString('Member Desk', $GLOBALS['_fchub_test_mails'][0][2]);
    }
}

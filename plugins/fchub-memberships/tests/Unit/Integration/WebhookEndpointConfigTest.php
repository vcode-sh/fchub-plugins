<?php

declare(strict_types=1);

namespace FChubMemberships\Tests\Unit\Integration;

use FChubMemberships\Integration\WebhookEndpointConfig;
use FChubMemberships\Tests\Unit\PluginTestCase;

final class WebhookEndpointConfigTest extends PluginTestCase
{
    public function test_migrates_legacy_urls_without_breaking_the_shared_receiver_secret(): void
    {
        $settings = WebhookEndpointConfig::migrateLegacy([
            'webhook_enabled' => 'yes',
            'webhook_urls' => "https://one.example/hook\nhttps://two.example/webhook",
            'webhook_secret' => 'legacy-secret',
        ]);

        self::assertCount(2, $settings['webhook_endpoints']);
        self::assertSame(['active', 'active'], array_column($settings['webhook_endpoints'], 'status'));
        self::assertSame(['legacy-secret', 'legacy-secret'], array_column($settings['webhook_endpoints'], 'secret'));
        self::assertSame([true, true], array_column($settings['webhook_endpoints'], 'requires_rotation'));
        self::assertSame($settings, WebhookEndpointConfig::migrateLegacy($settings));
    }

    public function test_public_projection_never_returns_secrets(): void
    {
        $public = WebhookEndpointConfig::public([
            'id' => 'endpoint-a',
            'name' => 'CRM receiver',
            'url' => 'https://crm.example/webhook',
            'secret' => 'never-return-this',
            'status' => 'paused',
            'requires_rotation' => false,
            'last_test_status' => 'failed',
            'last_tested_at' => '2026-07-24 08:00:00',
        ]);

        self::assertSame([
            'id' => 'endpoint-a',
            'name' => 'CRM receiver',
            'url' => 'https://crm.example/webhook',
            'status' => 'paused',
            'secret_configured' => true,
            'requires_rotation' => false,
            'last_test_status' => 'failed',
            'last_tested_at' => '2026-07-24 08:00:00',
        ], $public);
        self::assertStringNotContainsString('never-return-this', serialize($public));
    }

    public function test_resolves_only_active_destination_secrets_for_delivery(): void
    {
        $settings = ['webhook_endpoints' => [
            [
                'id' => 'active',
                'name' => 'Active',
                'url' => 'https://active.example/hook',
                'secret' => 'active-secret',
                'status' => 'active',
            ],
            [
                'id' => 'paused',
                'name' => 'Paused',
                'url' => 'https://paused.example/hook',
                'secret' => 'paused-secret',
                'status' => 'paused',
            ],
        ]];

        self::assertSame(
            ['https://active.example/hook'],
            array_column(WebhookEndpointConfig::active($settings), 'url')
        );
        self::assertSame(
            'active-secret',
            WebhookEndpointConfig::secretForUrl($settings, 'https://active.example/hook')
        );
        self::assertNull(WebhookEndpointConfig::secretForUrl(
            $settings,
            'https://paused.example/hook',
            true
        ));
    }
}

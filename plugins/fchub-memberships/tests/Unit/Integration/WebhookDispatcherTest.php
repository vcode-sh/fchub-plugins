<?php

declare(strict_types=1);

namespace FChubMemberships\Tests\Unit\Integration;

use FChubMemberships\Integration\WebhookDispatcher;
use FChubMemberships\Tests\Unit\PluginTestCase;

final class WebhookDispatcherTest extends PluginTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $GLOBALS['_fchub_test_users'][21] = (object) [
            'ID' => 21,
            'display_name' => 'Alice Example',
            'user_email' => 'alice@example.com',
        ];
    }

    public function test_register_dispatch_and_send_test_cover_webhook_dispatcher(): void
    {
        $GLOBALS['_fchub_test_options']['fchub_memberships_settings'] = [
            'webhook_enabled' => 'yes',
            'webhook_urls' => "https://example.com/hook\ninvalid-url\nhttps://example.com/second",
            'webhook_secret' => 'secret',
        ];
        $GLOBALS['_fchub_test_wpdb_overrides']['get_row'] = static fn(string $query): ?array => str_contains($query, 'wp_fchub_membership_plans')
            ? [
                'id' => 5,
                'title' => 'Gold Plan',
                'slug' => 'gold-plan',
                'description' => '',
                'status' => 'active',
                'level' => 0,
                'duration_type' => 'lifetime',
                'duration_days' => null,
                'trial_days' => 0,
                'grace_period_days' => 0,
                'includes_plan_ids' => '[]',
                'restriction_message' => null,
                'redirect_url' => null,
                'settings' => '{}',
                'meta' => '{}',
                'created_at' => '2026-01-01 00:00:00',
                'updated_at' => '2026-01-01 00:00:00',
            ]
            : null;

        $dispatcher = new WebhookDispatcher();
        $dispatcher->register();

        self::assertArrayHasKey('fchub_memberships/grant_created', $GLOBALS['_fchub_test_actions']);
        self::assertArrayHasKey('fchub_memberships/grant_revoked', $GLOBALS['_fchub_test_actions']);

        $dispatcher->onGrantCreated(21, 5, ['source_type' => 'order', 'source_id' => 77]);
        $dispatcher->onGrantRevoked([['id' => 1]], 5, 21, 'Canceled');
        $dispatcher->onGrantExpired([
            'id' => 1,
            'user_id' => 21,
            'plan_id' => 5,
            'status' => 'expired',
            'source_type' => 'manual',
            'created_at' => '2026-03-01 00:00:00',
            'expires_at' => '2026-03-20 00:00:00',
        ]);
        $dispatcher->onGrantPaused([
            'id' => 1,
            'user_id' => 21,
            'plan_id' => 5,
            'status' => 'paused',
            'source_type' => 'manual',
            'created_at' => '2026-03-01 00:00:00',
            'expires_at' => '2026-03-20 00:00:00',
        ], 'Paused');
        $dispatcher->onGrantResumed([
            'id' => 1,
            'user_id' => 21,
            'plan_id' => 5,
            'status' => 'active',
            'source_type' => 'manual',
            'created_at' => '2026-03-01 00:00:00',
            'expires_at' => '2026-03-20 00:00:00',
        ]);

        $test = $dispatcher->sendTest();

        self::assertCount(10, $GLOBALS['_fchub_test_scheduled_events']);
        self::assertSame('fchub_memberships_dispatch_webhook', $GLOBALS['_fchub_test_scheduled_events'][0][1]);
        self::assertTrue($test['success']);
        self::assertCount(2, $test['results']);
        self::assertCount(2, $GLOBALS['_fchub_test_remote_posts']);
    }

    public function test_disabled_or_empty_webhook_configuration_is_a_no_op(): void
    {
        $GLOBALS['_fchub_test_options']['fchub_memberships_settings'] = [
            'webhook_enabled' => 'no',
            'webhook_urls' => '',
        ];

        $dispatcher = new WebhookDispatcher();
        $dispatcher->register();
        $dispatcher->dispatch('grant_created', ['x' => 1]);
        $test = $dispatcher->sendTest();

        self::assertArrayNotHasKey('fchub_memberships/grant_created', $GLOBALS['_fchub_test_actions']);
        self::assertFalse($test['success']);
    }
}

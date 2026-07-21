<?php

declare(strict_types=1);

namespace FChubMemberships\Tests\Unit\Domain;

use FChubMemberships\Domain\SubscriptionValidityWatcher;
use FChubMemberships\Tests\Unit\PluginTestCase;

final class SubscriptionValidityCheckServiceTest extends PluginTestCase
{
    public function test_watcher_does_not_register_or_emit_a_fallback_validity_expiration_event(): void
    {
        $watcher = new SubscriptionValidityWatcher();
        $watcher->registerHooks();
        $emissions = 0;

        add_action('fluent_cart/subscription_expired_validity', static function () use (&$emissions): void {
            $emissions++;
        });

        self::assertArrayNotHasKey('fluent_cart/payments/subscription_status_changed', $GLOBALS['_fchub_test_actions']);

        do_action('fluent_cart/payments/subscription_status_changed', [
            'subscription' => (object) ['id' => 42],
            'new_status' => 'expired',
        ]);

        self::assertSame(0, $emissions);
    }
}

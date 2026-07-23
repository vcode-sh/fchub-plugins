<?php

declare(strict_types=1);

namespace FChubMemberships\Tests\Unit\Domain;

use FChubMemberships\Domain\Lifecycle\MembershipLifecycleCoordinator;
use FChubMemberships\Domain\SubscriptionValidityWatcher;
use FChubMemberships\Tests\Unit\PluginTestCase;

final class SubscriptionValidityWatcherCoordinatorTest extends PluginTestCase
{
    public function test_registered_subscription_and_cron_hooks_only_delegate_to_the_coordinator(): void
    {
        $coordinator = new RecordingLifecycleCoordinator();
        $watcher = new SubscriptionValidityWatcher($coordinator);
        $watcher->registerHooks();
        $subscription = (object) ['id' => 88, 'next_billing_date' => '2026-09-01 00:00:00'];
        $renewal = ['subscription' => $subscription, 'order' => (object) ['id' => 1201]];

        do_action('fluent_cart/subscription_renewed', $renewal);
        do_action('fluent_cart/payments/subscription_paused', ['subscription' => $subscription]);
        do_action('fluent_cart/payments/subscription_active', ['subscription' => $subscription]);
        do_action('fluent_cart/subscription_canceled', ['subscription' => $subscription]);
        do_action('fluent_cart/subscription_eot', ['subscription' => $subscription]);
        do_action('fluent_cart/subscription_expired_validity', ['subscription' => $subscription]);
        do_action('fluent_cart/subscription_reactivated', $renewal);
        $watcher->check();

        self::assertSame([
            'renew', 'pause', 'resume', 'cancel', 'eot', 'expire', 'reactivate', 'check',
        ], $coordinator->calls);
    }
}

final class RecordingLifecycleCoordinator extends MembershipLifecycleCoordinator
{
    public array $calls = [];

    public function __construct()
    {
    }

    public function renew(array $payload): array { $this->calls[] = 'renew'; return []; }
    public function pause(array|object $payload): array { $this->calls[] = 'pause'; return []; }
    public function resume(array|object $payload): array { $this->calls[] = 'resume'; return []; }
    public function cancel(array|object $payload): array { $this->calls[] = 'cancel'; return []; }
    public function endOfTerm(array|object $payload): array { $this->calls[] = 'eot'; return []; }
    public function expire(array|object $payload): array { $this->calls[] = 'expire'; return []; }
    public function reactivate(array $payload): array { $this->calls[] = 'reactivate'; return []; }
    public function checkValidity(): array { $this->calls[] = 'check'; return []; }
}

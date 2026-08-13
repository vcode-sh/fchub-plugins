<?php

declare(strict_types=1);

namespace FChubMemberships\Tests\Unit\Domain\Member;

use FChubMemberships\Domain\Member\FluentCartSourceGateway;
use FChubMemberships\Domain\Member\GrantSourceResolver;
use FChubMemberships\Tests\Unit\PluginTestCase;

final class GrantSourceResolverTest extends PluginTestCase
{
    public function test_it_links_an_order_that_exists(): void
    {
        $source = $this->resolve(['source_type' => 'order', 'source_id' => 123], orders: [123 => ['id' => 123]]);

        self::assertSame('Order #123', $source['label']);
        self::assertSame('https://example.com/wp-admin/admin.php?page=fluent-cart#/orders/123/view', $source['url']);
    }

    public function test_a_missing_order_keeps_its_identifier_and_loses_its_link(): void
    {
        $source = $this->resolve(['source_type' => 'order', 'source_id' => 123]);

        self::assertSame('Order #123', $source['label']);
        self::assertNull($source['url']);
    }

    public function test_a_subscription_reports_its_renewal_facts_and_links_the_parent_order(): void
    {
        $source = $this->resolve(
            ['source_type' => 'subscription', 'source_id' => 55],
            orders: [7 => ['id' => 7]],
            subscriptions: [55 => [
                'id' => 55,
                'status' => 'active',
                'next_billing_date' => '2026-09-12 00:00:00',
                'canceled_at' => null,
                'parent_order_id' => 7,
            ]]
        );

        self::assertSame('Subscription #55', $source['label']);
        self::assertSame('https://example.com/wp-admin/admin.php?page=fluent-cart#/orders/7/view', $source['url']);
        self::assertSame('active', $source['subscription']['status']);
        self::assertSame('2026-09-12 00:00:00', $source['subscription']['next_billing_date']);
    }

    public function test_a_cancelled_subscription_reports_the_cancellation_and_stops_promising_a_renewal(): void
    {
        $source = $this->resolve(
            ['source_type' => 'subscription', 'source_id' => 55],
            subscriptions: [55 => [
                'id' => 55,
                'status' => 'cancelled',
                'next_billing_date' => '2026-09-12 00:00:00',
                'canceled_at' => '2026-08-01 09:00:00',
                'parent_order_id' => 0,
            ]]
        );

        self::assertSame('cancelled', $source['subscription']['status']);
        self::assertSame('2026-08-01 09:00:00', $source['subscription']['canceled_at']);
        self::assertNull($source['subscription']['next_billing_date']);
        self::assertNull($source['url']);
    }

    public function test_a_missing_subscription_reports_no_renewal_facts(): void
    {
        $source = $this->resolve(['source_type' => 'subscription', 'source_id' => 55]);

        self::assertSame('Subscription #55', $source['label']);
        self::assertNull($source['url']);
        self::assertNull($source['subscription']);
    }

    public function test_a_manual_grant_names_the_administrator_who_created_it(): void
    {
        $GLOBALS['_fchub_test_users'][9] = (object) ['ID' => 9, 'display_name' => 'tomrobak'];

        $source = $this->resolve(
            ['source_type' => 'manual', 'source_id' => 0],
            audit: [[
                'action' => 'created',
                'actor_id' => 9,
                'actor_type' => 'admin',
                'created_at' => '2026-02-01 10:00:00',
            ]]
        );

        self::assertSame('Manual grant', $source['label']);
        self::assertSame('tomrobak', $source['actor']);
        self::assertSame('2026-02-01 10:00:00', $source['granted_at']);
        self::assertNull($source['url']);
    }

    public function test_a_manual_grant_without_an_audit_record_names_nobody_rather_than_guessing(): void
    {
        $source = $this->resolve(['source_type' => 'manual', 'source_id' => 0]);

        self::assertSame('Manual grant', $source['label']);
        self::assertNull($source['actor']);
    }

    public function test_a_system_actor_is_reported_as_the_system(): void
    {
        $source = $this->resolve(
            ['source_type' => 'manual', 'source_id' => 0],
            audit: [['action' => 'created', 'actor_id' => 0, 'actor_type' => 'system', 'created_at' => '2026-02-01 10:00:00']]
        );

        self::assertSame('System', $source['actor']);
    }

    public function test_an_unknown_source_type_never_produces_a_link(): void
    {
        $source = $this->resolve(['source_type' => 'wormhole', 'source_id' => 3]);

        self::assertSame('Wormhole #3', $source['label']);
        self::assertNull($source['url']);
    }

    public function test_a_trial_is_labelled_without_an_identifier(): void
    {
        $source = $this->resolve(['source_type' => 'trial', 'source_id' => 0]);

        self::assertSame('Trial', $source['label']);
        self::assertNull($source['url']);
    }

    /**
     * @param array<string, mixed> $membership
     * @param array<int, array<string, mixed>> $orders
     * @param array<int, array<string, mixed>> $subscriptions
     * @param list<array<string, mixed>> $audit
     * @return array<string, mixed>
     */
    private function resolve(array $membership, array $orders = [], array $subscriptions = [], array $audit = []): array
    {
        $gateway = new class($orders, $subscriptions) extends FluentCartSourceGateway {
            public function __construct(private array $orders, private array $subscriptions)
            {
            }

            public function order(int $orderId): ?array
            {
                return $this->orders[$orderId] ?? null;
            }

            public function subscription(int $subscriptionId): ?array
            {
                return $this->subscriptions[$subscriptionId] ?? null;
            }
        };

        return (new GrantSourceResolver($gateway))->resolve($membership, $audit);
    }
}

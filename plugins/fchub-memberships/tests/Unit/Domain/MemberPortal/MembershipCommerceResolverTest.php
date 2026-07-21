<?php

declare(strict_types=1);

namespace FChubMemberships\Tests\Unit\Domain\MemberPortal;

use FChubMemberships\Domain\MemberPortal\MembershipCommerceResolver;
use FChubMemberships\Tests\Unit\PluginTestCase;

final class MembershipCommerceResolverTest extends PluginTestCase
{
    public function test_it_delegates_subscription_management_to_fluentcart(): void
    {
        $resolver = new MembershipCommerceResolver(
            manageUrl: static fn(int $subscriptionId): string => "https://example.com/account/subscription/{$subscriptionId}",
            checkoutUrl: static fn(int $planId): string => '',
            nextBillingDate: static fn(int $subscriptionId): string => '1 Jun 2026'
        );

        $result = $resolver->resolve([
            'plan_id' => 5,
            'status' => 'active',
            'source_type' => 'subscription',
            'source_id' => 88,
        ]);

        self::assertSame('1 Jun 2026', $result['next_billing_date']);
        self::assertSame([
            'kind' => 'manage_subscription',
            'label' => 'Manage subscription',
            'url' => 'https://example.com/account/subscription/88',
        ], $result['action']);
    }

    public function test_it_offers_renewal_only_for_expired_non_subscription_memberships_with_checkout(): void
    {
        $resolver = new MembershipCommerceResolver(
            manageUrl: static fn(int $subscriptionId): string => '',
            checkoutUrl: static fn(int $planId): string => $planId === 5 ? 'https://example.com/checkout' : '',
            nextBillingDate: static fn(int $subscriptionId): string => ''
        );

        $expired = $resolver->resolve([
            'plan_id' => 5,
            'status' => 'expired',
            'source_type' => 'order',
            'source_id' => 77,
        ]);
        $revoked = $resolver->resolve([
            'plan_id' => 5,
            'status' => 'revoked',
            'source_type' => 'manual',
            'source_id' => 0,
        ]);
        $unlinked = $resolver->resolve([
            'plan_id' => 6,
            'status' => 'expired',
            'source_type' => 'order',
            'source_id' => 78,
        ]);

        self::assertSame([
            'kind' => 'renew_membership',
            'label' => 'Renew membership',
            'url' => 'https://example.com/checkout',
        ], $expired['action']);
        self::assertNull($revoked['action']);
        self::assertNull($unlinked['action']);
    }
}

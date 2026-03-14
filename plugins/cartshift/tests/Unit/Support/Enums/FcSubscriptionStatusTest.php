<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\Support\Enums;

use CartShift\Support\Enums\FcSubscriptionStatus;
use CartShift\Tests\Unit\PluginTestCase;

final class FcSubscriptionStatusTest extends PluginTestCase
{
    public function testActiveMapsToActive(): void
    {
        $this->assertSame(FcSubscriptionStatus::Active, FcSubscriptionStatus::fromWooCommerce('active'));
    }

    public function testOnHoldMapsToPaused(): void
    {
        $this->assertSame(FcSubscriptionStatus::Paused, FcSubscriptionStatus::fromWooCommerce('on-hold'));
    }

    public function testCancelledMapsToCanceled(): void
    {
        $this->assertSame(FcSubscriptionStatus::Canceled, FcSubscriptionStatus::fromWooCommerce('cancelled'));
    }

    public function testSwitchedMapsToCanceled(): void
    {
        $this->assertSame(FcSubscriptionStatus::Canceled, FcSubscriptionStatus::fromWooCommerce('switched'));
    }

    public function testExpiredMapsToExpired(): void
    {
        $this->assertSame(FcSubscriptionStatus::Expired, FcSubscriptionStatus::fromWooCommerce('expired'));
    }

    public function testPendingCancelMapsToExpiring(): void
    {
        $this->assertSame(FcSubscriptionStatus::Expiring, FcSubscriptionStatus::fromWooCommerce('pending-cancel'));
    }

    public function testPendingMapsToPending(): void
    {
        $this->assertSame(FcSubscriptionStatus::Pending, FcSubscriptionStatus::fromWooCommerce('pending'));
    }

    public function testUnknownMapsToPending(): void
    {
        $this->assertSame(FcSubscriptionStatus::Pending, FcSubscriptionStatus::fromWooCommerce('unknown-status'));
    }

    public function testFromWooCommerceAllStatuses(): void
    {
        $mapping = [
            'active' => FcSubscriptionStatus::Active,
            'on-hold' => FcSubscriptionStatus::Paused,
            'cancelled' => FcSubscriptionStatus::Canceled,
            'switched' => FcSubscriptionStatus::Canceled,
            'expired' => FcSubscriptionStatus::Expired,
            'pending-cancel' => FcSubscriptionStatus::Expiring,
            'pending' => FcSubscriptionStatus::Pending,
        ];

        foreach ($mapping as $wcStatus => $expected) {
            $this->assertSame(
                $expected,
                FcSubscriptionStatus::fromWooCommerce($wcStatus),
                "WC status '{$wcStatus}' should map to {$expected->value}"
            );
        }
    }

    public function testEnumValues(): void
    {
        $this->assertSame('active', FcSubscriptionStatus::Active->value);
        $this->assertSame('paused', FcSubscriptionStatus::Paused->value);
        $this->assertSame('canceled', FcSubscriptionStatus::Canceled->value);
        $this->assertSame('expired', FcSubscriptionStatus::Expired->value);
        $this->assertSame('expiring', FcSubscriptionStatus::Expiring->value);
        $this->assertSame('pending', FcSubscriptionStatus::Pending->value);
    }
}

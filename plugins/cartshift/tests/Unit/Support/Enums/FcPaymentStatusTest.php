<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\Support\Enums;

use CartShift\Support\Enums\FcPaymentStatus;
use CartShift\Tests\Unit\PluginTestCase;

final class FcPaymentStatusTest extends PluginTestCase
{
    public function testPendingMapsToPending(): void
    {
        $this->assertSame(FcPaymentStatus::Pending, FcPaymentStatus::fromWooCommerce('pending'));
    }

    public function testOnHoldMapsToPending(): void
    {
        $this->assertSame(FcPaymentStatus::Pending, FcPaymentStatus::fromWooCommerce('on-hold'));
    }

    public function testProcessingMapsToPaid(): void
    {
        $this->assertSame(FcPaymentStatus::Paid, FcPaymentStatus::fromWooCommerce('processing'));
    }

    public function testCompletedMapsToPaid(): void
    {
        $this->assertSame(FcPaymentStatus::Paid, FcPaymentStatus::fromWooCommerce('completed'));
    }

    public function testCancelledMapsToFailed(): void
    {
        $this->assertSame(FcPaymentStatus::Failed, FcPaymentStatus::fromWooCommerce('cancelled'));
    }

    public function testFailedMapsToFailed(): void
    {
        $this->assertSame(FcPaymentStatus::Failed, FcPaymentStatus::fromWooCommerce('failed'));
    }

    public function testRefundedMapsToRefunded(): void
    {
        $this->assertSame(FcPaymentStatus::Refunded, FcPaymentStatus::fromWooCommerce('refunded'));
    }

    public function testUnknownMapsToPending(): void
    {
        $this->assertSame(FcPaymentStatus::Pending, FcPaymentStatus::fromWooCommerce('something-weird'));
    }

    public function testFromWooCommerceAllStatuses(): void
    {
        $mapping = [
            'pending' => FcPaymentStatus::Pending,
            'on-hold' => FcPaymentStatus::Pending,
            'processing' => FcPaymentStatus::Paid,
            'completed' => FcPaymentStatus::Paid,
            'cancelled' => FcPaymentStatus::Failed,
            'failed' => FcPaymentStatus::Failed,
            'refunded' => FcPaymentStatus::Refunded,
        ];

        foreach ($mapping as $wcStatus => $expected) {
            $this->assertSame(
                $expected,
                FcPaymentStatus::fromWooCommerce($wcStatus),
                "WC status '{$wcStatus}' should map to {$expected->value}"
            );
        }
    }

    public function testEnumValues(): void
    {
        $this->assertSame('pending', FcPaymentStatus::Pending->value);
        $this->assertSame('paid', FcPaymentStatus::Paid->value);
        $this->assertSame('failed', FcPaymentStatus::Failed->value);
        $this->assertSame('refunded', FcPaymentStatus::Refunded->value);
        $this->assertSame('partially_refunded', FcPaymentStatus::PartiallyRefunded->value);
    }
}

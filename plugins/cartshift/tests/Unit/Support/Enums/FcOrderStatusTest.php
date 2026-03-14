<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\Support\Enums;

use CartShift\Support\Enums\FcOrderStatus;
use CartShift\Tests\Unit\PluginTestCase;

final class FcOrderStatusTest extends PluginTestCase
{
    public function testPendingMapsToOnHold(): void
    {
        $this->assertSame(FcOrderStatus::OnHold, FcOrderStatus::fromWooCommerce('pending'));
    }

    public function testProcessingMapsToProcessing(): void
    {
        $this->assertSame(FcOrderStatus::Processing, FcOrderStatus::fromWooCommerce('processing'));
    }

    public function testOnHoldMapsToOnHold(): void
    {
        $this->assertSame(FcOrderStatus::OnHold, FcOrderStatus::fromWooCommerce('on-hold'));
    }

    public function testCompletedMapsToCompleted(): void
    {
        $this->assertSame(FcOrderStatus::Completed, FcOrderStatus::fromWooCommerce('completed'));
    }

    public function testCancelledMapsToCanceled(): void
    {
        $this->assertSame(FcOrderStatus::Canceled, FcOrderStatus::fromWooCommerce('cancelled'));
    }

    public function testRefundedMapsToCompleted(): void
    {
        $this->assertSame(FcOrderStatus::Completed, FcOrderStatus::fromWooCommerce('refunded'));
    }

    public function testFailedMapsToFailed(): void
    {
        $this->assertSame(FcOrderStatus::Failed, FcOrderStatus::fromWooCommerce('failed'));
    }

    public function testUnknownMapsToOnHold(): void
    {
        $this->assertSame(FcOrderStatus::OnHold, FcOrderStatus::fromWooCommerce('some-random-status'));
    }

    public function testFromWooCommerceAllStatuses(): void
    {
        $mapping = [
            'pending' => FcOrderStatus::OnHold,
            'processing' => FcOrderStatus::Processing,
            'on-hold' => FcOrderStatus::OnHold,
            'completed' => FcOrderStatus::Completed,
            'cancelled' => FcOrderStatus::Canceled,
            'refunded' => FcOrderStatus::Completed,
            'failed' => FcOrderStatus::Failed,
        ];

        foreach ($mapping as $wcStatus => $expected) {
            $this->assertSame(
                $expected,
                FcOrderStatus::fromWooCommerce($wcStatus),
                "WC status '{$wcStatus}' should map to {$expected->value}"
            );
        }
    }

    public function testEnumValues(): void
    {
        $this->assertSame('processing', FcOrderStatus::Processing->value);
        $this->assertSame('completed', FcOrderStatus::Completed->value);
        $this->assertSame('on-hold', FcOrderStatus::OnHold->value);
        $this->assertSame('canceled', FcOrderStatus::Canceled->value);
        $this->assertSame('failed', FcOrderStatus::Failed->value);
    }
}

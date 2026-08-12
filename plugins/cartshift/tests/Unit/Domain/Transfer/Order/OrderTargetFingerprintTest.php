<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\Domain\Transfer\Order;

use CartShift\Domain\Transfer\Order\OrderTargetFingerprint;
use CartShift\Tests\Unit\PluginTestCase;

final class OrderTargetFingerprintTest extends PluginTestCase
{
    public function testSubscriptionOwnedLinkDoesNotRewriteTheOrderReceiptButMoneyStillDoes(): void
    {
        $base = [
            'order' => ['id' => 11, 'total' => 2400],
            'transactions' => [[
                'id' => 31,
                'subscription_id' => null,
                'order_type' => 'subscription_renewal',
                'total' => 2400,
            ]],
        ];
        $linked = $base;
        $linked['transactions'][0]['subscription_id'] = 77;
        $tampered = $linked;
        $tampered['transactions'][0]['total'] = 2300;
        $fingerprints = new OrderTargetFingerprint();

        self::assertSame(
            $fingerprints->fingerprint($base, ['shop-alpha:order:9' => 11]),
            $fingerprints->fingerprint($linked, ['shop-alpha:order:9' => 11]),
        );
        self::assertNotSame(
            $fingerprints->fingerprint($linked, ['shop-alpha:order:9' => 11]),
            $fingerprints->fingerprint($tampered, ['shop-alpha:order:9' => 11]),
        );
    }
}

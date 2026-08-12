<?php

declare(strict_types=1);

namespace CartShift\Tests\Integration\Contract;

require_once __DIR__ . '/InstalledContractTestCase.php';

final class WooOrderSourceContractTest extends InstalledContractTestCase
{
    public function testRealWooCrudOrderProducesTheLiteralImmutableLedger(): void
    {
        $result = $this->runRuntimeContract('woo-order-ledger');

        self::assertTrue($result['source_is_wc_order']);
        self::assertMatchesRegularExpression('/\Acontract-source:order:[1-9][0-9]*\z/', $result['identity']);
        self::assertSame(10000, $result['subtotal']);
        self::assertSame(1000, $result['coupon_discount_total']);
        self::assertSame(0, $result['manual_discount_total']);
        self::assertSame(230, $result['discount_tax']);
        self::assertSame(2000, $result['shipping_total']);
        self::assertSame(460, $result['shipping_tax']);
        self::assertSame(500, $result['fee_total']);
        self::assertSame(115, $result['fee_tax']);
        self::assertSame(2185, $result['cart_tax']);
        self::assertSame(14145, $result['gross_total']);
        self::assertCount(1, $result['product_line_ids']);
        self::assertSame(1, $result['fee_line_count']);
        self::assertSame(1, $result['shipping_line_count']);
        self::assertSame(1, $result['coupon_line_count']);
        self::assertSame(1, $result['tax_rate_count']);
        self::assertSame(['billing', 'shipping'], $result['address_types']);
        self::assertCount(4, $result['note_ids']);
        self::assertContains($result['fixture_note_id'], $result['note_ids']);
        self::assertTrue($result['note_public_identifier_is_non_content']);
        self::assertSame(['provider_reference'], $result['payment_evidence']);
        self::assertSame(1, $result['source_record_count']);
        self::assertTrue($result['source_record_digest_matches']);
    }
}

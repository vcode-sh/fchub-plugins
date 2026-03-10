<?php

declare(strict_types=1);

namespace FChubMultiCurrency\Tests\Unit\Integration;

use FChubMultiCurrency\Integration\FluentCrmSmartCodes;
use FChubMultiCurrency\Tests\Support\TestCase;
use FluentCart\App\Models\Order;
use FluentCrm\App\Models\FunnelSubscriber;
use PHPUnit\Framework\Attributes\Test;

final class FluentCrmSmartCodesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Order::resetMockOrders();
        FunnelSubscriber::resetMockData();
    }

    private function makeOrder(
        string $currency = 'USD',
        int $totalAmount = 10000,
        int $subtotal = 9000,
        array $meta = [],
    ): Order {
        $order = new Order();
        $order->id = 1;
        $order->currency = $currency;
        $order->total_amount = $totalAmount;
        $order->subtotal = $subtotal;
        foreach ($meta as $key => $value) {
            $order->setMeta($key, $value);
        }
        return $order;
    }

    #[Test]
    public function testDisplayCurrencyReturnsStoredCode(): void
    {
        $order = $this->makeOrder('USD', 10000, 9000, [
            '_fchub_mc_display_currency' => 'EUR',
            '_fchub_mc_base_currency' => 'USD',
            '_fchub_mc_rate' => '0.85000000',
        ]);

        $result = FluentCrmSmartCodes::resolveValue($order, 'display_currency', 'N/A');

        $this->assertSame('EUR', $result);
    }

    #[Test]
    public function testBaseCurrencyReturnsStoredCode(): void
    {
        $order = $this->makeOrder('USD', 10000, 9000, [
            '_fchub_mc_display_currency' => 'EUR',
            '_fchub_mc_base_currency' => 'USD',
            '_fchub_mc_rate' => '0.85000000',
        ]);

        $result = FluentCrmSmartCodes::resolveValue($order, 'base_currency', 'N/A');

        $this->assertSame('USD', $result);
    }

    #[Test]
    public function testExchangeRateReturnsStoredRate(): void
    {
        $order = $this->makeOrder('USD', 10000, 9000, [
            '_fchub_mc_display_currency' => 'EUR',
            '_fchub_mc_base_currency' => 'USD',
            '_fchub_mc_rate' => '0.85000000',
        ]);

        $result = FluentCrmSmartCodes::resolveValue($order, 'exchange_rate', '1');

        $this->assertSame('0.85000000', $result);
    }

    #[Test]
    public function testDisplayTotalConvertsAndFormats(): void
    {
        // total_amount=10000 cents ($100.00), rate=0.85 → 10000 * 0.85 = 8500 cents
        // Helper::toDecimal(8500, true, 'EUR') → "€85.00"
        $order = $this->makeOrder('USD', 10000, 9000, [
            '_fchub_mc_display_currency' => 'EUR',
            '_fchub_mc_base_currency' => 'USD',
            '_fchub_mc_rate' => '0.85',
        ]);

        $result = FluentCrmSmartCodes::resolveValue($order, 'display_total', 'N/A');

        $this->assertSame("\xe2\x82\xac" . '85.00', $result);
    }

    #[Test]
    public function testDisplaySubtotalConvertsAndFormats(): void
    {
        // subtotal=9000 cents ($90.00), rate=0.85 → 9000 * 0.85 = 7650 cents
        // Helper::toDecimal(7650, true, 'EUR') → "€76.50"
        $order = $this->makeOrder('USD', 10000, 9000, [
            '_fchub_mc_display_currency' => 'EUR',
            '_fchub_mc_base_currency' => 'USD',
            '_fchub_mc_rate' => '0.85',
        ]);

        $result = FluentCrmSmartCodes::resolveValue($order, 'display_subtotal', 'N/A');

        $this->assertSame("\xe2\x82\xac" . '76.50', $result);
    }

    #[Test]
    public function testChargedNoticeFormatsCorrectly(): void
    {
        $order = $this->makeOrder('USD', 10000, 9000, [
            '_fchub_mc_display_currency' => 'EUR',
            '_fchub_mc_base_currency' => 'USD',
            '_fchub_mc_rate' => '0.85000000',
        ]);

        $result = FluentCrmSmartCodes::resolveValue($order, 'charged_notice', 'N/A');

        $this->assertSame('Charged in USD at rate 1 USD = 0.85000000 EUR', $result);
    }

    #[Test]
    public function testFallbackWhenNoMulticurrencyMeta(): void
    {
        $order = $this->makeOrder('USD', 10000, 9000);

        // display_currency falls back to order->currency
        $this->assertSame('USD', FluentCrmSmartCodes::resolveValue($order, 'display_currency', 'N/A'));

        // base_currency falls back to order->currency
        $this->assertSame('USD', FluentCrmSmartCodes::resolveValue($order, 'base_currency', 'N/A'));

        // exchange_rate falls back to '1'
        $this->assertSame('1', FluentCrmSmartCodes::resolveValue($order, 'exchange_rate', '0'));
    }

    #[Test]
    public function testFallbackForMonetaryValuesReturnsDefault(): void
    {
        $order = $this->makeOrder('USD', 10000, 9000);

        $this->assertSame('N/A', FluentCrmSmartCodes::resolveValue($order, 'display_total', 'N/A'));
        $this->assertSame('N/A', FluentCrmSmartCodes::resolveValue($order, 'display_subtotal', 'N/A'));
        $this->assertSame('N/A', FluentCrmSmartCodes::resolveValue($order, 'charged_notice', 'N/A'));
    }

    #[Test]
    public function testRegistersHooksWhenFluentCrmActive(): void
    {
        FluentCrmSmartCodes::register();

        $filters = $GLOBALS['wp_filters_registered'];
        $tags = array_column($filters, 'tag');

        $this->assertContains('fluent_crm/extended_smart_codes', $tags);
        $this->assertContains('fluent_crm_funnel_context_smart_codes', $tags);
        $this->assertContains('fluent_crm/smartcode_group_callback_mc_order', $tags);
    }

    #[Test]
    public function testGetOrderDisplayCurrencyReturnsCode(): void
    {
        $order = $this->makeOrder('USD', 10000, 9000, [
            '_fchub_mc_display_currency' => 'EUR',
        ]);
        $order->id = 42;
        Order::setMockOrder(42, $order);

        $result = fchub_mc_get_order_display_currency(42);

        $this->assertSame('EUR', $result);
    }

    #[Test]
    public function testGetOrderDisplayCurrencyReturnsNullWhenNoMeta(): void
    {
        $order = $this->makeOrder('USD', 10000, 9000);
        $order->id = 42;
        Order::setMockOrder(42, $order);

        $result = fchub_mc_get_order_display_currency(42);

        $this->assertNull($result);
    }

    #[Test]
    public function testFormatOrderPriceConverts(): void
    {
        $order = $this->makeOrder('USD', 10000, 9000, [
            '_fchub_mc_display_currency' => 'EUR',
            '_fchub_mc_rate' => '0.85',
        ]);
        $order->id = 42;
        Order::setMockOrder(42, $order);

        // basePrice=100.00, rate=0.85 → converted=85.00 → rounded=85.00
        // CurrencySettings::getPriceHtml(85.00, 'EUR') → "EUR 85.00"
        $result = fchub_mc_format_order_price(100.00, 42);

        $this->assertSame('EUR 85.00', $result);
    }

    #[Test]
    public function testFormatOrderPriceFallsBackWithoutMeta(): void
    {
        $order = $this->makeOrder('USD', 10000, 9000);
        $order->id = 42;
        Order::setMockOrder(42, $order);

        // No multicurrency meta → falls back to base currency formatting
        // CurrencySettings::getPriceHtml(100.00) → "USD 100.00"
        $result = fchub_mc_format_order_price(100.00, 42);

        $this->assertSame('USD 100.00', $result);
    }

    #[Test]
    public function testUnknownValueKeyReturnsDefault(): void
    {
        $order = $this->makeOrder('USD', 10000, 9000, [
            '_fchub_mc_display_currency' => 'EUR',
            '_fchub_mc_base_currency' => 'USD',
            '_fchub_mc_rate' => '0.85',
        ]);

        $result = FluentCrmSmartCodes::resolveValue($order, 'nonexistent_key', 'fallback');

        $this->assertSame('fallback', $result);
    }

    #[Test]
    public function testParseReturnsDefaultWhenNoFunnelSubscriberId(): void
    {
        $subscriber = new \stdClass();

        $result = FluentCrmSmartCodes::parse('mc_order', 'display_currency', 'N/A', $subscriber);

        $this->assertSame('N/A', $result);
    }

    #[Test]
    public function testParseReturnsDefaultWhenFunnelSubscriberNotFound(): void
    {
        $subscriber = new \stdClass();
        $subscriber->funnel_subscriber_id = 999;

        $result = FluentCrmSmartCodes::parse('mc_order', 'display_currency', 'N/A', $subscriber);

        $this->assertSame('N/A', $result);
    }

    #[Test]
    public function testParseResolvesValueThroughFullChain(): void
    {
        // Set up FunnelSubscriber mock to return source_ref_id=42
        FunnelSubscriber::setMockData([
            1 => ['id' => 1, 'source_ref_id' => 42],
        ]);

        // Set up Order mock
        $order = $this->makeOrder('USD', 10000, 9000, [
            '_fchub_mc_display_currency' => 'EUR',
            '_fchub_mc_base_currency' => 'USD',
            '_fchub_mc_rate' => '0.92',
        ]);
        $order->id = 42;
        Order::setMockOrder(42, $order);

        $subscriber = new \stdClass();
        $subscriber->funnel_subscriber_id = 1;

        $result = FluentCrmSmartCodes::parse('mc_order', 'display_currency', 'N/A', $subscriber);

        $this->assertSame('EUR', $result);
    }

    #[Test]
    public function testRegisterGlobalSmartCodesAddsGroup(): void
    {
        $groups = [];
        $result = FluentCrmSmartCodes::registerGlobalSmartCodes($groups);

        $this->assertArrayHasKey('mc_order', $result);
        $this->assertSame('Multi-Currency Order', $result['mc_order']['title']);
        $this->assertArrayHasKey('shortcodes', $result['mc_order']);
        $this->assertArrayHasKey('{{mc_order.display_currency}}', $result['mc_order']['shortcodes']);
    }

    #[Test]
    public function testRegisterFunnelSmartCodesAddsGroupForCartFunnel(): void
    {
        $funnel = new \stdClass();
        $funnel->trigger_name = 'fluent-cart-order-paid';

        $groups = [];
        $result = FluentCrmSmartCodes::registerFunnelSmartCodes($groups, $funnel);

        $this->assertArrayHasKey('mc_order', $result);
    }

    #[Test]
    public function testRegisterFunnelSmartCodesSkipsNonCartFunnel(): void
    {
        $funnel = new \stdClass();
        $funnel->trigger_name = 'some-other-funnel';

        $groups = [];
        $result = FluentCrmSmartCodes::registerFunnelSmartCodes($groups, $funnel);

        $this->assertArrayNotHasKey('mc_order', $result);
    }

    #[Test]
    public function testGetOrderDisplayCurrencyReturnsNullForMissingOrder(): void
    {
        $result = fchub_mc_get_order_display_currency(999);

        $this->assertNull($result);
    }

    #[Test]
    public function testFormatOrderPriceFallsBackForMissingOrder(): void
    {
        // No order set for ID 999 → falls back to base formatting
        $result = fchub_mc_format_order_price(50.00, 999);

        $this->assertSame('USD 50.00', $result);
    }
}

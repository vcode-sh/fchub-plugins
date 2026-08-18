<?php

declare(strict_types=1);

namespace FChubMultiCurrency\Tests\Unit\Integration;

use FChubMultiCurrency\Domain\Services\CurrencyContextService;
use FChubMultiCurrency\Integration\PublicPriceApi;
use FChubMultiCurrency\Support\Constants;
use FChubMultiCurrency\Tests\Support\TestCase;
use FluentCart\Api\CurrencySettings;
use FluentCart\App\Models\Order;
use PHPUnit\Framework\Attributes\Test;

/**
 * Covers the public price helpers: `fchub_mc_format_price()`, the one documented
 * helper that still renders a converted price on the server, and the order-scoped
 * pair that replays the snapshot captured at checkout.
 */
final class PublicPriceApiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Order::resetMockOrders();
        CurrencySettings::setMock(['currency' => 'USD', 'currency_sign' => '$']);
        $this->setOption('fchub_mc_settings', [
            'enabled'            => 'yes',
            'base_currency'      => 'USD',
            'display_currencies' => [
                ['code' => 'EUR', 'name' => 'Euro', 'symbol' => '€', 'decimals' => 2, 'position' => 'left'],
            ],
        ]);
        $this->setWpdbMockRow([
            'base_currency'  => 'USD',
            'quote_currency' => 'EUR',
            'rate'           => '0.92000000',
            'provider'       => 'manual',
            'fetched_at'     => current_time('mysql'),
        ]);
    }

    /**
     * Nothing resolves the context eagerly any more — an eager resolve cost a rate
     * read on every request, including the ones that never render a price. This
     * helper is the only consumer of the memoised context, and it has always been
     * able to resolve for itself. This proves it still does.
     */
    #[Test]
    public function testItConvertsWithNoContextResolvedInAdvance(): void
    {
        $_COOKIE[Constants::COOKIE_KEY] = 'EUR';
        CurrencyContextService::reset();
        $this->resetResolvedContext();
        $this->assertNull(CurrencyContextService::getResolved(), 'Nothing should have resolved yet.');

        $html = PublicPriceApi::formatPrice(10000.0);

        $this->assertStringContainsString('92', $html, '100.00 USD at 0.92 is 92.00 EUR.');
        $this->assertStringContainsString('€', $html);
    }

    #[Test]
    public function testItLeavesTheBaseCurrencyToFluentCart(): void
    {
        CurrencyContextService::reset();
        $this->resetResolvedContext();

        $this->assertSame(
            CurrencySettings::getPriceHtml(10000.0),
            PublicPriceApi::formatPrice(10000.0),
            'A base-currency visitor gets FluentCart’s own formatting, unaltered.',
        );
    }

    #[Test]
    public function testItLeavesEverythingAloneWhileTheModuleIsDisabled(): void
    {
        $settings = $GLOBALS['wp_options'][Constants::OPTION_SETTINGS];
        $settings['enabled'] = 'no';
        $this->setOption(Constants::OPTION_SETTINGS, $settings);
        $_COOKIE[Constants::COOKIE_KEY] = 'EUR';
        CurrencyContextService::reset();
        $this->resetResolvedContext();

        $this->assertSame(
            CurrencySettings::getPriceHtml(10000.0),
            PublicPriceApi::formatPrice(10000.0),
        );
    }

    /**
     * OrderSnapshotHooks::captureAtCheckout() marks a base-display checkout by
     * storing the base code with no rate, purely so saveSnapshot() knows checkout
     * already ran. The documented contract owes null for a base-currency order,
     * so that bookkeeping sentinel must not leak through the public API.
     */
    #[Test]
    public function testItReturnsNullForABaseCurrencyCheckoutSentinel(): void
    {
        $orderId = $this->mockOrder(['_fchub_mc_display_currency' => 'USD']);

        $this->assertNull(
            fchub_mc_get_order_display_currency($orderId),
            'A base-currency checkout leaves a sentinel meta; the contract still owes null.',
        );
    }

    #[Test]
    public function testItReturnsTheDisplayCurrencyOfAConvertedOrder(): void
    {
        $orderId = $this->mockOrder([
            '_fchub_mc_display_currency' => 'EUR',
            '_fchub_mc_base_currency'    => 'USD',
            '_fchub_mc_rate'             => '0.92000000',
        ]);

        $this->assertSame('EUR', fchub_mc_get_order_display_currency($orderId));
    }

    #[Test]
    public function testItFormatsASentinelOrderAsPlainBaseCurrency(): void
    {
        $orderId = $this->mockOrder(['_fchub_mc_display_currency' => 'USD']);

        $this->assertSame(
            CurrencySettings::getPriceHtml(10000.0),
            fchub_mc_format_order_price(10000.0, $orderId),
            'The sentinel carries no rate, so order prices stay FluentCart’s own formatting.',
        );
    }

    /**
     * Registers a mock order charged in USD (the base currency) carrying the
     * given snapshot meta, and returns its id.
     *
     * @param array<string, string> $meta
     */
    private function mockOrder(array $meta): int
    {
        $order = new Order();
        $order->id = 42;
        $order->currency = 'USD';
        foreach ($meta as $key => $value) {
            $order->setMeta($key, $value);
        }
        Order::setMockOrder(42, $order);

        return 42;
    }
}

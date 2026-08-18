<?php

declare(strict_types=1);

namespace FChubMultiCurrency\Tests\Unit\Integration;

use FChubMultiCurrency\Domain\Services\CurrencyContextService;
use FChubMultiCurrency\Integration\PublicPriceApi;
use FChubMultiCurrency\Support\Constants;
use FChubMultiCurrency\Tests\Support\TestCase;
use FluentCart\Api\CurrencySettings;
use PHPUnit\Framework\Attributes\Test;

/**
 * Covers `fchub_mc_format_price()`, the one documented helper that still renders a
 * converted price on the server.
 */
final class PublicPriceApiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
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
}

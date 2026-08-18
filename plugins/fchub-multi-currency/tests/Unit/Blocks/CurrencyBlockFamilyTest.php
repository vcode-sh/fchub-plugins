<?php

declare(strict_types=1);

namespace FChubMultiCurrency\Tests\Unit\Blocks;

use FChubMultiCurrency\Blocks\CurrencyContextNoticeBlock;
use FChubMultiCurrency\Blocks\CurrencyCurrentBlock;
use FChubMultiCurrency\Blocks\CurrencySelectorButtonsBlock;
use FChubMultiCurrency\Blocks\ExchangeRateBlock;
use FChubMultiCurrency\Bootstrap\Modules\FrontendModule;
use FChubMultiCurrency\Tests\Support\TestCase;
use FluentCart\Api\CurrencySettings;
use PHPUnit\Framework\Attributes\Test;

final class CurrencyBlockFamilyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->resetResolvedContext();
        CurrencySettings::setMock(['currency' => 'EUR']);

        $this->setOption('fchub_mc_settings', [
            'enabled' => 'yes',
            'base_currency' => 'EUR',
            'display_currencies' => [
                ['code' => 'USD', 'name' => 'US Dollar', 'symbol' => '$', 'decimals' => 2, 'position' => 'left'],
                ['code' => 'GBP', 'name' => 'British Pound', 'symbol' => '£', 'decimals' => 2, 'position' => 'left'],
            ],
            'switcher_defaults' => [
                'favorite_currencies' => ['GBP'],
            ],
        ]);

        $this->setWpdbMockRow([
            'base_currency' => 'EUR',
            'quote_currency' => 'USD',
            'rate' => '1.10000000',
            'provider' => 'manual',
            'fetched_at' => current_time('mysql'),
        ]);

        FrontendModule::registerAssets();
    }

    #[Test]
    public function testCurrentCurrencyBlockRendersConfiguredMode(): void
    {
        $html = CurrencyCurrentBlock::render(['displayMode' => 'symbol_code']);

        $this->assertStringContainsString('EUR', $html, 'The first frame names the store base.');
        $this->assertStringNotContainsString('USD', $html, 'A cached block must not name one visitor.');
        $this->assertStringContainsString('data-fchub-mc-context-current="symbol_code"', $html);
    }

    #[Test]
    public function testExchangeRateBlockRendersCompactText(): void
    {
        $html = ExchangeRateBlock::render(['precision' => 4, 'format' => 'compact']);

        $this->assertStringContainsString('1 EUR = 1.0000 EUR', $html, 'Base converts at par.');
        $this->assertStringContainsString('data-fchub-mc-context-rate="compact"', $html);
        $this->assertStringContainsString('data-fchub-mc-rate-precision="4"', $html);
        $this->assertStringContainsString('data-fchub-mc-hide-when-base="0"', $html);
    }

    #[Test]
    public function testContextNoticeBlockRendersCompactNotice(): void
    {
        $html = CurrencyContextNoticeBlock::render(['mode' => 'compact']);

        $this->assertStringNotContainsString(
            'Viewing prices in',
            $html,
            'hide-when-base is on and the first frame is the base, so there is nothing to disclose yet.',
        );
        $this->assertStringContainsString('data-fchub-mc-context-notice="compact"', $html);
        $this->assertStringContainsString('data-fchub-mc-hide-when-base="1"', $html);
    }

    #[Test]
    public function testSelectorButtonsBlockPrioritizesFavorites(): void
    {
        $html = CurrencySelectorButtonsBlock::render([
            'favoriteCurrencies' => ['GBP'],
            'showFavoritesFirst' => true,
        ]);

        $this->assertStringContainsString('data-fchub-mc-button-switcher', $html);
        $this->assertStringContainsString('GBP', $html);
        $this->assertStringContainsString('USD', $html);
    }

    /**
     * The rendered block is cacheable HTML served to everyone. Whichever
     * visitor happens to warm the cache, the active button must be the store
     * base — the browser marks the visitor's own choice after the fact.
     */
    #[Test]
    public function testSelectorButtonsBlockMarksTheBaseActiveWhateverThisVisitorResolved(): void
    {
        $_COOKIE['fchub_mc_currency'] = 'USD';

        $html = CurrencySelectorButtonsBlock::render([]);

        $this->assertStringContainsString('is-active" data-value="EUR"', $html, 'The base button is the cached active state.');
        $this->assertStringNotContainsString('is-active" data-value="USD"', $html, 'A cached block must not mark one visitor\'s cookie active.');
    }
}

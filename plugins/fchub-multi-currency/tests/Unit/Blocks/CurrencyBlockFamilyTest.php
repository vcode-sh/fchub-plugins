<?php

declare(strict_types=1);

namespace FChubMultiCurrency\Tests\Unit\Blocks;

use FChubMultiCurrency\Blocks\CurrencyContextNoticeBlock;
use FChubMultiCurrency\Blocks\CurrencyCurrentBlock;
use FChubMultiCurrency\Blocks\CurrencySelectorButtonsBlock;
use FChubMultiCurrency\Blocks\ExchangeRateBlock;
use FChubMultiCurrency\Bootstrap\Modules\FrontendModule;
use FChubMultiCurrency\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class CurrencyBlockFamilyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->resetContextModuleCache();

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

        $this->assertStringContainsString('$', $html);
        $this->assertStringContainsString('USD', $html);
    }

    #[Test]
    public function testExchangeRateBlockRendersCompactText(): void
    {
        $html = ExchangeRateBlock::render(['precision' => 4, 'format' => 'compact']);

        $this->assertStringContainsString('1 EUR = 1.1000 USD', $html);
    }

    #[Test]
    public function testContextNoticeBlockRendersCompactNotice(): void
    {
        $html = CurrencyContextNoticeBlock::render(['mode' => 'compact']);

        $this->assertStringContainsString('Viewing prices in USD. Checkout in EUR.', $html);
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

    private function resetContextModuleCache(): void
    {
        $ref = new \ReflectionClass(\FChubMultiCurrency\Bootstrap\Modules\ContextModule::class);
        $prop = $ref->getProperty('cachedChain');
        $prop->setAccessible(true);
        $prop->setValue(null, null);
    }
}

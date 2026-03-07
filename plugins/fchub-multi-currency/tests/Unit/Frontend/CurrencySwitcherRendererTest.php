<?php

declare(strict_types=1);

namespace FChubMultiCurrency\Tests\Unit\Frontend;

use FChubMultiCurrency\Bootstrap\Modules\FrontendModule;
use FChubMultiCurrency\Frontend\CurrencySwitcherRenderer;
use FChubMultiCurrency\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class CurrencySwitcherRendererTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->resetContextModuleCache();

        $this->setOption('fchub_mc_settings', [
            'enabled'             => 'yes',
            'base_currency'       => 'EUR',
            'display_currencies'  => [
                ['code' => 'USD', 'name' => 'US Dollar', 'symbol' => '$', 'decimals' => 2, 'position' => 'left'],
                ['code' => 'GBP', 'name' => 'British Pound', 'symbol' => '£', 'decimals' => 2, 'position' => 'left'],
            ],
            'show_rate_freshness_badge' => 'yes',
            'switcher_defaults' => [
                'show_symbol' => 'yes',
                'show_code' => 'no',
                'dropdown_position' => 'auto',
                'dropdown_direction' => 'auto',
                'favorite_currencies' => ['GBP'],
                'show_favorites_first' => 'yes',
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
    public function testRenderShortcodeEnqueuesAssetsAndIncludesNoscriptFallback(): void
    {
        $html = FrontendModule::renderSwitcher(['label' => 'Currency']);

        $this->assertScriptEnqueued('fchub-mc-switcher');
        $this->assertStyleEnqueued('fchub-mc-switcher');
        $this->assertStringContainsString('fchub-mc-switcher-fallback-form', $html);
        $this->assertStringContainsString(CurrencySwitcherRenderer::NOSCRIPT_NONCE, $html);
        $this->assertStringContainsString('Currency', $html);
    }

    #[Test]
    public function testRendererEscapesMaliciousLabelInput(): void
    {
        $html = FrontendModule::renderSwitcher([
            'label' => '<script>alert("x")</script><b>Currency</b>',
        ]);

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringNotContainsString('<b>', $html);
        $this->assertStringContainsString('Currency', $html);
    }

    #[Test]
    public function testRendererKeepsCurrencyCodeVisibleWhenAllTriggerTextOptionsAreDisabled(): void
    {
        $html = FrontendModule::renderSwitcher([
            'show_code' => 'no',
            'show_name' => 'no',
            'show_symbol' => 'no',
        ]);

        $this->assertStringContainsString('class="fchub-mc-switcher__code">USD</span>', $html);
    }

    #[Test]
    public function testRendererCanShowCurrentCurrencyNameInTrigger(): void
    {
        $html = FrontendModule::renderSwitcher([
            'show_code' => 'no',
            'show_name' => 'yes',
        ]);

        $this->assertStringContainsString('class="fchub-mc-switcher__name">US Dollar</span>', $html);
        $this->assertStringNotContainsString('class="fchub-mc-switcher__code">USD</span>', $html);
    }

    #[Test]
    public function testRendererCanHideRateBadgePerInstance(): void
    {
        $html = FrontendModule::renderSwitcher([
            'show_rate_badge' => 'no',
        ]);

        $this->assertStringNotContainsString('fchub-mc-rate-badge', $html);
    }

    #[Test]
    public function testRendererSupportsSymbolAndDropdownDirectionConfiguration(): void
    {
        $html = FrontendModule::renderSwitcher([
            'show_code' => 'no',
            'show_symbol' => 'yes',
            'dropdown_direction' => 'up',
        ]);

        $this->assertStringContainsString('class="fchub-mc-switcher__symbol">$</span>', $html);
        $this->assertStringContainsString('fchub-mc-switcher--direction-up', $html);
    }

    #[Test]
    public function testRendererSupportsOptionVisibilityControlsAndActiveIndicator(): void
    {
        $html = FrontendModule::renderSwitcher([
            'show_option_flags' => 'no',
            'show_option_codes' => 'no',
            'show_option_symbols' => 'yes',
            'show_option_names' => 'yes',
            'show_active_indicator' => 'yes',
        ]);

        $this->assertStringContainsString('class="fchub-mc-switcher__option-symbol">$</span>', $html);
        $this->assertStringNotContainsString('class="fchub-mc-switcher__option-code">USD</span>', $html);
        $this->assertStringContainsString('class="fchub-mc-switcher__option-check" aria-hidden="true">&#10003;</span>', $html);
    }

    #[Test]
    public function testRendererCanRenderRateValueAndCheckoutContextFooter(): void
    {
        $html = FrontendModule::renderSwitcher([
            'show_rate_value' => 'yes',
            'show_context_note' => 'yes',
        ]);

        $this->assertStringContainsString('1 EUR = 1.10000000 USD', $html);
        $this->assertStringContainsString('Display prices only. Checkout is charged in EUR.', $html);
    }

    #[Test]
    public function testRendererSupportsExpandedLabelPositionModel(): void
    {
        $html = FrontendModule::renderSwitcher([
            'label' => 'Currency',
            'label_position' => 'below',
        ]);

        $this->assertStringContainsString('fchub-mc-switcher-stage--label-below', $html);
    }

    #[Test]
    public function testShortcodeInheritsGlobalSwitcherDefaults(): void
    {
        $html = FrontendModule::renderSwitcher([]);

        $this->assertStringContainsString('class="fchub-mc-switcher__symbol">$</span>', $html);
        $this->assertStringNotContainsString('class="fchub-mc-switcher__code">USD</span>', $html);
    }

    private function resetContextModuleCache(): void
    {
        $ref = new \ReflectionClass(\FChubMultiCurrency\Bootstrap\Modules\ContextModule::class);
        $prop = $ref->getProperty('cachedChain');
        $prop->setAccessible(true);
        $prop->setValue(null, null);
    }
}

<?php

declare(strict_types=1);

namespace FChubMultiCurrency\Tests\Unit\Bootstrap;

use FChubMultiCurrency\Bootstrap\Modules\FrontendModule;
use FChubMultiCurrency\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class FrontendModuleTest extends TestCase
{
    #[Test]
    public function testRenderSwitcherShowsBaseCurrencyOptionAndSelectedCodeWhenBaseIsChosen(): void
    {
        $_GET = [];
        $_COOKIE = [];

        $this->setOption('fchub_mc_settings', [
            'enabled' => 'yes',
            'base_currency' => 'EUR',
            'default_display_currency' => 'USD',
            'display_currencies' => [
                [
                    'code' => 'USD',
                    'name' => 'US Dollar',
                    'symbol' => '$',
                    'decimals' => 2,
                    'position' => 'left',
                ],
            ],
        ]);

        // No EUR->USD rate available, so context should fall back to base (EUR).
        $this->setWpdbMockRow(null);

        $html = FrontendModule::renderSwitcher([]);

        $this->assertStringContainsString('class="fchub-mc-switcher__code">EUR</span>', $html);
        $this->assertStringContainsString('data-value="EUR"', $html);
        $this->assertStringContainsString('data-value="USD"', $html);
    }
}

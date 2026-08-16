<?php

declare(strict_types=1);

namespace FChubMultiCurrency\Tests\Unit\Integration;

use FChubMultiCurrency\Integration\MultiCurrencySettings;
use FChubMultiCurrency\Tests\Support\TestCase;
use FluentCart\Api\CurrencySettings;
use PHPUnit\Framework\Attributes\Test;

final class MultiCurrencySettingsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        CurrencySettings::setMock(['currency' => 'EUR']);
        $this->setOption('fchub_mc_settings', [
            'base_currency' => 'PLN',
            'enabled' => 'yes',
        ]);
    }

    #[Test]
    public function testGlobalPanelReadsFluentCartsCanonicalBaseCurrency(): void
    {
        $settings = MultiCurrencySettings::getSettings();

        $this->assertSame('EUR', $settings['base_currency']);
    }

    #[Test]
    public function testSavingGlobalPanelCannotRestoreAStalePluginBaseCurrency(): void
    {
        MultiCurrencySettings::saveGlobalSettings([
            'integration' => [
                'enabled' => 'no',
                'checkout_disclosure_enabled' => 'yes',
                'fluentcrm_enabled' => 'no',
            ],
        ]);

        $saved = get_option('fchub_mc_settings');
        $this->assertSame('EUR', $saved['base_currency']);
        $this->assertSame('no', $saved['enabled']);
        $this->assertSame(200, $GLOBALS['wp_send_json_status']);
    }
}

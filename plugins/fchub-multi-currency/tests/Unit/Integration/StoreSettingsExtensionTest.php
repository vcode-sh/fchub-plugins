<?php

declare(strict_types=1);

namespace FChubMultiCurrency\Tests\Unit\Integration;

use FChubMultiCurrency\Integration\StoreSettingsExtension;
use FChubMultiCurrency\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class StoreSettingsExtensionTest extends TestCase
{
    #[Test]
    public function testAddValuesIncludesMultiCurrencySettings(): void
    {
        $this->setOption('fchub_mc_settings', ['enabled' => 'yes', 'base_currency' => 'PLN']);
        $this->setOption('fluent_cart_store_settings', ['currency' => 'EUR']);

        $values = StoreSettingsExtension::addValues(['currency' => 'USD']);

        $this->assertSame('yes', $values['fchub_mc_enabled']);
        $this->assertSame('EUR', $values['fchub_mc_base_currency']);
    }

    #[Test]
    public function testAddValuesDefaultsWhenNoSettings(): void
    {
        $values = StoreSettingsExtension::addValues([]);

        $this->assertSame('yes', $values['fchub_mc_enabled']);
        $this->assertSame('USD', $values['fchub_mc_base_currency']);
    }
}

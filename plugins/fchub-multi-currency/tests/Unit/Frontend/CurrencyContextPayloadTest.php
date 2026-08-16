<?php

declare(strict_types=1);

namespace FChubMultiCurrency\Tests\Unit\Frontend;

use FChubMultiCurrency\Frontend\CurrencyContextPayload;
use FChubMultiCurrency\Storage\OptionStore;
use FChubMultiCurrency\Tests\Support\MockBuilder;
use FChubMultiCurrency\Tests\Support\TestCase;
use FluentCart\Api\CurrencySettings;
use PHPUnit\Framework\Attributes\Test;

final class CurrencyContextPayloadTest extends TestCase
{
    #[Test]
    public function testAutomaticDisplaySeparatorsFollowFluentCartsNumberFormat(): void
    {
        CurrencySettings::setMock([
            'currency' => 'USD',
            'decimal_separator' => 'comma',
        ]);
        $this->setOption('fchub_mc_settings', [
            'display_currencies' => [[
                'code' => 'EUR',
                'name' => 'Euro',
                'symbol' => '€',
                'decimals' => 2,
                'position' => 'left',
                'decimal_separator' => '',
                'thousand_separator' => '',
            ]],
        ]);

        $payload = CurrencyContextPayload::build(
            MockBuilder::context(['display_code' => 'EUR']),
            new OptionStore(),
        );

        $this->assertSame(',', $payload['displayDecSep']);
        $this->assertSame('.', $payload['displayThousandSep']);
    }

    #[Test]
    public function testExplicitlyDisabledThousandsSeparatorSurvivesThePayload(): void
    {
        $this->setOption('fchub_mc_settings', [
            'display_currencies' => [[
                'code' => 'EUR',
                'name' => 'Euro',
                'symbol' => '€',
                'decimals' => 2,
                'position' => 'left',
                'decimal_separator' => ',',
                'thousand_separator' => 'none',
            ]],
        ]);

        $payload = CurrencyContextPayload::build(
            MockBuilder::context(['display_code' => 'EUR']),
            new OptionStore(),
        );

        $this->assertSame('', $payload['displayThousandSep']);
    }
}

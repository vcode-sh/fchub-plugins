<?php

declare(strict_types=1);

namespace FChubMultiCurrency\Tests\Unit\Integration;

use FChubMultiCurrency\Integration\DisplayPriceFormatter;
use FChubMultiCurrency\Storage\OptionStore;
use FChubMultiCurrency\Tests\Support\TestCase;
use FluentCart\Api\CurrencySettings;
use PHPUnit\Framework\Attributes\Test;

/**
 * Pins the FluentCart-fallback hydration to FluentCart 1.6.1's own rendering.
 *
 * A currency absent from the plugin's display_currencies hydrates from
 * FluentCart's settings, so its formatted shape must match what
 * `Helper::toDecimal()` renders for the same `currency_position` — one test
 * per position value FluentCart ships. The two positions that put the sign on
 * one side of the amount and the ISO code on the other (`symbool_before_iso`,
 * `symbool_after_iso`) cannot be reproduced by a one-sided symbol slot; the
 * fallback keeps their ISO side, dropping only the sign.
 */
final class DisplayPriceFormatterTest extends TestCase
{
    /** Formats 12.00 USD through the fallback path under one FluentCart position. */
    private function formatUsd(string $fluentCartPosition): string
    {
        CurrencySettings::setMock([
            'currency' => 'USD',
            'currency_sign' => '$',
            'currency_position' => $fluentCartPosition,
        ]);
        $this->setOption('fchub_mc_settings', [
            'base_currency' => 'USD',
            'display_currencies' => [],
        ]);

        return DisplayPriceFormatter::format(1200.0, '1.00000000', 'USD', new OptionStore());
    }

    #[Test]
    public function testBeforePositionRendersTheSignBeforeTheAmount(): void
    {
        $this->assertSame('$12.00', $this->formatUsd('before'));
    }

    #[Test]
    public function testAfterPositionRendersTheSignAfterTheAmount(): void
    {
        $this->assertSame('12.00$', $this->formatUsd('after'));
    }

    #[Test]
    public function testIsoBeforePositionRendersTheCodeBeforeTheAmount(): void
    {
        $this->assertSame('USD 12.00', $this->formatUsd('iso_before'));
    }

    #[Test]
    public function testIsoAfterPositionRendersTheCodeAfterTheAmount(): void
    {
        $this->assertSame('12.00 USD', $this->formatUsd('iso_after'));
    }

    #[Test]
    public function testSymboolBeforeIsoPositionKeepsItsIsoSide(): void
    {
        // FluentCart renders "$12.00 USD"; the one-sided symbol slot keeps the
        // ISO side and drops only the sign.
        $this->assertSame('12.00 USD', $this->formatUsd('symbool_before_iso'));
    }

    #[Test]
    public function testSymboolAfterIsoPositionKeepsItsIsoSide(): void
    {
        // FluentCart renders "USD 12.00$"; the one-sided symbol slot keeps the
        // ISO side and drops only the sign.
        $this->assertSame('USD 12.00', $this->formatUsd('symbool_after_iso'));
    }

    #[Test]
    public function testSymboolAndIsoPositionRendersFluentCartsExactPrefix(): void
    {
        $this->assertSame('USD $12.00', $this->formatUsd('symbool_and_iso'));
    }
}

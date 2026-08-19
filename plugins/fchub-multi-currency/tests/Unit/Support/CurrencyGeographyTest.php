<?php

declare(strict_types=1);

namespace FChubMultiCurrency\Tests\Unit\Support;

use FChubMultiCurrency\Support\CurrencyGeography;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CurrencyGeographyTest extends TestCase
{
    #[Test]
    public function testMapsTimezonesOnlyForOfferedCurrencies(): void
    {
        $map = CurrencyGeography::timezoneMap(['PLN', 'GBP']);

        self::assertSame('PLN', $map['Europe/Warsaw']);
        self::assertSame('GBP', $map['Europe/London']);
        self::assertArrayNotHasKey('Europe/Berlin', $map, 'EUR is not offered, so its zones ship nothing.');
        self::assertArrayNotHasKey('America/New_York', $map);
    }

    #[Test]
    public function testMultiCountryCurrenciesCoverEveryMemberZone(): void
    {
        $map = CurrencyGeography::timezoneMap(['EUR']);

        self::assertSame('EUR', $map['Europe/Berlin']);
        self::assertSame('EUR', $map['Europe/Paris']);
        self::assertSame('EUR', $map['Europe/Lisbon']);
    }

    #[Test]
    public function testUnknownCodesAndEmptyInputProduceAnEmptyMap(): void
    {
        self::assertSame([], CurrencyGeography::timezoneMap([]));
        self::assertSame([], CurrencyGeography::timezoneMap(['XXX']));
    }

    /**
     * ICU — the engine behind Intl in Chrome and Node — reports
     * CLDR-canonical zone ids, which for a handful of zones are the OLD
     * IANA names: Asia/Calcutta, Europe/Kiev, America/Buenos_Aires. PHP
     * only lists IANA-canonical names per country, so without the alias
     * rows a Chrome visitor in India would never match the map.
     */
    #[Test]
    public function testCoversTheAliasSpellingsBrowsersActuallyReport(): void
    {
        self::assertSame('INR', CurrencyGeography::timezoneMap(['INR'])['Asia/Calcutta']);
        self::assertSame('UAH', CurrencyGeography::timezoneMap(['UAH'])['Europe/Kiev']);
        self::assertSame('VND', CurrencyGeography::timezoneMap(['VND'])['Asia/Saigon']);
        self::assertSame('ARS', CurrencyGeography::timezoneMap(['ARS'])['America/Buenos_Aires']);
        self::assertSame('USD', CurrencyGeography::timezoneMap(['USD'])['America/Indianapolis']);

        self::assertArrayNotHasKey(
            'Asia/Calcutta',
            CurrencyGeography::timezoneMap(['PLN']),
            'An alias ships only when its currency is offered, like every other zone.',
        );
    }

    #[Test]
    public function testLowercaseInputResolvesTheSameMap(): void
    {
        self::assertSame(
            CurrencyGeography::timezoneMap(['PLN']),
            CurrencyGeography::timezoneMap(['pln']),
            'Settings-sourced codes are normalized, not trusted.',
        );
    }
}

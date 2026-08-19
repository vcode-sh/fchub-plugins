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

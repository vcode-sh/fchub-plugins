<?php

declare(strict_types=1);

namespace FChubMultiCurrency\Tests\Unit\Domain\ValueObjects;

use FChubMultiCurrency\Domain\ValueObjects\SelectableCurrencyCodes;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SelectableCurrencyCodesTest extends TestCase
{
    #[Test]
    public function itNormalizesTheBaseAndConfiguredCurrenciesIntoOneSet(): void
    {
        $codes = SelectableCurrencyCodes::from('usd', [
            ['code' => 'eur'],
            ['code' => 'EUR'],
            ['code' => ''],
            'not-a-currency',
        ]);

        self::assertSame(['USD', 'EUR'], $codes->all());
        self::assertSame(['EUR'], $codes->quoteCurrencies());
        self::assertTrue($codes->contains('eur'));
        self::assertFalse($codes->contains('GBP'));

        self::assertSame(['GBP', 'JPY'], SelectableCurrencyCodes::fromSettings([
            'base_currency' => 'gbp',
            'display_currencies' => [['code' => 'jpy']],
        ])->all());
    }
}

<?php

declare(strict_types=1);

namespace FChubMultiCurrency\Tests\Unit\Http\Controllers\Admin;

use FChubMultiCurrency\Http\Controllers\Admin\CurrencyCatalogueController;
use FChubMultiCurrency\Tests\Support\TestCase;

final class CurrencyCatalogueControllerTest extends TestCase
{
    public function testCodeToFlagReturnsCorrectEmojiForUsd(): void
    {
        $flag = CurrencyCatalogueController::codeToFlag('USD');

        // USD → US country code → 🇺🇸
        // Regional indicators: U = U+1F1FA, S = U+1F1F8
        $expected = mb_chr(0x1F1FA) . mb_chr(0x1F1F8);
        $this->assertSame($expected, $flag);
    }

    public function testCodeToFlagUsesOverrideForEur(): void
    {
        $flag = CurrencyCatalogueController::codeToFlag('EUR');

        // EUR → EU override → 🇪🇺
        $expected = mb_chr(0x1F1EA) . mb_chr(0x1F1FA);
        $this->assertSame($expected, $flag);
    }

    public function testCodeToFlagUsesOverrideForGbp(): void
    {
        $flag = CurrencyCatalogueController::codeToFlag('GBP');

        // GBP → GB override → 🇬🇧
        $expected = mb_chr(0x1F1EC) . mb_chr(0x1F1E7);
        $this->assertSame($expected, $flag);
    }

    public function testCodeToFlagReturnsEmptyForMultiCountryCurrencies(): void
    {
        // XAF, XCD, XOF, XPF map to '' — no single country
        $this->assertSame('', CurrencyCatalogueController::codeToFlag('XAF'));
        $this->assertSame('', CurrencyCatalogueController::codeToFlag('XOF'));
    }

    public function testCodeToFlagFallsBackToFirstTwoLetters(): void
    {
        // PLN → PL (Poland) → 🇵🇱
        $flag = CurrencyCatalogueController::codeToFlag('PLN');
        $expected = mb_chr(0x1F1F5) . mb_chr(0x1F1F1);
        $this->assertSame($expected, $flag);
    }

    public function testCodeToFlagProducesUnicodeRegionalIndicators(): void
    {
        $flag = CurrencyCatalogueController::codeToFlag('USD');

        // Verify it's 2 multibyte characters (regional indicator pairs)
        $this->assertSame(2, mb_strlen($flag));

        // Each regional indicator is 4 bytes in UTF-8
        $this->assertSame(8, strlen($flag));
    }

    public function testGetCatalogueReturnsEnrichedData(): void
    {
        $catalogue = CurrencyCatalogueController::getCatalogue();

        $this->assertNotEmpty($catalogue);

        // Verify structure of each entry
        $first = $catalogue[0];
        $this->assertArrayHasKey('code', $first);
        $this->assertArrayHasKey('name', $first);
        $this->assertArrayHasKey('symbol', $first);
        $this->assertArrayHasKey('decimals', $first);
        $this->assertArrayHasKey('flag', $first);
    }

    public function testGetCatalogueIncludesZeroDecimalCurrencies(): void
    {
        $catalogue = CurrencyCatalogueController::getCatalogue();
        $jpyEntry = null;

        foreach ($catalogue as $entry) {
            if ($entry['code'] === 'JPY') {
                $jpyEntry = $entry;
                break;
            }
        }

        $this->assertNotNull($jpyEntry, 'JPY should be in catalogue');
        $this->assertSame(0, $jpyEntry['decimals']);
    }
}

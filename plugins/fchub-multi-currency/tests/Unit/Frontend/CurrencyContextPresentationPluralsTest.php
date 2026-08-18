<?php

declare(strict_types=1);

namespace FChubMultiCurrency\Tests\Unit\Frontend;

use FChubMultiCurrency\Frontend\CurrencyContextPresentation;
use FChubMultiCurrency\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * The browser renders rate freshness at paint time, so it must pick the
 * plural form for a live count itself. The payload therefore ships every
 * plural form per time unit plus the site locale's selection rule as a
 * lookup table: indices for n = 0..200, where counts past 200 repeat the
 * 101..200 block — gettext rules depend only on n, n%10 and n%100.
 */
final class CurrencyContextPresentationPluralsTest extends TestCase
{
    public const POLISH_HEADER =
        'nplurals=3; plural=(n==1 ? 0 : n%10>=2 && n%10<=4 && (n%100<10 || n%100>=20) ? 1 : 2);';

    protected function tearDown(): void
    {
        unset($GLOBALS['wp_domain_translations']);
        parent::tearDown();
    }

    #[Test]
    public function testAnUntranslatedStoreShipsTheEnglishPairAndRule(): void
    {
        $templates = CurrencyContextPresentation::templates();

        self::assertSame(['%s min', '%s mins'], $templates['timeUnits']['min']);
        self::assertSame(['%s hour', '%s hours'], $templates['timeUnits']['hour']);

        $table = $templates['timePluralRule'];
        self::assertCount(201, $table);
        self::assertSame(0, $table[1], 'Only n=1 selects the singular in English.');
        self::assertSame(1, $table[0]);
        self::assertSame(1, $table[2]);
        self::assertSame(1, $table[101]);
        self::assertSame(1, $table[200]);
    }

    #[Test]
    public function testATranslatedDomainShipsEveryNativeFormAndItsRule(): void
    {
        $GLOBALS['wp_domain_translations']['fchub-multi-currency'] = new class {
            public function get_header(string $header): string|false
            {
                return $header === 'Plural-Forms'
                    ? CurrencyContextPresentationPluralsTest::POLISH_HEADER
                    : false;
            }

            public function translate_plural(string $single, string $plural, int $count): string
            {
                $index = $count === 1
                    ? 0
                    : ($count % 10 >= 2 && $count % 10 <= 4 && ($count % 100 < 10 || $count % 100 >= 20) ? 1 : 2);

                if ($single === '%s hour') {
                    return ['%s godzina', '%s godziny', '%s godzin'][$index];
                }

                return $index === 0 ? $single : $plural;
            }
        };

        $templates = CurrencyContextPresentation::templates();

        self::assertSame(
            ['%s godzina', '%s godziny', '%s godzin'],
            $templates['timeUnits']['hour'],
            'All three Polish forms ship, not a truncated pair.',
        );

        $table = $templates['timePluralRule'];
        self::assertCount(201, $table);
        self::assertSame(0, $table[1], '1 godzina');
        self::assertSame(1, $table[2], '2 godziny');
        self::assertSame(2, $table[5], '5 godzin');
        self::assertSame(2, $table[12], 'teens take the many form');
        self::assertSame(1, $table[22], '22 godziny — few returns past the teens');
        self::assertSame(2, $table[112], '112 godzin');
        self::assertSame(1, $table[122], '122 godziny');
        self::assertSame(2, $table[0]);
    }

    #[Test]
    public function testAMalformedPluralHeaderFallsBackToTheEnglishRule(): void
    {
        $GLOBALS['wp_domain_translations']['fchub-multi-currency'] = new class {
            public function get_header(string $header): string|false
            {
                return $header === 'Plural-Forms' ? 'nplurals=9; plural=exotic;' : false;
            }

            public function translate_plural(string $single, string $plural, int $count): string
            {
                return $count === 1 ? $single : $plural;
            }
        };

        $templates = CurrencyContextPresentation::templates();

        self::assertSame(0, $templates['timePluralRule'][1]);
        self::assertSame(1, $templates['timePluralRule'][5]);
        self::assertCount(2, $templates['timeUnits']['hour'], 'English pair when the rule cannot be parsed.');
    }
}

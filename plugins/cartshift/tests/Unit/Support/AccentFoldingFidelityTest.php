<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\Support;

use CartShift\Tests\Unit\PluginTestCase;

/**
 * Proves the test harness's remove_accents() is the real thing, not a shrug.
 *
 * ProductMatcher and VariantResolver now fold accents before comparing, so
 * remove_accents() decides whether a Polish catalogue matches at all. The
 * danger is not that the stub is wrong in some subtle corner — it is that a
 * stub which returns its argument makes every accent test in this suite pass
 * while production folds nothing. That failure is silent, and this project has
 * already been bitten by a stale stub once (see the CARTSHIFT_DB_VERSION note
 * in test-bootstrap.php).
 *
 * So the guarantee is a golden vector, not an opinion. GOLDEN_INPUT is every
 * character in WordPress's own replacement table, in table order.
 * GOLDEN_OUTPUT is what the real remove_accents() returned for that exact
 * string on a live WordPress install (pl_PL, WordPress 6.x, intl loaded).
 * A no-op stub fails on character one; a table that drifts from WordPress's
 * fails on whichever character drifted.
 */
final class AccentFoldingFidelityTest extends PluginTestCase
{
    /** Every character WordPress's remove_accents() table touches, in its order. */
    private const string GOLDEN_INPUT =
        'ªºÀÁÂÃÄÅÆÇÈÉÊËÌÍÎÏÐÑÒÓÔÕÖÙÚÛÜÝÞßàáâãäåæçèéêëìíîïðñòóôõöøùúûüýþÿØ'
        . 'ĀāĂăĄąĆćĈĉĊċČčĎďĐđĒēĔĕĖėĘęĚěĜĝĞğĠġĢģĤĥĦħĨĩĪīĬĭĮįİıĲĳĴĵĶķĸĹĺĻļĽľĿŀŁł'
        . 'ŃńŅņŇňŉŊŋŌōŎŏŐőŒœŔŕŖŗŘřŚśŜŝŞşŠšŢţŤťŦŧŨũŪūŬŭŮůŰűŲųŴŵŶŷŸŹźŻżŽžſ'
        . 'ƏǝȘșȚț€£ƠơƯư'
        . 'ẦầẰằỀềỒồỜờỪừỲỳẢảẨẩẲẳẺẻỂểỈỉỎỏỔổỞởỦủỬửỶỷẪẫẴẵẼẽỄễỖỗỠỡỮữỸỹ'
        . 'ẤấẮắẾếỐốỚớỨứẠạẬậẶặẸẹỆệỊịỌọỘộỢợỤụỰựỴỵɑ'
        . 'ǕǖǗǘǍǎǏǐǑǒǓǔǙǚǛǜ';

    /** What real WordPress answers for GOLDEN_INPUT. Captured, not reasoned about. */
    private const string GOLDEN_OUTPUT =
        'aoAAAAAAAECEEEEIIIIDNOOOOOUUUUYTHsaaaaaaaeceeeeiiiidnoooooouuuuythyO'
        . 'AaAaAaCcCcCcCcDdDdEeEeEeEeEeGgGgGgGgHhHhIiIiIiIiIiIJijJjKkkLlLlLlLlLl'
        . 'NnNnNnnNnOoOoOoOEoeRrRrRrSsSsSsSsTtTtTtUuUuUuUuUuUuWwYyYZzZzZzs'
        . 'EeSsTtEOoUu'
        . 'AaAaEeOoOoUuYyAaAaAaEeEeIiOoOoOoUuUuYyAaAaEeEeOoOoUuYy'
        . 'AaAaEeOoOoUuAaAaAaEeEeIiOoOoOoUuUuYya'
        . 'UuUuAaIiOoUuUuUu';

    /**
     * The whole table, in one comparison, against real WordPress's answer.
     *
     * This is the assertion that makes a no-op stub impossible: the two strings
     * differ at index 0 ('ª' vs 'a') and stay different for most of their
     * length, so nothing that returns its input can pass.
     */
    public function testTheStubReproducesWordPressCharacterForCharacter(): void
    {
        $this->assertSame(self::GOLDEN_OUTPUT, remove_accents(self::GOLDEN_INPUT));
    }

    /**
     * Belt and braces on the same point, stated as the property rather than the
     * value: if remove_accents() ever becomes an identity function, this fails
     * even if someone regenerates the golden pair from the broken stub.
     */
    public function testRemoveAccentsIsNotAnIdentityFunction(): void
    {
        $this->assertNotSame(
            self::GOLDEN_INPUT,
            remove_accents(self::GOLDEN_INPUT),
            'remove_accents() returned its argument. A no-op stub turns every accent test in this suite into a lie.',
        );
    }

    /**
     * The nine characters that made this whole change necessary.
     *
     * Transliterated, never dropped — the owner's explicit instruction. Both
     * cases verified on a live install: 'ĄĆĘŁŃÓŚŹŻ' => 'ACELNOSZZ'.
     */
    public function testEveryPolishDiacriticFoldsToItsLatinLetter(): void
    {
        $this->assertSame('ACELNOSZZ', remove_accents('ĄĆĘŁŃÓŚŹŻ'));
        $this->assertSame('acelnoszz', remove_accents('ąćęłńóśźż'));
        $this->assertSame('Zolc gesla jazn', remove_accents('Żółć gęślą jaźń'));
    }

    /**
     * Decomposed input, which is what a paste from macOS's own text fields
     * produces: 'Z' followed by a combining dot above is the same letter as
     * 'Ż', and WordPress normalises NFD to NFC before it consults the table.
     * Skipped rather than failed where intl is absent, because WordPress skips
     * it too.
     */
    public function testDecomposedInputIsNormalisedBeforeFolding(): void
    {
        if (!function_exists('normalizer_normalize')) {
            $this->markTestSkipped('intl is not loaded; WordPress skips the NFD-to-NFC step here as well.');
        }

        $this->assertSame('Zolty', remove_accents("Z\u{0307}o\u{0301}l\u{0301}ty"));
    }

    /**
     * Two entries that look like bugs and are not. WordPress maps the euro sign
     * to 'E' and deletes the pound sign outright. Pinned so nobody "corrects"
     * the table into disagreeing with production.
     */
    public function testTheCurrencyOddballsMatchWordPressRatherThanIntuition(): void
    {
        $this->assertSame('E', remove_accents('€'));
        $this->assertSame('', remove_accents('£'));
    }

    /**
     * ASCII takes WordPress's early return, untouched — including the ASCII
     * that a folded Polish name turns into, so folding is idempotent.
     */
    public function testAsciiIsUntouched(): void
    {
        $this->assertSame('Zolty kabel USB-C 12w1', remove_accents('Zolty kabel USB-C 12w1'));
        $this->assertSame(
            remove_accents('Żółty kabel USB-C 12w1'),
            remove_accents(remove_accents('Żółty kabel USB-C 12w1')),
        );
    }

    /**
     * sanitize_title() is the other stub production leans on, and the one that
     * used to lie: the old byte-oriented version turned 'Żółty' into '------ty'.
     * Every expectation here was read off a live install.
     */
    public function testSanitizeTitleTransliteratesRatherThanShreds(): void
    {
        $this->assertSame('zolty', sanitize_title('Żółty'));
        $this->assertSame('zolc-gesla-jazn', sanitize_title('Żółć  gęślą   jaźń'));
        $this->assertSame('bialy-rozmiar-xl', sanitize_title('Biały / Rozmiar XL'));
        $this->assertSame('rosso-large', sanitize_title('Rosso / Large'));
        $this->assertSame('sr-12', sanitize_title('Śr. 12"'));
        $this->assertSame('grose-xl', sanitize_title('Größe XL'));
        $this->assertSame('default', sanitize_title('Default'));
    }

    /**
     * An attribute slug arrives at ProductMigrator already sanitised, and is
     * sanitised again on the way into the composite key. That second pass has
     * to be a no-op or the key stops matching the one stored last run.
     */
    public function testSanitizeTitleIsIdempotent(): void
    {
        foreach (['Żółty / Rozmiar XL', 'Śr. 12"', 'Rosso / Large', 'Größe XL'] as $input) {
            $once = sanitize_title($input);

            $this->assertSame($once, sanitize_title($once), "sanitize_title() is not idempotent for '{$input}'.");
        }
    }
}

<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\Support;

use CartShift\Support\SkuAllocator;
use CartShift\Tests\Unit\PluginTestCase;

require_once dirname(__DIR__, 2) . '/stubs/FluentCartModelStubs.php';
require_once dirname(__DIR__, 2) . '/stubs/MbstringAbsence.php';

/**
 * The allocator on a host with no mbstring — which is a host PHP allows and
 * this plugin's composer.json permits, since it requires php >= 8.3 and nothing
 * else, and production autoloads through spl_autoload_register rather than
 * Composer anyway.
 *
 * The failure was not subtle. Every SKU that is not empty goes through clamp(),
 * so the first migrated variation with a SKU raised "Call to undefined function
 * mb_strlen()" and took the batch with it. The rest of the codebase had already
 * settled the pattern — CouponMigrator::collationKey(), ProductMatcher and
 * VariantResolver all guard theirs — and this was the one place that did not.
 *
 * The fallback is PCRE with `/u`, deliberately not strlen/substr. `sku` is
 * varchar(30) and MySQL counts that in characters, so a byte clamp truncates a
 * Polish or a Japanese SKU far shorter than the column would have, and a byte
 * *slice* can cut a multibyte sequence in half and store invalid UTF-8 in a
 * globally UNIQUE column. Both are tested below, against the same inputs the
 * mbstring path handles, because a fallback that merely does not crash is not
 * the same thing as a fallback that is correct.
 *
 * @see \CartShift\Tests\Unit\Support\SkuAllocatorTest for the same class with mbstring present.
 */
final class SkuAllocatorWithoutMbstringTest extends PluginTestCase
{
    /**
     * Thirty-three characters — over the column's thirty — and sixty-five bytes.
     *
     * One ASCII character in front of thirty-two two-byte ones, so that the
     * thirtieth *byte* falls in the middle of a character rather than neatly
     * between two. A string of nothing but two-byte characters would survive a
     * naive byte clamp intact and prove nothing about the interesting failure.
     */
    private const string LONG_MULTIBYTE_SKU = 'AŻÓŁĄŚĆŃŹŻÓŁĄŚĆŃŹŻÓŁĄŚĆŃŹŻÓŁĄŚĆŃŹ';

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        \CartShiftFcModelStore::install();

        $GLOBALS['_cartshift_test_no_mbstring'] = true;
    }

    #[\Override]
    protected function tearDown(): void
    {
        unset($GLOBALS['_cartshift_test_no_mbstring']);

        parent::tearDown();
    }

    /**
     * Fixture check. If this ever stops holding, every other test in this file
     * is quietly testing the mbstring path and proving nothing.
     */
    public function testTheHostUnderTestReallyHasNoMbstring(): void
    {
        $this->assertFalse(
            \CartShift\Support\function_exists('mb_strlen'),
            'The shim is not in effect, so nothing below is testing the fallback.',
        );

        $this->expectException(\Error::class);

        \CartShift\Support\mb_strlen('anything');
    }

    /**
     * The crash itself: any non-empty SKU reaches clamp().
     */
    public function testAnOrdinarySkuIsAllocatedWithoutMbstring(): void
    {
        $this->assertSame('TS-XL', (new SkuAllocator())->allocate('TS-XL', 13));
    }

    public function testACollidingSkuIsStillSuffixedWithoutMbstring(): void
    {
        \CartShiftFcModelStore::seed('ProductVariation', ['id' => 501, 'sku' => 'TS-XL']);

        $this->assertSame('TS-XL-wc13', (new SkuAllocator())->allocate('TS-XL', 13));
    }

    /**
     * Characters, not bytes. The SKU below is 33 characters in 65 bytes, so a
     * strlen fallback would clamp it to 30 *bytes* — fifteen and a half
     * characters — and hand the owner half the SKU the column can hold.
     */
    public function testALongMultibyteSkuIsClampedToThirtyCharactersNotThirtyBytes(): void
    {
        $sku = (new SkuAllocator())->allocate(self::LONG_MULTIBYTE_SKU, 13);

        $this->assertSame(SkuAllocator::MAX_LENGTH, self::characters($sku));
        $this->assertSame(59, strlen($sku), 'Fixture check: one ASCII plus twenty-nine two-byte characters.');
    }

    /**
     * The corruption a byte slice causes, stated as an assertion rather than a
     * length: `substr($sku, 0, 30)` lands mid-sequence on this input and stores
     * a broken code point in a column carrying a UNIQUE index.
     */
    public function testTheClampedSkuIsStillValidUtf8(): void
    {
        $sku = (new SkuAllocator())->allocate(self::LONG_MULTIBYTE_SKU, 13);

        $this->assertSame(1, preg_match('//u', $sku), 'A cut sequence is not a SKU, it is corruption.');

        // preg_match answers false, not 0, on a subject that is not valid
        // UTF-8 — which is itself the demonstration.
        $this->assertFalse(
            preg_match('//u', substr(self::LONG_MULTIBYTE_SKU, 0, SkuAllocator::MAX_LENGTH)),
            'Fixture check: the naive byte clamp really does break this input.',
        );
    }

    /**
     * Suffixing clamps the stem and never the suffix — `-wc13` is what makes the
     * value unique — and that arithmetic runs on character counts too.
     */
    public function testASuffixedMultibyteSkuFitsAndKeepsItsWholeSuffix(): void
    {
        \CartShiftFcModelStore::seed('ProductVariation', [
            'id'  => 501,
            'sku' => (new SkuAllocator())->allocate(self::LONG_MULTIBYTE_SKU, 13),
        ]);

        $suffixed = (new SkuAllocator())->allocate(self::LONG_MULTIBYTE_SKU, 13);

        $this->assertStringEndsWith('-wc13', $suffixed);
        $this->assertSame(SkuAllocator::MAX_LENGTH, self::characters($suffixed));
        $this->assertSame(1, preg_match('//u', $suffixed));
    }

    /**
     * The fallback is not merely safe, it is the same answer.
     *
     * Two allocators over the same empty catalogue, one on each path, have to
     * hand back identical strings — otherwise a shop that gains or loses
     * mbstring between two runs gets two different SKUs for one product, and the
     * second run's INSERT is a duplicate rather than a match.
     */
    public function testTheFallbackAgreesWithMbstringCharacterForCharacter(): void
    {
        $inputs = [
            'TS-XL',
            self::LONG_MULTIBYTE_SKU,
            'ŻÓŁW',
            '日本語のスキューコードはとても長いです',
            str_repeat('a', 45),
        ];

        foreach ($inputs as $sku) {
            $GLOBALS['_cartshift_test_no_mbstring'] = false;
            $withMbstring = (new SkuAllocator())->allocate($sku, 13);

            $GLOBALS['_cartshift_test_no_mbstring'] = true;
            $withoutMbstring = (new SkuAllocator())->allocate($sku, 13);

            $this->assertSame($withMbstring, $withoutMbstring, "Diverged on '{$sku}'.");
        }
    }

    /**
     * Character count without leaning on the extension the subject may not have.
     */
    private static function characters(string $value): int
    {
        return (int) preg_match_all('/./us', $value);
    }
}

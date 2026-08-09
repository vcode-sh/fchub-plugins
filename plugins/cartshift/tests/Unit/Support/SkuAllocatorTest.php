<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\Support;

use CartShift\Support\SkuAllocator;
use CartShift\Tests\Unit\PluginTestCase;

require_once dirname(__DIR__, 2) . '/stubs/FluentCartModelStubs.php';

/**
 * The shared answer to "will fct_product_variations accept this SKU".
 *
 * Two writers depend on it — ProductMigrator, which had the only copy, and the
 * orphan-variant path, which had none at all — and the column is unforgiving in
 * two different directions at once: two UNIQUE indexes, and varchar(30) under a
 * session that WordPress has stripped STRICT_TRANS_TABLES from, so an
 * over-length value is truncated rather than refused.
 *
 * The probe is driven through the FluentCart model fake, which honours the
 * where() constraint, so a test can seed one taken SKU and watch a different
 * one pass.
 */
final class SkuAllocatorTest extends PluginTestCase
{
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        \CartShiftFcModelStore::install();
    }

    public function testAFreeSkuIsHandedBackUnchanged(): void
    {
        $this->assertSame('TS-XL', (new SkuAllocator())->allocate('TS-XL', 13));
    }

    public function testATakenSkuGetsTheWooIdAsASuffix(): void
    {
        \CartShiftFcModelStore::seed('ProductVariation', ['id' => 501, 'sku' => 'TS-XL']);

        $this->assertSame('TS-XL-wc13', (new SkuAllocator())->allocate('TS-XL', 13));
    }

    /**
     * The memo, and the reason it is not merely a cache: two records in one run
     * can collide with each other rather than with FluentCart, and the second
     * INSERT would throw with nothing in the database to have warned about it.
     */
    public function testTheSameSkuTwiceInOneRunCollidesWithItself(): void
    {
        $allocator = new SkuAllocator();

        $this->assertSame('TS-XL', $allocator->allocate('TS-XL', 13));
        $this->assertSame('TS-XL-wc14', $allocator->allocate('TS-XL', 14));
    }

    /**
     * Same Woo id twice — a suffixed value that is itself taken walks on to a
     * numbered attempt rather than handing back the duplicate.
     */
    public function testASuffixThatIsAlsoTakenIsNumbered(): void
    {
        $allocator = new SkuAllocator();

        $allocator->allocate('TS-XL', 13);
        $allocator->allocate('TS-XL', 13);

        $this->assertSame('TS-XL-wc13-2', $allocator->allocate('TS-XL', 13));
    }

    public function testAnOverlongSkuIsClampedToThirtyCharacters(): void
    {
        $granted = (new SkuAllocator())->allocate(str_repeat('A', 45), 13);

        $this->assertSame(str_repeat('A', 30), $granted);
    }

    /**
     * The clamp has to happen *before* the probe, or the probe asks about a
     * value the table will never hold: two Woo SKUs sharing their first 30
     * characters both pass, and the second INSERT throws anyway.
     */
    public function testTwoSkusSharingTheirFirstThirtyCharactersDoNotBothPass(): void
    {
        $allocator = new SkuAllocator();

        $first  = $allocator->allocate(str_repeat('A', 45) . 'FIRST', 13);
        $second = $allocator->allocate(str_repeat('A', 45) . 'SECOND', 14);

        $this->assertNotSame($first, $second);
        $this->assertSame(str_repeat('A', 25) . '-wc14', $second);
    }

    /**
     * Suffixing truncates the stem, never the suffix: `-wc13` is the whole
     * point of adding it, and trimming that end hands back the duplicate.
     */
    public function testSuffixingKeepsTheSuffixAndTrimsTheStem(): void
    {
        \CartShiftFcModelStore::seed('ProductVariation', ['id' => 501, 'sku' => str_repeat('A', 30)]);

        $granted = (new SkuAllocator())->allocate(str_repeat('A', 45), 13);

        $this->assertSame(str_repeat('A', 25) . '-wc13', $granted);
        $this->assertSame(30, strlen($granted));
    }

    /**
     * Multibyte SKUs count characters, not bytes — varchar(30) is 30
     * characters, and a byte-wise cut lands mid-sequence.
     */
    public function testAMultibyteSkuIsCutOnCharacterBoundaries(): void
    {
        $granted = (new SkuAllocator())->allocate(str_repeat('é', 45), 13);

        $this->assertSame(30, mb_strlen($granted));
        $this->assertSame(str_repeat('é', 30), $granted);
    }

    /**
     * A Polish SKU survives the clamp as characters, not as a severed byte.
     *
     * Two claims, both checked against the live schema rather than assumed.
     * `fct_product_variations.sku` reports CHARACTER_MAXIMUM_LENGTH 30 with
     * CHARACTER_OCTET_LENGTH 120 under utf8mb4, so varchar(30) counts the same
     * units mb_substr does — MySQL's own `CAST('ŻÓŁTY-KABEL-USB-C-PREMIUM-XXL-EXTRA'
     * AS CHAR(30))` returns exactly the string asserted here. And the result is
     * still valid UTF-8, which a byte-wise substr() of this value would not be:
     * 35 characters is 38 bytes, so strlen()-based cutting lands inside 'Ż'.
     */
    public function testAPolishSkuIsClampedToThirtyCharactersNotThirtyBytes(): void
    {
        $granted = (new SkuAllocator())->allocate('ŻÓŁTY-KABEL-USB-C-PREMIUM-XXL-EXTRA', 13);

        $this->assertSame('ŻÓŁTY-KABEL-USB-C-PREMIUM-XXL-', $granted);
        $this->assertSame(30, mb_strlen($granted));
        $this->assertTrue(mb_check_encoding($granted, 'UTF-8'), 'The clamp cut inside a multibyte sequence.');
        $this->assertGreaterThan(30, strlen('ŻÓŁTY-KABEL-USB-C-PREMIUM-XXL-EXTRA'));
    }

    /**
     * The collision callback is how ProductMigrator writes its SkuCollision log
     * line, and it must fire only when a suffix was actually needed — a
     * warning for every SKU in the catalogue is a warning for none of them.
     */
    public function testTheCollisionCallbackFiresOnlyWhenASuffixWasNeeded(): void
    {
        $reported = [];

        $allocator = new SkuAllocator(static function (string $requested, string $granted, int $wcId) use (&$reported): void {
            $reported[] = [$requested, $granted, $wcId];
        });

        $allocator->allocate('TS-L', 12);

        $this->assertSame([], $reported);

        $allocator->allocate('TS-L', 13);

        $this->assertSame([['TS-L', 'TS-L-wc13', 13]], $reported);
    }
}

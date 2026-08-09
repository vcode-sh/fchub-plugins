<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\Domain\Mapping;

use CartShift\Domain\Mapping\VariantResolver;
use CartShift\Tests\Unit\PluginTestCase;

/**
 * FluentCart line items reference a product AND a variation row, so a link that
 * leaves variants unresolved produces orders whose line items point at nothing.
 * An unmatched Woo variation is reported as an orphan rather than silently
 * re-pointed at a sibling — putting "XL" revenue on the "L" row would break
 * FluentCart's per-variant reporting permanently and invisibly.
 */
final class VariantResolverTest extends PluginTestCase
{
    private function v(int $id, string $sku, string $name): array
    {
        return ['id' => $id, 'sku' => $sku, 'name' => $name];
    }

    public function testSkuBeatsNameAndPosition(): void
    {
        $result = (new VariantResolver())->resolve(
            [$this->v(11, 'TS-L', 'Large')],
            [$this->v(501, 'TS-XL', 'Large'), $this->v(502, 'TS-L', 'Enormous')],
        );

        $this->assertSame([11 => 502], $result['map']);
        $this->assertSame([], $result['orphans']);
    }

    public function testNameBeatsPositionWhenNoSkuMatches(): void
    {
        $result = (new VariantResolver())->resolve(
            [$this->v(11, '', 'Large')],
            [$this->v(501, '', 'Small'), $this->v(502, '', 'Large')],
        );

        $this->assertSame([11 => 502], $result['map']);
    }

    public function testPositionIsTheLastResort(): void
    {
        $result = (new VariantResolver())->resolve(
            [$this->v(11, '', 'Alpha'), $this->v(12, '', 'Beta')],
            [$this->v(501, '', 'One'), $this->v(502, '', 'Two')],
        );

        $this->assertSame([11 => 501, 12 => 502], $result['map']);
        $this->assertSame([], $result['orphans']);
    }

    public function testAnUnmatchedWooVariationIsAnOrphan(): void
    {
        $result = (new VariantResolver())->resolve(
            [$this->v(11, '', 'Small'), $this->v(12, '', 'Large'), $this->v(13, '', 'XL')],
            [$this->v(501, '', 'Small'), $this->v(502, '', 'Large')],
        );

        $this->assertSame([11 => 501, 12 => 502], $result['map']);
        $this->assertSame([13], $result['orphans']);
    }

    public function testAnFcVariantIsNeverClaimedTwice(): void
    {
        $result = (new VariantResolver())->resolve(
            [$this->v(11, 'DUP', 'Large'), $this->v(12, 'DUP', 'Large')],
            [$this->v(501, 'DUP', 'Large')],
        );

        $this->assertSame([11 => 501], $result['map']);
        $this->assertSame([12], $result['orphans']);
    }

    public function testABlankSkuNeverMatchesABlankSku(): void
    {
        $result = (new VariantResolver())->resolve(
            [$this->v(11, '', 'Only')],
            [$this->v(501, '', 'Different')],
        );

        // Falls through to position, not to "both blank therefore equal".
        $this->assertSame([11 => 501], $result['map']);
    }

    public function testNoFcVariantsMakesEveryWooVariationAnOrphan(): void
    {
        $result = (new VariantResolver())->resolve(
            [$this->v(11, '', 'Small'), $this->v(12, '', 'Large')],
            [],
        );

        $this->assertSame([], $result['map']);
        $this->assertSame([11, 12], $result['orphans']);
    }

    /**
     * The failure this class exists to prevent, arriving through the back door.
     *
     * The name pass is an exact comparison, so without accent folding `Biały`
     * is simply not `Bialy`, nothing matches, and every variation falls through
     * to position — which here pairs them in the wrong order, silently, and
     * puts one colour's revenue on the other's row for ever. The FC variants
     * are listed in the opposite order to the Woo ones precisely so that
     * position-pairing gives a different, wrong answer.
     */
    public function testPolishVariantNamesMatchTheirUnaccentedTwinsRatherThanFallingToPosition(): void
    {
        $result = (new VariantResolver())->resolve(
            [$this->v(11, '', 'Biały'), $this->v(12, '', 'Żółty')],
            [$this->v(501, '', 'Zolty'), $this->v(502, '', 'Bialy')],
        );

        $this->assertSame(
            [11 => 502, 12 => 501],
            $result['map'],
            'Position-pairing would have given [11 => 501, 12 => 502] — the wrong colour on each row.',
        );
        $this->assertSame([], $result['orphans']);
    }

    /**
     * Both sides accented, differing only in case and punctuation — the normal
     * case once a shop has been migrated once already and the names came back
     * with their diacritics intact.
     */
    public function testAccentedNamesOnBothSidesStillMatch(): void
    {
        $result = (new VariantResolver())->resolve(
            [$this->v(11, '', 'Rozmiar Duży'), $this->v(12, '', 'Rozmiar Średni')],
            [$this->v(501, '', 'rozmiar sredni'), $this->v(502, '', 'ROZMIAR-DUŻY')],
        );

        $this->assertSame([11 => 502, 12 => 501], $result['map']);
    }

    /**
     * Folding must not invent matches either. Two genuinely different Polish
     * colours stay different once their accents are gone, so this falls to
     * position — the honest answer — rather than pairing them by name.
     */
    public function testFoldingDoesNotCollapseTwoDifferentPolishNames(): void
    {
        $result = (new VariantResolver())->resolve(
            [$this->v(11, '', 'Zielony')],
            [$this->v(501, '', 'Żółty')],
        );

        // Position, and only because there is nothing else left to try.
        $this->assertSame([11 => 501], $result['map']);
    }

    public function testASimpleProductMapsItsSinglePseudoVariation(): void
    {
        // A Woo simple product is passed as one pseudo-variation keyed by the
        // product ID — mirroring how ProductMigrator stores ENTITY_VARIATION
        // for simple products.
        $result = (new VariantResolver())->resolve(
            [$this->v(42, '', 'Default')],
            [$this->v(777, '', 'Default')],
        );

        $this->assertSame([42 => 777], $result['map']);
    }
}

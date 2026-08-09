<?php

declare(strict_types=1);

namespace FChubPortalExtender\Tests\Unit\Portal;

use FChubPortalExtender\Portal\IconSupport;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * FluentCart sanitises every portal menu icon through wp_kses() with the
 * whitelist returned by `fct_allowed_svg_tags`, and its own list covers svg,
 * path and g only. That silently deleted dashicon spans and emptied any SVG
 * built from shapes — issue #62.
 *
 * These tests cover the part we own: the whitelist we hand back. wp_kses itself
 * is WordPress's, and stubbing it here would only prove that the stub agrees
 * with itself — the sanitiser's actual behaviour is verified against real
 * WordPress and real FluentCart in the playground. Asserting the list directly
 * is also the stricter guard: a dangerous element cannot be permitted if it
 * never appears in the array.
 */
final class IconSupportTest extends TestCase
{
    /**
     * @return array<string, array<string, bool>>
     */
    private function merged(): array
    {
        return IconSupport::allowIconTags(fchub_pe_fluentcart_allowed_svg_tags());
    }

    #[Test]
    public function testFluentCartsOwnEntriesSurviveTheMerge(): void
    {
        $original = fchub_pe_fluentcart_allowed_svg_tags();
        $merged = $this->merged();

        foreach ($original as $tag => $attributes) {
            self::assertArrayHasKey($tag, $merged, "FluentCart's own <{$tag}> was dropped.");

            foreach (array_keys($attributes) as $attribute) {
                self::assertArrayHasKey(
                    $attribute,
                    $merged[$tag],
                    "FluentCart's own {$tag}[{$attribute}] was dropped."
                );
            }
        }
    }

    #[Test]
    public function testAnotherPluginsEntriesSurviveTheMerge(): void
    {
        $merged = IconSupport::allowIconTags([
            'marquee' => ['behavior' => true],
            'svg'     => ['data-someone-elses' => true],
        ]);

        self::assertArrayHasKey('marquee', $merged, 'We replaced the list instead of merging into it.');
        self::assertArrayHasKey('data-someone-elses', $merged['svg']);
        self::assertArrayHasKey('circle', $merged, 'Our own entries went missing during the merge.');
    }

    #[Test]
    public function testAnEmptyIncomingListStillProducesAUsableWhitelist(): void
    {
        $merged = IconSupport::allowIconTags([]);

        self::assertArrayHasKey('svg', $merged);
        self::assertArrayHasKey('span', $merged);
    }

    #[Test]
    public function testANonArrayIncomingValueIsToleratedRatherThanFatal(): void
    {
        $merged = IconSupport::allowIconTags(null);

        self::assertIsArray($merged);
        self::assertArrayHasKey('svg', $merged);
    }

    /**
     * The dashicon case from the report: `resolveIcon()` emits a span, and span
     * was not on FluentCart's list, so the icon was deleted outright.
     */
    #[Test]
    public function testTheDashiconSpanIsPermitted(): void
    {
        $merged = $this->merged();

        self::assertArrayHasKey('span', $merged);
        self::assertArrayHasKey('class', $merged['span']);
        self::assertArrayHasKey('style', $merged['span']);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function shapeElementProvider(): array
    {
        return [
            'circle' => ['circle'],
            'ellipse' => ['ellipse'],
            'rect' => ['rect'],
            'line' => ['line'],
            'polyline' => ['polyline'],
            'polygon' => ['polygon'],
        ];
    }

    #[Test]
    #[DataProvider('shapeElementProvider')]
    public function testSvgShapeElementsArePermitted(string $element): void
    {
        self::assertArrayHasKey($element, $this->merged());
    }

    /**
     * FluentCart's whitelist spells the key `viewBox`, but wp_kses lowercases
     * attribute names before comparing, so the attribute was stripped from every
     * icon — FluentCart's own included. The lowercase key is the fix.
     */
    #[Test]
    public function testTheLowercaseViewBoxKeyIsPresent(): void
    {
        $merged = $this->merged();

        self::assertArrayHasKey('viewbox', $merged['svg'], 'wp_kses matches lowercase attribute names.');
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function strokeAttributeProvider(): array
    {
        return [
            'stroke-linecap' => ['stroke-linecap'],
            'stroke-linejoin' => ['stroke-linejoin'],
            'fill-rule' => ['fill-rule'],
            'clip-rule' => ['clip-rule'],
        ];
    }

    #[Test]
    #[DataProvider('strokeAttributeProvider')]
    public function testCommonPresentationAttributesArePermittedOnPaths(string $attribute): void
    {
        self::assertArrayHasKey($attribute, $this->merged()['path']);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function forbiddenElementProvider(): array
    {
        return [
            'script' => ['script'],
            'foreignObject' => ['foreignObject'],
            'foreignobject' => ['foreignobject'],
            'use' => ['use'],
            'iframe' => ['iframe'],
            'a' => ['a'],
            'image' => ['image'],
            'animate' => ['animate'],
            'set' => ['set'],
        ];
    }

    /**
     * The guard that matters. Widening a sanitiser whitelist is easy to do by
     * accident and hard to notice, so every element that could execute, fetch
     * or navigate is named here and asserted absent.
     */
    #[Test]
    #[DataProvider('forbiddenElementProvider')]
    public function testDangerousElementsAreNeverPermitted(string $element): void
    {
        self::assertArrayNotHasKey($element, $this->merged());
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function forbiddenAttributeProvider(): array
    {
        return [
            'href' => ['href'],
            'xlink:href' => ['xlink:href'],
            'src' => ['src'],
            'onload' => ['onload'],
            'onclick' => ['onclick'],
            'onerror' => ['onerror'],
            'formaction' => ['formaction'],
        ];
    }

    #[Test]
    #[DataProvider('forbiddenAttributeProvider')]
    public function testDangerousAttributesAreNeverPermittedOnAnyElement(string $attribute): void
    {
        foreach ($this->merged() as $element => $attributes) {
            self::assertArrayNotHasKey(
                $attribute,
                $attributes,
                "{$attribute} is permitted on <{$element}>."
            );
        }
    }

    #[Test]
    public function testNoEventHandlerAttributeSlipsInUnderAnyName(): void
    {
        foreach ($this->merged() as $element => $attributes) {
            foreach (array_keys($attributes) as $attribute) {
                self::assertDoesNotMatchRegularExpression(
                    '/^on[a-z]+$/i',
                    (string) $attribute,
                    "An event handler attribute is permitted on <{$element}>: {$attribute}"
                );
            }
        }
    }

    /**
     * `style` is allowed on the dashicon span because that is how the icon is
     * sized, and WordPress runs it through safecss_filter_attr. It has no
     * business on an SVG element, where it buys nothing and widens the surface.
     */
    #[Test]
    public function testStyleIsPermittedOnlyOnTheDashiconSpan(): void
    {
        foreach ($this->merged() as $element => $attributes) {
            if ($element === 'span') {
                continue;
            }

            self::assertArrayNotHasKey('style', $attributes, "style is permitted on <{$element}>.");
        }
    }
}

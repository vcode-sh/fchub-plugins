<?php

declare(strict_types=1);

namespace FChubPortalExtender\Tests\Unit\Portal;

use FChubPortalExtender\Portal\MenuPlacement;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class MenuPlacementTest extends TestCase
{
    /**
     * A menu the way FluentCart hands it to us: core items keyed by slug, with
     * our endpoints already spliced in before `profile`.
     *
     * @param array<int, string> $core
     * @param array<int, string> $custom
     * @return array<string, array<string, string>>
     */
    private function menu(array $core, array $custom = []): array
    {
        $items = [];

        foreach ($core as $slug) {
            if ($slug === 'profile') {
                foreach ($custom as $customSlug) {
                    $items[$customSlug] = ['label' => ucfirst($customSlug)];
                }
            }

            $items[$slug] = ['label' => ucfirst($slug)];
        }

        // No profile item to splice against — append, as FluentCart's API does.
        if (!in_array('profile', $core, true)) {
            foreach ($custom as $customSlug) {
                $items[$customSlug] = ['label' => ucfirst($customSlug)];
            }
        }

        return $items;
    }

    /**
     * @param array<string, string> $overrides
     * @return array<string, mixed>
     */
    private function endpoint(string $slug, array $overrides = []): array
    {
        return array_merge([
            'slug'           => $slug,
            'menu_anchor'    => MenuPlacement::DEFAULT_ANCHOR,
            'menu_placement' => MenuPlacement::DEFAULT_PLACEMENT,
        ], $overrides);
    }

    private const FULL_MENU = [
        'dashboard',
        'purchase-history',
        'subscriptions',
        'licenses',
        'downloads',
        'profile',
    ];

    #[Test]
    public function testTheDefaultPlacementReproducesFluentCartsOwnSlot(): void
    {
        $items = $this->menu(self::FULL_MENU, ['support']);

        $result = MenuPlacement::apply($items, [$this->endpoint('support')]);

        self::assertSame(array_keys($items), array_keys($result), 'Defaults must not move anything.');
    }

    #[Test]
    public function testAnEndpointCanSitDirectlyAfterTheDashboard(): void
    {
        $items = $this->menu(self::FULL_MENU, ['support']);

        $result = MenuPlacement::apply($items, [
            $this->endpoint('support', ['menu_anchor' => 'dashboard', 'menu_placement' => 'after']),
        ]);

        self::assertSame([
            'dashboard',
            'support',
            'purchase-history',
            'subscriptions',
            'licenses',
            'downloads',
            'profile',
        ], array_keys($result));
    }

    #[Test]
    public function testAnEndpointCanTakeTheVeryFirstSlot(): void
    {
        $items = $this->menu(self::FULL_MENU, ['support']);

        $result = MenuPlacement::apply($items, [
            $this->endpoint('support', ['menu_anchor' => 'dashboard', 'menu_placement' => 'before']),
        ]);

        self::assertSame('support', array_key_first($result));
    }

    #[Test]
    public function testAnEndpointCanTakeTheVeryLastSlot(): void
    {
        $items = $this->menu(self::FULL_MENU, ['support']);

        $result = MenuPlacement::apply($items, [
            $this->endpoint('support', ['menu_anchor' => 'profile', 'menu_placement' => 'after']),
        ]);

        self::assertSame('support', array_key_last($result));
    }

    /**
     * The whole reason placement is anchored rather than numbered: FluentCart
     * hides these two per visitor, so the anchor has to degrade to the same
     * visual slot rather than to nothing.
     */
    #[Test]
    public function testAnchoringAfterAHiddenItemFallsBackToTheNearestVisibleOneAbove(): void
    {
        // No licenses (no Pro) and no subscriptions (customer has none).
        $items = $this->menu(['dashboard', 'purchase-history', 'downloads', 'profile'], ['support']);

        $result = MenuPlacement::apply($items, [
            $this->endpoint('support', ['menu_anchor' => 'licenses', 'menu_placement' => 'after']),
        ]);

        self::assertSame([
            'dashboard',
            'purchase-history',
            'support',
            'downloads',
            'profile',
        ], array_keys($result), 'The endpoint should hold the gap where Licenses would have been.');
    }

    #[Test]
    public function testAnchoringBeforeAHiddenItemFallsBackToTheNearestVisibleOneBelow(): void
    {
        $items = $this->menu(['dashboard', 'purchase-history', 'downloads', 'profile'], ['support']);

        $result = MenuPlacement::apply($items, [
            $this->endpoint('support', ['menu_anchor' => 'subscriptions', 'menu_placement' => 'before']),
        ]);

        self::assertSame([
            'dashboard',
            'purchase-history',
            'support',
            'downloads',
            'profile',
        ], array_keys($result));
    }

    #[Test]
    public function testSeveralEndpointsSharingASlotKeepTheirOwnOrder(): void
    {
        $items = $this->menu(self::FULL_MENU, ['alpha', 'beta', 'gamma']);

        $result = MenuPlacement::apply($items, [
            $this->endpoint('alpha', ['menu_anchor' => 'dashboard', 'menu_placement' => 'after']),
            $this->endpoint('beta', ['menu_anchor' => 'dashboard', 'menu_placement' => 'after']),
            $this->endpoint('gamma', ['menu_anchor' => 'dashboard', 'menu_placement' => 'after']),
        ]);

        self::assertSame(
            ['dashboard', 'alpha', 'beta', 'gamma', 'purchase-history'],
            array_slice(array_keys($result), 0, 5),
            'Endpoints in one slot must follow their configured order, not reverse it.'
        );
    }

    #[Test]
    public function testEndpointsInDifferentSlotsAllLandCorrectly(): void
    {
        $items = $this->menu(self::FULL_MENU, ['alpha', 'beta']);

        $result = MenuPlacement::apply($items, [
            $this->endpoint('alpha', ['menu_anchor' => 'downloads', 'menu_placement' => 'after']),
            $this->endpoint('beta', ['menu_anchor' => 'dashboard', 'menu_placement' => 'before']),
        ]);

        self::assertSame([
            'beta',
            'dashboard',
            'purchase-history',
            'subscriptions',
            'licenses',
            'downloads',
            'alpha',
            'profile',
        ], array_keys($result));
    }

    #[Test]
    public function testAnotherPluginsMenuItemIsNeverMovedOrLost(): void
    {
        $items = $this->menu(self::FULL_MENU, ['support']);
        $items['some-other-plugin'] = ['label' => 'Other'];

        $result = MenuPlacement::apply($items, [
            $this->endpoint('support', ['menu_anchor' => 'dashboard', 'menu_placement' => 'after']),
        ]);

        self::assertArrayHasKey('some-other-plugin', $result);
        self::assertCount(count($items), $result);
    }

    #[Test]
    public function testTheItemCountNeverChanges(): void
    {
        $items = $this->menu(self::FULL_MENU, ['alpha', 'beta']);

        $result = MenuPlacement::apply($items, [
            $this->endpoint('alpha', ['menu_anchor' => 'profile', 'menu_placement' => 'after']),
            $this->endpoint('beta', ['menu_anchor' => 'dashboard', 'menu_placement' => 'before']),
        ]);

        self::assertCount(count($items), $result);
        self::assertSame([], array_diff(array_keys($items), array_keys($result)));
    }

    #[Test]
    public function testTheMenuValuesArePassedThroughUntouched(): void
    {
        $items = $this->menu(self::FULL_MENU, ['support']);
        $items['support']['icon_svg'] = '<svg></svg>';

        $result = MenuPlacement::apply($items, [
            $this->endpoint('support', ['menu_anchor' => 'dashboard', 'menu_placement' => 'after']),
        ]);

        self::assertSame($items['support'], $result['support']);
        self::assertSame($items['profile'], $result['profile']);
    }

    #[Test]
    public function testAnEndpointMissingFromTheMenuIsIgnored(): void
    {
        $items = $this->menu(self::FULL_MENU);

        $result = MenuPlacement::apply($items, [
            $this->endpoint('never-registered', ['menu_anchor' => 'dashboard', 'menu_placement' => 'after']),
        ]);

        self::assertSame(array_keys($items), array_keys($result));
    }

    #[Test]
    public function testAMenuWithNoCoreItemsIsLeftAlone(): void
    {
        $items = ['support' => ['label' => 'Support'], 'other' => ['label' => 'Other']];

        $result = MenuPlacement::apply($items, [
            $this->endpoint('support', ['menu_anchor' => 'dashboard', 'menu_placement' => 'after']),
        ]);

        self::assertSame($items, $result);
    }

    #[Test]
    public function testAnUnknownAnchorFallsBackToTheDefaultSlotRatherThanDisappearing(): void
    {
        $items = $this->menu(self::FULL_MENU, ['support']);

        $result = MenuPlacement::apply($items, [
            $this->endpoint('support', ['menu_anchor' => 'not-a-real-item', 'menu_placement' => 'after']),
        ]);

        self::assertSame(array_keys($items), array_keys($result));
        self::assertCount(count($items), $result);
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: array<int, string>, 3: array{0: string, 1: string}}>
     */
    public static function anchorResolutionProvider(): array
    {
        $reduced = ['dashboard', 'purchase-history', 'downloads', 'profile'];

        return [
            'visible anchor is used as-is' => ['downloads', 'after', $reduced, ['downloads', 'after']],
            'hidden anchor, after, walks up' => ['licenses', 'after', $reduced, ['purchase-history', 'after']],
            'hidden anchor, before, walks down' => ['subscriptions', 'before', $reduced, ['downloads', 'before']],
            'hidden anchor with nothing above becomes the top' => [
                'purchase-history',
                'after',
                ['downloads', 'profile'],
                ['downloads', 'before'],
            ],
            'hidden anchor with nothing below becomes the bottom' => [
                'profile',
                'before',
                ['dashboard', 'purchase-history'],
                ['purchase-history', 'after'],
            ],
        ];
    }

    /**
     * @param array<int, string> $present
     * @param array{0: string, 1: string} $expected
     */
    #[Test]
    #[DataProvider('anchorResolutionProvider')]
    public function testAnchorResolution(string $anchor, string $placement, array $present, array $expected): void
    {
        self::assertSame($expected, MenuPlacement::resolveAnchor($anchor, $placement, $present));
    }

    #[Test]
    public function testResolutionGivesUpOnlyWhenNoCoreItemSurvives(): void
    {
        self::assertNull(MenuPlacement::resolveAnchor('profile', 'before', []));
    }

    #[Test]
    public function testEveryOfferedOptionIsValidAndUnique(): void
    {
        $options = MenuPlacement::options();
        $values = array_column($options, 'value');

        self::assertNotEmpty($options);
        self::assertSame($values, array_unique($values), 'Two choices share a value.');

        foreach ($options as $option) {
            self::assertTrue(MenuPlacement::isValidAnchor($option['anchor']), $option['value']);
            self::assertTrue(MenuPlacement::isValidPlacement($option['placement']), $option['value']);
            self::assertSame($option['placement'] . ':' . $option['anchor'], $option['value']);
            self::assertNotSame('', $option['label']);
        }
    }

    #[Test]
    public function testTheDefaultSlotIsOfferedInTheAdmin(): void
    {
        $values = array_column(MenuPlacement::options(), 'value');

        self::assertContains(
            MenuPlacement::DEFAULT_PLACEMENT . ':' . MenuPlacement::DEFAULT_ANCHOR,
            $values,
            'Upgrading sites must be able to see the setting they already have.'
        );
    }

    /**
     * If FluentCart renames or reorders its menu, this is the test that notices.
     */
    #[Test]
    public function testTheCoreOrderMatchesFluentCartsOwnMenu(): void
    {
        self::assertSame([
            'dashboard',
            'purchase-history',
            'subscriptions',
            'licenses',
            'downloads',
            'profile',
        ], MenuPlacement::CORE_MENU_ORDER);
    }
}

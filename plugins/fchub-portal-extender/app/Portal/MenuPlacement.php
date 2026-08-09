<?php

namespace FChubPortalExtender\Portal;

defined('ABSPATH') || exit;

/**
 * Decides where custom endpoints sit in the FluentCart customer portal menu.
 *
 * FluentCart's own API drops every custom endpoint immediately before `profile`
 * and offers no position argument, so this is done by reordering the finished
 * menu on the `fluent_cart/global_customer_menu_items` filter.
 *
 * Placement is anchored to a named core item rather than to an absolute index,
 * and that is deliberate. FluentCart hides `licenses` when Pro or the license
 * module is off, and hides `subscriptions` from customers who have none — so
 * "position 2" is a different neighbour for different visitors, and an index is
 * a promise nobody can keep. An anchor degrades honestly: when the anchor is
 * missing we fall back to the nearest visible item on the same side, which is
 * the same slot on screen.
 */
class MenuPlacement
{
    /**
     * FluentCart's built-in menu items, in the order it builds them.
     *
     * Mirrors CustomerProfileHandler::renderCustomerMenu(). Items are removed
     * from that array per visitor, never reordered, so this stays the canonical
     * sequence to reason about.
     */
    public const CORE_MENU_ORDER = [
        'dashboard',
        'purchase-history',
        'subscriptions',
        'licenses',
        'downloads',
        'profile',
    ];

    public const PLACEMENT_BEFORE = 'before';
    public const PLACEMENT_AFTER = 'after';

    /** Where FluentCart puts custom endpoints when nobody asks for anything else. */
    public const DEFAULT_ANCHOR = 'profile';
    public const DEFAULT_PLACEMENT = self::PLACEMENT_BEFORE;

    public static function isValidAnchor(string $anchor): bool
    {
        return in_array($anchor, self::CORE_MENU_ORDER, true);
    }

    public static function isValidPlacement(string $placement): bool
    {
        return in_array($placement, [self::PLACEMENT_BEFORE, self::PLACEMENT_AFTER], true);
    }

    /**
     * The choices offered in the admin, in menu order.
     *
     * Only "before" the first item and "after" every item are listed: offering
     * both sides of every anchor would give two labels for one slot, and people
     * would reasonably wonder which one they picked.
     *
     * @return array<int, array{value: string, anchor: string, placement: string, label: string}>
     */
    public static function options(): array
    {
        $labels = self::coreLabels();
        $first = self::CORE_MENU_ORDER[0];

        // Reads top to bottom like the menu itself. Every anchor offers only its
        // "after" side, because "after Dashboard" and "before Purchase History"
        // are the same gap and two labels for one slot help nobody. The two
        // exceptions are the ends: the very top, and the default slot, which is
        // named explicitly so an upgrading site can find the setting it already
        // has.
        $options = [[
            'anchor'    => $first,
            'placement' => self::PLACEMENT_BEFORE,
            /* translators: %s is a customer portal menu item, e.g. "Dashboard". */
            'label'     => sprintf(__('First — above %s', 'fchub-portal-extender'), $labels[$first]),
        ]];

        foreach (self::CORE_MENU_ORDER as $slug) {
            if ($slug === self::DEFAULT_ANCHOR) {
                $options[] = [
                    'anchor'    => $slug,
                    'placement' => self::PLACEMENT_BEFORE,
                    /* translators: %s is a customer portal menu item, e.g. "Profile". */
                    'label'     => sprintf(__('Before %s (default)', 'fchub-portal-extender'), $labels[$slug]),
                ];
            }

            $options[] = [
                'anchor'    => $slug,
                'placement' => self::PLACEMENT_AFTER,
                /* translators: %s is a customer portal menu item, e.g. "Dashboard". */
                'label'     => sprintf(__('After %s', 'fchub-portal-extender'), $labels[$slug]),
            ];
        }

        return array_map(
            static fn(array $option): array => $option + [
                'value' => $option['placement'] . ':' . $option['anchor'],
            ],
            $options
        );
    }

    /**
     * Labels for the built-in items, matching FluentCart's own wording so the
     * admin choices read like the menu the customer sees.
     *
     * @return array<string, string>
     */
    private static function coreLabels(): array
    {
        return [
            'dashboard'        => __('Dashboard', 'fchub-portal-extender'),
            'purchase-history' => __('Purchase History', 'fchub-portal-extender'),
            'subscriptions'    => __('Subscription Plans', 'fchub-portal-extender'),
            'licenses'         => __('Licenses', 'fchub-portal-extender'),
            'downloads'        => __('Downloads', 'fchub-portal-extender'),
            'profile'          => __('Profile', 'fchub-portal-extender'),
        ];
    }

    /**
     * Move an anchor request onto an item the visitor can actually see.
     *
     * The intent is a slot, not a name. "After Licenses" on a site without
     * licenses means "where Licenses would have been", so we walk backwards to
     * the nearest visible item and sit after that. "Before X" walks forwards for
     * the same reason. Either way the endpoint lands in the same visual gap.
     *
     * @param array<int, string> $presentCoreSlugs Core slugs actually in this visitor's menu.
     * @return array{0: string, 1: string}|null [anchor, placement], or null when no core item survives.
     */
    public static function resolveAnchor(string $anchor, string $placement, array $presentCoreSlugs): ?array
    {
        $present = array_values(array_filter(
            self::CORE_MENU_ORDER,
            static fn(string $slug): bool => in_array($slug, $presentCoreSlugs, true)
        ));

        if ($present === []) {
            return null;
        }

        if (in_array($anchor, $present, true)) {
            return [$anchor, $placement];
        }

        $index = array_search($anchor, self::CORE_MENU_ORDER, true);
        if ($index === false) {
            // An anchor we do not recognise at all. Treat it as the default slot
            // rather than dropping the endpoint out of the menu.
            return self::resolveAnchor(self::DEFAULT_ANCHOR, self::DEFAULT_PLACEMENT, $presentCoreSlugs);
        }

        if ($placement === self::PLACEMENT_AFTER) {
            for ($i = $index - 1; $i >= 0; $i--) {
                if (in_array(self::CORE_MENU_ORDER[$i], $present, true)) {
                    return [self::CORE_MENU_ORDER[$i], self::PLACEMENT_AFTER];
                }
            }

            // Nothing visible above it — the slot is the top of the menu.
            return [$present[0], self::PLACEMENT_BEFORE];
        }

        $total = count(self::CORE_MENU_ORDER);
        for ($i = $index + 1; $i < $total; $i++) {
            if (in_array(self::CORE_MENU_ORDER[$i], $present, true)) {
                return [self::CORE_MENU_ORDER[$i], self::PLACEMENT_BEFORE];
            }
        }

        // Nothing visible below it — the slot is the bottom of the menu.
        return [$present[count($present) - 1], self::PLACEMENT_AFTER];
    }

    /**
     * Reorder the rendered menu so each custom endpoint sits where it asked to.
     *
     * Items belonging to other plugins are passed through untouched and keep
     * their position: this reorders, it never curates. The item count out always
     * equals the item count in.
     *
     * @param array<string, mixed> $items Menu items keyed by slug, in render order.
     * @param array<int, array<string, mixed>> $endpoints Active endpoints, already in their own order.
     * @return array<string, mixed>
     */
    public static function apply(array $items, array $endpoints): array
    {
        if ($items === [] || $endpoints === []) {
            return $items;
        }

        $presentCore = array_values(array_filter(
            array_keys($items),
            static fn($slug): bool => in_array($slug, self::CORE_MENU_ORDER, true)
        ));

        if ($presentCore === []) {
            // No built-in items to anchor against — whatever is here is not a
            // menu we understand, so leave it exactly as we found it.
            return $items;
        }

        $before = [];
        $after = [];
        $ours = [];

        foreach ($endpoints as $endpoint) {
            $slug = (string) ($endpoint['slug'] ?? '');
            if ($slug === '' || !array_key_exists($slug, $items)) {
                continue;
            }

            $resolved = self::resolveAnchor(
                (string) ($endpoint['menu_anchor'] ?? self::DEFAULT_ANCHOR),
                (string) ($endpoint['menu_placement'] ?? self::DEFAULT_PLACEMENT),
                $presentCore
            );

            if ($resolved === null) {
                continue;
            }

            [$anchor, $placement] = $resolved;
            $ours[$slug] = true;

            if ($placement === self::PLACEMENT_BEFORE) {
                $before[$anchor][] = $slug;
                continue;
            }

            $after[$anchor][] = $slug;
        }

        if ($ours === []) {
            return $items;
        }

        $reordered = [];

        foreach ($items as $slug => $item) {
            if (isset($ours[$slug])) {
                // Emitted by its bucket, wherever that turns out to be.
                continue;
            }

            foreach ($before[$slug] ?? [] as $ourSlug) {
                $reordered[$ourSlug] = $items[$ourSlug];
            }

            $reordered[$slug] = $item;

            foreach ($after[$slug] ?? [] as $ourSlug) {
                $reordered[$ourSlug] = $items[$ourSlug];
            }
        }

        // A bucket can only be missed if its anchor vanished between resolution
        // and this loop, which should be impossible. Appending beats losing the
        // endpoint entirely, so belt and braces.
        foreach ($ours as $slug => $unused) {
            if (!array_key_exists($slug, $reordered)) {
                $reordered[$slug] = $items[$slug];
            }
        }

        return $reordered;
    }
}

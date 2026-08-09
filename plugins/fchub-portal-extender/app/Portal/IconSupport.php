<?php

namespace FChubPortalExtender\Portal;

defined('ABSPATH') || exit;

/**
 * Keeps custom endpoint icons alive on the customer portal front end.
 *
 * FluentCart pipes every menu icon through wp_kses() with the whitelist
 * returned by the 'fct_allowed_svg_tags' filter. Its own whitelist covers
 * svg, path and g only — so circles, rects and dashicon spans are deleted
 * on sight, and viewBox is dropped from everything because wp_kses
 * lowercases attribute names before matching while FluentCart's key is
 * camelCase. The result is an empty icon slot. We widen the whitelist,
 * and we make sure the dashicons stylesheet is actually on the page.
 */
class IconSupport
{
    /**
     * Attributes shared by every SVG element we allow.
     *
     * @var array<string, bool>
     */
    private const COMMON_SVG_ATTRIBUTES = [
        'id'                => true,
        'class'             => true,
        'fill'              => true,
        'fill-rule'         => true,
        'fill-opacity'      => true,
        'clip-rule'         => true,
        'stroke'            => true,
        'stroke-width'      => true,
        'stroke-linecap'    => true,
        'stroke-linejoin'   => true,
        'stroke-miterlimit' => true,
        'stroke-dasharray'  => true,
        'stroke-dashoffset' => true,
        'stroke-opacity'    => true,
        'opacity'           => true,
        'transform'         => true,
        'aria-hidden'       => true,
        'focusable'         => true,
        'role'              => true,
    ];

    /**
     * Wire up the front-end hooks. Called with the active endpoints so we
     * never enqueue a stylesheet nobody asked for.
     *
     * @param array<int, array<string, mixed>> $endpoints
     */
    public static function register(array $endpoints): void
    {
        if (is_admin()) {
            return;
        }

        $needsDashicons = self::usesDashicons($endpoints);

        // Primary path: enqueue in the head when we can tell up front that
        // this request renders the portal.
        if ($needsDashicons) {
            add_action('wp_enqueue_scripts', [self::class, 'maybeEnqueueDashicons'], 20);
        }

        // The menu items filter fires once, immediately before FluentCart
        // registers its own whitelist and renders the menu — which makes it
        // the only place our filter is ever needed. It is also the very hook
        // our endpoints ride on, so it costs us no extra fragility.
        add_filter('fluent_cart/global_customer_menu_items', function ($items) use ($needsDashicons) {
            // FluentCart replaces the whitelist wholesale at priority 10,
            // so we merge on top of it at 20.
            add_filter('fct_allowed_svg_tags', [self::class, 'allowIconTags'], 20);

            if ($needsDashicons) {
                // Late fallback for template setups the page check misses.
                // WordPress prints styles enqueued this late in the footer.
                wp_enqueue_style('dashicons');
            }

            return $items;
        }, 99);
    }

    /**
     * Merge our icon markup into whatever whitelist is already in play.
     *
     * @param mixed $tags
     *
     * @return array<string, array<string, bool>>
     */
    public static function allowIconTags($tags): array
    {
        $tags = is_array($tags) ? $tags : [];

        foreach (self::iconTags() as $tag => $attributes) {
            $existing = isset($tags[$tag]) && is_array($tags[$tag]) ? $tags[$tag] : [];
            $tags[$tag] = array_merge($existing, $attributes);
        }

        return $tags;
    }

    /**
     * Enqueue dashicons when the current request renders the portal.
     */
    public static function maybeEnqueueDashicons(): void
    {
        if (!self::isPortalRequest()) {
            return;
        }

        wp_enqueue_style('dashicons');
    }

    /**
     * The tags and attributes our icons need on top of FluentCart's list.
     * Deliberately no script, no foreignObject, no event handlers, no
     * xlink:href, no href, and no style on SVG elements.
     *
     * @return array<string, array<string, bool>>
     */
    private static function iconTags(): array
    {
        $common = self::COMMON_SVG_ATTRIBUTES;

        return [
            'svg' => array_merge($common, [
                'xmlns'   => true,
                'width'   => true,
                'height'  => true,
                // wp_kses lowercases attribute names before matching, so the
                // camelCase key alone never matches. Keep both.
                'viewBox' => true,
                'viewbox' => true,
                'preserveaspectratio' => true,
            ]),
            'g'        => $common,
            'defs'     => $common,
            'title'    => ['id' => true, 'class' => true],
            'desc'     => ['id' => true, 'class' => true],
            'path'     => array_merge($common, ['d' => true]),
            'circle'   => array_merge($common, ['cx' => true, 'cy' => true, 'r' => true]),
            'ellipse'  => array_merge($common, ['cx' => true, 'cy' => true, 'rx' => true, 'ry' => true]),
            'rect'     => array_merge($common, [
                'x'      => true,
                'y'      => true,
                'width'  => true,
                'height' => true,
                'rx'     => true,
                'ry'     => true,
            ]),
            'line'     => array_merge($common, ['x1' => true, 'y1' => true, 'x2' => true, 'y2' => true]),
            'polyline' => array_merge($common, ['points' => true]),
            'polygon'  => array_merge($common, ['points' => true]),
            // Dashicons are a font glyph on a span, not an SVG at all.
            'span'     => ['class' => true, 'style' => true],
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $endpoints
     */
    private static function usesDashicons(array $endpoints): bool
    {
        foreach ($endpoints as $endpoint) {
            if (($endpoint['icon_type'] ?? '') === 'dashicon' && !empty($endpoint['icon_value'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Best-effort check for "this request renders the customer portal".
     */
    private static function isPortalRequest(): bool
    {
        if (!is_singular()) {
            return false;
        }

        $post = get_post();

        if (!$post) {
            return false;
        }

        if (has_shortcode((string) $post->post_content, 'fluent_cart_customer_profile')) {
            return true;
        }

        if (function_exists('has_block') && has_block('fluent-cart/customer-profile', $post)) {
            return true;
        }

        return $post->ID === self::customerProfilePageId();
    }

    private static function customerProfilePageId(): int
    {
        if (!class_exists('\FluentCart\Api\StoreSettings')) {
            return 0;
        }

        try {
            return (int) (new \FluentCart\Api\StoreSettings())->getCustomerProfilePageId();
        } catch (\Throwable $e) {
            return 0;
        }
    }
}

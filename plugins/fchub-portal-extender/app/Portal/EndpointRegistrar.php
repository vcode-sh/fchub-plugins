<?php

namespace FChubPortalExtender\Portal;

defined('ABSPATH') || exit;

use FChubPortalExtender\Storage\EndpointRepository;

class EndpointRegistrar
{
    public static function register(): void
    {
        $repo = new EndpointRepository();
        $endpoints = $repo->getActive();

        if (empty($endpoints)) {
            return;
        }

        $api = fluent_cart_api();

        foreach ($endpoints as $endpoint) {
            $args = [
                'title' => $endpoint['title'],
            ];

            // Icon
            if (!empty($endpoint['icon_value'])) {
                if ($endpoint['icon_type'] === 'url') {
                    $args['icon_url'] = $endpoint['icon_value'];
                } else {
                    $args['icon_svg'] = self::resolveIcon($endpoint);
                }
            }

            $scrollEnabled = !empty($endpoint['scroll_enabled']);
            $scrollMode = $endpoint['scroll_mode'] ?? 'auto';
            $scrollHeight = max(200, (int) ($endpoint['scroll_height'] ?? 600));

            // Content source — when scroll is enabled, we always use render_callback
            // so we can wrap the output in a scrollable container
            if ($endpoint['type'] === 'page_id' && !empty($endpoint['page_id'])) {
                if ($scrollEnabled) {
                    $pageId = (int) $endpoint['page_id'];
                    $args['render_callback'] = function () use ($pageId, $scrollMode, $scrollHeight) {
                        self::renderPageContent($pageId, $scrollMode, $scrollHeight);
                    };
                } else {
                    $args['page_id'] = (int) $endpoint['page_id'];
                }
            } elseif ($endpoint['type'] === 'shortcode' && !empty($endpoint['shortcode'])) {
                $shortcode = $endpoint['shortcode'];
                if ($scrollEnabled) {
                    $args['render_callback'] = function () use ($shortcode, $scrollMode, $scrollHeight) {
                        self::renderScrollWrapper(do_shortcode($shortcode), $scrollMode, $scrollHeight);
                    };
                } else {
                    $args['render_callback'] = function () use ($shortcode) {
                        echo do_shortcode($shortcode);
                    };
                }
            } else {
                continue;
            }

            try {
                $api->addCustomerDashboardEndpoint($endpoint['slug'], $args);
            } catch (\Exception $e) {
                continue;
            }
        }
    }

    private static function renderPageContent(int $pageId, string $scrollMode, int $maxHeight): void
    {
        $query = new \WP_Query([
            'post_type'      => 'page',
            'post__in'       => [$pageId],
            'posts_per_page' => 1,
            'orderby'        => 'post__in',
        ]);

        if ($query->have_posts()) {
            ob_start();
            while ($query->have_posts()) {
                $query->the_post();
                the_content();
            }
            wp_reset_postdata();
            $content = ob_get_clean();

            self::renderScrollWrapper(
                '<div class="fluent-cart-custom-page-content"><div>' . $content . '</div></div>',
                $scrollMode,
                $maxHeight
            );
        } else {
            echo '<p>' . esc_html__('No content found!', 'fluent-cart') . '</p>';
        }
    }

    private static function renderScrollWrapper(string $content, string $mode, int $maxHeight): void
    {
        $id = 'fchub-pe-' . wp_unique_id();

        if ($mode === 'fixed') {
            printf(
                '<div id="%s" class="fchub-pe-scroll-container" style="max-height:%dpx;overflow-y:auto;">%s</div>',
                esc_attr($id),
                $maxHeight,
                $content
            );
            return;
        }

        // Auto mode: fill remaining viewport height with a 24px bottom margin
        printf(
            '<div id="%s" class="fchub-pe-scroll-container" style="overflow-y:auto;">%s</div>',
            esc_attr($id),
            $content
        );

        // Inline script to measure offset and set max-height to fill remaining viewport.
        // Runs once on load and again on resize to stay responsive.
        printf(
            '<script>(function(){var el=document.getElementById("%s");if(!el)return;'
            . 'function fit(){var r=el.getBoundingClientRect();el.style.maxHeight=Math.max(200,window.innerHeight-r.top-24)+"px";}'
            . 'fit();window.addEventListener("resize",fit);'
            . 'new MutationObserver(function(m,o){fit();o.disconnect();}).observe(el.parentElement||document.body,{childList:true,subtree:true});'
            . '})();</script>',
            esc_js($id)
        );
    }

    private static function resolveIcon(array $endpoint): string
    {
        if ($endpoint['icon_type'] === 'dashicon') {
            $icon = esc_attr($endpoint['icon_value']);
            return '<span class="dashicons ' . $icon . '" style="font-size:20px;width:20px;height:20px;"></span>';
        }

        return $endpoint['icon_value'];
    }
}

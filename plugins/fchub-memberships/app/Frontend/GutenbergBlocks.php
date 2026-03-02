<?php

namespace FChubMemberships\Frontend;

defined('ABSPATH') || exit;

class GutenbergBlocks
{
    public static function register(): void
    {
        add_action('init', [self::class, 'registerBlocks']);
        add_action('enqueue_block_editor_assets', [self::class, 'enqueueEditorAssets']);
    }

    public static function registerBlocks(): void
    {
        register_block_type('fchub-memberships/restrict', [
            'attributes'      => [
                'plan_slugs'          => ['type' => 'string', 'default' => ''],
                'resource_type'       => ['type' => 'string', 'default' => ''],
                'resource_id'         => ['type' => 'string', 'default' => ''],
                'restriction_message' => ['type' => 'string', 'default' => ''],
            ],
            'render_callback' => [self::class, 'renderRestrictBlock'],
        ]);

        register_block_type('fchub-memberships/membership-status', [
            'attributes'      => [
                'display' => ['type' => 'string', 'default' => 'compact'],
            ],
            'render_callback' => [self::class, 'renderMembershipStatusBlock'],
        ]);
    }

    public static function enqueueEditorAssets(): void
    {
        wp_enqueue_script(
            'fchub-memberships-blocks',
            FCHUB_MEMBERSHIPS_URL . 'assets/js/blocks.js',
            ['wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-i18n'],
            FCHUB_MEMBERSHIPS_VERSION,
            true
        );

        wp_enqueue_style(
            'fchub-memberships-blocks-editor',
            FCHUB_MEMBERSHIPS_URL . 'assets/css/frontend.css',
            [],
            FCHUB_MEMBERSHIPS_VERSION
        );
    }

    /**
     * Server-side render for fchub-memberships/restrict block.
     * Reuses the same logic as the [fchub_restrict] shortcode.
     */
    public static function renderRestrictBlock(array $attributes, string $content): string
    {
        return Shortcodes::renderRestrict([
            'plan'          => $attributes['plan_slugs'] ?? '',
            'resource_type' => $attributes['resource_type'] ?? '',
            'resource_id'   => $attributes['resource_id'] ?? '',
            'message'       => $attributes['restriction_message'] ?? '',
            'show_login'    => 'yes',
            'drip_message'  => '',
        ], $content);
    }

    /**
     * Server-side render for fchub-memberships/membership-status block.
     */
    public static function renderMembershipStatusBlock(array $attributes): string
    {
        return Shortcodes::renderMembershipStatus([
            'display' => $attributes['display'] ?? 'compact',
        ]);
    }
}

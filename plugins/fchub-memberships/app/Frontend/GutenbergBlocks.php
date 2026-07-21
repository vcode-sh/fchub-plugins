<?php

namespace FChubMemberships\Frontend;

defined('ABSPATH') || exit;

use FChubMemberships\Domain\ProtectionEditorConfig;

class GutenbergBlocks
{
    public static function register(): void
    {
        add_action('init', [self::class, 'registerBlocks']);
        add_action('rest_api_init', [self::class, 'registerProtectionFields']);
        add_action('enqueue_block_editor_assets', [self::class, 'enqueueEditorAssets']);
    }

    public static function registerProtectionFields(): void
    {
        $postTypes = get_post_types(['public' => true, 'show_in_rest' => true], 'names');
        unset($postTypes['attachment']);

        foreach ($postTypes as $postType) {
            register_rest_field($postType, 'fchub_membership_protection', [
                'get_callback' => static function (array $object): array {
                    $postId = (int) ($object['id'] ?? 0);
                    $post = get_post($postId);
                    return $post ? (new ProtectionEditorConfig())->getForPost($postId, $post->post_type) : [];
                },
                'update_callback' => static function ($value, \WP_Post $post): bool|\WP_Error {
                    if (!current_user_can('edit_post', $post->ID)) {
                        return new \WP_Error(
                            'fchub_protection_forbidden',
                            __('You cannot change membership protection for this content.', 'fchub-memberships')
                        );
                    }

                    return (new ProtectionEditorConfig())->saveForPost(
                        (int) $post->ID,
                        (string) $post->post_type,
                        is_array($value) ? $value : []
                    );
                },
                'schema' => [
                    'description' => __('Membership protection editor configuration.', 'fchub-memberships'),
                    'type' => 'object',
                    'context' => ['edit'],
                    'properties' => [
                        'enabled' => ['type' => 'boolean'],
                        'plan_ids' => ['type' => 'array', 'items' => ['type' => 'integer']],
                        'teaser_mode' => ['type' => 'string'],
                        'teaser_word_count' => ['type' => 'integer'],
                        'custom_teaser' => ['type' => 'string'],
                        'restriction_message' => ['type' => 'string'],
                        'fallback_message' => ['type' => 'string', 'readonly' => true],
                        'cta_text' => ['type' => 'string'],
                        'cta_url' => ['type' => 'string'],
                        'plans' => ['type' => 'array', 'readonly' => true],
                        'effective' => ['type' => 'object', 'readonly' => true],
                    ],
                ],
            ]);
        }
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
            [
                'wp-blocks',
                'wp-element',
                'wp-block-editor',
                'wp-components',
                'wp-i18n',
                'wp-plugins',
                'wp-data',
                'wp-editor',
            ],
            self::assetVersion('assets/js/blocks.js'),
            true
        );

        wp_enqueue_style(
            'fchub-memberships-blocks-editor',
            FCHUB_MEMBERSHIPS_URL . 'assets/css/frontend.css',
            [],
            self::assetVersion('assets/css/frontend.css')
        );

        wp_enqueue_style(
            'fchub-memberships-protection-editor',
            FCHUB_MEMBERSHIPS_URL . 'assets/css/editor.css',
            ['wp-components'],
            self::assetVersion('assets/css/editor.css')
        );
    }

    private static function assetVersion(string $relativePath): string
    {
        $modified = filemtime(FCHUB_MEMBERSHIPS_PATH . $relativePath);
        return $modified ? FCHUB_MEMBERSHIPS_VERSION . '.' . $modified : FCHUB_MEMBERSHIPS_VERSION;
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

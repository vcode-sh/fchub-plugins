<?php

declare(strict_types=1);

namespace FChubMemberships\Tests\Unit\Domain;

use FChubMemberships\Domain\AccessEvaluator;
use FChubMemberships\Domain\ContentProtection;
use FChubMemberships\Tests\Unit\PluginTestCase;

if (!defined('FCHUB_TESTING')) {
    define('FCHUB_TESTING', true);
}

final class ContentProtectionBehaviorTest extends PluginTestCase
{
    private function injectEvaluator(ContentProtection $protection, AccessEvaluator $evaluator): void
    {
        $reflection = new \ReflectionProperty(ContentProtection::class, 'evaluator');
        $reflection->setValue($protection, $evaluator);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $post = new \WP_Post();
        $post->ID = 55;
        $post->post_type = 'post';
        $post->post_title = 'Members Post';
        $post->post_excerpt = 'Excerpt content';

        $taxonomyPost = new \WP_Post();
        $taxonomyPost->ID = 201;
        $taxonomyPost->post_type = 'post';
        $taxonomyPost->post_title = 'Inherited Post';

        $GLOBALS['_fchub_test_current_post'] = $post;
        $GLOBALS['_fchub_test_posts'][55] = $post;
        $GLOBALS['_fchub_test_posts'][201] = $taxonomyPost;
        $GLOBALS['_fchub_test_posts_by_type']['post'] = [$post, $taxonomyPost];
        $GLOBALS['_fchub_test_post_types'] = ['post', 'page', 'attachment'];
        $GLOBALS['_fchub_test_get_object_taxonomies']['post'] = ['category'];
        $GLOBALS['_fchub_test_post_terms'][201]['category'] = [(object) ['term_id' => 3]];
    }

    public function test_register_adds_runtime_hooks_and_bulk_actions_for_public_post_types(): void
    {
        $protection = new ContentProtection();
        $protection->register();

        self::assertArrayHasKey('the_content', $GLOBALS['_fchub_test_filters']);
        self::assertArrayHasKey('get_the_excerpt', $GLOBALS['_fchub_test_filters']);
        self::assertArrayHasKey('rest_prepare_post', $GLOBALS['_fchub_test_filters']);
        self::assertArrayHasKey('rest_prepare_page', $GLOBALS['_fchub_test_filters']);
        self::assertArrayHasKey('bulk_actions-edit-post', $GLOBALS['_fchub_test_filters']);
        self::assertArrayHasKey('handle_bulk_action-edit-post', $GLOBALS['_fchub_test_filters']);
        self::assertArrayHasKey('template_redirect', $GLOBALS['_fchub_test_actions']);
        self::assertArrayHasKey('pre_get_posts', $GLOBALS['_fchub_test_actions']);
    }

    public function test_filter_excerpt_and_rest_content_use_teaser_and_paused_contexts(): void
    {
        $GLOBALS['_fchub_test_options']['fchub_memberships_settings'] = [
            'restriction_message_membership_paused' => 'Paused by settings',
        ];
        $GLOBALS['_fchub_test_current_user_id'] = 9;

        $GLOBALS['_fchub_test_wpdb_overrides']['get_row'] = static fn(string $query): ?array => str_contains($query, "resource_type = 'post' AND resource_id = '55'")
            ? [
                'id' => 1,
                'resource_type' => 'post',
                'resource_id' => '55',
                'plan_ids' => '[]',
                'protection_mode' => 'explicit',
                'restriction_message' => null,
                'redirect_url' => null,
                'show_teaser' => 'yes',
                'meta' => '{}',
                'created_at' => '2026-03-01 00:00:00',
                'updated_at' => '2026-03-01 00:00:00',
            ]
            : null;

        $protection = new ContentProtection();
        $evaluator = new class extends AccessEvaluator {
            public function isProtected(string $provider, string $resourceType, string $resourceId): bool
            {
                return true;
            }

            public function canAccess(int $userId, string $provider, string $resourceType, string $resourceId): bool
            {
                return false;
            }

            public function evaluate(int $userId, string $provider, string $resourceType, string $resourceId): array
            {
                return [
                    'allowed' => false,
                    'reason' => 'membership_paused',
                    'drip_locked' => false,
                    'drip_available_at' => null,
                    'grant' => null,
                ];
            }
        };
        $this->injectEvaluator($protection, $evaluator);

        $excerpt = $protection->filterExcerpt('Excerpt content');
        $response = new \WP_REST_Response(['content' => ['rendered' => 'Original']]);
        $rest = $protection->filterRestContent($response, $GLOBALS['_fchub_test_posts'][55], new \WP_REST_Request('GET', '/posts/55'));
        $data = $rest->get_data();

        self::assertSame('Excerpt content', $excerpt);
        self::assertTrue($data['content']['protected']);
        self::assertStringContainsString('Paused by settings', $data['content']['rendered']);
        self::assertStringContainsString('fchub-restricted-membership_paused', $data['content']['rendered']);
    }

    public function test_filter_archive_queries_excludes_only_inaccessible_protected_posts(): void
    {
        $GLOBALS['_fchub_test_options']['fchub_memberships_settings'] = [
            'hide_protected_in_archive' => 'yes',
        ];
        $GLOBALS['_fchub_test_current_user_id'] = 9;

        $GLOBALS['_fchub_test_wpdb_overrides']['get_results'] = static function (string $query): array {
            return match (true) {
                str_contains($query, "SELECT resource_id FROM wp_fchub_membership_protection_rules WHERE resource_type = 'post'") => [
                    ['resource_id' => '55'],
                    ['resource_id' => '77'],
                ],
                str_contains($query, "resource_type IN ('category')") => [
                    ['resource_type' => 'category', 'resource_id' => '3', 'meta' => '{"inheritance_mode":"all_posts"}'],
                ],
                default => [],
            };
        };

        $protection = new ContentProtection();
        $evaluator = new class extends AccessEvaluator {
            public function canAccessMultiple(int $userId, array $postIds, string $postType): array
            {
                return ['55'];
            }
        };
        $this->injectEvaluator($protection, $evaluator);

        $query = new \WP_Query(['post_type' => 'post']);
        $query->is_archive = true;
        $protection->filterArchiveQueries($query);

        self::assertSame([77, 201], $query->get('post__not_in'));
    }

    public function test_handle_bulk_action_protects_and_unprotects_posts_and_appends_result_query_args(): void
    {
        $inserted = [];
        $deleted = [];

        $otherPost = new \WP_Post();
        $otherPost->ID = 56;
        $otherPost->post_type = 'post';
        $otherPost->post_title = 'Second Post';
        $GLOBALS['_fchub_test_posts'][56] = $otherPost;

        $GLOBALS['_fchub_test_wpdb_overrides']['get_row'] = static fn(string $query): ?array => str_contains($query, "resource_id = '56'")
            ? [
                'id' => 9,
                'resource_type' => 'post',
                'resource_id' => '56',
                'plan_ids' => '[]',
                'protection_mode' => 'explicit',
                'restriction_message' => null,
                'redirect_url' => null,
                'show_teaser' => 'no',
                'meta' => '{}',
                'created_at' => '2026-03-01 00:00:00',
                'updated_at' => '2026-03-01 00:00:00',
            ]
            : null;
        $GLOBALS['_fchub_test_wpdb_overrides']['insert'] = static function (string $table, array $data) use (&$inserted): int {
            $inserted[] = [$table, $data];
            return 1;
        };
        $GLOBALS['_fchub_test_wpdb_overrides']['delete'] = static function (string $table, array $where) use (&$deleted): int {
            $deleted[] = [$table, $where];
            return 1;
        };

        $protection = new ContentProtection();
        $protectedUrl = $protection->handleBulkAction('https://example.com/wp-admin/edit.php', 'fchub_protect', [55]);
        $unprotectedUrl = $protection->handleBulkAction('https://example.com/wp-admin/edit.php', 'fchub_unprotect', [56]);

        self::assertStringContainsString('fchub_bulk_action=fchub_protect', $protectedUrl);
        self::assertStringContainsString('fchub_bulk_count=1', $protectedUrl);
        self::assertStringContainsString('fchub_bulk_action=fchub_unprotect', $unprotectedUrl);
        self::assertStringContainsString('fchub_bulk_count=1', $unprotectedUrl);
        self::assertSame('wp_fchub_membership_protection_rules', $inserted[0][0]);
        self::assertSame([['wp_fchub_membership_protection_rules', ['id' => 9]]], $deleted);
    }

    public function test_filter_content_template_redirect_and_render_block_cover_remaining_public_paths(): void
    {
        $GLOBALS['_fchub_test_options']['fchub_memberships_settings'] = [
            'default_protection_mode' => 'redirect',
            'restriction_message_logged_out' => 'Please log in',
            'restriction_message_drip_locked' => 'Unlocks on {unlock_date}',
            'pricing_page_url' => 'https://example.com/pricing',
        ];
        $GLOBALS['_fchub_test_options']['date_format'] = 'Y-m-d';
        $GLOBALS['_fchub_test_current_user_id'] = 0;
        $GLOBALS['_fchub_test_current_user'] = (object) [
            'ID' => 0,
            'display_name' => 'Guest',
            'user_email' => '',
        ];

        $GLOBALS['_fchub_test_wpdb_overrides']['get_row'] = static fn(string $query): ?array => match (true) {
            str_contains($query, "resource_type = 'post' AND resource_id = '55'") => [
                'id' => 1,
                'resource_type' => 'post',
                'resource_id' => '55',
                'plan_ids' => '[5]',
                'protection_mode' => 'redirect',
                'restriction_message' => null,
                'redirect_url' => 'https://example.com/custom-redirect',
                'show_teaser' => 'no',
                'meta' => '{"cta_text":"Buy Now","cta_url":"https://example.com/buy"}',
                'created_at' => '2026-03-01 00:00:00',
                'updated_at' => '2026-03-01 00:00:00',
            ],
            str_contains($query, 'wp_fchub_membership_plans') => [
                'id' => 5,
                'title' => 'Gold Plan',
                'slug' => 'gold-plan',
                'description' => '',
                'status' => 'active',
                'level' => 0,
                'duration_type' => 'lifetime',
                'duration_days' => null,
                'trial_days' => 0,
                'grace_period_days' => 0,
                'includes_plan_ids' => '[]',
                'restriction_message' => '',
                'redirect_url' => '',
                'settings' => '{}',
                'meta' => '{}',
                'created_at' => '2026-01-01 00:00:00',
                'updated_at' => '2026-01-01 00:00:00',
            ],
            default => null,
        };

        $protection = new ContentProtection();
        $evaluator = new class extends AccessEvaluator {
            public function isProtected(string $provider, string $resourceType, string $resourceId): bool
            {
                return true;
            }

            public function canAccess(int $userId, string $provider, string $resourceType, string $resourceId): bool
            {
                return false;
            }

            public function evaluate(int $userId, string $provider, string $resourceType, string $resourceId): array
            {
                return [
                    'allowed' => false,
                    'reason' => 'drip_locked',
                    'drip_locked' => true,
                    'drip_available_at' => '2026-03-20 10:00:00',
                    'grant' => null,
                ];
            }

            public function getRedirectUrl(string $resourceType, string $resourceId): ?string
            {
                return 'https://example.com/custom-redirect';
            }

            public function getRestrictionMessage(string $resourceType, string $resourceId, string $context = 'no_access'): string
            {
                return match ($context) {
                    'logged_out' => 'Please log in',
                    'drip_locked' => 'Unlocks on {unlock_date}',
                    default => 'Available for {plan_names}. {login_url} {pricing_url} {user_name}',
                };
            }
        };
        $this->injectEvaluator($protection, $evaluator);

        $GLOBALS['_fchub_test_current_post'] = $GLOBALS['_fchub_test_posts'][55];
        $GLOBALS['_fchub_test_current_user_id'] = 9;
        $dripLocked = $protection->filterContent('Secret');

        $html = $protection->renderRestrictionBlock([
            'resource_type' => 'post',
            'resource_id' => '55',
            'plan_ids' => [5],
            'meta' => [
                'cta_text' => 'Buy Now',
                'cta_url' => 'https://example.com/buy',
            ],
        ], 'logged_out', 'post', '55');

        $_GET['fchub_bulk_action'] = 'fchub_protect';
        $_GET['fchub_bulk_count'] = '2';
        ob_start();
        $protection->bulkActionAdminNotice();
        $notice = ob_get_clean();
        unset($_GET['fchub_bulk_action'], $_GET['fchub_bulk_count']);

        $protection->invalidateUserCache(9, 5, []);
        $protection->invalidateRevokedUsersCache([], 5, 9, 'reason');
        $protection->invalidateGrantUserCache(['user_id' => 9]);
        $actions = $protection->registerBulkActions([]);

        self::assertStringContainsString('Available on:', $dripLocked);
        self::assertStringContainsString('2026-03-20', $dripLocked);
        self::assertStringContainsString('Gold Plan', $html);
        self::assertStringContainsString('wp-login.php', $html);
        self::assertStringContainsString('Buy Now', $html);
        self::assertStringContainsString('2 items protected.', $notice);
        self::assertSame('Protect with Membership', $actions['fchub_protect']);
        self::assertSame([
            'fchub_user_9_accessible_posts_active',
            'fchub_user_9_accessible_posts_active',
            'fchub_user_9_accessible_posts_active',
        ], $GLOBALS['_fchub_test_deleted_transients']);
    }

    public function test_add_meta_box_render_meta_box_and_save_meta_box_cover_editor_paths(): void
    {
        $GLOBALS['_fchub_test_wpdb_overrides']['get_results'] = static function (string $query): array {
            return match (true) {
                str_contains($query, "SELECT * FROM wp_fchub_membership_plans WHERE 1=1 AND status = 'active'") => [[
                    'id' => 5,
                    'title' => 'Gold Plan',
                    'slug' => 'gold-plan',
                    'description' => '',
                    'status' => 'active',
                    'level' => 1,
                    'duration_type' => 'lifetime',
                    'duration_days' => null,
                    'trial_days' => 0,
                    'grace_period_days' => 0,
                    'includes_plan_ids' => '[]',
                    'restriction_message' => null,
                    'redirect_url' => null,
                    'settings' => '{}',
                    'meta' => '{}',
                    'created_at' => '2026-01-01 00:00:00',
                    'updated_at' => '2026-01-01 00:00:00',
                ]],
                str_contains($query, 'SELECT DISTINCT plan_id FROM wp_fchub_membership_plan_rules') => [['plan_id' => '5']],
                default => [],
            };
        };
        $GLOBALS['_fchub_test_wpdb_overrides']['get_row'] = static fn(string $query): ?array => match (true) {
            str_contains($query, "resource_type = 'post' AND resource_id = '55'") => [
                'id' => 1,
                'resource_type' => 'post',
                'resource_id' => '55',
                'plan_ids' => '[5]',
                'protection_mode' => 'explicit',
                'restriction_message' => 'Join now',
                'redirect_url' => '',
                'show_teaser' => 'yes',
                'meta' => '{"teaser_mode":"custom","custom_teaser":"Preview text","cta_text":"Buy","cta_url":"https://example.com/buy"}',
                'created_at' => '2026-03-01 00:00:00',
                'updated_at' => '2026-03-01 00:00:00',
            ],
            str_contains($query, 'wp_fchub_membership_plans') => [
                'id' => 5,
                'title' => 'Gold Plan',
                'slug' => 'gold-plan',
                'description' => '',
                'status' => 'active',
                'level' => 1,
                'duration_type' => 'lifetime',
                'duration_days' => null,
                'trial_days' => 0,
                'grace_period_days' => 0,
                'includes_plan_ids' => '[]',
                'restriction_message' => null,
                'redirect_url' => null,
                'settings' => '{}',
                'meta' => '{}',
                'created_at' => '2026-01-01 00:00:00',
                'updated_at' => '2026-01-01 00:00:00',
            ],
            default => null,
        };

        $inserted = [];
        $deleted = [];
        $GLOBALS['_fchub_test_wpdb_overrides']['insert'] = static function (string $table, array $data, \wpdb $wpdb) use (&$inserted): int {
            $inserted[] = [$table, $data];
            $wpdb->insert_id = 70;
            return 1;
        };
        $GLOBALS['_fchub_test_wpdb_overrides']['delete'] = static function (string $table, array $where) use (&$deleted): int {
            $deleted[] = [$table, $where];
            return 1;
        };

        $protection = new ContentProtection();
        $protection->addMetaBox();

        ob_start();
        $protection->renderMetaBox($GLOBALS['_fchub_test_posts'][55]);
        $html = ob_get_clean();

        $_POST = [
            '_fchub_protection_nonce' => 'nonce',
            'fchub_is_protected' => '1',
            'fchub_plan_ids' => ['5'],
            'fchub_restriction_message' => 'Restricted',
            'fchub_teaser_mode' => 'words',
            'fchub_teaser_word_count' => '25',
            'fchub_custom_teaser' => 'Preview',
            'fchub_cta_text' => 'Buy Now',
            'fchub_cta_url' => 'https://example.com/buy',
        ];
        $protection->saveMetaBox(55, $GLOBALS['_fchub_test_posts'][55]);

        $_POST = [
            '_fchub_protection_nonce' => 'nonce',
        ];
        $protection->saveMetaBox(55, $GLOBALS['_fchub_test_posts'][55]);
        $_POST = [];

        self::assertCount(3, $GLOBALS['_fchub_test_meta_boxes']);
        self::assertStringContainsString('Protect this content', $html);
        self::assertStringContainsString('Preview text', $html);
        self::assertStringContainsString('Gold Plan', $html);
        self::assertSame([['wp_fchub_membership_protection_rules', ['id' => 1]]], $deleted);
    }
}

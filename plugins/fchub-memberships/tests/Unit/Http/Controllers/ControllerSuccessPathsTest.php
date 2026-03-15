<?php

declare(strict_types=1);

namespace FChubMemberships\Tests\Unit\Http\Controllers;

use FChubMemberships\Http\Controllers\ContentController;
use FChubMemberships\Http\Controllers\ImportController;
use FChubMemberships\Tests\Unit\PluginTestCase;

final class ControllerSuccessPathsTest extends PluginTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $post = new \WP_Post();
        $post->ID = 55;
        $post->post_type = 'post';
        $post->post_title = 'Members Post';
        $GLOBALS['_fchub_test_posts'][55] = $post;

        $menu = new \WP_Post();
        $menu->ID = 99;
        $menu->post_type = 'nav_menu_item';
        $menu->post_title = 'Members Area';
        $menu->title = 'Members Area';
        $GLOBALS['_fchub_test_posts'][99] = $menu;
        $GLOBALS['_fchub_test_posts_by_type']['nav_menu_item'] = [$menu];
        $GLOBALS['_fchub_test_post_types'] = ['post', 'page', 'nav_menu_item'];
    }

    public function test_content_controller_covers_successful_mutations_and_search_branches(): void
    {
        $inserted = [];
        $updated = [];
        $deleted = [];

        $GLOBALS['_fchub_test_wpdb_overrides']['insert'] = static function (string $table, array $data, \wpdb $wpdb) use (&$inserted): int {
            $inserted[] = [$table, $data];
            $wpdb->insert_id = 44;
            return 1;
        };
        $GLOBALS['_fchub_test_wpdb_overrides']['update'] = static function (string $table, array $data, array $where) use (&$updated): int {
            $updated[] = [$table, $data, $where];
            return 1;
        };
        $GLOBALS['_fchub_test_wpdb_overrides']['delete'] = static function (string $table, array $where) use (&$deleted): int {
            $deleted[] = [$table, $where];
            return 1;
        };
        $GLOBALS['_fchub_test_wpdb_overrides']['get_row'] = static fn(string $query): ?array => match (true) {
            str_contains($query, "WHERE id = 44") => [
                'id' => 44,
                'resource_type' => 'post',
                'resource_id' => '55',
                'plan_ids' => '[5]',
                'protection_mode' => 'explicit',
                'restriction_message' => 'Join',
                'redirect_url' => '',
                'show_teaser' => 'yes',
                'meta' => '{}',
                'created_at' => '2026-03-01 00:00:00',
                'updated_at' => '2026-03-01 00:00:00',
            ],
            str_contains($query, "resource_type = 'post' AND resource_id = '55'") => [
                'id' => 44,
                'resource_type' => 'post',
                'resource_id' => '55',
                'plan_ids' => '[5]',
                'protection_mode' => 'explicit',
                'restriction_message' => 'Join',
                'redirect_url' => '',
                'show_teaser' => 'yes',
                'meta' => '{}',
                'created_at' => '2026-03-01 00:00:00',
                'updated_at' => '2026-03-01 00:00:00',
            ],
            str_contains($query, "resource_type = 'post' AND resource_id = '56'") => [
                'id' => 45,
                'resource_type' => 'post',
                'resource_id' => '56',
                'plan_ids' => '[5]',
                'protection_mode' => 'explicit',
                'restriction_message' => 'Join',
                'redirect_url' => '',
                'show_teaser' => 'yes',
                'meta' => '{}',
                'created_at' => '2026-03-01 00:00:00',
                'updated_at' => '2026-03-01 00:00:00',
            ],
            default => null,
        };
        $GLOBALS['_fchub_test_wpdb_overrides']['get_results'] = static fn(string $query): array => match (true) {
            str_contains($query, "resource_type = 'url_pattern'") => [[
                'id' => 90,
                'resource_type' => 'url_pattern',
                'resource_id' => '/members-area',
                'plan_ids' => '[]',
                'protection_mode' => 'explicit',
                'restriction_message' => null,
                'redirect_url' => null,
                'show_teaser' => 'no',
                'meta' => '{}',
                'created_at' => '2026-03-01 00:00:00',
                'updated_at' => '2026-03-01 00:00:00',
            ]],
            default => [],
        };

        $protected = ContentController::protect(new \WP_REST_Request('POST', '/protect', [
            'resource_type' => 'post',
            'resource_id' => '55',
            'plan_ids' => [5],
            'show_teaser' => 'yes',
            'restriction_message' => 'Join',
        ]));
        $updatedResponse = ContentController::update(new \WP_REST_Request('PUT', '/content/44', [
            'id' => 44,
            'plan_ids' => [5, 6],
            'show_teaser' => 'no',
        ]));
        $unprotected = ContentController::unprotect(new \WP_REST_Request('POST', '/unprotect', [
            'resource_type' => 'post',
            'resource_id' => '55',
        ]));
        $destroyed = ContentController::destroy(new \WP_REST_Request('DELETE', '/content/44', ['id' => 44]));
        $bulkProtected = ContentController::bulkProtect(new \WP_REST_Request('POST', '/bulk-protect', [
            'resource_type' => 'post',
            'resource_ids' => ['55', '56'],
            'plan_ids' => [5],
        ]))->get_data();
        $bulkUnprotected = ContentController::bulkUnprotect(new \WP_REST_Request('POST', '/bulk-unprotect', [
            'resource_type' => 'post',
            'resource_ids' => ['55', '56'],
        ]))->get_data();
        $menuSearch = ContentController::searchResources(new \WP_REST_Request('GET', '/search', [
            'type' => 'menu_item',
            'query' => 'Members',
        ]))->get_data();
        $urlPatterns = ContentController::searchResources(new \WP_REST_Request('GET', '/search', [
            'type' => 'url_pattern',
        ]))->get_data();

        self::assertSame(201, $protected->get_status());
        self::assertSame(200, $updatedResponse->get_status());
        self::assertSame('Protection removed.', $unprotected->get_data()['message']);
        self::assertSame('Protection rule deleted.', $destroyed->get_data()['message']);
        self::assertSame(2, $bulkProtected['protected']);
        self::assertSame(2, $bulkUnprotected['unprotected']);
        self::assertSame('Members Area', $menuSearch['data'][0]['label']);
        self::assertSame('/members-area', $urlPatterns['data'][0]['label']);
        self::assertCount(4, $deleted);
    }

    public function test_import_controller_parse_successfully_detects_generic_csv(): void
    {
        $csv = <<<CSV
email,username,level,expires_at
alice@example.com,alice,Gold,2026-03-20
bob@example.com,bob,Silver,
CSV;

        $response = ImportController::parse(new \WP_REST_Request('POST', '/import/parse', [
            'content' => $csv,
        ]))->get_data();

        self::assertSame('Generic', $response['data']['format']);
        self::assertCount(2, $response['data']['members']);
        self::assertCount(2, $response['data']['preview']);
        self::assertSame(2, $response['data']['stats']['total']);
    }
}

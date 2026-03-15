<?php

declare(strict_types=1);

namespace FChubMemberships\Tests\Unit\Storage;

use FChubMemberships\Storage\ProtectionRuleRepository;
use FChubMemberships\Tests\Unit\PluginTestCase;

final class ProtectionRuleRepositoryTest extends PluginTestCase
{
    private function row(array $overrides = []): array
    {
        return array_merge([
            'id' => 7,
            'resource_type' => 'post',
            'resource_id' => '55',
            'plan_ids' => '[5,6]',
            'protection_mode' => 'redirect',
            'restriction_message' => 'Join now',
            'redirect_url' => 'https://example.com/join',
            'show_teaser' => 'yes',
            'meta' => '{"inheritance_mode":"all_posts"}',
            'created_at' => '2026-03-01 10:00:00',
            'updated_at' => '2026-03-02 10:00:00',
        ], $overrides);
    }

    public function test_count_uses_same_filters_as_all_and_create_update_normalize_values(): void
    {
        $inserted = [];
        $updated = [];
        $queries = [];
        $row = $this->row();

        $GLOBALS['_fchub_test_wpdb_overrides']['insert'] = static function (string $table, array $data, \wpdb $wpdb) use (&$inserted): int {
            $inserted[] = [$table, $data];
            $wpdb->insert_id = 20;
            return 1;
        };

        $GLOBALS['_fchub_test_wpdb_overrides']['update'] = static function (string $table, array $data, array $where) use (&$updated): int {
            $updated[] = [$table, $data, $where];
            return 1;
        };

        $GLOBALS['_fchub_test_wpdb_overrides']['get_row'] = fn(string $query): ?array => match (true) {
            str_contains($query, 'WHERE id = 7') => $row,
            str_contains($query, "resource_type = 'post' AND resource_id = '55'") => $row,
            default => null,
        };

        $GLOBALS['_fchub_test_wpdb_overrides']['get_results'] = static function (string $query) use (&$queries, $row): array {
            $queries[] = $query;
            return [$row];
        };

        $GLOBALS['_fchub_test_wpdb_overrides']['get_var'] = static function (string $query) use (&$queries): int {
            $queries[] = $query;
            return 3;
        };

        $repo = new ProtectionRuleRepository();
        $all = $repo->all([
            'resource_type' => 'post',
            'protection_mode' => 'redirect',
            'plan_id' => 5,
            'search' => '55',
            'per_page' => 10,
            'page' => 2,
        ]);
        $count = $repo->count([
            'resource_type' => 'post',
            'protection_mode' => 'redirect',
            'plan_id' => 5,
            'search' => '55',
        ]);
        $created = $repo->create([
            'resource_type' => 'page',
            'resource_id' => '99',
            'plan_ids' => [],
            'protection_mode' => 'invalid-mode',
            'meta' => ['flag' => true],
        ]);
        $repo->update(7, [
            'plan_ids' => [],
            'protection_mode' => 'bad',
            'meta' => ['updated' => true],
        ]);

        self::assertCount(1, $all);
        self::assertSame([5, 6], $all[0]['plan_ids']);
        self::assertSame(3, $count);
        self::assertSame(20, $created);
        self::assertSame('explicit', $inserted[0][1]['protection_mode']);
        self::assertSame('[]', $inserted[0][1]['plan_ids']);
        self::assertSame('{"flag":true}', $inserted[0][1]['meta']);
        self::assertSame('explicit', $updated[0][1]['protection_mode']);
        self::assertSame('[]', $updated[0][1]['plan_ids']);
        self::assertSame('{"updated":true}', $updated[0][1]['meta']);

        $queryDump = implode("\n", $queries);
        self::assertStringContainsString("plan_ids LIKE '%\\\"5\\\"%'", $queryDump);
        self::assertStringContainsString("resource_id LIKE '%55%'", $queryDump);
    }

    public function test_create_or_update_and_taxonomy_inheritance_helpers_behave_consistently(): void
    {
        $inserted = [];
        $updated = [];

        $categoryPost = new \WP_Post();
        $categoryPost->ID = 201;
        $categoryPost->post_type = 'post';
        $categoryPost->post_title = 'Premium Post';
        $GLOBALS['_fchub_test_posts_by_type']['post'] = [$categoryPost];
        $GLOBALS['_fchub_test_get_object_taxonomies']['post'] = ['category'];
        $GLOBALS['_fchub_test_post_terms'][201]['category'] = [(object) ['term_id' => 3]];

        $GLOBALS['_fchub_test_wpdb_overrides']['insert'] = static function (string $table, array $data, \wpdb $wpdb) use (&$inserted): int {
            $inserted[] = [$table, $data];
            $wpdb->insert_id = 33;
            return 1;
        };
        $GLOBALS['_fchub_test_wpdb_overrides']['update'] = static function (string $table, array $data, array $where) use (&$updated): int {
            $updated[] = [$table, $data, $where];
            return 1;
        };
        $GLOBALS['_fchub_test_wpdb_overrides']['get_row'] = fn(string $query): ?array => str_contains($query, "resource_type = 'post' AND resource_id = '55'")
            ? $this->row(['id' => 7])
            : null;
        $GLOBALS['_fchub_test_wpdb_overrides']['get_results'] = static function (string $query): array {
            return match (true) {
                str_contains($query, 'SELECT resource_id FROM wp_fchub_membership_protection_rules') => [
                    ['resource_id' => '55'],
                    ['resource_id' => '99'],
                ],
                str_contains($query, "resource_type IN ('category')") => [
                    ['resource_type' => 'category', 'resource_id' => '3', 'meta' => '{"inheritance_mode":"all_posts"}'],
                    ['resource_type' => 'category', 'resource_id' => '4', 'meta' => '{"inheritance_mode":"none"}'],
                ],
                default => [],
            };
        };

        $repo = new ProtectionRuleRepository();
        $updatedId = $repo->createOrUpdate('post', '55', ['plan_ids' => [9]]);
        $createdId = $repo->createOrUpdate('page', '77', ['plan_ids' => [10]]);
        $protectedIds = $repo->getProtectedResourceIds('post');
        $taxonomyInherited = $repo->getPostIdsProtectedByTaxonomy('post');

        self::assertSame(7, $updatedId);
        self::assertSame(33, $createdId);
        self::assertSame(['55', '99'], $protectedIds);
        self::assertSame(['201'], $taxonomyInherited);
        self::assertTrue($repo->isProtected('post', '55'));
        self::assertCount(1, $updated);
        self::assertCount(1, $inserted);
    }

    public function test_all_hydrates_partial_rows_without_emitting_missing_key_warnings(): void
    {
        $GLOBALS['_fchub_test_wpdb_overrides']['get_results'] = static fn(string $query): array => [[
            'resource_type' => 'url_pattern',
            'resource_id' => '/members-area',
        ]];

        $repo = new ProtectionRuleRepository();
        $rules = $repo->all(['resource_type' => 'url_pattern']);

        self::assertSame([[
            'resource_type' => 'url_pattern',
            'resource_id' => '/members-area',
            'id' => 0,
            'plan_ids' => [],
            'protection_mode' => 'explicit',
            'restriction_message' => null,
            'redirect_url' => null,
            'show_teaser' => 'no',
            'meta' => [],
        ]], array_map(static fn(array $rule): array => [
            'resource_type' => $rule['resource_type'],
            'resource_id' => $rule['resource_id'],
            'id' => $rule['id'],
            'plan_ids' => $rule['plan_ids'],
            'protection_mode' => $rule['protection_mode'],
            'restriction_message' => $rule['restriction_message'],
            'redirect_url' => $rule['redirect_url'],
            'show_teaser' => $rule['show_teaser'],
            'meta' => $rule['meta'],
        ], $rules));
    }
}

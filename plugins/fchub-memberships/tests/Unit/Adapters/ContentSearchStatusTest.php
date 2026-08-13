<?php

declare(strict_types=1);

namespace FChubMemberships\Tests\Unit\Adapters;

use FChubMemberships\Adapters\WordPressContentAdapter;
use FChubMemberships\Http\Controllers\ContentController;
use FChubMemberships\Tests\Unit\PluginTestCase;

/**
 * A picker that cannot see a draft cannot protect one, so the search reads
 * every editable status and reports which one it found.
 */
final class ContentSearchStatusTest extends PluginTestCase
{
    /** @param array<int, array{0: int, 1: string, 2: string}> $rows */
    private function seedPosts(array $rows, string $postType = 'page'): void
    {
        foreach ($rows as [$id, $title, $status]) {
            $post = new \WP_Post();
            $post->ID = $id;
            $post->post_type = $postType;
            $post->post_title = $title;
            $post->post_status = $status;
            $GLOBALS['_fchub_test_posts'][$id] = $post;
            $GLOBALS['_fchub_test_posts_by_type'][$postType][] = $post;
        }
        $GLOBALS['_fchub_test_post_types'] = [$postType];
    }

    public function test_search_requests_every_editable_status(): void
    {
        $this->seedPosts([[7, 'Checkout', 'draft']]);

        (new WordPressContentAdapter())->searchResources('Checkout', 'page', 20);

        self::assertCount(1, $GLOBALS['_fchub_test_get_posts_args']);
        self::assertSame(
            ['publish', 'future', 'draft', 'pending', 'private'],
            $GLOBALS['_fchub_test_get_posts_args'][0]['post_status'],
        );
    }

    public function test_search_reports_the_status_of_each_result(): void
    {
        $this->seedPosts([
            [7, 'Checkout draft', 'draft'],
            [8, 'Checkout live', 'publish'],
            [9, 'Checkout later', 'future'],
        ]);

        $results = (new WordPressContentAdapter())->searchResources('Checkout', 'page', 20);

        self::assertSame(
            [['7', 'draft'], ['8', 'publish'], ['9', 'future']],
            array_map(static fn(array $row): array => [$row['id'], $row['status']], $results),
        );
    }

    public function test_taxonomy_results_carry_no_status(): void
    {
        $GLOBALS['_fchub_test_taxonomies'] = ['category'];
        $GLOBALS['_fchub_test_terms_by_taxonomy']['category'] = [
            (object) ['term_id' => 5, 'name' => 'Gold'],
        ];

        $results = (new WordPressContentAdapter())->searchResources('', 'category', 20);

        self::assertSame('Gold', $results[0]['label']);
        self::assertArrayNotHasKey('status', $results[0]);
    }

    public function test_controller_passes_status_through_beside_the_type_stamp(): void
    {
        $this->seedPosts([[7, 'Checkout', 'pending']]);

        $response = ContentController::searchResources(new \WP_REST_Request('GET', '/search', [
            'type'  => 'page',
            'query' => 'Checkout',
        ]));

        $row = $response->get_data()['data'][0];
        self::assertSame('pending', $row['status']);
        self::assertSame('page', $row['type']);
        self::assertArrayHasKey('type_label', $row);
    }
}

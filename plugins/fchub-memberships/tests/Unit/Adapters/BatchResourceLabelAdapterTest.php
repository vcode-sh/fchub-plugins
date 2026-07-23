<?php

declare(strict_types=1);

namespace FChubMemberships\Tests\Unit\Adapters;

use FChubMemberships\Adapters\Contracts\BatchResourceLabelAdapterInterface;
use FChubMemberships\Adapters\FluentCommunityAdapter;
use FChubMemberships\Adapters\FluentCrmAdapter;
use FChubMemberships\Adapters\LearnDashAdapter;
use FChubMemberships\Adapters\WordPressContentAdapter;
use FChubMemberships\Tests\Unit\PluginTestCase;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

final class BatchResourceLabelAdapterTest extends PluginTestCase
{
    public function test_wordpress_adapter_resolves_post_labels_in_one_bounded_read(): void
    {
        foreach ([11 => 'Eleven', 12 => 'Twelve', 99 => 'Unrequested'] as $id => $title) {
            $post = new \WP_Post();
            $post->ID = $id;
            $post->post_type = 'post';
            $post->post_title = $title;
            $GLOBALS['_fchub_test_posts'][$id] = $post;
            $GLOBALS['_fchub_test_posts_by_type']['post'][] = $post;
        }
        $GLOBALS['_fchub_test_post_types'] = ['post'];

        $adapter = new WordPressContentAdapter();
        self::assertInstanceOf(BatchResourceLabelAdapterInterface::class, $adapter);
        self::assertSame([
            '11' => 'Eleven',
            '12' => 'Twelve',
            '13' => 'Post #13',
        ], $adapter->getResourceLabels('post', ['11', '12', '13', '11']));
        self::assertCount(1, $GLOBALS['_fchub_test_get_posts_args']);
        self::assertSame([11, 12, 13], $GLOBALS['_fchub_test_get_posts_args'][0]['post__in']);
    }

    public function test_wordpress_adapter_resolves_taxonomy_labels_with_fallbacks(): void
    {
        $GLOBALS['_fchub_test_taxonomies'] = ['category'];
        $GLOBALS['_fchub_test_terms_by_taxonomy']['category'] = [
            (object) ['term_id' => 5, 'name' => 'Gold'],
            (object) ['term_id' => 99, 'name' => 'Unrequested'],
        ];

        self::assertSame([
            '5' => 'Gold',
            '6' => 'Term #6',
        ], (new WordPressContentAdapter())->getResourceLabels('category', ['5', '6']));
    }

    public function test_wordpress_adapter_throws_when_taxonomy_labels_cannot_be_loaded(): void
    {
        $GLOBALS['_fchub_test_taxonomies'] = ['category'];
        $GLOBALS['_fchub_test_get_terms_override'] = static fn(array $args): \WP_Error => new \WP_Error(
            'taxonomy_read_failed',
            'Taxonomy storage unavailable.'
        );

        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Taxonomy resource labels could not be loaded.');
            (new WordPressContentAdapter())->getResourceLabels('category', ['5']);
        } finally {
            unset($GLOBALS['_fchub_test_get_terms_override']);
        }
    }

    public function test_wordpress_adapter_uses_nav_menu_setup_titles_for_menu_items(): void
    {
        $menuItem = new \WP_Post();
        $menuItem->ID = 77;
        $menuItem->post_type = 'nav_menu_item';
        $menuItem->post_title = '';
        $menuItem->title = 'Configured Menu Label';
        $GLOBALS['_fchub_test_posts'][77] = $menuItem;
        $GLOBALS['_fchub_test_posts_by_type']['nav_menu_item'] = [$menuItem];

        self::assertSame([
            '77' => 'Configured Menu Label',
            '78' => 'Post #78',
        ], (new WordPressContentAdapter())->getResourceLabels('menu_item', ['77', '78']));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_inactive_provider_adapters_return_complete_fallback_maps_without_queries(): void
    {
        self::assertSame([
            '21' => 'Course #21',
            '22' => 'Course #22',
        ], (new LearnDashAdapter())->getResourceLabels('ld_course', ['21', '22']));
        self::assertSame([
            '31' => 'Tag #31',
            '32' => 'Tag #32',
        ], (new FluentCrmAdapter())->getResourceLabels('fluentcrm_tag', ['31', '32']));
        self::assertSame([
            '41' => 'Space #41',
            '42' => 'Space #42',
        ], (new FluentCommunityAdapter())->getResourceLabels('fc_space', ['41', '42']));
    }
}

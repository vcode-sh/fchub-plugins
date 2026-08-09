<?php

declare(strict_types=1);

namespace FChubMemberships\Tests\Unit\Domain\Plan;

use FChubMemberships\Domain\Plan\PlanRulePresenter;
use FChubMemberships\Support\ResourceTypeRegistry;
use FChubMemberships\Tests\Unit\PluginTestCase;

final class PlanRulePresenterTest extends PluginTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        ResourceTypeRegistry::reset();
        $GLOBALS['_fchub_test_options']['date_format'] = 'Y-m-d';
    }

    protected function tearDown(): void
    {
        ResourceTypeRegistry::reset();

        parent::tearDown();
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function rule(array $overrides = []): array
    {
        return array_merge([
            'id'              => 1,
            'plan_id'         => 5,
            'provider'        => 'wordpress_core',
            'resource_type'   => 'post',
            'resource_id'     => '0',
            'drip_delay_days' => 0,
            'drip_type'       => 'immediate',
            'drip_date'       => null,
            'sort_order'      => 0,
            'meta'            => [],
            'created_at'      => '2026-03-13 22:00:00',
            'updated_at'      => '2026-03-13 22:00:00',
        ], $overrides);
    }

    private function registerPost(int $id, string $title, string $status = 'publish'): void
    {
        $post = new \WP_Post();
        $post->ID = $id;
        $post->post_type = 'post';
        $post->post_title = $title;
        $post->post_status = $status;
        $GLOBALS['_fchub_test_posts'][$id] = $post;
    }

    public function test_resolves_posts_and_terms_to_titles_and_permalinks(): void
    {
        $this->registerPost(101, 'Members Only Guide');
        $GLOBALS['_fchub_test_terms_by_taxonomy']['category'][9] = (object) [
            'term_id'  => 9,
            'name'     => 'Member Library',
            'taxonomy' => 'category',
        ];

        $presenter = new PlanRulePresenter();

        self::assertSame([
            ['title' => 'Members Only Guide', 'url' => 'https://example.com/?p=101'],
            ['title' => 'Member Library', 'url' => 'https://example.com/category/9'],
        ], $presenter->immediateResources([
            $this->rule(['resource_type' => 'post', 'resource_id' => '101']),
            $this->rule(['resource_type' => 'category', 'resource_id' => '9']),
        ]));
    }

    public function test_wildcard_rules_are_described_without_a_link(): void
    {
        $presenter = new PlanRulePresenter();

        self::assertSame([
            ['title' => 'All Posts', 'url' => ''],
            ['title' => 'All Categories', 'url' => ''],
            ['title' => 'All Pages', 'url' => ''],
        ], $presenter->immediateResources([
            $this->rule(['resource_type' => 'post', 'resource_id' => '*']),
            $this->rule(['resource_type' => 'category', 'resource_id' => '0']),
            $this->rule(['resource_type' => 'page', 'resource_id' => '']),
        ]));
    }

    public function test_unresolvable_and_non_content_rules_are_dropped(): void
    {
        $this->registerPost(303, 'Work In Progress', 'draft');

        $presenter = new PlanRulePresenter();

        self::assertSame([], $presenter->immediateResources([
            // Deleted post.
            $this->rule(['resource_type' => 'post', 'resource_id' => '404']),
            // Deleted term.
            $this->rule(['resource_type' => 'category', 'resource_id' => '77']),
            // Never published.
            $this->rule(['resource_type' => 'post', 'resource_id' => '303']),
            // Protection plumbing.
            $this->rule(['resource_type' => 'url_pattern', 'resource_id' => '/members/*']),
            $this->rule(['resource_type' => 'special_page', 'resource_id' => 'search']),
            $this->rule(['resource_type' => 'menu_item', 'resource_id' => '12']),
            // CRM segmentation.
            $this->rule([
                'provider'      => 'fluentcrm',
                'resource_type' => 'fluentcrm_tag',
                'resource_id'   => '12',
            ]),
            // Resource type nobody registers any more.
            $this->rule(['resource_type' => 'sfwd-quiz', 'resource_id' => '5']),
        ]));
    }

    public function test_drip_items_describe_when_content_unlocks(): void
    {
        $this->registerPost(201, 'Week One');
        $this->registerPost(202, 'Week Two');
        $this->registerPost(203, 'Launch Day');
        $this->registerPost(204, 'Undated');

        $presenter = new PlanRulePresenter();

        self::assertSame([
            ['title' => 'Week One', 'available_date' => '1 day after joining'],
            ['title' => 'Week Two', 'available_date' => '14 days after joining'],
            ['title' => 'Launch Day', 'available_date' => '2026-05-01'],
            ['title' => 'Undated', 'available_date' => ''],
        ], $presenter->dripItems([
            $this->rule([
                'resource_id'     => '201',
                'drip_type'       => 'delayed',
                'drip_delay_days' => 1,
            ]),
            $this->rule([
                'resource_id'     => '202',
                'drip_type'       => 'delayed',
                'drip_delay_days' => 14,
            ]),
            $this->rule([
                'resource_id' => '203',
                'drip_type'   => 'fixed_date',
                'drip_date'   => '2026-05-01 12:00:00',
            ]),
            $this->rule([
                'resource_id' => '204',
                'drip_type'   => 'fixed_date',
                'drip_date'   => null,
            ]),
        ]));
    }

    public function test_immediate_and_drip_rules_are_split_by_drip_type(): void
    {
        $this->registerPost(101, 'Available Now');
        $this->registerPost(202, 'Available Later');

        $rules = [
            $this->rule(['resource_id' => '101']),
            $this->rule([
                'resource_id'     => '202',
                'drip_type'       => 'delayed',
                'drip_delay_days' => 7,
            ]),
        ];

        $presenter = new PlanRulePresenter();

        self::assertSame(
            [['title' => 'Available Now', 'url' => 'https://example.com/?p=101']],
            $presenter->immediateResources($rules)
        );
        self::assertSame(
            [['title' => 'Available Later', 'available_date' => '7 days after joining']],
            $presenter->dripItems($rules)
        );
    }

    public function test_empty_rule_sets_produce_empty_lists(): void
    {
        $presenter = new PlanRulePresenter();

        self::assertSame([], $presenter->immediateResources([]));
        self::assertSame([], $presenter->dripItems([]));
    }
}

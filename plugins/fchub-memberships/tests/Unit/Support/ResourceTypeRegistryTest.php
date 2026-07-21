<?php

declare(strict_types=1);

namespace FChubMemberships\Tests\Unit\Support;

use FChubMemberships\Support\Constants;
use FChubMemberships\Support\ResourceTypeRegistry;
use FChubMemberships\Tests\Unit\PluginTestCase;
use FChubMemberships\Http\Controllers\Plans\PlanReadController;
use FChubMemberships\Adapters\WordPressContentAdapter;

if (!defined('WC_ABSPATH')) {
    define('WC_ABSPATH', '/tmp/woocommerce/');
}

if (!defined('LEARNDASH_VERSION')) {
    define('LEARNDASH_VERSION', '4.0.0');
}

if (!defined('FLUENT_COMMUNITY_PLUGIN_VERSION')) {
    define('FLUENT_COMMUNITY_PLUGIN_VERSION', '1.0.0');
}

if (!defined('FLUENTCRM')) {
    define('FLUENTCRM', 'fluentcrm');
}

final class ResourceTypeRegistryTest extends PluginTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        ResourceTypeRegistry::reset();

        $GLOBALS['_fchub_test_post_type_objects'] = [
            'fluent-course' => (object) [
                'name' => 'fluent-course',
                'label' => 'Fluent Courses',
                'labels' => (object) ['singular_name' => 'Fluent Course'],
                'menu_icon' => 'cart',
            ],
            'product' => (object) [
                'name' => 'product',
                'label' => 'Products',
                'labels' => (object) ['singular_name' => 'Product'],
                'menu_icon' => 'products',
            ],
            'sfwd-topic' => (object) [
                'name' => 'sfwd-topic',
                'label' => 'Topics',
                'labels' => (object) ['singular_name' => 'Topic'],
                'menu_icon' => 'welcome-learn-more',
            ],
            'sfwd-courses' => (object) [
                'name' => 'sfwd-courses',
                'label' => 'Legacy Courses',
                'labels' => (object) ['singular_name' => 'Legacy Course'],
                'menu_icon' => 'welcome-learn-more',
            ],
            'sfwd-lessons' => (object) [
                'name' => 'sfwd-lessons',
                'label' => 'Legacy Lessons',
                'labels' => (object) ['singular_name' => 'Legacy Lesson'],
                'menu_icon' => 'welcome-learn-more',
            ],
            'attachment' => (object) [
                'name' => 'attachment',
                'label' => 'Attachment',
                'labels' => (object) ['singular_name' => 'Attachment'],
                'menu_icon' => 'media-default',
            ],
        ];

        $GLOBALS['_fchub_test_taxonomy_objects'] = [
            'product-category' => (object) [
                'name' => 'product-category',
                'label' => 'Product Categories',
                'labels' => (object) ['singular_name' => 'Product Category'],
            ],
            'product_cat' => (object) [
                'name' => 'product_cat',
                'label' => 'Woo Categories',
                'labels' => (object) ['singular_name' => 'Woo Category'],
            ],
            'ld_group' => (object) [
                'name' => 'ld_group',
                'label' => 'LearnDash Groups',
                'labels' => (object) ['singular_name' => 'LearnDash Group'],
            ],
            'pa_color' => (object) [
                'name' => 'pa_color',
                'label' => 'Colors',
                'labels' => (object) ['singular_name' => 'Color'],
            ],
            'nav_menu' => (object) [
                'name' => 'nav_menu',
                'label' => 'Menus',
                'labels' => (object) ['singular_name' => 'Menu'],
            ],
        ];

        add_action('fchub_memberships/resource_types', static function (ResourceTypeRegistry $registry): void {
            $registry->register('custom_hook_type', [
                'label' => 'Hook Type',
                'group' => 'advanced',
                'searchable' => true,
                'source' => 'Custom',
            ]);
        });
    }

    public function test_registry_registers_defaults_dynamic_types_and_provider_specific_types(): void
    {
        $registry = ResourceTypeRegistry::getInstance();
        $all = $registry->getAll();

        self::assertTrue($registry->isValid('post'));
        self::assertSame('WordPress', $all['post']['source']);
        self::assertSame('FluentCart', $all['fluent-course']['source']);
        self::assertSame('WooCommerce', $all['product']['source']);
        self::assertSame('LearnDash', $all['sfwd-topic']['source']);
        self::assertArrayNotHasKey('attachment', $all, 'Blacklisted post type should not be registered.');

        self::assertSame('FluentCart', $all['product-category']['source']);
        self::assertSame('WooCommerce', $all['product_cat']['source']);
        self::assertSame('LearnDash', $all['ld_group']['source']);
        self::assertArrayNotHasKey('pa_color', $all, 'WooCommerce attributes should be skipped.');
        self::assertArrayNotHasKey('nav_menu', $all, 'Blacklisted taxonomies should be skipped.');

        self::assertSame(Constants::PROVIDER_LEARNDASH, $all['ld_course']['provider']);
        self::assertSame(Constants::PROVIDER_LEARNDASH, $all['ld_group']['provider']);
        self::assertSame(Constants::PROVIDER_FLUENT_COMMUNITY, $all['fc_space']['provider']);
        self::assertSame('Hook Type', $all['custom_hook_type']['label']);
    }

    public function test_registry_uses_canonical_external_enrolment_types_with_safe_capabilities(): void
    {
        $all = ResourceTypeRegistry::getInstance()->getAll();

        self::assertArrayHasKey('ld_course', $all);
        self::assertArrayHasKey('ld_group', $all);
        self::assertArrayHasKey('fc_space', $all);
        self::assertArrayHasKey('fc_course', $all);
        self::assertArrayHasKey('fluentcrm_tag', $all);
        self::assertArrayHasKey('fluentcrm_list', $all);
        self::assertArrayNotHasKey('sfwd-courses', $all);
        self::assertArrayNotHasKey('sfwd-lessons', $all);

        foreach (['ld_course', 'ld_group', 'fc_space', 'fc_course', 'fluentcrm_tag', 'fluentcrm_list'] as $type) {
            self::assertFalse($all[$type]['allow_all']);
            self::assertArrayHasKey('adapter', $all[$type]);
        }

        self::assertSame(\FChubMemberships\Adapters\FluentCrmAdapter::class, $all['fluentcrm_tag']['adapter']);
        self::assertSame(\FChubMemberships\Adapters\FluentCommunityAdapter::class, $all['fc_space']['adapter']);
    }

    public function test_external_resource_labels_are_resolved_by_the_registered_adapter(): void
    {
        $adapter = new class {
            public function getResourceLabel(string $resourceType, string $resourceId): string
            {
                return 'Resolved by adapter';
            }
        };
        $registry = ResourceTypeRegistry::getInstance();
        $registry->register('adapter_label_test', [
            'adapter' => $adapter::class,
        ]);
        $method = new \ReflectionMethod(PlanReadController::class, 'resolveResourceName');

        self::assertSame(
            'Resolved by adapter',
            $method->invoke(null, 'adapter_label_test', '11', $registry)
        );
    }

    public function test_legacy_learndash_resource_types_are_read_only_and_never_registered_for_writes(): void
    {
        $registry = ResourceTypeRegistry::getInstance();

        self::assertArrayNotHasKey('sfwd-courses', $registry->getAll());
        self::assertArrayNotHasKey('sfwd-lessons', $registry->getAll());
        self::assertTrue(method_exists($registry, 'resolveReadType'));
        self::assertSame('ld_course', $registry->resolveReadType('sfwd-courses'));
        self::assertSame('sfwd-lessons', $registry->resolveReadType('sfwd-lessons'));
        self::assertSame('LearnDash Course', $registry->getForRead('sfwd-courses')['label']);
        self::assertSame('LearnDash Lesson', $registry->getForRead('sfwd-lessons')['label']);
        self::assertSame(Constants::PROVIDER_WORDPRESS_CORE, $registry->getForRead('sfwd-lessons')['provider']);
        self::assertSame(WordPressContentAdapter::class, $registry->getForRead('sfwd-lessons')['adapter']);
        self::assertFalse($registry->isValid('sfwd-courses'));
        self::assertFalse($registry->isValid('sfwd-lessons'));
    }

    public function test_legacy_learndash_course_label_uses_the_canonical_adapter_key(): void
    {
        $registry = ResourceTypeRegistry::getInstance();
        $method = new \ReflectionMethod(PlanReadController::class, 'resolveResourceName');

        self::assertSame(
            'Course #11',
            $method->invoke(null, 'sfwd-courses', '11', $registry)
        );
    }

    public function test_registry_exposes_grouped_searchable_and_select_option_views(): void
    {
        $registry = ResourceTypeRegistry::getInstance();

        $content = $registry->getByGroup('content');
        $searchable = $registry->getSearchableTypes();
        $options = $registry->toSelectOptions();
        $groups = $registry->getGroups();

        self::assertArrayHasKey('fluent-course', $content);
        self::assertArrayHasKey('custom_hook_type', $searchable);
        self::assertContains('advanced', $groups);
        self::assertContains('taxonomy', $groups);
        self::assertSame([
            'content' => 'Content',
            'taxonomy' => 'Taxonomy',
            'navigation' => 'Navigation',
            'advanced' => 'Advanced',
        ], $registry->getGroupLabels());

        $hookOption = array_values(array_filter($options, static fn(array $option): bool => $option['value'] === 'custom_hook_type'));
        self::assertCount(1, $hookOption);
        self::assertSame('Hook Type', $hookOption[0]['label']);
        self::assertSame('advanced', $hookOption[0]['group']);
        self::assertSame('Custom', $hookOption[0]['source']);

        self::assertNull($registry->get('missing-type'));
    }

    public function test_registry_falls_back_to_label_or_name_when_dynamic_objects_have_no_labels_payload(): void
    {
        ResourceTypeRegistry::reset();

        $GLOBALS['_fchub_test_post_type_objects'] = [
            'course' => (object) [
                'name' => 'course',
                'label' => 'Courses',
            ],
        ];
        $GLOBALS['_fchub_test_taxonomy_objects'] = [
            'audience' => (object) [
                'name' => 'audience',
            ],
        ];

        $all = ResourceTypeRegistry::getInstance()->getAll();

        self::assertSame('Courses', $all['course']['label']);
        self::assertSame('admin-post', $all['course']['icon']);
        self::assertSame('audience', $all['audience']['label']);
    }
}

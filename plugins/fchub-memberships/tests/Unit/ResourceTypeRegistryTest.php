<?php

namespace FChubMemberships\Tests\Unit;

use PHPUnit\Framework\TestCase;
use FChubMemberships\Support\ResourceTypeRegistry;
use FChubMemberships\Support\Constants;

class ResourceTypeRegistryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        ResourceTypeRegistry::reset();
    }

    protected function tearDown(): void
    {
        ResourceTypeRegistry::reset();
        parent::tearDown();
    }

    public function testGetInstanceReturnsSameInstance(): void
    {
        $a = ResourceTypeRegistry::getInstance();
        $b = ResourceTypeRegistry::getInstance();
        $this->assertSame($a, $b);
    }

    public function testResetCreatesNewInstance(): void
    {
        $a = ResourceTypeRegistry::getInstance();
        ResourceTypeRegistry::reset();
        $b = ResourceTypeRegistry::getInstance();
        $this->assertNotSame($a, $b);
    }

    public function testGetAllReturnsArray(): void
    {
        $registry = ResourceTypeRegistry::getInstance();
        $all = $registry->getAll();
        $this->assertIsArray($all);
        $this->assertNotEmpty($all);
    }

    public function testCoreTypesRegistered(): void
    {
        $registry = ResourceTypeRegistry::getInstance();

        $this->assertNotNull($registry->get('post'));
        $this->assertNotNull($registry->get('page'));
        $this->assertNotNull($registry->get('category'));
        $this->assertNotNull($registry->get('post_tag'));
    }

    public function testAdvancedTypesRegistered(): void
    {
        $registry = ResourceTypeRegistry::getInstance();

        $this->assertNotNull($registry->get('menu_item'));
        $this->assertNotNull($registry->get('comment'));
        $this->assertNotNull($registry->get('url_pattern'));
        $this->assertNotNull($registry->get('special_page'));
        $this->assertNotNull($registry->get('more_tag'));
    }

    public function testIsValidReturnsTrueForRegisteredType(): void
    {
        $registry = ResourceTypeRegistry::getInstance();
        $this->assertTrue($registry->isValid('post'));
        $this->assertTrue($registry->isValid('page'));
        $this->assertTrue($registry->isValid('comment'));
    }

    public function testIsValidReturnsFalseForUnregisteredType(): void
    {
        $registry = ResourceTypeRegistry::getInstance();
        $this->assertFalse($registry->isValid('nonexistent_type'));
        $this->assertFalse($registry->isValid(''));
    }

    public function testGetReturnNullForUnregisteredType(): void
    {
        $registry = ResourceTypeRegistry::getInstance();
        $this->assertNull($registry->get('nonexistent_type'));
    }

    public function testGetReturnsConfigWithRequiredKeys(): void
    {
        $registry = ResourceTypeRegistry::getInstance();
        $type = $registry->get('post');

        $this->assertArrayHasKey('key', $type);
        $this->assertArrayHasKey('label', $type);
        $this->assertArrayHasKey('group', $type);
        $this->assertArrayHasKey('icon', $type);
        $this->assertArrayHasKey('searchable', $type);
        $this->assertArrayHasKey('supports_bulk', $type);
        $this->assertArrayHasKey('provider', $type);

        $this->assertEquals('post', $type['key']);
        $this->assertEquals('content', $type['group']);
        $this->assertTrue($type['searchable']);
        $this->assertEquals(Constants::PROVIDER_WORDPRESS_CORE, $type['provider']);
    }

    public function testGetByGroupReturnsFilteredResults(): void
    {
        $registry = ResourceTypeRegistry::getInstance();

        $contentTypes = $registry->getByGroup('content');
        $this->assertNotEmpty($contentTypes);
        foreach ($contentTypes as $type) {
            $this->assertEquals('content', $type['group']);
        }

        $taxonomyTypes = $registry->getByGroup('taxonomy');
        $this->assertNotEmpty($taxonomyTypes);
        foreach ($taxonomyTypes as $type) {
            $this->assertEquals('taxonomy', $type['group']);
        }
    }

    public function testGetByGroupReturnsEmptyForUnknownGroup(): void
    {
        $registry = ResourceTypeRegistry::getInstance();
        $types = $registry->getByGroup('nonexistent');
        $this->assertEmpty($types);
    }

    public function testRegisterCustomType(): void
    {
        $registry = ResourceTypeRegistry::getInstance();

        // Force initialization first
        $registry->getAll();

        $registry->register('custom_test', [
            'label'     => 'Custom Test',
            'group'     => 'advanced',
            'icon'      => 'test-icon',
            'searchable' => true,
        ]);

        $this->assertTrue($registry->isValid('custom_test'));
        $type = $registry->get('custom_test');
        $this->assertEquals('Custom Test', $type['label']);
        $this->assertEquals('advanced', $type['group']);
        $this->assertTrue($type['searchable']);
        $this->assertEquals('custom_test', $type['key']);
    }

    public function testRegisterOverridesExistingType(): void
    {
        $registry = ResourceTypeRegistry::getInstance();

        // Force initialization
        $registry->getAll();

        $registry->register('post', [
            'label' => 'Blog Posts',
            'group' => 'content',
        ]);

        $type = $registry->get('post');
        $this->assertEquals('Blog Posts', $type['label']);
        $this->assertEquals('post', $type['key']); // key is always enforced
    }

    public function testRegisterEnforcesKeyField(): void
    {
        $registry = ResourceTypeRegistry::getInstance();
        $registry->getAll();

        $registry->register('my_type', [
            'key'   => 'wrong_key', // should be overridden
            'label' => 'My Type',
        ]);

        $type = $registry->get('my_type');
        $this->assertEquals('my_type', $type['key']);
    }

    public function testGetSearchableTypes(): void
    {
        $registry = ResourceTypeRegistry::getInstance();
        $searchable = $registry->getSearchableTypes();

        $this->assertNotEmpty($searchable);
        foreach ($searchable as $type) {
            $this->assertTrue($type['searchable']);
        }

        $this->assertArrayHasKey('post', $searchable);
        $this->assertArrayHasKey('page', $searchable);
    }

    public function testToSelectOptionsFormat(): void
    {
        $registry = ResourceTypeRegistry::getInstance();
        $options = $registry->toSelectOptions();

        $this->assertIsArray($options);
        $this->assertNotEmpty($options);

        $first = $options[0];
        $this->assertArrayHasKey('value', $first);
        $this->assertArrayHasKey('label', $first);
        $this->assertArrayHasKey('group', $first);
    }

    public function testGetGroupsReturnsUniqueGroups(): void
    {
        $registry = ResourceTypeRegistry::getInstance();
        $groups = $registry->getGroups();

        $this->assertContains('content', $groups);
        $this->assertContains('taxonomy', $groups);
        $this->assertContains('navigation', $groups);
        $this->assertContains('advanced', $groups);

        // Check uniqueness
        $this->assertEquals(array_unique($groups), $groups);
    }

    public function testGetGroupLabelsReturnsLabels(): void
    {
        $registry = ResourceTypeRegistry::getInstance();
        $labels = $registry->getGroupLabels();

        $this->assertArrayHasKey('content', $labels);
        $this->assertArrayHasKey('taxonomy', $labels);
        $this->assertArrayHasKey('navigation', $labels);
        $this->assertArrayHasKey('advanced', $labels);

        $this->assertIsString($labels['content']);
    }

    public function testDefaultProviderIsWordPressCore(): void
    {
        $registry = ResourceTypeRegistry::getInstance();
        $registry->getAll();

        $registry->register('test_no_provider', [
            'label' => 'Test',
        ]);

        $type = $registry->get('test_no_provider');
        $this->assertEquals(Constants::PROVIDER_WORDPRESS_CORE, $type['provider']);
    }

    public function testPostTypeIsContentGroup(): void
    {
        $registry = ResourceTypeRegistry::getInstance();

        $post = $registry->get('post');
        $page = $registry->get('page');

        $this->assertEquals('content', $post['group']);
        $this->assertEquals('content', $page['group']);
    }

    public function testTaxonomyTypesAreInTaxonomyGroup(): void
    {
        $registry = ResourceTypeRegistry::getInstance();

        $category = $registry->get('category');
        $tag = $registry->get('post_tag');

        $this->assertEquals('taxonomy', $category['group']);
        $this->assertEquals('taxonomy', $tag['group']);
    }

    public function testMenuItemIsInNavigationGroup(): void
    {
        $registry = ResourceTypeRegistry::getInstance();
        $menuItem = $registry->get('menu_item');

        $this->assertEquals('navigation', $menuItem['group']);
        $this->assertFalse($menuItem['searchable']);
    }

    public function testAdvancedGroupTypes(): void
    {
        $registry = ResourceTypeRegistry::getInstance();

        $comment = $registry->get('comment');
        $urlPattern = $registry->get('url_pattern');
        $specialPage = $registry->get('special_page');

        $this->assertEquals('advanced', $comment['group']);
        $this->assertEquals('advanced', $urlPattern['group']);
        $this->assertEquals('advanced', $specialPage['group']);
    }

    public function testGetProviderForResourceType(): void
    {
        $registry = ResourceTypeRegistry::getInstance();

        // Core post types should map to wordpress_core
        $post = $registry->get('post');
        $this->assertEquals(Constants::PROVIDER_WORDPRESS_CORE, $post['provider']);

        $page = $registry->get('page');
        $this->assertEquals(Constants::PROVIDER_WORDPRESS_CORE, $page['provider']);

        // Taxonomies should also be wordpress_core
        $category = $registry->get('category');
        $this->assertEquals(Constants::PROVIDER_WORDPRESS_CORE, $category['provider']);

        $tag = $registry->get('post_tag');
        $this->assertEquals(Constants::PROVIDER_WORDPRESS_CORE, $tag['provider']);

        // Advanced types
        $comment = $registry->get('comment');
        $this->assertEquals(Constants::PROVIDER_WORDPRESS_CORE, $comment['provider']);

        $specialPage = $registry->get('special_page');
        $this->assertEquals(Constants::PROVIDER_WORDPRESS_CORE, $specialPage['provider']);

        // Custom type without explicit provider should default to wordpress_core
        $registry->register('custom_provider_test', [
            'label' => 'Test',
        ]);
        $custom = $registry->get('custom_provider_test');
        $this->assertEquals(Constants::PROVIDER_WORDPRESS_CORE, $custom['provider']);

        // Custom type with explicit provider should keep it
        $registry->register('ld_test', [
            'label'    => 'LD Test',
            'provider' => Constants::PROVIDER_LEARNDASH,
        ]);
        $ld = $registry->get('ld_test');
        $this->assertEquals(Constants::PROVIDER_LEARNDASH, $ld['provider']);
    }
}

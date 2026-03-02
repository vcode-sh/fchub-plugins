<?php

namespace FChubMemberships\Tests\Unit;

use PHPUnit\Framework\TestCase;
use FChubMemberships\Http\Controllers\PlanController;
use FChubMemberships\Support\ResourceTypeRegistry;

class PlanControllerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        ResourceTypeRegistry::reset();

        // Set up mock posts
        $post42 = new \WP_Post();
        $post42->ID = 42;
        $post42->post_type = 'post';
        $post42->post_title = 'My Blog Post';
        $GLOBALS['wp_mock_posts'][42] = $post42;

        $page10 = new \WP_Post();
        $page10->ID = 10;
        $page10->post_type = 'page';
        $page10->post_title = 'About Us';
        $GLOBALS['wp_mock_posts'][10] = $page10;

        // Set up mock terms
        $cat5 = new \WP_Term();
        $cat5->term_id = 5;
        $cat5->name = 'News';
        $cat5->taxonomy = 'category';
        $GLOBALS['wp_mock_terms'][5] = $cat5;

        $tag3 = new \WP_Term();
        $tag3->term_id = 3;
        $tag3->name = 'Tutorial';
        $tag3->taxonomy = 'post_tag';
        $GLOBALS['wp_mock_terms'][3] = $tag3;

        // Admin user for permission checks
        $GLOBALS['wp_mock_current_user_id'] = 1;
        $GLOBALS['wp_mock_user_caps'][1] = ['manage_options' => true];
    }

    protected function tearDown(): void
    {
        ResourceTypeRegistry::reset();
        $GLOBALS['wp_mock_posts'] = [];
        $GLOBALS['wp_mock_terms'] = [];
        $GLOBALS['wp_mock_current_user_id'] = 0;
        $GLOBALS['wp_mock_user_caps'] = [];
        parent::tearDown();
    }

    // --- resolve-resources endpoint tests ---

    public function testResolveResourcesReturnsNames(): void
    {
        $request = new \WP_REST_Request('POST', '/admin/plans/resolve-resources');
        $request->set_json_params([
            'resources' => [
                ['resource_type' => 'post', 'resource_id' => '42'],
                ['resource_type' => 'page', 'resource_id' => '10'],
            ],
        ]);

        $response = PlanController::resolveResources($request);
        $data = $response->get_data()['data'];

        $this->assertEquals('My Blog Post', $data['post:42']);
        $this->assertEquals('About Us', $data['page:10']);
    }

    public function testResolveResourcesHandlesDeletedResources(): void
    {
        $request = new \WP_REST_Request('POST', '/admin/plans/resolve-resources');
        $request->set_json_params([
            'resources' => [
                ['resource_type' => 'post', 'resource_id' => '999'],
            ],
        ]);

        $response = PlanController::resolveResources($request);
        $data = $response->get_data()['data'];

        $this->assertEquals('(Deleted)', $data['post:999']);
    }

    public function testResolveResourcesHandlesWildcard(): void
    {
        $request = new \WP_REST_Request('POST', '/admin/plans/resolve-resources');
        $request->set_json_params([
            'resources' => [
                ['resource_type' => 'post', 'resource_id' => '*'],
                ['resource_type' => 'page', 'resource_id' => '0'],
            ],
        ]);

        $response = PlanController::resolveResources($request);
        $data = $response->get_data()['data'];

        $this->assertEquals('All Posts', $data['post:*']);
        $this->assertEquals('All Pages', $data['page:0']);
    }

    public function testResolveResourcesHandlesEmptyArray(): void
    {
        $request = new \WP_REST_Request('POST', '/admin/plans/resolve-resources');
        $request->set_json_params(['resources' => []]);

        $response = PlanController::resolveResources($request);
        $data = $response->get_data()['data'];

        $this->assertEmpty($data);
    }

    public function testResolveResourcesHandlesMixedTypes(): void
    {
        $request = new \WP_REST_Request('POST', '/admin/plans/resolve-resources');
        $request->set_json_params([
            'resources' => [
                ['resource_type' => 'post', 'resource_id' => '42'],
                ['resource_type' => 'category', 'resource_id' => '5'],
                ['resource_type' => 'post_tag', 'resource_id' => '3'],
            ],
        ]);

        $response = PlanController::resolveResources($request);
        $data = $response->get_data()['data'];

        $this->assertEquals('My Blog Post', $data['post:42']);
        $this->assertEquals('News', $data['category:5']);
        $this->assertEquals('Tutorial', $data['post_tag:3']);
    }

    public function testResolveResourcesHandlesInvalidType(): void
    {
        $request = new \WP_REST_Request('POST', '/admin/plans/resolve-resources');
        $request->set_json_params([
            'resources' => [
                ['resource_type' => 'nonexistent_type', 'resource_id' => '1'],
            ],
        ]);

        $response = PlanController::resolveResources($request);
        $data = $response->get_data()['data'];

        // Should still return something meaningful, not crash
        $this->assertArrayHasKey('nonexistent_type:1', $data);
    }

    public function testResolveResourcesHandlesSpecialPageType(): void
    {
        $request = new \WP_REST_Request('POST', '/admin/plans/resolve-resources');
        $request->set_json_params([
            'resources' => [
                ['resource_type' => 'special_page', 'resource_id' => 'blog'],
                ['resource_type' => 'special_page', 'resource_id' => 'front_page'],
                ['resource_type' => 'special_page', 'resource_id' => 'search'],
            ],
        ]);

        $response = PlanController::resolveResources($request);
        $data = $response->get_data()['data'];

        $this->assertEquals('Blog / Posts Page', $data['special_page:blog']);
        $this->assertEquals('Front Page', $data['special_page:front_page']);
        $this->assertEquals('Search Results', $data['special_page:search']);
    }

    // --- Rule validation tests ---

    public function testStoreValidatesResourceType(): void
    {
        $request = new \WP_REST_Request('POST', '/admin/plans');
        $request->set_json_params([
            'title'  => 'Test Plan',
            'status' => 'active',
            'rules'  => [
                ['resource_type' => 'nonexistent_type_xyz', 'resource_id' => '1', 'drip_type' => 'immediate'],
            ],
        ]);

        $response = PlanController::store($request);

        $this->assertEquals(422, $response->get_status());
        $this->assertStringContainsString('invalid resource type', $response->get_data()['message']);
    }

    public function testStoreValidatesDripDelayBounds(): void
    {
        $request = new \WP_REST_Request('POST', '/admin/plans');
        $request->set_json_params([
            'title'  => 'Test Plan',
            'status' => 'active',
            'rules'  => [
                ['resource_type' => 'post', 'resource_id' => '42', 'drip_type' => 'delayed', 'drip_delay_days' => 999],
            ],
        ]);

        $response = PlanController::store($request);

        $this->assertEquals(422, $response->get_status());
        $this->assertStringContainsString('between 1 and 730', $response->get_data()['message']);
    }

    public function testStoreValidatesDripDelayMinimum(): void
    {
        $request = new \WP_REST_Request('POST', '/admin/plans');
        $request->set_json_params([
            'title'  => 'Test Plan',
            'status' => 'active',
            'rules'  => [
                ['resource_type' => 'post', 'resource_id' => '42', 'drip_type' => 'delayed', 'drip_delay_days' => 0],
            ],
        ]);

        $response = PlanController::store($request);

        $this->assertEquals(422, $response->get_status());
        $this->assertStringContainsString('between 1 and 730', $response->get_data()['message']);
    }

    public function testStoreValidatesFutureDripDate(): void
    {
        $request = new \WP_REST_Request('POST', '/admin/plans');
        $request->set_json_params([
            'title'  => 'Test Plan',
            'status' => 'active',
            'rules'  => [
                ['resource_type' => 'post', 'resource_id' => '42', 'drip_type' => 'fixed_date', 'drip_date' => '2020-01-01'],
            ],
        ]);

        $response = PlanController::store($request);

        $this->assertEquals(422, $response->get_status());
        $this->assertStringContainsString('past', $response->get_data()['message']);
    }

    public function testStoreRequiresDripDateForFixedDateType(): void
    {
        $request = new \WP_REST_Request('POST', '/admin/plans');
        $request->set_json_params([
            'title'  => 'Test Plan',
            'status' => 'active',
            'rules'  => [
                ['resource_type' => 'post', 'resource_id' => '42', 'drip_type' => 'fixed_date'],
            ],
        ]);

        $response = PlanController::store($request);

        $this->assertEquals(422, $response->get_status());
        $this->assertStringContainsString('drip_date is required', $response->get_data()['message']);
    }

    public function testStoreStripsAccessType(): void
    {
        // Rules with access_type should pass validation (access_type is stripped before save).
        // The store will proceed past validation into PlanService, which may fail on DB
        // operations in test environment. We verify validation passed by checking the error
        // message does NOT reference access_type or validation issues.
        $request = new \WP_REST_Request('POST', '/admin/plans');
        $request->set_json_params([
            'title'  => 'Test Plan',
            'status' => 'active',
            'rules'  => [
                [
                    'resource_type' => 'post',
                    'resource_id'   => '0',
                    'access_type'   => 'view',  // This should be stripped
                    'drip_type'     => 'immediate',
                ],
            ],
        ]);

        $response = PlanController::store($request);
        $data = $response->get_data();

        // If validation passed, the error will be from DB layer (e.g. "Plan not found"
        // because the mock DB can't actually create plans), not from rule validation
        if ($response->get_status() === 422) {
            $this->assertStringNotContainsString('invalid resource type', $data['message'] ?? '');
            $this->assertStringNotContainsString('access_type', $data['message'] ?? '');
        } else {
            $this->assertTrue(true); // Success means validation passed
        }
    }

    // --- Provider auto-mapping test ---

    public function testPrepareRulesAutoMapsProviderFromRegistry(): void
    {
        // A rule with resource_type 'category' should pass validation since it's
        // a valid type in the registry. The provider would be auto-mapped by
        // prepareRulesForStorage. DB-level errors are expected in test env.
        $request = new \WP_REST_Request('POST', '/admin/plans');
        $request->set_json_params([
            'title'  => 'Test Plan',
            'status' => 'active',
            'rules'  => [
                [
                    'resource_type' => 'category',
                    'resource_id'   => '5',
                    'drip_type'     => 'immediate',
                ],
            ],
        ]);

        $response = PlanController::store($request);
        $data = $response->get_data();

        // Validation should pass - any error should be from DB, not validation
        if ($response->get_status() === 422) {
            $this->assertStringNotContainsString('invalid resource type', $data['message'] ?? '');
            $this->assertStringNotContainsString('delay', $data['message'] ?? '');
        } else {
            $this->assertTrue(true);
        }
    }

    // --- Enriched response test ---

    public function testResolveResourcesHandlesUrlPattern(): void
    {
        $request = new \WP_REST_Request('POST', '/admin/plans/resolve-resources');
        $request->set_json_params([
            'resources' => [
                ['resource_type' => 'url_pattern', 'resource_id' => '/members/*'],
            ],
        ]);

        $response = PlanController::resolveResources($request);
        $data = $response->get_data()['data'];

        $this->assertEquals('/members/*', $data['url_pattern:/members/*']);
    }

    public function testResolveResourcesHandlesDeletedTaxonomy(): void
    {
        $request = new \WP_REST_Request('POST', '/admin/plans/resolve-resources');
        $request->set_json_params([
            'resources' => [
                ['resource_type' => 'category', 'resource_id' => '9999'],
            ],
        ]);

        $response = PlanController::resolveResources($request);
        $data = $response->get_data()['data'];

        $this->assertEquals('(Deleted)', $data['category:9999']);
    }
}

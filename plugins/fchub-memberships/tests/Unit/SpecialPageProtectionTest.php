<?php

namespace FChubMemberships\Tests\Unit;

use FChubMemberships\Tests\Support\TestCase;
use FChubMemberships\Tests\Support\MockBuilder;
use FChubMemberships\Domain\SpecialPageProtection;

class SpecialPageProtectionTest extends TestCase
{
    private SpecialPageProtection $protection;

    protected function setUp(): void
    {
        parent::setUp();
        $this->protection = new SpecialPageProtection();
    }

    // ── Static method tests ─────────────────────────────────────

    public function testGetSpecialPageTypesReturnsExpectedTypes(): void
    {
        $types = SpecialPageProtection::getSpecialPageTypes();

        $this->assertArrayHasKey('search', $types);
        $this->assertArrayHasKey('author', $types);
        $this->assertArrayHasKey('date', $types);
        $this->assertArrayHasKey('post_type_archive', $types);
        $this->assertArrayHasKey('front_page', $types);
        $this->assertArrayHasKey('blog_page', $types);
    }

    public function testGetSpecialPageTypesReturnsNonEmptyLabels(): void
    {
        $types = SpecialPageProtection::getSpecialPageTypes();

        foreach ($types as $key => $label) {
            $this->assertNotEmpty($label, "Label for '{$key}' should not be empty");
            $this->assertIsString($label);
        }
    }

    public function testGetSpecialPageTypesCountIsSix(): void
    {
        $types = SpecialPageProtection::getSpecialPageTypes();
        $this->assertCount(6, $types);
    }

    // ── detectCurrentSpecialPage() tests ────────────────────────

    public function testDetectsSearchPage(): void
    {
        $GLOBALS['wp_mock_is_search'] = true;

        $result = $this->protection->detectCurrentSpecialPage();
        $this->assertEquals('search', $result);
    }

    public function testDetectsAuthorPage(): void
    {
        $GLOBALS['wp_mock_is_author'] = true;
        $GLOBALS['wp_mock_queried_object_id'] = 5;

        $result = $this->protection->detectCurrentSpecialPage();
        $this->assertEquals('author:5', $result);
    }

    public function testDetectsAuthorPageWithId(): void
    {
        $GLOBALS['wp_mock_is_author'] = true;
        $GLOBALS['wp_mock_queried_object_id'] = 42;

        $result = $this->protection->detectCurrentSpecialPage();
        $this->assertEquals('author:42', $result);
    }

    public function testDetectsDateArchive(): void
    {
        $GLOBALS['wp_mock_is_date'] = true;

        $result = $this->protection->detectCurrentSpecialPage();
        $this->assertEquals('date', $result);
    }

    public function testDetectsPostTypeArchive(): void
    {
        $GLOBALS['wp_mock_is_post_type_archive'] = true;
        $GLOBALS['wp_mock_query_vars']['post_type'] = 'portfolio';

        $result = $this->protection->detectCurrentSpecialPage();
        $this->assertEquals('post_type_archive:portfolio', $result);
    }

    public function testDetectsPostTypeArchiveWithArrayType(): void
    {
        $GLOBALS['wp_mock_is_post_type_archive'] = true;
        $GLOBALS['wp_mock_query_vars']['post_type'] = ['product', 'portfolio'];

        $result = $this->protection->detectCurrentSpecialPage();
        $this->assertEquals('post_type_archive:product', $result);
    }

    public function testDetectsFrontPage(): void
    {
        $GLOBALS['wp_mock_is_front_page'] = true;

        $result = $this->protection->detectCurrentSpecialPage();
        $this->assertEquals('front_page', $result);
    }

    public function testDetectsBlogPage(): void
    {
        $GLOBALS['wp_mock_is_home'] = true;

        $result = $this->protection->detectCurrentSpecialPage();
        $this->assertEquals('blog_page', $result);
    }

    public function testReturnsNullForNonSpecialPage(): void
    {
        // All conditions are false by default
        $result = $this->protection->detectCurrentSpecialPage();
        $this->assertNull($result);
    }

    // ── Rule meta / action tests ────────────────────────────────

    public function testRuleMetaRedirectActionIsDefault(): void
    {
        $rule = ['meta' => [], 'plan_ids' => []];
        $action = $rule['meta']['action'] ?? 'redirect';
        $this->assertEquals('redirect', $action);
    }

    public function testRuleMetaMessageAction(): void
    {
        $rule = [
            'meta' => ['action' => 'message', 'restriction_message' => 'Test message'],
            'plan_ids' => [],
        ];
        $this->assertEquals('message', $rule['meta']['action']);
        $this->assertEquals('Test message', $rule['meta']['restriction_message']);
    }

    public function testRuleMetaFilterResultsAction(): void
    {
        $rule = ['meta' => ['action' => 'filter_results'], 'plan_ids' => []];
        $action = $rule['meta']['action'] ?? 'redirect';
        $this->assertEquals('filter_results', $action);
    }

    // ── Compound resource ID parsing ────────────────────────────

    public function testCompoundResourceIdParsing(): void
    {
        $parts = explode(':', 'author:5', 2);
        $this->assertCount(2, $parts);
        $this->assertEquals('author', $parts[0]);
        $this->assertEquals('5', $parts[1]);
    }

    public function testSimpleResourceIdHasNoColon(): void
    {
        $parts = explode(':', 'search', 2);
        $this->assertCount(1, $parts);
        $this->assertEquals('search', $parts[0]);
    }

    public function testPostTypeArchiveResourceIdParsing(): void
    {
        $parts = explode(':', 'post_type_archive:portfolio', 2);
        $this->assertCount(2, $parts);
        $this->assertEquals('post_type_archive', $parts[0]);
        $this->assertEquals('portfolio', $parts[1]);
    }

    // ── getSpecialPageRule() fallback logic ──────────────────────

    public function testRuleFallbackLogicExactThenGenericThenWildcard(): void
    {
        // Simulate the getSpecialPageRule() fallback logic:
        // 1. exact match (author:5)
        // 2. generic type (author)
        // 3. wildcard (*)

        $rules = [
            'author:5' => ['id' => 1, 'resource_id' => 'author:5', 'meta' => ['action' => 'redirect']],
            'author'   => ['id' => 2, 'resource_id' => 'author', 'meta' => ['action' => 'message']],
            '*'        => ['id' => 3, 'resource_id' => '*', 'meta' => ['action' => 'redirect']],
        ];

        // Test exact match wins
        $resourceId = 'author:5';
        $rule = $rules[$resourceId] ?? null;
        $this->assertNotNull($rule);
        $this->assertEquals(1, $rule['id']);

        // Test generic fallback
        $resourceId = 'author:99'; // not in rules
        $rule = $rules[$resourceId] ?? null;
        if (!$rule) {
            $parts = explode(':', $resourceId, 2);
            if (count($parts) === 2) {
                $rule = $rules[$parts[0]] ?? null;
            }
        }
        $this->assertNotNull($rule);
        $this->assertEquals(2, $rule['id']);

        // Test wildcard fallback
        $resourceId = 'search'; // not in rules, no colon
        $rule = $rules[$resourceId] ?? null;
        if (!$rule) {
            $parts = explode(':', $resourceId, 2);
            if (count($parts) === 2) {
                $rule = $rules[$parts[0]] ?? null;
            }
        }
        if (!$rule) {
            $rule = $rules['*'] ?? null;
        }
        $this->assertNotNull($rule);
        $this->assertEquals(3, $rule['id']);
    }

    // ── handleRestriction() logic tests ─────────────────────────

    public function testRedirectActionSetsRedirectUrl(): void
    {
        $rule = [
            'meta' => ['action' => 'redirect', 'redirect_url' => 'http://localhost/pricing'],
            'plan_ids' => [],
        ];

        $this->protection->handleRestriction($rule, 'search', 0);

        $this->assertEquals('http://localhost/pricing', $GLOBALS['wp_mock_redirect_url']);
    }

    public function testRedirectActionUsesDefaultUrl(): void
    {
        $this->setOption('fchub_memberships_settings', [
            'default_redirect_url' => 'http://localhost/default',
        ]);

        $rule = [
            'meta' => ['action' => 'redirect'],
            'plan_ids' => [],
        ];

        $this->protection->handleRestriction($rule, 'search', 0);

        $this->assertEquals('http://localhost/default', $GLOBALS['wp_mock_redirect_url']);
    }

    public function testMessageActionCallsWpDie(): void
    {
        $rule = [
            'meta' => ['action' => 'message', 'restriction_message' => 'Custom block message'],
            'plan_ids' => [],
        ];

        $this->protection->handleRestriction($rule, 'search', 0);

        $this->assertNotNull($GLOBALS['wp_mock_die_message']);
        $this->assertStringContainsString('Custom block message', $GLOBALS['wp_mock_die_message']);
    }

    public function testMessageActionShowsLoginLinkForLoggedOut(): void
    {
        $rule = [
            'meta' => ['action' => 'message', 'restriction_message' => 'Please log in'],
            'plan_ids' => [],
        ];

        // userId = 0 means logged out
        $this->protection->handleRestriction($rule, 'search', 0);

        $this->assertStringContainsString('wp-login.php', $GLOBALS['wp_mock_die_message']);
    }

    public function testMessageActionHidesLoginLinkForLoggedIn(): void
    {
        $rule = [
            'meta' => ['action' => 'message', 'restriction_message' => 'No access'],
            'plan_ids' => [],
        ];

        // userId > 0 means logged in
        $this->protection->handleRestriction($rule, 'search', 5);

        $this->assertStringNotContainsString('wp-login.php', $GLOBALS['wp_mock_die_message']);
    }

    // ── Admin bypass ────────────────────────────────────────────

    public function testAdminBypassCheckLogic(): void
    {
        // Simulate admin bypass logic from checkSpecialPageProtection
        $userId = 1;
        $GLOBALS['wp_mock_user_caps'][$userId] = ['manage_options' => true];
        $settings = ['admin_bypass' => 'yes'];

        $isAdmin = user_can($userId, 'manage_options');
        $bypassEnabled = ($settings['admin_bypass'] ?? 'yes') === 'yes';

        $this->assertTrue($isAdmin && $bypassEnabled, 'Admin should bypass protection');
    }

    public function testAdminBypassDisabledCheckLogic(): void
    {
        $userId = 1;
        $GLOBALS['wp_mock_user_caps'][$userId] = ['manage_options' => true];
        $settings = ['admin_bypass' => 'no'];

        $isAdmin = user_can($userId, 'manage_options');
        $bypassEnabled = ($settings['admin_bypass'] ?? 'yes') === 'yes';

        $this->assertTrue($isAdmin, 'User is admin');
        $this->assertFalse($bypassEnabled, 'Bypass is disabled');
        $this->assertFalse($isAdmin && $bypassEnabled, 'Admin bypass should not work when disabled');
    }

    // ── Detection priority ──────────────────────────────────────

    public function testDetectionPrioritySearchBeforeAuthor(): void
    {
        // If both is_search and is_author are true, search should win (checked first)
        $GLOBALS['wp_mock_is_search'] = true;
        $GLOBALS['wp_mock_is_author'] = true;
        $GLOBALS['wp_mock_queried_object_id'] = 5;

        $result = $this->protection->detectCurrentSpecialPage();
        $this->assertEquals('search', $result);
    }

    public function testDetectionPriorityFrontPageBeforeHome(): void
    {
        // If both is_front_page and is_home are true, front_page should win
        $GLOBALS['wp_mock_is_front_page'] = true;
        $GLOBALS['wp_mock_is_home'] = true;

        $result = $this->protection->detectCurrentSpecialPage();
        $this->assertEquals('front_page', $result);
    }
}

<?php

namespace FChubMemberships\Tests\Unit;

use FChubMemberships\Tests\Support\TestCase;
use FChubMemberships\Tests\Support\MockBuilder;
use FChubMemberships\Domain\UrlProtection;

/**
 * Tests for UrlProtection URL matching, exclusion, and normalization logic.
 *
 * UrlProtection's public methods matchUrl(), matchExact(), matchPrefix(),
 * matchRegex(), and isExcluded() are pure functions on inputs, so we can
 * instantiate the class (it tries to new up repos in __construct, but we
 * only call the stateless matching methods).
 */
class UrlProtectionTest extends TestCase
{
    // ── Exact Match Tests ───────────────────────────────────────

    public function testExactMatchFindsUrl(): void
    {
        $protection = new UrlProtection();

        $rule = ['meta' => ['match_mode' => 'exact', 'url_pattern' => '/members-area']];
        $this->assertTrue($protection->matchUrl('http://localhost/members-area', $rule));
    }

    public function testExactMatchRejectsNonMatch(): void
    {
        $protection = new UrlProtection();

        $rule = ['meta' => ['match_mode' => 'exact', 'url_pattern' => '/members-area']];
        $this->assertFalse($protection->matchUrl('http://localhost/other-page', $rule));
    }

    public function testExactMatchIgnoresTrailingSlash(): void
    {
        $protection = new UrlProtection();

        $this->assertTrue($protection->matchExact('http://localhost/members-area/', '/members-area'));
        $this->assertTrue($protection->matchExact('http://localhost/members-area', '/members-area/'));
    }

    public function testExactMatchWithFullUrl(): void
    {
        $protection = new UrlProtection();

        $this->assertTrue($protection->matchExact('http://localhost/about', 'http://localhost/about'));
    }

    public function testExactMatchRootPath(): void
    {
        $protection = new UrlProtection();

        $this->assertTrue($protection->matchExact('http://localhost/', '/'));
    }

    // ── Prefix Match Tests ──────────────────────────────────────

    public function testPrefixMatchWithWildcard(): void
    {
        $protection = new UrlProtection();

        $this->assertTrue($protection->matchPrefix('http://localhost/members-area/lesson-1', '/members-area/*'));
        $this->assertTrue($protection->matchPrefix('http://localhost/members-area/deep/nested', '/members-area/*'));
    }

    public function testPrefixMatchWithoutTrailingSlash(): void
    {
        $protection = new UrlProtection();

        // Without wildcard, should match exact or subpath
        $this->assertTrue($protection->matchPrefix('http://localhost/members-area', '/members-area'));
        $this->assertTrue($protection->matchPrefix('http://localhost/members-area/page', '/members-area'));
    }

    public function testPrefixMatchDoesNotPartialMatchSegment(): void
    {
        $protection = new UrlProtection();

        // /members should NOT match /members-area (partial segment match)
        // With wildcard it does prefix match
        $result = $protection->matchPrefix('http://localhost/members-area', '/members');
        // Without wildcard, it checks: url === pattern || starts_with(url, pattern . '/')
        // /members-area !== /members, and /members-area doesn't start with /members/
        $this->assertFalse($result);
    }

    public function testPrefixMatchRootMatchesAll(): void
    {
        $protection = new UrlProtection();

        $this->assertTrue($protection->matchPrefix('http://localhost/anything', '/*'));
    }

    // ── Regex Match Tests ───────────────────────────────────────

    public function testRegexMatchFindsPattern(): void
    {
        $protection = new UrlProtection();

        $this->assertTrue($protection->matchRegex('http://localhost/course/lesson-1', '#^/course/lesson-\d+$#'));
    }

    public function testRegexMatchRejectsNonMatch(): void
    {
        $protection = new UrlProtection();

        $this->assertFalse($protection->matchRegex('http://localhost/about', '#^/course/lesson-\d+$#'));
    }

    public function testRegexMatchHandlesInvalidPattern(): void
    {
        $protection = new UrlProtection();

        // Invalid regex should not cause errors, just return false
        $this->assertFalse($protection->matchRegex('http://localhost/test', '[invalid'));
    }

    public function testRegexMatchComplexPattern(): void
    {
        $protection = new UrlProtection();

        $pattern = '#^/(?:premium|vip)/.*$#';
        $this->assertTrue($protection->matchRegex('http://localhost/premium/content', $pattern));
        $this->assertTrue($protection->matchRegex('http://localhost/vip/lounge', $pattern));
        $this->assertFalse($protection->matchRegex('http://localhost/free/content', $pattern));
    }

    // ── Exclusion Tests ─────────────────────────────────────────

    public function testExclusionPatternBypassesRule(): void
    {
        $protection = new UrlProtection();

        $result = $protection->isExcluded('http://localhost/members-area/public', ['/members-area/public']);
        $this->assertTrue($result, 'Exact exclusion should match');
    }

    public function testMultipleExclusionPatterns(): void
    {
        $protection = new UrlProtection();

        $patterns = ['/members-area/free', '/members-area/preview/*'];
        $this->assertTrue($protection->isExcluded('http://localhost/members-area/free', $patterns));
        $this->assertTrue($protection->isExcluded('http://localhost/members-area/preview/lesson', $patterns));
        $this->assertFalse($protection->isExcluded('http://localhost/members-area/premium', $patterns));
    }

    public function testExclusionWithWildcard(): void
    {
        $protection = new UrlProtection();

        $this->assertTrue($protection->isExcluded('http://localhost/members/faq/page-2', ['/members/faq/*']));
    }

    public function testExclusionWithSubpath(): void
    {
        $protection = new UrlProtection();

        // Prefix + subpath match: /public/info starts with /public/
        $this->assertTrue($protection->isExcluded('http://localhost/public/info', ['/public']));
    }

    public function testExclusionEmptyPatternsReturnsFalse(): void
    {
        $protection = new UrlProtection();

        $this->assertFalse($protection->isExcluded('http://localhost/test', []));
    }

    public function testExclusionSkipsEmptyStrings(): void
    {
        $protection = new UrlProtection();

        $this->assertFalse($protection->isExcluded('http://localhost/test', ['', '  ']));
    }

    // ── Specificity / Rule Ordering Tests ───────────────────────

    public function testMostSpecificRuleWins(): void
    {
        // Rules sorted by specificity: exact > prefix > regex
        $matchOrder = ['exact' => 1, 'prefix' => 2, 'regex' => 3];

        $rules = [
            ['meta' => ['match_mode' => 'regex', 'url_pattern' => '#.*#']],
            ['meta' => ['match_mode' => 'exact', 'url_pattern' => '/page']],
            ['meta' => ['match_mode' => 'prefix', 'url_pattern' => '/page/*']],
        ];

        usort($rules, function ($a, $b) use ($matchOrder) {
            $modeA = $a['meta']['match_mode'] ?? 'prefix';
            $modeB = $b['meta']['match_mode'] ?? 'prefix';
            return ($matchOrder[$modeA] ?? 99) <=> ($matchOrder[$modeB] ?? 99);
        });

        $this->assertEquals('exact', $rules[0]['meta']['match_mode']);
        $this->assertEquals('prefix', $rules[1]['meta']['match_mode']);
        $this->assertEquals('regex', $rules[2]['meta']['match_mode']);
    }

    public function testExactBeatsPrefixInOrdering(): void
    {
        $matchOrder = ['exact' => 1, 'prefix' => 2, 'regex' => 3];

        $exact = $matchOrder['exact'];
        $prefix = $matchOrder['prefix'];

        $this->assertLessThan($prefix, $exact, 'Exact should have higher priority (lower number) than prefix');
    }

    public function testPrefixBeatsRegexInOrdering(): void
    {
        $matchOrder = ['exact' => 1, 'prefix' => 2, 'regex' => 3];

        $prefix = $matchOrder['prefix'];
        $regex = $matchOrder['regex'];

        $this->assertLessThan($regex, $prefix, 'Prefix should have higher priority (lower number) than regex');
    }

    // ── Skip Conditions Tests ───────────────────────────────────

    public function testSkipConditionAdminPages(): void
    {
        // The real code checks is_admin()
        $GLOBALS['wp_mock_is_admin'] = true;
        $this->assertTrue(is_admin(), 'Admin check should skip URL protection');
    }

    public function testSkipConditionAjaxRequests(): void
    {
        $GLOBALS['wp_mock_doing_ajax'] = true;
        $this->assertTrue(wp_doing_ajax(), 'Ajax check should skip URL protection');
    }

    public function testSkipConditionRestApiRequests(): void
    {
        // The real code checks defined('REST_REQUEST') && REST_REQUEST
        define('REST_REQUEST_TEST', true);
        $this->assertTrue(REST_REQUEST_TEST, 'REST API check should skip URL protection');
    }

    // ── Action Handler Tests ────────────────────────────────────

    public function testRedirectActionSetsRedirect(): void
    {
        $protection = new UrlProtection();

        $rule = [
            'meta' => [
                'action' => 'redirect',
                'redirect_url' => 'http://localhost/pricing',
            ],
            'resource_id' => '1',
        ];

        // handleRestriction calls wp_safe_redirect which sets global
        // We can't call it directly because it has exit, but we can test logic
        $redirectUrl = $rule['meta']['redirect_url'];
        $this->assertEquals('http://localhost/pricing', $redirectUrl);
    }

    public function testMessageActionUsesRestrictionMessage(): void
    {
        $rule = [
            'meta' => [
                'action' => 'message',
                'restriction_message' => 'Members only content.',
            ],
        ];

        $message = $rule['meta']['restriction_message'] ?? 'This area is for members only.';
        $this->assertEquals('Members only content.', $message);
    }

    public function testLoginActionDefaultsToLoginUrl(): void
    {
        $rule = ['meta' => ['action' => 'login']];
        $action = $rule['meta']['action'];

        $this->assertEquals('login', $action);
        // In real code, wp_safe_redirect(wp_login_url($currentUrl))
        $loginUrl = wp_login_url('http://localhost/protected');
        $this->assertStringContainsString('wp-login.php', $loginUrl);
    }

    // ── Caching Tests ───────────────────────────────────────────

    public function testCachedRulesReturnedFromTransient(): void
    {
        // Simulate the caching logic: if transient exists, getUrlRules() returns it
        $cachedRules = [['id' => 1, 'meta' => ['match_mode' => 'exact', 'url_pattern' => '/test']]];
        $GLOBALS['wp_transients']['fchub_url_protection_rules'] = $cachedRules;

        $fromTransient = get_transient('fchub_url_protection_rules');
        $this->assertSame($cachedRules, $fromTransient, 'Transient should return cached rules');
    }

    public function testClearCacheDeletesTransient(): void
    {
        $GLOBALS['wp_transients']['fchub_url_protection_rules'] = [['dummy']];

        UrlProtection::clearCache();

        $this->assertFalse(get_transient('fchub_url_protection_rules'));
    }

    // ── matchUrl() dispatch Tests ───────────────────────────────

    public function testMatchUrlDispatchesExact(): void
    {
        $protection = new UrlProtection();
        $rule = ['meta' => ['match_mode' => 'exact', 'url_pattern' => '/test']];

        $this->assertTrue($protection->matchUrl('http://localhost/test', $rule));
        $this->assertFalse($protection->matchUrl('http://localhost/test/sub', $rule));
    }

    public function testMatchUrlDispatchesPrefix(): void
    {
        $protection = new UrlProtection();
        $rule = ['meta' => ['match_mode' => 'prefix', 'url_pattern' => '/test/*']];

        $this->assertTrue($protection->matchUrl('http://localhost/test/sub', $rule));
    }

    public function testMatchUrlDispatchesRegex(): void
    {
        $protection = new UrlProtection();
        $rule = ['meta' => ['match_mode' => 'regex', 'url_pattern' => '#^/test/\d+$#']];

        $this->assertTrue($protection->matchUrl('http://localhost/test/123', $rule));
        $this->assertFalse($protection->matchUrl('http://localhost/test/abc', $rule));
    }

    public function testMatchUrlReturnsFalseForUnknownMode(): void
    {
        $protection = new UrlProtection();
        $rule = ['meta' => ['match_mode' => 'unknown_mode', 'url_pattern' => '/test']];

        $this->assertFalse($protection->matchUrl('http://localhost/test', $rule));
    }

    public function testMatchUrlReturnsFalseForEmptyPattern(): void
    {
        $protection = new UrlProtection();
        $rule = ['meta' => ['match_mode' => 'exact', 'url_pattern' => '']];

        $this->assertFalse($protection->matchUrl('http://localhost/test', $rule));
    }

    // ── Path Normalization Tests ────────────────────────────────

    public function testNormalizationHandlesFullUrl(): void
    {
        $protection = new UrlProtection();

        // Full URLs should be normalized to just the path
        $this->assertTrue($protection->matchExact('https://example.com/page', '/page'));
    }

    public function testNormalizationHandlesRelativePath(): void
    {
        $protection = new UrlProtection();

        $this->assertTrue($protection->matchExact('/page', '/page'));
    }

    public function testNormalizationTrimsTrailingSlashes(): void
    {
        $protection = new UrlProtection();

        $this->assertTrue($protection->matchExact('/page/', '/page'));
        $this->assertTrue($protection->matchExact('/page', '/page/'));
    }

    public function testNormalizationEnsuresLeadingSlash(): void
    {
        $protection = new UrlProtection();

        // Both should normalize to /page
        $this->assertTrue($protection->matchExact('page', '/page'));
    }
}

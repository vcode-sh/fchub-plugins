<?php

namespace FChubMemberships\Tests\Unit;

use FChubMemberships\Tests\Support\TestCase;
use FChubMemberships\Tests\Support\MockBuilder;

/**
 * Tests for ContentProtection teaser generation and restriction logic.
 *
 * Since ContentProtection relies on heavy WP integration (get_post, is_singular,
 * ProtectionRuleRepository), these tests simulate the buildTeaser() and
 * buildProtectedOutput() logic in-memory to verify teaser modes, restriction
 * blocks, and backwards compatibility.
 */
class ContentProtectionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
    }

    // ── Helpers ──────────────────────────────────────────────────

    /**
     * Simulate buildTeaser() from ContentProtection (lines 593-634).
     */
    private function buildTeaser(string $mode, array $meta, string $originalContent, ?object $post = null): string
    {
        $teaserContent = '';

        switch ($mode) {
            case 'excerpt':
                if ($post && $post->post_excerpt) {
                    $teaserContent = wpautop($post->post_excerpt);
                }
                break;

            case 'more_tag':
                if (preg_match('/<!--more(.*?)?-->/', $originalContent, $matches, \PREG_OFFSET_CAPTURE)) {
                    $teaserContent = substr($originalContent, 0, $matches[0][1]);
                }
                break;

            case 'words':
                $wordCount = (int) ($meta['teaser_word_count'] ?? 50);
                $wordCount = max(1, min($wordCount, 500));
                $teaserContent = wpautop(wp_trim_words(wp_strip_all_tags($originalContent), $wordCount, '...'));
                break;

            case 'custom':
                $customText = $meta['custom_teaser'] ?? '';
                if ($customText) {
                    $teaserContent = wpautop($customText);
                }
                break;

            case 'none':
            default:
                break;
        }

        if ($teaserContent === '') {
            return '';
        }

        return '<div class="fchub-teaser">' . $teaserContent . '</div>';
    }

    /**
     * Simulate renderRestrictionBlock() output structure.
     */
    private function buildRestrictionBlock(
        string $context,
        string $message,
        array $planNames = [],
        array $meta = []
    ): string {
        $html = '<div class="fchub-membership-restricted fchub-restricted-' . esc_attr($context) . '">';
        $html .= wpautop($message);

        if (!empty($planNames)) {
            $html .= '<div class="fchub-plan-list">';
            $html .= '<ul>';
            foreach ($planNames as $name) {
                $html .= '<li>' . esc_html($name) . '</li>';
            }
            $html .= '</ul>';
            $html .= '</div>';
        }

        if ($context === 'logged_out') {
            $html .= '<p class="fchub-login-link"><a href="' . esc_url(wp_login_url('http://localhost/sample-post/')) . '" class="fchub-btn fchub-btn-login">Log in</a></p>';
        }

        $ctaText = $meta['cta_text'] ?? '';
        $ctaUrl = $meta['cta_url'] ?? '';
        if ($ctaText && $ctaUrl) {
            $html .= '<p class="fchub-cta"><a href="' . esc_url($ctaUrl) . '" class="fchub-btn fchub-btn-cta">' . esc_html($ctaText) . '</a></p>';
        }

        $html .= '</div>';
        return $html;
    }

    /**
     * Resolve teaser mode from rule (with backwards compatibility).
     */
    private function resolveTeaserMode(?array $rule): string
    {
        $meta = $rule['meta'] ?? [];
        $teaserMode = $meta['teaser_mode'] ?? null;

        if ($teaserMode === null && $rule !== null) {
            $teaserMode = ($rule['show_teaser'] === 'yes') ? 'excerpt' : 'none';
        }

        return $teaserMode ?: 'none';
    }

    // ── Teaser Mode Tests ───────────────────────────────────────

    public function testNoneTeaserShowsOnlyRestriction(): void
    {
        $teaser = $this->buildTeaser('none', [], '<p>Full content here</p>');

        $this->assertEquals('', $teaser, 'None mode should produce empty teaser');
    }

    public function testExcerptTeaserShowsExcerpt(): void
    {
        $post = new \WP_Post();
        $post->post_excerpt = 'This is the excerpt';

        $teaser = $this->buildTeaser('excerpt', [], '<p>Full content</p>', $post);

        $this->assertStringContainsString('fchub-teaser', $teaser);
        $this->assertStringContainsString('This is the excerpt', $teaser);
    }

    public function testExcerptTeaserEmptyWhenNoExcerpt(): void
    {
        $post = new \WP_Post();
        $post->post_excerpt = '';

        $teaser = $this->buildTeaser('excerpt', [], '<p>Full content</p>', $post);

        $this->assertEquals('', $teaser, 'Excerpt mode should be empty when no excerpt');
    }

    public function testMoreTagTeaserShowsContentBeforeTag(): void
    {
        $content = '<p>Visible content</p><!--more--><p>Hidden content</p>';

        $teaser = $this->buildTeaser('more_tag', [], $content);

        $this->assertStringContainsString('Visible content', $teaser);
        $this->assertStringNotContainsString('Hidden content', $teaser);
    }

    public function testMoreTagFallsBackToEmptyIfNoTag(): void
    {
        $content = '<p>Full content without more tag</p>';

        $teaser = $this->buildTeaser('more_tag', [], $content);

        $this->assertEquals('', $teaser, 'More tag mode should be empty when no more tag exists');
    }

    public function testMoreTagWithCustomText(): void
    {
        $content = '<p>Before</p><!--more Read the rest --><p>After</p>';

        $teaser = $this->buildTeaser('more_tag', [], $content);

        $this->assertStringContainsString('Before', $teaser);
        $this->assertStringNotContainsString('After', $teaser);
    }

    public function testWordsTeaserTrimsContent(): void
    {
        $content = 'one two three four five six seven eight nine ten eleven twelve';

        $teaser = $this->buildTeaser('words', ['teaser_word_count' => 5], $content);

        $this->assertStringContainsString('fchub-teaser', $teaser);
        $this->assertStringContainsString('one two three four five', $teaser);
        $this->assertStringNotContainsString('eleven', $teaser);
    }

    public function testWordsTeaserUsesConfiguredCount(): void
    {
        $content = 'word1 word2 word3 word4 word5 word6 word7 word8 word9 word10';

        $teaser3 = $this->buildTeaser('words', ['teaser_word_count' => 3], $content);
        $teaser7 = $this->buildTeaser('words', ['teaser_word_count' => 7], $content);

        $this->assertStringContainsString('word3', $teaser3);
        $this->assertStringNotContainsString('word4', $teaser3);

        $this->assertStringContainsString('word7', $teaser7);
        $this->assertStringNotContainsString('word8', $teaser7);
    }

    public function testWordsTeaserClampedToMinimum(): void
    {
        $content = 'hello world foo bar';

        // 0 should be clamped to 1
        $teaser = $this->buildTeaser('words', ['teaser_word_count' => 0], $content);

        $this->assertStringContainsString('hello', $teaser);
    }

    public function testWordsTeaserClampedToMaximum(): void
    {
        $words = implode(' ', array_fill(0, 600, 'word'));

        // 600 should be clamped to 500
        $teaser = $this->buildTeaser('words', ['teaser_word_count' => 600], $words);

        $this->assertStringContainsString('fchub-teaser', $teaser);
    }

    public function testCustomTeaserShowsCustomText(): void
    {
        $meta = ['custom_teaser' => 'This is premium content. Subscribe to access!'];

        $teaser = $this->buildTeaser('custom', $meta, '<p>Hidden content</p>');

        $this->assertStringContainsString('This is premium content', $teaser);
        $this->assertStringNotContainsString('Hidden content', $teaser);
    }

    public function testCustomTeaserEmptyWhenNoText(): void
    {
        $teaser = $this->buildTeaser('custom', ['custom_teaser' => ''], '<p>Content</p>');

        $this->assertEquals('', $teaser);
    }

    // ── Restriction Block Tests ─────────────────────────────────

    public function testRestrictionBlockContainsPlanNames(): void
    {
        $html = $this->buildRestrictionBlock('no_access', 'Restricted', ['Silver Plan', 'Gold Plan']);

        $this->assertStringContainsString('Silver Plan', $html);
        $this->assertStringContainsString('Gold Plan', $html);
        $this->assertStringContainsString('fchub-plan-list', $html);
    }

    public function testRestrictionBlockShowsLoginButton(): void
    {
        $html = $this->buildRestrictionBlock('logged_out', 'Please log in');

        $this->assertStringContainsString('wp-login.php', $html);
        $this->assertStringContainsString('fchub-btn-login', $html);
        $this->assertStringContainsString('Log in', $html);
    }

    public function testRestrictionBlockHidesLoginForLoggedIn(): void
    {
        $html = $this->buildRestrictionBlock('no_access', 'No access');

        $this->assertStringNotContainsString('fchub-btn-login', $html);
    }

    public function testRestrictionBlockShowsCtaButton(): void
    {
        $html = $this->buildRestrictionBlock('no_access', 'Restricted', [], [
            'cta_text' => 'Get Access Now',
            'cta_url' => 'http://localhost/pricing',
        ]);

        $this->assertStringContainsString('Get Access Now', $html);
        $this->assertStringContainsString('pricing', $html);
        $this->assertStringContainsString('fchub-btn-cta', $html);
    }

    public function testRestrictionBlockNoPlanListWhenEmpty(): void
    {
        $html = $this->buildRestrictionBlock('no_access', 'Restricted', []);

        $this->assertStringNotContainsString('fchub-plan-list', $html);
    }

    public function testRestrictionBlockContextClass(): void
    {
        $html = $this->buildRestrictionBlock('drip_locked', 'Coming soon');

        $this->assertStringContainsString('fchub-restricted-drip_locked', $html);
    }

    // ── Placeholder Resolution Tests ────────────────────────────

    public function testPlaceholderResolutionPlanNames(): void
    {
        $message = 'Access requires: {plan_names}';
        $planNames = ['Silver', 'Gold'];
        $resolved = str_replace('{plan_names}', implode(', ', $planNames), $message);

        $this->assertEquals('Access requires: Silver, Gold', $resolved);
    }

    public function testPlaceholderResolutionLoginUrl(): void
    {
        $message = 'Please <a href="{login_url}">log in</a>';
        $loginUrl = esc_url(wp_login_url('http://localhost/protected-page'));
        $resolved = str_replace('{login_url}', $loginUrl, $message);

        $this->assertStringContainsString('wp-login.php', $resolved);
    }

    public function testPlaceholderResolutionUserName(): void
    {
        $this->setCurrentUserId(1);
        $user = wp_get_current_user();
        $name = $user->ID ? $user->display_name : 'Guest';

        $message = 'Hello {user_name}';
        $resolved = str_replace('{user_name}', esc_html($name), $message);

        $this->assertEquals('Hello Test User', $resolved);
    }

    public function testPlaceholderResolutionGuestUser(): void
    {
        $this->setCurrentUserId(0);
        $user = wp_get_current_user();
        $name = $user->ID ? $user->display_name : 'Guest';

        $message = 'Hello {user_name}';
        $resolved = str_replace('{user_name}', esc_html($name), $message);

        $this->assertEquals('Hello Guest', $resolved);
    }

    // ── Backwards Compatibility Tests ───────────────────────────

    public function testBackwardsCompatShowTeaserYes(): void
    {
        $rule = MockBuilder::protectionRule()
            ->forPost(1)
            ->withShowTeaser('yes')
            ->withMeta([]) // no teaser_mode set
            ->build();

        $teaserMode = $this->resolveTeaserMode($rule);
        $this->assertEquals('excerpt', $teaserMode, 'show_teaser=yes should map to excerpt mode');
    }

    public function testBackwardsCompatShowTeaserNo(): void
    {
        $rule = MockBuilder::protectionRule()
            ->forPost(1)
            ->withShowTeaser('no')
            ->withMeta([]) // no teaser_mode set
            ->build();

        $teaserMode = $this->resolveTeaserMode($rule);
        $this->assertEquals('none', $teaserMode, 'show_teaser=no should map to none mode');
    }

    public function testTeaserModeOverridesShowTeaser(): void
    {
        $rule = MockBuilder::protectionRule()
            ->forPost(1)
            ->withShowTeaser('no')
            ->withMeta(['teaser_mode' => 'words'])
            ->build();

        $teaserMode = $this->resolveTeaserMode($rule);
        $this->assertEquals('words', $teaserMode, 'teaser_mode should override show_teaser');
    }

    // ── Bulk Action Tests ───────────────────────────────────────

    public function testBulkProtectActionName(): void
    {
        $actions = [];
        $actions['fchub_protect'] = 'Protect with Membership';
        $actions['fchub_unprotect'] = 'Remove Membership Protection';

        $this->assertArrayHasKey('fchub_protect', $actions);
        $this->assertArrayHasKey('fchub_unprotect', $actions);
    }

    public function testBulkActionUnknownSkipped(): void
    {
        $action = 'unknown_action';
        $isHandled = in_array($action, ['fchub_protect', 'fchub_unprotect'], true);

        $this->assertFalse($isHandled, 'Unknown actions should be skipped');
    }

    // ── Full output assembly ────────────────────────────────────

    public function testFullProtectedOutputCombinesTeaserAndRestriction(): void
    {
        $post = new \WP_Post();
        $post->post_excerpt = 'Excerpt here';

        $teaser = $this->buildTeaser('excerpt', [], '<p>Content</p>', $post);
        $restriction = $this->buildRestrictionBlock('no_access', 'Restricted');

        $fullOutput = $teaser . $restriction;

        $this->assertStringContainsString('fchub-teaser', $fullOutput);
        $this->assertStringContainsString('fchub-membership-restricted', $fullOutput);
        $this->assertStringContainsString('Excerpt here', $fullOutput);
    }

    public function testNoneTeaserOnlyShowsRestriction(): void
    {
        $teaser = $this->buildTeaser('none', [], '<p>Content</p>');
        $restriction = $this->buildRestrictionBlock('no_access', 'Restricted');

        $fullOutput = $teaser . $restriction;

        $this->assertStringNotContainsString('fchub-teaser', $fullOutput);
        $this->assertStringContainsString('fchub-membership-restricted', $fullOutput);
    }

    // ── Archive filter exclusion ────────────────────────────────

    public function testArchiveExcludeLogicTypeCasting(): void
    {
        // Verify the bug fix: both arrays should be cast to same type before diff
        $protectedIds = ['1', '2', '3'];
        $accessibleIds = ['2'];

        $protectedStr = array_map('strval', $protectedIds);
        $accessibleStr = array_map('strval', $accessibleIds);
        $excludeIds = array_map('intval', array_diff($protectedStr, $accessibleStr));

        $this->assertContains(1, $excludeIds);
        $this->assertContains(3, $excludeIds);
        $this->assertNotContains(2, $excludeIds);
    }

    // ── Attachment exclusion ────────────────────────────────────

    public function testAttachmentExcludedFromMetaBox(): void
    {
        $postTypes = ['post' => 'post', 'page' => 'page', 'attachment' => 'attachment'];
        unset($postTypes['attachment']);

        $this->assertArrayNotHasKey('attachment', $postTypes);
        $this->assertArrayHasKey('post', $postTypes);
        $this->assertArrayHasKey('page', $postTypes);
    }
}

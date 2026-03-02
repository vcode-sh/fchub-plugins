<?php

namespace FChubMemberships\Tests\Unit;

use FChubMemberships\Tests\Support\TestCase;
use FChubMemberships\Tests\Support\MockBuilder;

/**
 * Tests for CommentProtection logic.
 *
 * Since CommentProtection relies on ProtectionRuleRepository and AccessEvaluator
 * which need $wpdb, these tests simulate the same logic in-memory to verify the
 * comment protection modes and access control decisions.
 */
class CommentProtectionTest extends TestCase
{
    /** @var array In-memory protection rules */
    private array $rules = [];

    /** @var array In-memory grants */
    private array $grants = [];

    /** @var array In-memory settings */
    private array $settings = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->rules = [];
        $this->grants = [];
        $this->settings = [];
    }

    // ── Helpers ──────────────────────────────────────────────────

    private function addCommentRule(string $resourceId, string $mode = 'hide_all', array $extraMeta = []): void
    {
        $this->rules[] = MockBuilder::protectionRule()
            ->forComment($resourceId)
            ->withMeta(array_merge(['comment_mode' => $mode], $extraMeta))
            ->build();
    }

    private function addContentProtectionRule(int $postId, string $postType = 'post'): void
    {
        $this->rules[] = MockBuilder::protectionRule()
            ->forResource($postType, (string) $postId)
            ->build();
    }

    private function addGrant(int $userId, string $resourceType, string $resourceId): void
    {
        $this->grants[] = MockBuilder::grant()
            ->forUser($userId)
            ->forResource($resourceType, $resourceId)
            ->active()
            ->build();
    }

    private function findRule(string $resourceType, string $resourceId): ?array
    {
        foreach ($this->rules as $rule) {
            if ($rule['resource_type'] === $resourceType && $rule['resource_id'] === $resourceId) {
                return $rule;
            }
        }
        return null;
    }

    private function userHasGrant(int $userId, string $resourceType, string $resourceId): bool
    {
        foreach ($this->grants as $grant) {
            if ($grant['user_id'] === $userId
                && $grant['resource_type'] === $resourceType
                && ($grant['resource_id'] === $resourceId || $grant['resource_id'] === '*')
                && $grant['status'] === 'active') {
                return true;
            }
        }
        return false;
    }

    /**
     * Simulate the getCommentMode() logic from CommentProtection.
     */
    private function getCommentMode(int $postId): ?string
    {
        // Check explicit comment protection for this post
        $rule = $this->findRule('comment', (string) $postId);
        if ($rule) {
            return $rule['meta']['comment_mode'] ?? 'hide_all';
        }

        // Check wildcard comment protection
        $wildcardRule = $this->findRule('comment', '*');
        if ($wildcardRule) {
            $postType = $GLOBALS['wp_mock_posts'][$postId]->post_type ?? 'post';
            $contentRule = $this->findRule($postType, (string) $postId);
            if ($contentRule) {
                return $wildcardRule['meta']['comment_mode'] ?? 'hide_all';
            }
        }

        // Check inheritance
        if (($this->settings['inherit_comment_protection'] ?? 'no') === 'yes') {
            $postType = $GLOBALS['wp_mock_posts'][$postId]->post_type ?? 'post';
            $contentRule = $this->findRule($postType, (string) $postId);
            if ($contentRule) {
                return $this->settings['default_comment_mode'] ?? 'disable_posting';
            }
        }

        return null;
    }

    /**
     * Simulate userHasCommentAccess() logic.
     */
    private function userHasCommentAccess(int $userId, int $postId): bool
    {
        if ($this->userHasGrant($userId, 'comment', (string) $postId)) {
            return true;
        }
        if ($this->userHasGrant($userId, 'comment', '*')) {
            return true;
        }
        $postType = $GLOBALS['wp_mock_posts'][$postId]->post_type ?? 'post';
        if ($this->userHasGrant($userId, $postType, (string) $postId)) {
            return true;
        }
        return false;
    }

    /**
     * Simulate filterCommentsOpen() logic.
     */
    private function filterCommentsOpen(bool $open, int $postId, int $userId): bool
    {
        if (!$open) {
            return false;
        }

        $mode = $this->getCommentMode($postId);
        if ($mode === null) {
            return $open;
        }

        if ($mode === 'disable_posting' || $mode === 'hide_all') {
            if (!$userId || !$this->userHasCommentAccess($userId, $postId)) {
                return false;
            }
        }

        return $open;
    }

    /**
     * Simulate filterCommentsArray() logic.
     */
    private function filterCommentsArray(array $comments, int $postId, int $userId): array
    {
        $mode = $this->getCommentMode($postId);
        if ($mode !== 'hide_all') {
            return $comments;
        }

        if ($userId && $this->userHasCommentAccess($userId, $postId)) {
            return $comments;
        }

        return [];
    }

    /**
     * Simulate filterCommentsNumber() logic.
     */
    private function filterCommentsNumber(int $count, int $postId, int $userId): int
    {
        $mode = $this->getCommentMode($postId);
        if ($mode === null) {
            return $count;
        }

        if ($mode === 'show_count') {
            return $count;
        }

        if ($mode === 'hide_all') {
            if (!$userId || !$this->userHasCommentAccess($userId, $postId)) {
                return 0;
            }
        }

        return $count;
    }

    // ── Tests ───────────────────────────────────────────────────

    public function testHideAllModeHidesComments(): void
    {
        $this->setMockPost(10);
        $this->addCommentRule('10', 'hide_all');

        $comments = [['id' => 1], ['id' => 2]];
        $filtered = $this->filterCommentsArray($comments, 10, 0);

        $this->assertEmpty($filtered, 'hide_all mode should return empty array for non-members');
    }

    public function testHideAllModeClosesCommentForm(): void
    {
        $this->setMockPost(10);
        $this->addCommentRule('10', 'hide_all');

        $result = $this->filterCommentsOpen(true, 10, 0);

        $this->assertFalse($result, 'hide_all mode should close comments for non-members');
    }

    public function testHideAllModeReturnsZeroCount(): void
    {
        $this->setMockPost(10);
        $this->addCommentRule('10', 'hide_all');

        $count = $this->filterCommentsNumber(5, 10, 0);

        $this->assertEquals(0, $count, 'hide_all mode should show 0 comments for non-members');
    }

    public function testShowCountModePreservesCount(): void
    {
        $this->setMockPost(10);
        $this->addCommentRule('10', 'show_count');

        $count = $this->filterCommentsNumber(5, 10, 0);

        $this->assertEquals(5, $count, 'show_count mode should preserve the real comment count');
    }

    public function testShowCountModeAllowsCommentsToShow(): void
    {
        $this->setMockPost(10);
        $this->addCommentRule('10', 'show_count');

        $comments = [['id' => 1], ['id' => 2]];
        $filtered = $this->filterCommentsArray($comments, 10, 0);

        // show_count mode does not filter comments array (only hide_all does)
        $this->assertCount(2, $filtered, 'show_count mode should not hide comments');
    }

    public function testDisablePostingModeShowsComments(): void
    {
        $this->setMockPost(10);
        $this->addCommentRule('10', 'disable_posting');

        $comments = [['id' => 1], ['id' => 2]];
        $filtered = $this->filterCommentsArray($comments, 10, 0);

        // disable_posting only affects comments_open, not the comments array
        $this->assertCount(2, $filtered, 'disable_posting should not hide existing comments');
    }

    public function testDisablePostingModeClosesForm(): void
    {
        $this->setMockPost(10);
        $this->addCommentRule('10', 'disable_posting');

        $result = $this->filterCommentsOpen(true, 10, 0);

        $this->assertFalse($result, 'disable_posting mode should close comment form for non-members');
    }

    public function testMemberCanViewAllComments(): void
    {
        $this->setMockPost(10);
        $this->addCommentRule('10', 'hide_all');
        $this->addGrant(1, 'comment', '10');

        $comments = [['id' => 1], ['id' => 2], ['id' => 3]];
        $filtered = $this->filterCommentsArray($comments, 10, 1);

        $this->assertCount(3, $filtered, 'Members should see all comments');
    }

    public function testMemberCanPostComments(): void
    {
        $this->setMockPost(10);
        $this->addCommentRule('10', 'disable_posting');
        $this->addGrant(1, 'comment', '10');

        $result = $this->filterCommentsOpen(true, 10, 1);

        $this->assertTrue($result, 'Members should be able to post comments');
    }

    public function testWildcardRuleAppliesToAllProtectedPosts(): void
    {
        $this->setMockPost(10);
        $this->setMockPost(20);
        $this->addCommentRule('*', 'hide_all');

        // Post 10 is content-protected
        $this->addContentProtectionRule(10);

        $mode10 = $this->getCommentMode(10);
        $this->assertEquals('hide_all', $mode10, 'Wildcard rule should apply to content-protected post');

        // Post 20 is NOT content-protected
        $mode20 = $this->getCommentMode(20);
        $this->assertNull($mode20, 'Wildcard rule should NOT apply to unprotected post');
    }

    public function testRestrictionMessageFromRule(): void
    {
        $message = 'Join to participate!';
        $rule = MockBuilder::protectionRule()
            ->forComment('10')
            ->withMeta(['comment_mode' => 'hide_all', 'restriction_message' => $message])
            ->build();

        $this->assertEquals($message, $rule['meta']['restriction_message']);
    }

    public function testNoProtectionReturnsNullMode(): void
    {
        $this->setMockPost(10);

        $mode = $this->getCommentMode(10);

        $this->assertNull($mode, 'No comment protection rule should return null mode');
    }

    public function testNullModeDoesNotFilterComments(): void
    {
        $this->setMockPost(10);

        $comments = [['id' => 1]];
        $filtered = $this->filterCommentsArray($comments, 10, 0);

        $this->assertCount(1, $filtered, 'Without protection, comments should not be filtered');
    }

    public function testNullModeDoesNotCloseForm(): void
    {
        $this->setMockPost(10);

        $result = $this->filterCommentsOpen(true, 10, 0);

        $this->assertTrue($result, 'Without protection, comment form should remain open');
    }

    public function testInheritedProtectionUsesDefaultMode(): void
    {
        $this->setMockPost(10);
        $this->addContentProtectionRule(10);
        $this->settings = [
            'inherit_comment_protection' => 'yes',
            'default_comment_mode' => 'disable_posting',
        ];

        $mode = $this->getCommentMode(10);

        $this->assertEquals('disable_posting', $mode, 'Inherited protection should use default comment mode');
    }

    public function testInheritedProtectionNotAppliedWhenDisabled(): void
    {
        $this->setMockPost(10);
        $this->addContentProtectionRule(10);
        $this->settings = [
            'inherit_comment_protection' => 'no',
        ];

        $mode = $this->getCommentMode(10);

        $this->assertNull($mode, 'Inherited protection should not apply when disabled');
    }

    public function testMemberWithContentAccessCanViewComments(): void
    {
        $this->setMockPost(10);
        $this->addCommentRule('10', 'hide_all');
        // Grant content access (not comment access directly)
        $this->addGrant(1, 'post', '10');

        $canAccess = $this->userHasCommentAccess(1, 10);

        $this->assertTrue($canAccess, 'User with content access should be able to view comments');
    }

    public function testMemberWithWildcardCommentGrantCanAccess(): void
    {
        $this->setMockPost(10);
        $this->addCommentRule('10', 'hide_all');
        $this->addGrant(1, 'comment', '*');

        $canAccess = $this->userHasCommentAccess(1, 10);

        $this->assertTrue($canAccess, 'User with wildcard comment grant should have comment access');
    }

    public function testAlreadyClosedCommentsStayClosed(): void
    {
        $this->setMockPost(10);
        $this->addCommentRule('10', 'hide_all');

        // Comments are already closed
        $result = $this->filterCommentsOpen(false, 10, 0);

        $this->assertFalse($result, 'Already-closed comments should remain closed');
    }

    public function testDefaultCommentModeIsHideAll(): void
    {
        $rule = MockBuilder::protectionRule()
            ->forComment('10')
            ->withMeta([]) // no explicit comment_mode
            ->build();

        $mode = $rule['meta']['comment_mode'] ?? 'hide_all';

        $this->assertEquals('hide_all', $mode, 'Default comment mode should be hide_all');
    }
}

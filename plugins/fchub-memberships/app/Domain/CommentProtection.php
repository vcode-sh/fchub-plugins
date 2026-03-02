<?php

namespace FChubMemberships\Domain;

defined('ABSPATH') || exit;

use FChubMemberships\Storage\ProtectionRuleRepository;
use FChubMemberships\Support\Constants;

class CommentProtection
{
    private AccessEvaluator $evaluator;
    private ProtectionRuleRepository $protectionRepo;

    public function __construct()
    {
        $this->evaluator = new AccessEvaluator();
        $this->protectionRepo = new ProtectionRuleRepository();
    }

    public function register(): void
    {
        // Control whether comments are open
        add_filter('comments_open', [$this, 'filterCommentsOpen'], 20, 2);

        // Filter the comment list output
        add_filter('comments_array', [$this, 'filterCommentsArray'], 20, 2);

        // Add restriction message before comment form
        add_action('comment_form_before', [$this, 'maybeShowRestrictionMessage']);

        // Filter displayed comment count
        add_filter('get_comments_number', [$this, 'filterCommentsNumber'], 20, 2);
    }

    /**
     * Close comments for non-members when comment protection is active.
     */
    public function filterCommentsOpen(bool $open, int $postId): bool
    {
        if (!$open) {
            return false;
        }

        $mode = $this->getCommentMode($postId);
        if ($mode === null) {
            return $open;
        }

        if ($mode === 'disable_posting' || $mode === 'hide_all') {
            $userId = get_current_user_id();
            if (!$userId || !$this->userHasCommentAccess($userId, $postId)) {
                return false;
            }
        }

        return $open;
    }

    /**
     * Hide comments from non-members when mode is hide_all.
     *
     * @param array $comments
     * @param int   $postId
     * @return array
     */
    public function filterCommentsArray(array $comments, int $postId): array
    {
        $mode = $this->getCommentMode($postId);
        if ($mode !== 'hide_all') {
            return $comments;
        }

        $userId = get_current_user_id();
        if ($userId && $this->userHasCommentAccess($userId, $postId)) {
            return $comments;
        }

        return [];
    }

    /**
     * Show restriction message before the comment form area.
     */
    public function maybeShowRestrictionMessage(): void
    {
        $post = get_post();
        if (!$post) {
            return;
        }

        $mode = $this->getCommentMode($post->ID);
        if ($mode === null) {
            return;
        }

        $userId = get_current_user_id();
        if ($userId && $this->userHasCommentAccess($userId, $post->ID)) {
            return;
        }

        $message = $this->getRestrictionMessage($post->ID);

        echo '<div class="fchub-comment-restricted fchub-restricted-' . esc_attr($mode) . '">';
        echo wp_kses_post(wpautop($message));
        echo '</div>';
    }

    /**
     * Filter comment count for show_count mode.
     * In hide_all mode, show 0 for non-members.
     */
    public function filterCommentsNumber(int $count, int $postId): int
    {
        $mode = $this->getCommentMode($postId);
        if ($mode === null) {
            return $count;
        }

        // show_count mode: always show the real count
        if ($mode === 'show_count') {
            return $count;
        }

        // hide_all mode: hide count from non-members
        if ($mode === 'hide_all') {
            $userId = get_current_user_id();
            if (!$userId || !$this->userHasCommentAccess($userId, $postId)) {
                return 0;
            }
        }

        return $count;
    }

    /**
     * Determine the comment protection mode for a post.
     * Returns null if no comment protection is active.
     *
     * Checks:
     * 1. Explicit comment protection rule for this post
     * 2. Wildcard comment protection rule ('*')
     * 3. Content protection with comment inheritance enabled
     */
    private function getCommentMode(int $postId): ?string
    {
        // Check explicit comment protection for this post
        $rule = $this->protectionRepo->findByResource('comment', (string) $postId);
        if ($rule) {
            return $rule['meta']['comment_mode'] ?? 'hide_all';
        }

        // Check wildcard comment protection
        $wildcardRule = $this->protectionRepo->findByResource('comment', '*');
        if ($wildcardRule) {
            // Check if the parent post is actually content-protected
            $postType = get_post_type($postId);
            if ($postType && $this->evaluator->isProtected(Constants::PROVIDER_WORDPRESS_CORE, $postType, (string) $postId)) {
                return $wildcardRule['meta']['comment_mode'] ?? 'hide_all';
            }
        }

        // Check if comment inheritance is enabled globally
        $settings = get_option('fchub_memberships_settings', []);
        if (($settings['inherit_comment_protection'] ?? 'no') === 'yes') {
            $postType = get_post_type($postId);
            if ($postType && $this->evaluator->isProtected(Constants::PROVIDER_WORDPRESS_CORE, $postType, (string) $postId)) {
                return $settings['default_comment_mode'] ?? 'disable_posting';
            }
        }

        return null;
    }

    /**
     * Check if a user has access to comments on a post.
     */
    private function userHasCommentAccess(int $userId, int $postId): bool
    {
        // Check direct comment grant
        if ($this->evaluator->canAccess($userId, Constants::PROVIDER_WORDPRESS_CORE, 'comment', (string) $postId)) {
            return true;
        }

        // Check wildcard comment grant
        if ($this->evaluator->canAccess($userId, Constants::PROVIDER_WORDPRESS_CORE, 'comment', '*')) {
            return true;
        }

        // If linked to content protection, check content access
        $postType = get_post_type($postId);
        if ($postType && $this->evaluator->canAccess($userId, Constants::PROVIDER_WORDPRESS_CORE, $postType, (string) $postId)) {
            return true;
        }

        return false;
    }

    /**
     * Get the restriction message for comment protection.
     */
    private function getRestrictionMessage(int $postId): string
    {
        $rule = $this->protectionRepo->findByResource('comment', (string) $postId);
        if ($rule && !empty($rule['meta']['restriction_message'])) {
            return $rule['meta']['restriction_message'];
        }

        $wildcardRule = $this->protectionRepo->findByResource('comment', '*');
        if ($wildcardRule && !empty($wildcardRule['meta']['restriction_message'])) {
            return $wildcardRule['meta']['restriction_message'];
        }

        $settings = get_option('fchub_memberships_settings', []);
        return $settings['comment_restriction_message']
            ?? __('Join a membership plan to participate in the discussion.', 'fchub-memberships');
    }
}

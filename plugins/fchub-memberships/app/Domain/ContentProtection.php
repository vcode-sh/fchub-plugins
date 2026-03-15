<?php

namespace FChubMemberships\Domain;

defined('ABSPATH') || exit;

use FChubMemberships\Storage\ProtectionRuleRepository;
use FChubMemberships\Support\Constants;

class ContentProtection
{
    private AccessEvaluator $evaluator;

    public function __construct()
    {
        $this->evaluator = new AccessEvaluator();
    }

    public function register(): void
    {
        // Content filtering
        add_filter('the_content', [$this, 'filterContent'], 999);
        add_filter('get_the_excerpt', [$this, 'filterExcerpt'], 999);

        // Template redirect for full-page protection
        add_action('template_redirect', [$this, 'templateRedirect']);

        // Archive/loop filtering
        add_action('pre_get_posts', [$this, 'filterArchiveQueries']);

        // REST API content filtering for all public post types
        $postTypes = get_post_types(['public' => true, 'show_in_rest' => true], 'names');
        foreach ($postTypes as $postType) {
            add_filter("rest_prepare_{$postType}", [$this, 'filterRestContent'], 10, 3);
        }

        // Post editor meta box
        add_action('add_meta_boxes', [$this, 'addMetaBox']);
        add_action('save_post', [$this, 'saveMetaBox'], 10, 2);

        // Bulk actions for all public post types
        $publicPostTypes = get_post_types(['public' => true], 'names');
        unset($publicPostTypes['attachment']);
        foreach ($publicPostTypes as $pt) {
            add_filter("bulk_actions-edit-{$pt}", [$this, 'registerBulkActions']);
            add_filter("handle_bulk_action-edit-{$pt}", [$this, 'handleBulkAction'], 10, 3);
        }

        // Admin notices for bulk action results
        add_action('admin_notices', [$this, 'bulkActionAdminNotice']);

        // Invalidate user access cache on grant lifecycle events
        add_action('fchub_memberships/grant_created', [$this, 'invalidateUserCache'], 10, 3);
        add_action('fchub_memberships/grant_revoked', [$this, 'invalidateRevokedUsersCache'], 10, 4);
        add_action('fchub_memberships/grant_paused', [$this, 'invalidateGrantUserCache'], 10, 1);
        add_action('fchub_memberships/grant_resumed', [$this, 'invalidateGrantUserCache'], 10, 1);
        add_action('fchub_memberships/grant_renewed', [$this, 'invalidateGrantUserCache'], 10, 1);
        // Bug G fix: Also invalidate cache when grants expire (cron-triggered events)
        add_action('fchub_memberships/grant_expired', [$this, 'invalidateGrantUserCache'], 10, 1);
        add_action('fchub_memberships/grant_term_expired', [$this, 'invalidateGrantUserCache'], 10, 1);
    }

    /**
     * Filter post/page content based on membership access.
     */
    public function filterContent(string $content): string
    {
        if (is_admin() || !is_singular()) {
            return $content;
        }

        $post = get_post();
        if (!$post) {
            return $content;
        }

        $postType = $post->post_type;
        $postId = (string) $post->ID;

        if (!$this->evaluator->isProtected(Constants::PROVIDER_WORDPRESS_CORE, $postType, $postId)) {
            return $content;
        }

        $userId = get_current_user_id();

        if (!$userId) {
            return $this->buildProtectedOutput('logged_out', $postType, $postId, $content);
        }

        $result = $this->evaluator->evaluate($userId, Constants::PROVIDER_WORDPRESS_CORE, $postType, $postId);

        if ($result['allowed']) {
            return $content;
        }

        // Also check taxonomy-level protection
        if ($this->hasAccessViaTaxonomy($userId, $post)) {
            return $content;
        }

        if ($result['drip_locked']) {
            return $this->getDripLockedHtml($result['drip_available_at'], $postType, $postId, $content);
        }

        return $this->buildProtectedOutput($this->contextForEvaluation($result), $postType, $postId, $content);
    }

    /**
     * Filter excerpt for protected content.
     */
    public function filterExcerpt(string $excerpt): string
    {
        if (is_admin()) {
            return $excerpt;
        }

        $post = get_post();
        if (!$post) {
            return $excerpt;
        }

        $postType = $post->post_type;
        $postId = (string) $post->ID;

        if (!$this->evaluator->isProtected(Constants::PROVIDER_WORDPRESS_CORE, $postType, $postId)) {
            return $excerpt;
        }

        $userId = get_current_user_id();
        if ($userId && $this->evaluator->canAccess($userId, Constants::PROVIDER_WORDPRESS_CORE, $postType, $postId)) {
            return $excerpt;
        }

        // Bug H fix: Also check taxonomy-based access, same as filterContent() does
        if ($userId && $this->hasAccessViaTaxonomy($userId, $post)) {
            return $excerpt;
        }

        // Show excerpt in archive listings if teaser mode is not 'none'
        $protectionRepo = new ProtectionRuleRepository();
        $rule = $protectionRepo->findByResource($postType, $postId);
        $meta = $rule['meta'] ?? [];
        $teaserMode = $meta['teaser_mode'] ?? null;

        // Backwards compatibility
        if ($teaserMode === null && $rule !== null) {
            $teaserMode = ($rule['show_teaser'] === 'yes') ? 'excerpt' : 'none';
        }

        if ($teaserMode && $teaserMode !== 'none') {
            return $excerpt;
        }

        return '';
    }

    /**
     * Redirect for full-page protection mode.
     */
    public function templateRedirect(): void
    {
        if (is_admin() || !is_singular()) {
            return;
        }

        $settings = get_option('fchub_memberships_settings', []);
        $mode = $settings['default_protection_mode'] ?? 'content_replace';

        if ($mode !== 'redirect') {
            return;
        }

        $post = get_post();
        if (!$post) {
            return;
        }

        $postType = $post->post_type;
        $postId = (string) $post->ID;

        if (!$this->evaluator->isProtected(Constants::PROVIDER_WORDPRESS_CORE, $postType, $postId)) {
            return;
        }

        $userId = get_current_user_id();
        if ($userId && $this->evaluator->canAccess($userId, Constants::PROVIDER_WORDPRESS_CORE, $postType, $postId)) {
            return;
        }

        $redirectUrl = $this->evaluator->getRedirectUrl($postType, $postId);
        if ($redirectUrl) {
            wp_safe_redirect($redirectUrl);
            exit;
        }
    }

    /**
     * Filter archive queries to exclude protected posts.
     */
    public function filterArchiveQueries(\WP_Query $query): void
    {
        if (is_admin() || !$query->is_main_query()) {
            return;
        }

        if (!$query->is_archive() && !$query->is_home() && !$query->is_search()) {
            return;
        }

        $settings = get_option('fchub_memberships_settings', []);
        if (($settings['hide_protected_in_archive'] ?? 'no') !== 'yes') {
            return;
        }

        $protectionRepo = new \FChubMemberships\Storage\ProtectionRuleRepository();
        $postType = $query->get('post_type') ?: 'post';

        if (is_array($postType)) {
            $postType = $postType[0] ?? 'post';
        }

        $protectedIds = $protectionRepo->getProtectedResourceIds($postType);

        // Also include posts protected via taxonomy term inheritance
        $taxonomyProtectedIds = $protectionRepo->getPostIdsProtectedByTaxonomy($postType);
        if (!empty($taxonomyProtectedIds)) {
            $protectedIds = array_unique(array_merge($protectedIds, $taxonomyProtectedIds));
        }

        if (empty($protectedIds)) {
            return;
        }

        $userId = get_current_user_id();
        if (!$userId) {
            $excludeIds = array_map('intval', $protectedIds);
        } else {
            $accessibleIds = $this->evaluator->canAccessMultiple($userId, $protectedIds, $postType);
            // Bug #1: Cast both arrays to string for consistent comparison in array_diff
            $protectedStrIds = array_map('strval', $protectedIds);
            $accessibleStrIds = array_map('strval', $accessibleIds);
            $excludeIds = array_map('intval', array_diff($protectedStrIds, $accessibleStrIds));
        }

        if (!empty($excludeIds)) {
            $existing = $query->get('post__not_in') ?: [];
            $query->set('post__not_in', array_merge($existing, $excludeIds));
        }
    }

    /**
     * Filter REST API content.
     */
    public function filterRestContent($response, $post, $request)
    {
        $postType = $post->post_type;
        $postId = (string) $post->ID;

        if (!$this->evaluator->isProtected(Constants::PROVIDER_WORDPRESS_CORE, $postType, $postId)) {
            return $response;
        }

        $userId = get_current_user_id();
        if ($userId && $this->evaluator->canAccess($userId, Constants::PROVIDER_WORDPRESS_CORE, $postType, $postId)) {
            return $response;
        }

        $context = 'logged_out';
        if ($userId) {
            $result = $this->evaluator->evaluate($userId, Constants::PROVIDER_WORDPRESS_CORE, $postType, $postId);
            $context = $this->contextForEvaluation($result);
        }
        $data = $response->get_data();
        $data['content']['rendered'] = $this->buildProtectedOutput($context, $postType, $postId, $data['content']['rendered'] ?? '');
        $data['content']['protected'] = true;
        $response->set_data($data);

        return $response;
    }

    /**
     * Add membership protection meta box to post editor.
     */
    public function addMetaBox(): void
    {
        $postTypes = get_post_types(['public' => true], 'names');
        unset($postTypes['attachment']);

        foreach ($postTypes as $postType) {
            add_meta_box(
                'fchub-membership-protection',
                __('Membership Protection', 'fchub-memberships'),
                [$this, 'renderMetaBox'],
                $postType,
                'side',
                'default'
            );
        }
    }

    /**
     * Render the membership protection meta box.
     */
    public function renderMetaBox(\WP_Post $post): void
    {
        $protectionRepo = new ProtectionRuleRepository();
        $rule = $protectionRepo->findByResource($post->post_type, (string) $post->ID);
        $isProtected = $rule !== null;
        $planIds = $rule['plan_ids'] ?? [];
        $restrictionMessage = $rule['restriction_message'] ?? '';
        $meta = $rule['meta'] ?? [];

        // Backwards compatibility: migrate show_teaser to teaser_mode
        $teaserMode = $meta['teaser_mode'] ?? null;
        if ($teaserMode === null && $rule !== null) {
            $teaserMode = ($rule['show_teaser'] === 'yes') ? 'excerpt' : 'none';
        }
        $teaserMode = $teaserMode ?: 'none';

        $teaserWordCount = $meta['teaser_word_count'] ?? 50;
        $customTeaser = $meta['custom_teaser'] ?? '';
        $ctaText = $meta['cta_text'] ?? '';
        $ctaUrl = $meta['cta_url'] ?? '';

        $planService = new \FChubMemberships\Domain\Plan\PlanService();
        $plans = $planService->getOptions();

        // Find plans that include this post via plan rules
        $ruleResolver = new \FChubMemberships\Domain\Plan\PlanRuleResolver();
        $implicitPlanIds = $ruleResolver->findPlansWithResource(Constants::PROVIDER_WORDPRESS_CORE, $post->post_type, (string) $post->ID);

        wp_nonce_field('fchub_memberships_protection', '_fchub_protection_nonce');
        ?>
        <p>
            <label>
                <input type="checkbox" name="fchub_is_protected" value="1" <?php checked($isProtected); ?>>
                <?php esc_html_e('Protect this content', 'fchub-memberships'); ?>
            </label>
        </p>
        <?php if (!empty($implicitPlanIds)): ?>
            <p class="description" style="margin-top:4px;">
                <?php esc_html_e('Also included in plans via rules:', 'fchub-memberships'); ?>
                <?php
                $planRepo = new \FChubMemberships\Storage\PlanRepository();
                $implicitNames = [];
                foreach ($implicitPlanIds as $pid) {
                    $p = $planRepo->find((int) $pid);
                    if ($p) {
                        $implicitNames[] = esc_html($p['title']);
                    }
                }
                echo implode(', ', $implicitNames);
                ?>
            </p>
        <?php endif; ?>
        <div id="fchub-protection-details" style="<?php echo $isProtected ? '' : 'display:none;'; ?>">
            <p>
                <label><strong><?php esc_html_e('Required Plans:', 'fchub-memberships'); ?></strong></label><br>
                <?php foreach ($plans as $plan): ?>
                    <label>
                        <input type="checkbox" name="fchub_plan_ids[]" value="<?php echo esc_attr($plan['id']); ?>"
                            <?php checked(in_array($plan['id'], $planIds, false)); ?>>
                        <?php echo esc_html($plan['label']); ?>
                    </label><br>
                <?php endforeach; ?>
                <?php if (empty($plans)): ?>
                    <em><?php esc_html_e('No plans created yet.', 'fchub-memberships'); ?></em>
                <?php endif; ?>
            </p>
            <p>
                <label><strong><?php esc_html_e('Teaser Mode:', 'fchub-memberships'); ?></strong></label><br>
                <select name="fchub_teaser_mode" id="fchub-teaser-mode" style="width:100%;">
                    <option value="none" <?php selected($teaserMode, 'none'); ?>><?php esc_html_e('No teaser (restriction message only)', 'fchub-memberships'); ?></option>
                    <option value="excerpt" <?php selected($teaserMode, 'excerpt'); ?>><?php esc_html_e('Show post excerpt', 'fchub-memberships'); ?></option>
                    <option value="more_tag" <?php selected($teaserMode, 'more_tag'); ?>><?php esc_html_e('Content before <!--more--> tag', 'fchub-memberships'); ?></option>
                    <option value="words" <?php selected($teaserMode, 'words'); ?>><?php esc_html_e('First N words', 'fchub-memberships'); ?></option>
                    <option value="custom" <?php selected($teaserMode, 'custom'); ?>><?php esc_html_e('Custom teaser text', 'fchub-memberships'); ?></option>
                </select>
            </p>
            <p id="fchub-teaser-word-count-wrap" style="<?php echo $teaserMode === 'words' ? '' : 'display:none;'; ?>">
                <label><?php esc_html_e('Number of words:', 'fchub-memberships'); ?></label>
                <input type="number" name="fchub_teaser_word_count" value="<?php echo esc_attr($teaserWordCount); ?>" min="1" max="500" style="width:80px;">
            </p>
            <p id="fchub-custom-teaser-wrap" style="<?php echo $teaserMode === 'custom' ? '' : 'display:none;'; ?>">
                <label><?php esc_html_e('Custom teaser text:', 'fchub-memberships'); ?></label>
                <textarea name="fchub_custom_teaser" style="width:100%;" rows="3"><?php echo esc_textarea($customTeaser); ?></textarea>
            </p>
            <p>
                <label><?php esc_html_e('Custom restriction message:', 'fchub-memberships'); ?></label>
                <textarea name="fchub_restriction_message" style="width:100%;" rows="3" placeholder="<?php esc_attr_e('Supports: {plan_names}, {login_url}, {pricing_url}, {user_name}', 'fchub-memberships'); ?>"><?php echo esc_textarea($restrictionMessage); ?></textarea>
            </p>
            <p>
                <label><?php esc_html_e('CTA Button Text:', 'fchub-memberships'); ?></label>
                <input type="text" name="fchub_cta_text" value="<?php echo esc_attr($ctaText); ?>" style="width:100%;" placeholder="<?php esc_attr_e('e.g. Get Access Now', 'fchub-memberships'); ?>">
            </p>
            <p>
                <label><?php esc_html_e('CTA Button URL:', 'fchub-memberships'); ?></label>
                <input type="url" name="fchub_cta_url" value="<?php echo esc_attr($ctaUrl); ?>" style="width:100%;" placeholder="<?php esc_attr_e('e.g. /pricing', 'fchub-memberships'); ?>">
            </p>
        </div>
        <script>
        jQuery(function($) {
            $('input[name="fchub_is_protected"]').on('change', function() {
                $('#fchub-protection-details').toggle(this.checked);
            });
            $('#fchub-teaser-mode').on('change', function() {
                var mode = $(this).val();
                $('#fchub-teaser-word-count-wrap').toggle(mode === 'words');
                $('#fchub-custom-teaser-wrap').toggle(mode === 'custom');
            });
        });
        </script>
        <?php
    }

    /**
     * Save meta box data.
     */
    public function saveMetaBox(int $postId, \WP_Post $post): void
    {
        if (!isset($_POST['_fchub_protection_nonce']) || !wp_verify_nonce($_POST['_fchub_protection_nonce'], 'fchub_memberships_protection')) {
            return;
        }

        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        if (!current_user_can('edit_post', $postId)) {
            return;
        }

        $protectionRepo = new ProtectionRuleRepository();
        $isProtected = !empty($_POST['fchub_is_protected']);

        if ($isProtected) {
            $planIds = isset($_POST['fchub_plan_ids']) ? array_map('intval', (array) $_POST['fchub_plan_ids']) : [];
            $restrictionMessage = sanitize_textarea_field($_POST['fchub_restriction_message'] ?? '');

            $teaserMode = sanitize_text_field($_POST['fchub_teaser_mode'] ?? 'none');
            $allowedModes = ['none', 'excerpt', 'more_tag', 'custom', 'words'];
            if (!in_array($teaserMode, $allowedModes, true)) {
                $teaserMode = 'none';
            }

            // Map teaser_mode to show_teaser for backwards compatibility
            $showTeaser = ($teaserMode !== 'none') ? 'yes' : 'no';

            $meta = [
                'teaser_mode'       => $teaserMode,
                'teaser_word_count' => absint($_POST['fchub_teaser_word_count'] ?? 50) ?: 50,
                'custom_teaser'     => sanitize_textarea_field($_POST['fchub_custom_teaser'] ?? ''),
                'cta_text'          => sanitize_text_field($_POST['fchub_cta_text'] ?? ''),
                'cta_url'           => esc_url_raw($_POST['fchub_cta_url'] ?? ''),
            ];

            $protectionRepo->createOrUpdate($post->post_type, (string) $postId, [
                'plan_ids'            => $planIds,
                'protection_mode'     => Constants::PROTECTION_MODE_EXPLICIT,
                'restriction_message' => $restrictionMessage ?: null,
                'show_teaser'         => $showTeaser,
                'meta'                => $meta,
            ]);
        } else {
            $rule = $protectionRepo->findByResource($post->post_type, (string) $postId);
            if ($rule) {
                $protectionRepo->delete($rule['id']);
            }
        }

        AccessEvaluator::clearCache();
    }

    /**
     * Register bulk actions in post list tables.
     */
    public function registerBulkActions(array $actions): array
    {
        $actions['fchub_protect'] = __('Protect with Membership', 'fchub-memberships');
        $actions['fchub_unprotect'] = __('Remove Membership Protection', 'fchub-memberships');
        return $actions;
    }

    /**
     * Handle bulk protect/unprotect actions.
     */
    public function handleBulkAction(string $redirectTo, string $action, array $postIds): string
    {
        if (!in_array($action, ['fchub_protect', 'fchub_unprotect'], true)) {
            return $redirectTo;
        }

        $protectionRepo = new ProtectionRuleRepository();
        $count = 0;

        foreach ($postIds as $postId) {
            $post = get_post((int) $postId);
            if (!$post) {
                continue;
            }

            if ($action === 'fchub_protect') {
                $existing = $protectionRepo->findByResource($post->post_type, (string) $post->ID);
                if (!$existing) {
                    $protectionRepo->createOrUpdate($post->post_type, (string) $post->ID, [
                        'plan_ids'        => [],
                        'protection_mode' => Constants::PROTECTION_MODE_EXPLICIT,
                        'show_teaser'     => 'no',
                        'meta'            => ['teaser_mode' => 'none'],
                    ]);
                    $count++;
                }
            } elseif ($action === 'fchub_unprotect') {
                $rule = $protectionRepo->findByResource($post->post_type, (string) $post->ID);
                if ($rule) {
                    $protectionRepo->delete($rule['id']);
                    $count++;
                }
            }
        }

        AccessEvaluator::clearCache();

        return add_query_arg([
            'fchub_bulk_action' => $action,
            'fchub_bulk_count'  => $count,
        ], $redirectTo);
    }

    /**
     * Display admin notice after bulk action.
     */
    public function bulkActionAdminNotice(): void
    {
        if (empty($_GET['fchub_bulk_action']) || !isset($_GET['fchub_bulk_count'])) {
            return;
        }

        $action = sanitize_text_field($_GET['fchub_bulk_action']);
        $count = (int) $_GET['fchub_bulk_count'];

        if ($action === 'fchub_protect') {
            $message = sprintf(
                _n('%d item protected.', '%d items protected.', $count, 'fchub-memberships'),
                $count
            );
            $message .= ' <a href="' . esc_url(admin_url('admin.php?page=fchub-memberships#/content')) . '">';
            $message .= esc_html__('Manage protection rules to assign plans.', 'fchub-memberships');
            $message .= '</a>';
        } elseif ($action === 'fchub_unprotect') {
            $message = sprintf(
                _n('%d item unprotected.', '%d items unprotected.', $count, 'fchub-memberships'),
                $count
            );
        } else {
            return;
        }

        printf('<div class="notice notice-success is-dismissible"><p>%s</p></div>', wp_kses_post($message));
    }

    private function hasAccessViaTaxonomy(int $userId, \WP_Post $post): bool
    {
        $taxonomies = get_object_taxonomies($post->post_type, 'names');

        foreach ($taxonomies as $taxonomy) {
            $terms = get_the_terms($post->ID, $taxonomy);
            if (!$terms || is_wp_error($terms)) {
                continue;
            }

            foreach ($terms as $term) {
                if ($this->evaluator->canAccess($userId, Constants::PROVIDER_WORDPRESS_CORE, $taxonomy, (string) $term->term_id)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Build the full protected output: teaser + restriction block.
     */
    private function buildProtectedOutput(string $context, string $resourceType, string $resourceId, string $originalContent): string
    {
        $protectionRepo = new ProtectionRuleRepository();
        $rule = $protectionRepo->findByResource($resourceType, $resourceId);
        $meta = $rule['meta'] ?? [];

        // Backwards compatibility: derive teaser_mode from show_teaser if not set
        $teaserMode = $meta['teaser_mode'] ?? null;
        if ($teaserMode === null && $rule !== null) {
            $teaserMode = ($rule['show_teaser'] === 'yes') ? 'excerpt' : 'none';
        }
        $teaserMode = $teaserMode ?: 'none';

        $teaser = $this->buildTeaser($teaserMode, $meta, $originalContent);
        $restrictionBlock = $this->renderRestrictionBlock($rule, $context, $resourceType, $resourceId);

        return $teaser . $restrictionBlock;
    }

    /**
     * Build teaser content based on teaser mode.
     */
    private function buildTeaser(string $mode, array $meta, string $originalContent): string
    {
        $teaserContent = '';

        switch ($mode) {
            case 'excerpt':
                $post = get_post();
                if ($post && $post->post_excerpt) {
                    $teaserContent = wpautop($post->post_excerpt);
                }
                break;

            case 'more_tag':
                if (preg_match('/<!--more(.*?)?-->/', $originalContent, $matches, PREG_OFFSET_CAPTURE)) {
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
     * Render the restriction block with message, plan names, login/CTA buttons.
     */
    public function renderRestrictionBlock(?array $rule, string $context, string $resourceType, string $resourceId): string
    {
        wp_enqueue_style('fchub-memberships-frontend', FCHUB_MEMBERSHIPS_URL . 'assets/css/frontend.css', [], FCHUB_MEMBERSHIPS_VERSION);

        $message = $this->evaluator->getRestrictionMessage($resourceType, $resourceId, $context);
        $message = $this->resolveMessagePlaceholders($message, $rule, $resourceId);
        $meta = $rule['meta'] ?? [];

        $html = '<div class="fchub-membership-restricted fchub-restricted-' . esc_attr($context) . '">';
        $html .= wpautop($message);

        // Show available plan names
        $planNames = $this->getPlanNamesForResource($rule, $resourceType, $resourceId);
        if (!empty($planNames)) {
            $html .= '<div class="fchub-plan-list">';
            $html .= '<p class="fchub-plan-list-label">' . esc_html__('Available with:', 'fchub-memberships') . '</p>';
            $html .= '<ul>';
            foreach ($planNames as $name) {
                $html .= '<li>' . esc_html($name) . '</li>';
            }
            $html .= '</ul>';
            $html .= '</div>';
        }

        // Login button for logged-out users
        if ($context === 'logged_out') {
            $html .= sprintf(
                '<p class="fchub-login-link"><a href="%s" class="fchub-btn fchub-btn-login">%s</a></p>',
                esc_url(wp_login_url(get_permalink())),
                esc_html__('Log in', 'fchub-memberships')
            );
        }

        // CTA button
        $ctaText = $meta['cta_text'] ?? '';
        $ctaUrl = $meta['cta_url'] ?? '';
        if ($ctaText && $ctaUrl) {
            $html .= sprintf(
                '<p class="fchub-cta"><a href="%s" class="fchub-btn fchub-btn-cta">%s</a></p>',
                esc_url($ctaUrl),
                esc_html($ctaText)
            );
        }

        $html .= '</div>';

        return $html;
    }

    /**
     * Map evaluator results to restriction-message contexts.
     *
     * @param array<string, mixed> $result
     */
    private function contextForEvaluation(array $result): string
    {
        if (!empty($result['drip_locked'])) {
            return 'drip_locked';
        }

        return match ($result['reason'] ?? '') {
            Constants::REASON_MEMBERSHIP_PAUSED => 'membership_paused',
            default => 'no_access',
        };
    }

    /**
     * Resolve placeholders in restriction messages.
     */
    private function resolveMessagePlaceholders(string $message, ?array $rule, string $resourceId): string
    {
        $postId = (int) $resourceId;

        // {plan_names}
        if (strpos($message, '{plan_names}') !== false) {
            $planNames = $this->getPlanNamesForResource($rule, $rule['resource_type'] ?? '', $resourceId);
            $message = str_replace('{plan_names}', implode(', ', $planNames), $message);
        }

        // {login_url}
        if (strpos($message, '{login_url}') !== false) {
            $message = str_replace('{login_url}', esc_url(wp_login_url(get_permalink($postId))), $message);
        }

        // {pricing_url}
        if (strpos($message, '{pricing_url}') !== false) {
            $settings = get_option('fchub_memberships_settings', []);
            $pricingUrl = $settings['pricing_page_url'] ?? '';
            $message = str_replace('{pricing_url}', esc_url($pricingUrl), $message);
        }

        // {user_name}
        if (strpos($message, '{user_name}') !== false) {
            $user = wp_get_current_user();
            $name = $user->ID ? $user->display_name : __('Guest', 'fchub-memberships');
            $message = str_replace('{user_name}', esc_html($name), $message);
        }

        return $message;
    }

    /**
     * Get plan names that grant access to a resource.
     */
    private function getPlanNamesForResource(?array $rule, string $resourceType, string $resourceId): array
    {
        $planIds = $rule['plan_ids'] ?? [];

        // Also check plans via rule resolver
        $ruleResolver = new \FChubMemberships\Domain\Plan\PlanRuleResolver();
        $implicitPlanIds = $ruleResolver->findPlansWithResource(Constants::PROVIDER_WORDPRESS_CORE, $resourceType, $resourceId);
        $allPlanIds = array_unique(array_merge($planIds, $implicitPlanIds));

        if (empty($allPlanIds)) {
            return [];
        }

        $planRepo = new \FChubMemberships\Storage\PlanRepository();
        $names = [];
        foreach ($allPlanIds as $planId) {
            $plan = $planRepo->find((int) $planId);
            if ($plan && $plan['status'] === 'active') {
                $names[] = $plan['title'];
            }
        }

        return $names;
    }

    /**
     * Invalidate cache when a grant is created.
     */
    public function invalidateUserCache(int $userId, int $planId, array $context): void
    {
        AccessEvaluator::clearUserCache($userId);
    }

    /**
     * Invalidate cache for all users affected by a revoke.
     */
    public function invalidateRevokedUsersCache(array $grants, int $planId, int $userId, string $reason): void
    {
        AccessEvaluator::clearUserCache($userId);
    }

    /**
     * Invalidate cache using user_id from a grant array.
     */
    public function invalidateGrantUserCache(array $grant): void
    {
        if (!empty($grant['user_id'])) {
            AccessEvaluator::clearUserCache((int) $grant['user_id']);
        }
    }

    private function getDripLockedHtml(string $dripAvailableAt, string $resourceType, string $resourceId, string $originalContent): string
    {
        $message = $this->evaluator->getRestrictionMessage($resourceType, $resourceId, 'drip_locked');

        // Replace smart codes
        $unlockDate = wp_date(get_option('date_format'), strtotime($dripAvailableAt));
        $message = str_replace('{unlock_date}', $unlockDate, $message);

        wp_enqueue_style('fchub-memberships-frontend', FCHUB_MEMBERSHIPS_URL . 'assets/css/frontend.css', [], FCHUB_MEMBERSHIPS_VERSION);

        return '<div class="fchub-membership-restricted fchub-restricted-drip-locked">'
            . wpautop($message)
            . '<p class="fchub-unlock-date">' . sprintf(
                esc_html__('Available on: %s', 'fchub-memberships'),
                esc_html($unlockDate)
            ) . '</p>'
            . '</div>';
    }
}

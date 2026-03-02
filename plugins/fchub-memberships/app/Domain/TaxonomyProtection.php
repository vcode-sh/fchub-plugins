<?php

namespace FChubMemberships\Domain;

defined('ABSPATH') || exit;

use FChubMemberships\Storage\PlanRepository;
use FChubMemberships\Storage\ProtectionRuleRepository;
use FChubMemberships\Support\Constants;

class TaxonomyProtection
{
    private PlanRepository $planRepo;
    private ProtectionRuleRepository $protectionRepo;

    public function __construct()
    {
        $this->planRepo = new PlanRepository();
        $this->protectionRepo = new ProtectionRuleRepository();
    }

    public function register(): void
    {
        $taxonomies = get_taxonomies(['public' => true], 'names');

        foreach ($taxonomies as $taxonomy) {
            add_action("{$taxonomy}_edit_form_fields", [$this, 'renderEditFields'], 10, 2);
            add_action("{$taxonomy}_add_form_fields", [$this, 'renderAddFields'], 10, 1);
            add_action("edited_{$taxonomy}", [$this, 'saveTermProtection'], 10, 2);
            add_action("created_{$taxonomy}", [$this, 'saveTermProtection'], 10, 2);
        }

        add_action('delete_term', [$this, 'cleanupTermProtection'], 10, 4);
    }

    /**
     * Get all protected term IDs for a given taxonomy.
     */
    public function getProtectedTermIds(string $taxonomy): array
    {
        return $this->protectionRepo->getProtectedResourceIds($taxonomy);
    }

    /**
     * Check if a post is protected via any of its taxonomy terms (with inheritance_mode=all_posts).
     *
     * @return bool|array False if not protected, or the protection rule array if protected.
     */
    public function isPostProtectedByTaxonomy(int $postId): bool|array
    {
        $post = get_post($postId);
        if (!$post) {
            return false;
        }

        $taxonomies = get_object_taxonomies($post->post_type, 'names');
        foreach ($taxonomies as $taxonomy) {
            $terms = get_the_terms($postId, $taxonomy);
            if (!$terms || is_wp_error($terms)) {
                continue;
            }

            foreach ($terms as $term) {
                $rule = $this->protectionRepo->findByResource($taxonomy, (string) $term->term_id);
                if ($rule) {
                    $meta = $rule['meta'] ?? [];
                    $inheritanceMode = $meta['inheritance_mode'] ?? 'none';
                    if ($inheritanceMode === 'all_posts') {
                        return $rule;
                    }
                }
            }
        }

        return false;
    }

    /**
     * Render protection fields on the "Edit Term" form.
     */
    public function renderEditFields(\WP_Term $term, string $taxonomy): void
    {
        $rule = $this->protectionRepo->findByResource($taxonomy, (string) $term->term_id);
        $isProtected = $rule !== null;
        $planIds = $rule['plan_ids'] ?? [];
        $meta = $rule['meta'] ?? [];
        $inheritanceMode = $meta['inheritance_mode'] ?? 'none';
        $plans = $this->planRepo->getActivePlans();

        // Get the taxonomy object for label
        $taxonomyObj = get_taxonomy($taxonomy);
        $singularLabel = $taxonomyObj ? strtolower($taxonomyObj->labels->singular_name) : $taxonomy;

        wp_nonce_field('fchub_memberships_protection', '_fchub_protection_nonce');
        ?>
        <tr class="form-field">
            <th scope="row"><?php esc_html_e('Membership Protection', 'fchub-memberships'); ?></th>
            <td>
                <label>
                    <input type="checkbox" name="fchub_is_protected" value="1" <?php checked($isProtected); ?>>
                    <?php esc_html_e('Protect this term', 'fchub-memberships'); ?>
                </label>
                <p class="description"><?php esc_html_e('Require a membership plan to access content in this term.', 'fchub-memberships'); ?></p>
            </td>
        </tr>
        <tr class="form-field fchub-term-details" style="<?php echo $isProtected ? '' : 'display:none;'; ?>">
            <th scope="row"><?php esc_html_e('Required Plans', 'fchub-memberships'); ?></th>
            <td>
                <?php if (!empty($plans)): ?>
                    <?php foreach ($plans as $plan): ?>
                        <label>
                            <input type="checkbox" name="fchub_plan_ids[]" value="<?php echo esc_attr($plan['id']); ?>"
                                <?php checked(is_array($planIds) && in_array($plan['id'], $planIds, false)); ?>>
                            <?php echo esc_html($plan['title']); ?>
                        </label><br>
                    <?php endforeach; ?>
                <?php else: ?>
                    <em><?php esc_html_e('No plans created yet.', 'fchub-memberships'); ?></em>
                <?php endif; ?>
            </td>
        </tr>
        <tr class="form-field fchub-term-details" style="<?php echo $isProtected ? '' : 'display:none;'; ?>">
            <th scope="row"><?php esc_html_e('Post Inheritance', 'fchub-memberships'); ?></th>
            <td>
                <select name="fchub_inheritance_mode" style="width:100%;max-width:400px;">
                    <option value="none" <?php selected($inheritanceMode, 'none'); ?>><?php esc_html_e('No inheritance (protect term archive only)', 'fchub-memberships'); ?></option>
                    <option value="all_posts" <?php selected($inheritanceMode, 'all_posts'); ?>><?php esc_html_e('Protect all posts in this term', 'fchub-memberships'); ?></option>
                </select>
                <p class="description">
                    <?php
                    printf(
                        /* translators: %s: taxonomy singular name (e.g. "category") */
                        esc_html__('When set to "all posts", every post assigned to this %s will require membership access.', 'fchub-memberships'),
                        esc_html($singularLabel)
                    );
                    ?>
                </p>
            </td>
        </tr>
        <script>
        jQuery(function($) {
            $('input[name="fchub_is_protected"]').on('change', function() {
                $('.fchub-term-details').toggle(this.checked);
            });
        });
        </script>
        <?php
    }

    /**
     * Render protection fields on the "Add New Term" form.
     */
    public function renderAddFields(string $taxonomy): void
    {
        $plans = $this->planRepo->getActivePlans();
        $taxonomyObj = get_taxonomy($taxonomy);
        $singularLabel = $taxonomyObj ? strtolower($taxonomyObj->labels->singular_name) : $taxonomy;

        wp_nonce_field('fchub_memberships_protection', '_fchub_protection_nonce');
        ?>
        <div class="form-field">
            <label><?php esc_html_e('Membership Protection', 'fchub-memberships'); ?></label>
            <label>
                <input type="checkbox" name="fchub_is_protected" value="1">
                <?php esc_html_e('Protect this term', 'fchub-memberships'); ?>
            </label>
            <p class="description"><?php esc_html_e('Require a membership plan to access content in this term.', 'fchub-memberships'); ?></p>
        </div>
        <div class="form-field fchub-term-details" style="display:none;">
            <label><?php esc_html_e('Required Plans', 'fchub-memberships'); ?></label>
            <?php if (!empty($plans)): ?>
                <?php foreach ($plans as $plan): ?>
                    <label>
                        <input type="checkbox" name="fchub_plan_ids[]" value="<?php echo esc_attr($plan['id']); ?>">
                        <?php echo esc_html($plan['title']); ?>
                    </label><br>
                <?php endforeach; ?>
            <?php else: ?>
                <em><?php esc_html_e('No plans created yet.', 'fchub-memberships'); ?></em>
            <?php endif; ?>
        </div>
        <div class="form-field fchub-term-details" style="display:none;">
            <label><?php esc_html_e('Post Inheritance', 'fchub-memberships'); ?></label>
            <select name="fchub_inheritance_mode" style="width:100%;max-width:400px;">
                <option value="none"><?php esc_html_e('No inheritance (protect term archive only)', 'fchub-memberships'); ?></option>
                <option value="all_posts"><?php esc_html_e('Protect all posts in this term', 'fchub-memberships'); ?></option>
            </select>
            <p class="description">
                <?php
                printf(
                    /* translators: %s: taxonomy singular name */
                    esc_html__('When set to "all posts", every post assigned to this %s will require membership access.', 'fchub-memberships'),
                    esc_html($singularLabel)
                );
                ?>
            </p>
        </div>
        <script>
        jQuery(function($) {
            $('input[name="fchub_is_protected"]').on('change', function() {
                $('.fchub-term-details').toggle(this.checked);
            });
        });
        </script>
        <?php
    }

    /**
     * Save term protection settings.
     */
    public function saveTermProtection(int $termId, int $ttId): void
    {
        if (!isset($_POST['_fchub_protection_nonce']) || !wp_verify_nonce($_POST['_fchub_protection_nonce'], 'fchub_memberships_protection')) {
            return;
        }

        if (!current_user_can('manage_categories')) {
            return;
        }

        $term = get_term($termId);
        if (!$term || is_wp_error($term)) {
            return;
        }

        $taxonomy = $term->taxonomy;
        $isProtected = !empty($_POST['fchub_is_protected']);

        if ($isProtected) {
            $planIds = isset($_POST['fchub_plan_ids']) ? array_map('intval', (array) $_POST['fchub_plan_ids']) : [];

            $inheritanceMode = sanitize_text_field($_POST['fchub_inheritance_mode'] ?? 'none');
            if (!in_array($inheritanceMode, ['none', 'all_posts'], true)) {
                $inheritanceMode = 'none';
            }

            $this->protectionRepo->createOrUpdate($taxonomy, (string) $termId, [
                'plan_ids'        => $planIds,
                'protection_mode' => Constants::PROTECTION_MODE_EXPLICIT,
                'meta'            => ['inheritance_mode' => $inheritanceMode],
            ]);
        } else {
            $rule = $this->protectionRepo->findByResource($taxonomy, (string) $termId);
            if ($rule) {
                $this->protectionRepo->delete($rule['id']);
            }
        }

        AccessEvaluator::clearCache();
    }

    /**
     * Clean up protection rules when a term is deleted.
     */
    public function cleanupTermProtection(int $termId, int $ttId, string $taxonomy, \WP_Term $deletedTerm): void
    {
        $rule = $this->protectionRepo->findByResource($taxonomy, (string) $termId);
        if ($rule) {
            $this->protectionRepo->delete($rule['id']);
        }
    }
}

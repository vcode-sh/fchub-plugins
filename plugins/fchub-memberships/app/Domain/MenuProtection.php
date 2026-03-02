<?php

namespace FChubMemberships\Domain;

defined('ABSPATH') || exit;

use FChubMemberships\Storage\PlanRepository;
use FChubMemberships\Storage\ProtectionRuleRepository;
use FChubMemberships\Support\Constants;

class MenuProtection
{
    private AccessEvaluator $evaluator;
    private ProtectionRuleRepository $ruleRepo;

    public function __construct()
    {
        $this->evaluator = new AccessEvaluator();
        $this->ruleRepo = new ProtectionRuleRepository();
    }

    public function register(): void
    {
        // Filter menu items on frontend
        add_filter('wp_nav_menu_objects', [$this, 'filterMenuObjects'], 10, 2);

        // Admin menu editor fields
        add_action('wp_nav_menu_item_custom_fields', [$this, 'addMenuItemFields'], 10, 2);
        add_action('wp_update_nav_menu_item', [$this, 'saveMenuItemFields'], 10, 2);

        // Clean up when a menu item is deleted
        add_action('delete_post', [$this, 'cleanupMenuItemRule']);
    }

    /**
     * Filter nav menu items based on membership visibility rules.
     *
     * @param \stdClass[] $items Sorted menu items.
     * @param \stdClass   $args  Menu arguments.
     * @return \stdClass[]
     */
    public function filterMenuObjects(array $items, object $args): array
    {
        if (is_admin()) {
            return $items;
        }

        $userId = get_current_user_id();
        $filtered = [];

        foreach ($items as $item) {
            if (!$this->shouldShowItem($item, $userId)) {
                $rule = $this->getMenuItemRule((int) $item->ID);
                if ($rule) {
                    $meta = $rule['meta'] ?? [];
                    $replacementText = $meta['replacement_text'] ?? '';
                    $replacementUrl = $meta['replacement_url'] ?? '';

                    if ($replacementText || $replacementUrl) {
                        $filtered[] = $this->replaceItem($item, $rule);
                        continue;
                    }
                }

                // Remove child items when parent is hidden
                $parentId = (int) $item->ID;
                $items = array_filter($items, function ($child) use ($parentId) {
                    return (int) $child->menu_item_parent !== $parentId;
                });

                continue;
            }

            $filtered[] = $item;
        }

        return $filtered;
    }

    /**
     * Determine if a menu item should be shown to the given user.
     */
    public function shouldShowItem(object $item, int $userId): bool
    {
        $rule = $this->getMenuItemRule((int) $item->ID);
        if (!$rule) {
            return true;
        }

        $meta = $rule['meta'] ?? [];
        $visibility = $meta['visibility'] ?? 'members_only';

        switch ($visibility) {
            case 'logged_in':
                return $userId > 0;

            case 'logged_out':
                return $userId === 0;

            case 'non_members_only':
                if ($userId === 0) {
                    return true;
                }
                return !$this->userHasAnyPlanAccess($userId, $rule['plan_ids']);

            case 'specific_plans':
                if ($userId === 0) {
                    return false;
                }
                return $this->userHasSpecificPlanAccess($userId, $rule['plan_ids']);

            case 'members_only':
            default:
                if ($userId === 0) {
                    return false;
                }
                return $this->userHasAnyPlanAccess($userId, $rule['plan_ids']);
        }
    }

    /**
     * Replace a menu item's text and URL for restricted users.
     */
    public function replaceItem(object $item, array $rule): object
    {
        $meta = $rule['meta'] ?? [];

        if (!empty($meta['replacement_text'])) {
            $item->title = esc_html($meta['replacement_text']);
        }

        if (!empty($meta['replacement_url'])) {
            $item->url = esc_url($meta['replacement_url']);
        }

        $item->classes[] = 'fchub-members-only';

        return $item;
    }

    /**
     * Render membership restriction fields in the Appearance > Menus editor.
     */
    public function addMenuItemFields(int $itemId, object $item): void
    {
        $rule = $this->getMenuItemRule($itemId);
        $isRestricted = $rule !== null;
        $meta = $rule['meta'] ?? [];
        $visibility = $meta['visibility'] ?? 'members_only';
        $replacementText = $meta['replacement_text'] ?? '';
        $replacementUrl = $meta['replacement_url'] ?? '';
        $planIds = $rule['plan_ids'] ?? [];

        $planRepo = new PlanRepository();
        $plans = $planRepo->getActivePlans();

        wp_nonce_field('fchub_menu_protection_' . $itemId, '_fchub_menu_nonce_' . $itemId);
        ?>
        <p class="field-fchub-restrict description description-wide">
            <label>
                <input type="checkbox"
                       name="fchub_menu_restrict[<?php echo esc_attr($itemId); ?>]"
                       value="1"
                       class="fchub-menu-restrict-toggle"
                       data-item-id="<?php echo esc_attr($itemId); ?>"
                    <?php checked($isRestricted); ?>>
                <?php esc_html_e('Restrict by membership', 'fchub-memberships'); ?>
            </label>
        </p>
        <div class="fchub-menu-fields-<?php echo esc_attr($itemId); ?>" style="<?php echo $isRestricted ? '' : 'display:none;'; ?>padding-left:10px;">
            <p class="description description-wide">
                <label><?php esc_html_e('Visibility mode', 'fchub-memberships'); ?></label>
                <select name="fchub_menu_visibility[<?php echo esc_attr($itemId); ?>]" class="widefat fchub-menu-visibility-select" data-item-id="<?php echo esc_attr($itemId); ?>">
                    <option value="members_only" <?php selected($visibility, 'members_only'); ?>><?php esc_html_e('Members only', 'fchub-memberships'); ?></option>
                    <option value="non_members_only" <?php selected($visibility, 'non_members_only'); ?>><?php esc_html_e('Non-members only', 'fchub-memberships'); ?></option>
                    <option value="specific_plans" <?php selected($visibility, 'specific_plans'); ?>><?php esc_html_e('Specific plans', 'fchub-memberships'); ?></option>
                    <option value="logged_in" <?php selected($visibility, 'logged_in'); ?>><?php esc_html_e('Logged-in users', 'fchub-memberships'); ?></option>
                    <option value="logged_out" <?php selected($visibility, 'logged_out'); ?>><?php esc_html_e('Logged-out users', 'fchub-memberships'); ?></option>
                </select>
            </p>
            <div class="fchub-menu-plans-<?php echo esc_attr($itemId); ?>" style="<?php echo $visibility === 'specific_plans' ? '' : 'display:none;'; ?>">
                <p class="description description-wide">
                    <label><?php esc_html_e('Required plans', 'fchub-memberships'); ?></label><br>
                    <?php if (!empty($plans)): ?>
                        <?php foreach ($plans as $plan): ?>
                            <label>
                                <input type="checkbox"
                                       name="fchub_menu_plans[<?php echo esc_attr($itemId); ?>][]"
                                       value="<?php echo esc_attr($plan['id']); ?>"
                                    <?php checked(in_array($plan['id'], $planIds, false)); ?>>
                                <?php echo esc_html($plan['title']); ?>
                            </label><br>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <em><?php esc_html_e('No plans created yet.', 'fchub-memberships'); ?></em>
                    <?php endif; ?>
                </p>
            </div>
            <p class="description description-wide fchub-menu-replacement-<?php echo esc_attr($itemId); ?>" style="<?php echo in_array($visibility, ['members_only', 'specific_plans'], true) ? '' : 'display:none;'; ?>">
                <label><?php esc_html_e('Replacement text', 'fchub-memberships'); ?></label>
                <input type="text"
                       name="fchub_menu_replacement_text[<?php echo esc_attr($itemId); ?>]"
                       value="<?php echo esc_attr($replacementText); ?>"
                       class="widefat"
                       placeholder="<?php esc_attr_e('e.g., Upgrade to Access', 'fchub-memberships'); ?>">
            </p>
            <p class="description description-wide fchub-menu-replacement-<?php echo esc_attr($itemId); ?>" style="<?php echo in_array($visibility, ['members_only', 'specific_plans'], true) ? '' : 'display:none;'; ?>">
                <label><?php esc_html_e('Replacement URL', 'fchub-memberships'); ?></label>
                <input type="text"
                       name="fchub_menu_replacement_url[<?php echo esc_attr($itemId); ?>]"
                       value="<?php echo esc_attr($replacementUrl); ?>"
                       class="widefat"
                       placeholder="<?php esc_attr_e('e.g., /pricing', 'fchub-memberships'); ?>">
            </p>
        </div>
        <script>
        jQuery(function($) {
            var id = <?php echo (int) $itemId; ?>;
            $('input.fchub-menu-restrict-toggle[data-item-id="' + id + '"]').on('change', function() {
                $('.fchub-menu-fields-' + id).toggle(this.checked);
            });
            $('select.fchub-menu-visibility-select[data-item-id="' + id + '"]').on('change', function() {
                var val = $(this).val();
                $('.fchub-menu-plans-' + id).toggle(val === 'specific_plans');
                $('.fchub-menu-replacement-' + id).toggle(val === 'members_only' || val === 'specific_plans');
            });
        });
        </script>
        <?php
    }

    /**
     * Save menu item protection fields.
     */
    public function saveMenuItemFields(int $menuId, int $menuItemId): void
    {
        if (!isset($_POST['_fchub_menu_nonce_' . $menuItemId])
            || !wp_verify_nonce($_POST['_fchub_menu_nonce_' . $menuItemId], 'fchub_menu_protection_' . $menuItemId)
        ) {
            return;
        }

        if (!current_user_can('edit_theme_options')) {
            return;
        }

        $isRestricted = !empty($_POST['fchub_menu_restrict'][$menuItemId]);

        if ($isRestricted) {
            $visibility = sanitize_text_field($_POST['fchub_menu_visibility'][$menuItemId] ?? 'members_only');
            $allowedVisibilities = ['members_only', 'non_members_only', 'specific_plans', 'logged_in', 'logged_out'];
            if (!in_array($visibility, $allowedVisibilities, true)) {
                $visibility = 'members_only';
            }

            $planIds = [];
            if ($visibility === 'specific_plans' && isset($_POST['fchub_menu_plans'][$menuItemId])) {
                $planIds = array_map('intval', (array) $_POST['fchub_menu_plans'][$menuItemId]);
            }

            $replacementText = sanitize_text_field($_POST['fchub_menu_replacement_text'][$menuItemId] ?? '');
            $replacementUrl = sanitize_text_field($_POST['fchub_menu_replacement_url'][$menuItemId] ?? '');

            $meta = [
                'visibility' => $visibility,
                'replacement_text' => $replacementText,
                'replacement_url' => $replacementUrl,
            ];

            $this->ruleRepo->createOrUpdate('menu_item', (string) $menuItemId, [
                'plan_ids'        => $planIds,
                'protection_mode' => Constants::PROTECTION_MODE_EXPLICIT,
                'meta'            => $meta,
            ]);
        } else {
            $rule = $this->ruleRepo->findByResource('menu_item', (string) $menuItemId);
            if ($rule) {
                $this->ruleRepo->delete($rule['id']);
            }
        }

        AccessEvaluator::clearCache();
    }

    /**
     * Get the protection rule for a menu item.
     */
    public function getMenuItemRule(int $itemId): ?array
    {
        return $this->ruleRepo->findByResource('menu_item', (string) $itemId);
    }

    /**
     * Clean up protection rules when a menu item post is deleted.
     */
    public function cleanupMenuItemRule(int $postId): void
    {
        if (get_post_type($postId) !== 'nav_menu_item') {
            return;
        }

        $rule = $this->ruleRepo->findByResource('menu_item', (string) $postId);
        if ($rule) {
            $this->ruleRepo->delete($rule['id']);
        }
    }

    /**
     * Check if the user has an active grant for any membership plan.
     */
    private function userHasAnyPlanAccess(int $userId, array $planIds): bool
    {
        $grantRepo = new \FChubMemberships\Storage\GrantRepository();
        $grants = $grantRepo->getByUserId($userId, ['status' => Constants::STATUS_ACTIVE]);

        if (empty($grants)) {
            return false;
        }

        // If no specific plan IDs are configured, any active grant qualifies
        if (empty($planIds)) {
            return true;
        }

        foreach ($grants as $grant) {
            if ($grant['plan_id'] !== null && in_array($grant['plan_id'], $planIds, false)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if the user has an active grant for one of the specified plans.
     */
    private function userHasSpecificPlanAccess(int $userId, array $planIds): bool
    {
        if (empty($planIds)) {
            return false;
        }

        $grantRepo = new \FChubMemberships\Storage\GrantRepository();
        $grants = $grantRepo->getByUserId($userId, ['status' => Constants::STATUS_ACTIVE]);

        foreach ($grants as $grant) {
            if ($grant['plan_id'] !== null && in_array($grant['plan_id'], $planIds, false)) {
                return true;
            }
        }

        return false;
    }
}

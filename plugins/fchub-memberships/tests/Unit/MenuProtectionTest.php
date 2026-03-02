<?php

namespace FChubMemberships\Tests\Unit;

use FChubMemberships\Tests\Support\TestCase;
use FChubMemberships\Tests\Support\MockBuilder;

/**
 * Tests for MenuProtection logic.
 *
 * Since MenuProtection relies on GrantRepository and ProtectionRuleRepository
 * which need $wpdb, these tests simulate the shouldShowItem() logic in-memory.
 */
class MenuProtectionTest extends TestCase
{
    /** @var array In-memory protection rules keyed by menu item ID */
    private array $rules = [];

    /** @var array In-memory grants */
    private array $grants = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->rules = [];
        $this->grants = [];
    }

    // ── Helpers ──────────────────────────────────────────────────

    private function addMenuRule(int $itemId, string $visibility = 'members_only', array $planIds = [], array $meta = []): void
    {
        $this->rules[$itemId] = MockBuilder::protectionRule()
            ->forMenuItem($itemId)
            ->withPlans($planIds)
            ->withMeta(array_merge([
                'visibility' => $visibility,
                'replacement_text' => $meta['replacement_text'] ?? '',
                'replacement_url' => $meta['replacement_url'] ?? '',
            ], $meta))
            ->build();
    }

    private function addGrant(int $userId, int $planId): void
    {
        $this->grants[] = MockBuilder::grant()
            ->forUser($userId)
            ->forPlan($planId)
            ->active()
            ->build();
    }

    private function getMenuItemRule(int $itemId): ?array
    {
        return $this->rules[$itemId] ?? null;
    }

    private function userHasAnyPlanAccess(int $userId, array $requiredPlanIds): bool
    {
        $userGrants = array_filter($this->grants, fn($g) => $g['user_id'] === $userId && $g['status'] === 'active');
        if (empty($userGrants)) {
            return false;
        }
        if (empty($requiredPlanIds)) {
            return true;
        }
        foreach ($userGrants as $grant) {
            if ($grant['plan_id'] !== null && in_array($grant['plan_id'], $requiredPlanIds, false)) {
                return true;
            }
        }
        return false;
    }

    private function userHasSpecificPlanAccess(int $userId, array $planIds): bool
    {
        if (empty($planIds)) {
            return false;
        }
        return $this->userHasAnyPlanAccess($userId, $planIds);
    }

    /**
     * Simulate shouldShowItem() from MenuProtection.
     */
    private function shouldShowItem(int $itemId, int $userId): bool
    {
        $rule = $this->getMenuItemRule($itemId);
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
     * Simulate replaceItem() logic.
     */
    private function replaceItem(object $item, array $rule): object
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

    private function makeMenuItem(int $id, string $title = 'Menu Item', string $url = '/test', int $parentId = 0): object
    {
        $item = new \stdClass();
        $item->ID = $id;
        $item->title = $title;
        $item->url = $url;
        $item->menu_item_parent = $parentId;
        $item->classes = [];
        return $item;
    }

    // ── Tests ───────────────────────────────────────────────────

    public function testMembersOnlyHidesFromNonMembers(): void
    {
        $this->addMenuRule(100, 'members_only');

        $this->assertFalse($this->shouldShowItem(100, 0), 'Logged-out user should not see members_only item');
        $this->assertFalse($this->shouldShowItem(100, 5), 'Logged-in non-member should not see members_only item');
    }

    public function testMembersOnlyShowsToMembers(): void
    {
        $this->addMenuRule(100, 'members_only');
        $this->addGrant(1, 1);

        $this->assertTrue($this->shouldShowItem(100, 1), 'Member should see members_only item');
    }

    public function testNonMembersOnlyHidesFromMembers(): void
    {
        $this->addMenuRule(100, 'non_members_only');
        $this->addGrant(1, 1);

        $this->assertFalse($this->shouldShowItem(100, 1), 'Member should not see non_members_only item');
    }

    public function testNonMembersOnlyShowsToNonMembers(): void
    {
        $this->addMenuRule(100, 'non_members_only');

        $this->assertTrue($this->shouldShowItem(100, 0), 'Logged-out user should see non_members_only item');
        $this->assertTrue($this->shouldShowItem(100, 5), 'Non-member should see non_members_only item');
    }

    public function testLoggedInModeChecksAuthStatus(): void
    {
        $this->addMenuRule(100, 'logged_in');

        $this->assertFalse($this->shouldShowItem(100, 0), 'Logged-out user should not see logged_in item');
        $this->assertTrue($this->shouldShowItem(100, 1), 'Logged-in user should see logged_in item');
        $this->assertTrue($this->shouldShowItem(100, 99), 'Any logged-in user should see logged_in item');
    }

    public function testLoggedOutModeChecksAuthStatus(): void
    {
        $this->addMenuRule(100, 'logged_out');

        $this->assertTrue($this->shouldShowItem(100, 0), 'Logged-out user should see logged_out item');
        $this->assertFalse($this->shouldShowItem(100, 1), 'Logged-in user should not see logged_out item');
    }

    public function testSpecificPlansChecksUserPlans(): void
    {
        $this->addMenuRule(100, 'specific_plans', [1, 2]);
        $this->addGrant(1, 1);  // User 1 has plan 1
        $this->addGrant(2, 3);  // User 2 has plan 3 (not required)

        $this->assertTrue($this->shouldShowItem(100, 1), 'User with required plan should see item');
        $this->assertFalse($this->shouldShowItem(100, 2), 'User without required plan should not see item');
        $this->assertFalse($this->shouldShowItem(100, 0), 'Logged-out user should not see specific_plans item');
    }

    public function testSpecificPlansWithEmptyPlanIds(): void
    {
        $this->addMenuRule(100, 'specific_plans', []);
        $this->addGrant(1, 1);

        $this->assertFalse($this->shouldShowItem(100, 1), 'Empty plan IDs in specific_plans should deny access');
    }

    public function testReplacementTextApplied(): void
    {
        $this->addMenuRule(100, 'members_only', [], [
            'replacement_text' => 'Upgrade Now',
            'replacement_url' => '/pricing',
        ]);

        $item = $this->makeMenuItem(100, 'Premium Content', '/premium');
        $rule = $this->rules[100];

        $replaced = $this->replaceItem($item, $rule);

        $this->assertEquals(esc_html('Upgrade Now'), $replaced->title);
    }

    public function testReplacementUrlApplied(): void
    {
        $this->addMenuRule(100, 'members_only', [], [
            'replacement_url' => 'http://localhost/pricing',
        ]);

        $item = $this->makeMenuItem(100, 'Premium', '/premium');
        $rule = $this->rules[100];

        $replaced = $this->replaceItem($item, $rule);

        $this->assertStringContainsString('pricing', $replaced->url);
    }

    public function testCssClassAddedToProtectedItems(): void
    {
        $this->addMenuRule(100, 'members_only', [], ['replacement_text' => 'Upgrade']);

        $item = $this->makeMenuItem(100, 'Premium');
        $rule = $this->rules[100];

        $replaced = $this->replaceItem($item, $rule);

        $this->assertContains('fchub-members-only', $replaced->classes);
    }

    public function testUnprotectedItemsPassThrough(): void
    {
        // No rule added for item 200
        $this->assertTrue($this->shouldShowItem(200, 0), 'Unprotected item should show for anyone');
        $this->assertTrue($this->shouldShowItem(200, 1), 'Unprotected item should show for logged-in');
    }

    public function testMembersOnlyWithSpecificPlansFilter(): void
    {
        $this->addMenuRule(100, 'members_only', [2, 3]);
        $this->addGrant(1, 1);  // Plan 1 - not in required list
        $this->addGrant(2, 2);  // Plan 2 - in required list

        $this->assertFalse($this->shouldShowItem(100, 1), 'User with wrong plan should not see item');
        $this->assertTrue($this->shouldShowItem(100, 2), 'User with correct plan should see item');
    }

    public function testMembersOnlyNoPlansRequiredAnyGrantWorks(): void
    {
        $this->addMenuRule(100, 'members_only', []);
        $this->addGrant(1, 99);  // Any plan

        $this->assertTrue($this->shouldShowItem(100, 1), 'Any active grant should satisfy members_only with no plan filter');
    }

    public function testFilterMenuObjectsSkipsChildrenOfHiddenParent(): void
    {
        // Simulate the filterMenuObjects child-removal logic
        $parent = $this->makeMenuItem(1, 'Parent');
        $child = $this->makeMenuItem(2, 'Child', '/child', 1);
        $unrelated = $this->makeMenuItem(3, 'Other');

        $items = [$parent, $child, $unrelated];

        // Parent is restricted
        $this->addMenuRule(1, 'members_only');

        // Simulate filtering: remove hidden parent + its children
        $filtered = [];
        $hiddenParentIds = [];

        foreach ($items as $item) {
            if (!$this->shouldShowItem((int) $item->ID, 0)) {
                $hiddenParentIds[] = (int) $item->ID;
                continue;
            }

            if (in_array((int) $item->menu_item_parent, $hiddenParentIds, true)) {
                continue;
            }

            $filtered[] = $item;
        }

        $this->assertCount(1, $filtered, 'Only unrelated item should remain');
        $this->assertEquals(3, $filtered[0]->ID, 'Unrelated item should pass through');
    }

    public function testVisibilityDefaultsToMembersOnly(): void
    {
        $rule = MockBuilder::protectionRule()
            ->forMenuItem(100)
            ->withMeta([]) // no visibility set
            ->build();

        $visibility = $rule['meta']['visibility'] ?? 'members_only';
        $this->assertEquals('members_only', $visibility);
    }
}

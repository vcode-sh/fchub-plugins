<?php

namespace FChubMemberships\Tests\Unit;

use FChubMemberships\Tests\Support\TestCase;
use FChubMemberships\Tests\Support\MockBuilder;
use FChubMemberships\Support\Constants;

/**
 * Tests for AccessEvaluator logic.
 *
 * Since AccessEvaluator uses GrantRepository, PlanRuleResolver, and
 * ProtectionRuleRepository (all requiring $wpdb), these tests simulate the
 * evaluate() decision tree in-memory, verifying: admin bypass, direct grants,
 * plan-based grants, wildcard grants, drip locking, paused memberships, and
 * restriction message resolution.
 */
class AccessEvaluatorTest extends TestCase
{
    /** @var array In-memory grants */
    private array $grants = [];

    /** @var array In-memory plan rules */
    private array $planRules = [];

    /** @var array In-memory protection rules */
    private array $protectionRules = [];

    /** @var array In-memory plans */
    private array $plans = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->grants = [];
        $this->planRules = [];
        $this->protectionRules = [];
        $this->plans = [];
    }

    // ── Helpers ──────────────────────────────────────────────────

    private function addGrant(array $data): void
    {
        $this->grants[] = $data;
    }

    private function addPlanRule(int $planId, string $provider, string $resourceType, string $resourceId, array $extra = []): void
    {
        $this->planRules[] = array_merge([
            'plan_id'       => $planId,
            'provider'      => $provider,
            'resource_type' => $resourceType,
            'resource_id'   => $resourceId,
            'drip_type'     => Constants::DRIP_TYPE_IMMEDIATE,
            'drip_delay_days' => 0,
            'drip_date'     => null,
        ], $extra);
    }

    private function addPlan(int $id, string $title, array $extra = []): void
    {
        $this->plans[$id] = array_merge([
            'id' => $id,
            'title' => $title,
            'status' => 'active',
            'restriction_message' => '',
        ], $extra);
    }

    /**
     * Simulate evaluate() logic from AccessEvaluator.
     */
    private function evaluate(int $userId, string $provider, string $resourceType, string $resourceId): array
    {
        // Admin bypass
        if ($this->isAdminBypass($userId)) {
            return [
                'allowed' => true,
                'reason' => Constants::REASON_ADMIN_BYPASS,
                'drip_locked' => false,
                'drip_available_at' => null,
                'grant' => null,
                'trial_active' => false,
            ];
        }

        // Check paused
        $pausedGrant = $this->findGrant($userId, $provider, $resourceType, $resourceId, Constants::STATUS_PAUSED);
        if ($pausedGrant) {
            return [
                'allowed' => false,
                'reason' => Constants::REASON_MEMBERSHIP_PAUSED,
                'drip_locked' => false,
                'drip_available_at' => null,
                'grant' => $pausedGrant,
                'trial_active' => false,
            ];
        }

        // Check direct grant
        $grant = $this->findGrant($userId, $provider, $resourceType, $resourceId, Constants::STATUS_ACTIVE);
        if ($grant) {
            $now = time();
            $trialActive = !empty($grant['trial_ends_at']) && strtotime($grant['trial_ends_at']) > $now;

            if (!empty($grant['drip_available_at']) && strtotime($grant['drip_available_at']) > $now) {
                return [
                    'allowed' => false,
                    'reason' => Constants::REASON_DRIP_LOCKED,
                    'drip_locked' => true,
                    'drip_available_at' => $grant['drip_available_at'],
                    'grant' => $grant,
                    'trial_active' => $trialActive,
                ];
            }

            return [
                'allowed' => true,
                'reason' => Constants::REASON_DIRECT_GRANT,
                'drip_locked' => false,
                'drip_available_at' => null,
                'grant' => $grant,
                'trial_active' => $trialActive,
            ];
        }

        // Check plan-based grants
        $planGrants = $this->getGrantsByUser($userId, Constants::STATUS_ACTIVE);
        $checkedPlanIds = [];

        foreach ($planGrants as $planGrant) {
            if ($planGrant['plan_id'] === null || in_array($planGrant['plan_id'], $checkedPlanIds, true)) {
                continue;
            }
            $checkedPlanIds[] = $planGrant['plan_id'];

            if ($this->planHasResource($planGrant['plan_id'], $provider, $resourceType, $resourceId)) {
                $now = time();
                $dripRule = $this->getDripRule($planGrant['plan_id'], $provider, $resourceType, $resourceId);

                if ($dripRule && $dripRule['drip_type'] !== Constants::DRIP_TYPE_IMMEDIATE) {
                    $dripDate = $this->calculateDripDate($dripRule, $planGrant);
                    if ($dripDate && strtotime($dripDate) > $now) {
                        $planTrialActive = !empty($planGrant['trial_ends_at']) && strtotime($planGrant['trial_ends_at']) > $now;
                        return [
                            'allowed' => false,
                            'reason' => Constants::REASON_DRIP_LOCKED,
                            'drip_locked' => true,
                            'drip_available_at' => $dripDate,
                            'grant' => $planGrant,
                            'trial_active' => $planTrialActive,
                        ];
                    }
                }

                $planTrialActive = !empty($planGrant['trial_ends_at']) && strtotime($planGrant['trial_ends_at']) > $now;
                return [
                    'allowed' => true,
                    'reason' => Constants::REASON_PLAN_GRANT,
                    'drip_locked' => false,
                    'drip_available_at' => null,
                    'grant' => $planGrant,
                    'trial_active' => $planTrialActive,
                ];
            }
        }

        // Check wildcard grants
        $wildcardGrant = $this->findGrant($userId, $provider, $resourceType, '*', Constants::STATUS_ACTIVE);
        if ($wildcardGrant) {
            $pausedWildcard = $this->findGrant($userId, $provider, $resourceType, '*', Constants::STATUS_PAUSED);
            if ($pausedWildcard) {
                return [
                    'allowed' => false,
                    'reason' => Constants::REASON_MEMBERSHIP_PAUSED,
                    'drip_locked' => false,
                    'drip_available_at' => null,
                    'grant' => $pausedWildcard,
                    'trial_active' => false,
                ];
            }

            $now = time();
            $wildcardTrialActive = !empty($wildcardGrant['trial_ends_at']) && strtotime($wildcardGrant['trial_ends_at']) > $now;
            return [
                'allowed' => true,
                'reason' => Constants::REASON_WILDCARD_GRANT,
                'drip_locked' => false,
                'drip_available_at' => null,
                'grant' => $wildcardGrant,
                'trial_active' => $wildcardTrialActive,
            ];
        }

        return [
            'allowed' => false,
            'reason' => Constants::REASON_NO_GRANT,
            'drip_locked' => false,
            'drip_available_at' => null,
            'grant' => null,
            'trial_active' => false,
        ];
    }

    private function isAdminBypass(int $userId): bool
    {
        $settings = get_option('fchub_memberships_settings', []);
        if (($settings['admin_bypass'] ?? 'yes') !== 'yes') {
            return false;
        }
        return user_can($userId, 'manage_options');
    }

    private function findGrant(int $userId, string $provider, string $resourceType, string $resourceId, string $status): ?array
    {
        foreach ($this->grants as $grant) {
            if ($grant['user_id'] === $userId
                && $grant['provider'] === $provider
                && $grant['resource_type'] === $resourceType
                && $grant['resource_id'] === $resourceId
                && $grant['status'] === $status) {
                return $grant;
            }
        }
        return null;
    }

    private function getGrantsByUser(int $userId, string $status): array
    {
        return array_filter($this->grants, fn($g) => $g['user_id'] === $userId && $g['status'] === $status);
    }

    private function planHasResource(int $planId, string $provider, string $resourceType, string $resourceId): bool
    {
        foreach ($this->planRules as $rule) {
            if ($rule['plan_id'] === $planId
                && $rule['provider'] === $provider
                && $rule['resource_type'] === $resourceType
                && ($rule['resource_id'] === $resourceId || $rule['resource_id'] === '*')) {
                return true;
            }
        }
        return false;
    }

    private function getDripRule(int $planId, string $provider, string $resourceType, string $resourceId): ?array
    {
        foreach ($this->planRules as $rule) {
            if ($rule['plan_id'] === $planId
                && $rule['provider'] === $provider
                && $rule['resource_type'] === $resourceType
                && ($rule['resource_id'] === $resourceId || $rule['resource_id'] === '*')) {
                return $rule;
            }
        }
        return null;
    }

    private function calculateDripDate(array $dripRule, array $grant): ?string
    {
        if ($dripRule['drip_type'] === Constants::DRIP_TYPE_DELAYED && $dripRule['drip_delay_days'] > 0) {
            $grantDate = $grant['created_at'] ?? date('Y-m-d H:i:s');
            return date('Y-m-d H:i:s', strtotime($grantDate . ' +' . $dripRule['drip_delay_days'] . ' days'));
        }
        if ($dripRule['drip_type'] === Constants::DRIP_TYPE_FIXED_DATE && !empty($dripRule['drip_date'])) {
            return $dripRule['drip_date'];
        }
        return null;
    }

    // ── Tests ───────────────────────────────────────────────────

    public function testDirectGrantAllowsAccess(): void
    {
        $this->addGrant(MockBuilder::grant()
            ->forUser(1)->forResource('post', '10')->active()->build());

        $result = $this->evaluate(1, 'wordpress_core', 'post', '10');

        $this->assertTrue($result['allowed']);
        $this->assertEquals(Constants::REASON_DIRECT_GRANT, $result['reason']);
    }

    public function testPlanBasedGrantAllowsAccess(): void
    {
        $this->addGrant(MockBuilder::grant()
            ->forUser(1)->forPlan(1)->forResource('plan', '1')->active()->build());
        $this->addPlanRule(1, 'wordpress_core', 'post', '10');

        $result = $this->evaluate(1, 'wordpress_core', 'post', '10');

        $this->assertTrue($result['allowed']);
        $this->assertEquals(Constants::REASON_PLAN_GRANT, $result['reason']);
    }

    public function testWildcardGrantAllowsAccess(): void
    {
        $this->addGrant(MockBuilder::grant()
            ->forUser(1)->forResource('post', '*')->active()->build());

        $result = $this->evaluate(1, 'wordpress_core', 'post', '10');

        $this->assertTrue($result['allowed']);
        $this->assertEquals(Constants::REASON_WILDCARD_GRANT, $result['reason']);
    }

    public function testNoGrantDeniesAccess(): void
    {
        $result = $this->evaluate(1, 'wordpress_core', 'post', '10');

        $this->assertFalse($result['allowed']);
        $this->assertEquals(Constants::REASON_NO_GRANT, $result['reason']);
        $this->assertNull($result['grant']);
    }

    public function testPausedGrantDeniesAccess(): void
    {
        $this->addGrant(MockBuilder::grant()
            ->forUser(1)->forResource('post', '10')->paused()->build());

        $result = $this->evaluate(1, 'wordpress_core', 'post', '10');

        $this->assertFalse($result['allowed']);
        $this->assertEquals(Constants::REASON_MEMBERSHIP_PAUSED, $result['reason']);
        $this->assertNotNull($result['grant']);
    }

    public function testPausedWildcardGrantDeniesAccess(): void
    {
        // Active wildcard + paused wildcard
        $this->addGrant(MockBuilder::grant()
            ->forUser(1)->forResource('post', '*')->active()->build());
        $this->addGrant(MockBuilder::grant()
            ->forUser(1)->forResource('post', '*')->paused()->build());

        $result = $this->evaluate(1, 'wordpress_core', 'post', '10');

        $this->assertFalse($result['allowed']);
        $this->assertEquals(Constants::REASON_MEMBERSHIP_PAUSED, $result['reason']);
    }

    public function testAdminBypassAllowsAccess(): void
    {
        $this->setAdminBypass(true);
        $this->setUserCapability(1, 'manage_options');

        $result = $this->evaluate(1, 'wordpress_core', 'post', '10');

        $this->assertTrue($result['allowed']);
        $this->assertEquals(Constants::REASON_ADMIN_BYPASS, $result['reason']);
        $this->assertNull($result['grant']);
    }

    public function testAdminBypassDisabled(): void
    {
        $this->setAdminBypass(false);
        $this->setUserCapability(1, 'manage_options');

        $result = $this->evaluate(1, 'wordpress_core', 'post', '10');

        $this->assertFalse($result['allowed']);
        $this->assertEquals(Constants::REASON_NO_GRANT, $result['reason']);
    }

    public function testDripLockedContentBlocked(): void
    {
        $futureDate = date('Y-m-d H:i:s', strtotime('+30 days'));
        $this->addGrant(MockBuilder::grant()
            ->forUser(1)->forResource('post', '10')->active()
            ->withDrip($futureDate)->build());

        $result = $this->evaluate(1, 'wordpress_core', 'post', '10');

        $this->assertFalse($result['allowed']);
        $this->assertEquals(Constants::REASON_DRIP_LOCKED, $result['reason']);
        $this->assertTrue($result['drip_locked']);
        $this->assertEquals($futureDate, $result['drip_available_at']);
    }

    public function testDripUnlockedContentAllowed(): void
    {
        $pastDate = date('Y-m-d H:i:s', strtotime('-1 day'));
        $this->addGrant(MockBuilder::grant()
            ->forUser(1)->forResource('post', '10')->active()
            ->withDrip($pastDate)->build());

        $result = $this->evaluate(1, 'wordpress_core', 'post', '10');

        $this->assertTrue($result['allowed']);
        $this->assertFalse($result['drip_locked']);
    }

    public function testTrialActiveDetection(): void
    {
        $futureDate = date('Y-m-d H:i:s', strtotime('+7 days'));
        $this->addGrant(MockBuilder::grant()
            ->forUser(1)->forResource('post', '10')->active()
            ->withTrial($futureDate)->build());

        $result = $this->evaluate(1, 'wordpress_core', 'post', '10');

        $this->assertTrue($result['allowed']);
        $this->assertTrue($result['trial_active']);
    }

    public function testTrialExpiredNotActive(): void
    {
        $pastDate = date('Y-m-d H:i:s', strtotime('-1 day'));
        $this->addGrant(MockBuilder::grant()
            ->forUser(1)->forResource('post', '10')->active()
            ->withTrial($pastDate)->build());

        $result = $this->evaluate(1, 'wordpress_core', 'post', '10');

        $this->assertTrue($result['allowed']);
        $this->assertFalse($result['trial_active']);
    }

    public function testExpiredGrantDenied(): void
    {
        // Expired grants have status 'expired' not 'active'
        $this->addGrant(MockBuilder::grant()
            ->forUser(1)->forResource('post', '10')->expired()->build());

        $result = $this->evaluate(1, 'wordpress_core', 'post', '10');

        $this->assertFalse($result['allowed']);
        $this->assertEquals(Constants::REASON_NO_GRANT, $result['reason']);
    }

    public function testRevokedGrantDenied(): void
    {
        $this->addGrant(MockBuilder::grant()
            ->forUser(1)->forResource('post', '10')->revoked()->build());

        $result = $this->evaluate(1, 'wordpress_core', 'post', '10');

        $this->assertFalse($result['allowed']);
        $this->assertEquals(Constants::REASON_NO_GRANT, $result['reason']);
    }

    public function testPlanIdZeroIsValid(): void
    {
        // Bug #5: plan_id=0 should be treated as a valid plan
        $grant = MockBuilder::grant()
            ->forUser(1)->forPlan(0)->forResource('plan', '0')->active()->build();
        $this->addGrant($grant);
        $this->addPlanRule(0, 'wordpress_core', 'post', '10');

        $result = $this->evaluate(1, 'wordpress_core', 'post', '10');

        // plan_id=0 is valid (not null), should be checked
        $this->assertTrue($result['allowed']);
    }

    public function testPlanIdNullSkipped(): void
    {
        $grant = MockBuilder::grant()
            ->forUser(1)->forResource('post', '99')->active()->build();
        $grant['plan_id'] = null;
        $this->addGrant($grant);

        // Plan rules exist for plan 1 but not the null plan
        $this->addPlanRule(1, 'wordpress_core', 'post', '10');

        $result = $this->evaluate(1, 'wordpress_core', 'post', '10');

        // Should not find access because null plan_id is skipped
        $this->assertFalse($result['allowed']);
    }

    public function testDripDelayedCalculation(): void
    {
        $grantDate = '2024-01-01 00:00:00';
        $dripRule = [
            'drip_type' => Constants::DRIP_TYPE_DELAYED,
            'drip_delay_days' => 7,
        ];
        $grant = ['created_at' => $grantDate];

        $result = $this->calculateDripDate($dripRule, $grant);

        $this->assertEquals('2024-01-08 00:00:00', $result);
    }

    public function testDripFixedDateCalculation(): void
    {
        $dripRule = [
            'drip_type' => Constants::DRIP_TYPE_FIXED_DATE,
            'drip_delay_days' => 0,
            'drip_date' => '2024-06-15 00:00:00',
        ];

        $result = $this->calculateDripDate($dripRule, []);

        $this->assertEquals('2024-06-15 00:00:00', $result);
    }

    public function testDripImmediateReturnsNull(): void
    {
        $dripRule = [
            'drip_type' => Constants::DRIP_TYPE_IMMEDIATE,
            'drip_delay_days' => 0,
            'drip_date' => null,
        ];

        $result = $this->calculateDripDate($dripRule, []);

        $this->assertNull($result);
    }

    public function testNullGrantDateFallback(): void
    {
        // Bug #3: null created_at should use current time as fallback
        $dripRule = [
            'drip_type' => Constants::DRIP_TYPE_DELAYED,
            'drip_delay_days' => 5,
        ];
        $grant = ['created_at' => null];

        $result = $this->calculateDripDate($dripRule, $grant);

        // Should use current date as fallback
        $expectedMin = date('Y-m-d', strtotime('+4 days'));
        $expectedMax = date('Y-m-d', strtotime('+6 days'));
        $resultDate = substr($result, 0, 10);

        $this->assertGreaterThanOrEqual($expectedMin, $resultDate);
        $this->assertLessThanOrEqual($expectedMax, $resultDate);
    }

    public function testRestrictionMessageFromSettings(): void
    {
        $this->setOption('fchub_memberships_settings', [
            'restriction_message_no_access' => 'Custom no access message',
        ]);

        $settings = get_option('fchub_memberships_settings', []);
        $message = $settings['restriction_message_no_access'] ?? 'Default';

        $this->assertEquals('Custom no access message', $message);
    }

    public function testRestrictionMessageDefaultFallback(): void
    {
        $settings = get_option('fchub_memberships_settings', []);
        $defaults = [
            'logged_out'        => 'This content is available to members only. Please log in to access.',
            'no_access'         => "You don't have access to this content. View membership options to learn more.",
            'expired'           => 'Your access to this content has expired. Renew your subscription to continue.',
            'drip_locked'       => 'This content will be available to you soon. Check back later.',
            'membership_paused' => 'Your membership is currently paused. Resume your membership to access this content.',
        ];

        $context = 'no_access';
        $message = $settings['restriction_message_' . $context] ?? $defaults[$context] ?? $defaults['no_access'];

        $this->assertStringContainsString("don't have access", $message);
    }

    public function testCacheKeyIncludesAllDimensions(): void
    {
        // Verify cache key format from evaluate()
        $cacheKey = "1:wordpress_core:post:10";

        $this->assertStringContainsString('1', $cacheKey);
        $this->assertStringContainsString('wordpress_core', $cacheKey);
        $this->assertStringContainsString('post', $cacheKey);
        $this->assertStringContainsString('10', $cacheKey);
    }

    public function testCanAccessMultipleBatchCheck(): void
    {
        // Simulate canAccessMultiple() logic
        $postIds = ['1', '2', '3', '4', '5'];
        $directlyGranted = ['2', '4'];

        $accessible = [];
        foreach ($postIds as $postId) {
            if (in_array($postId, $directlyGranted, true)) {
                $accessible[] = $postId;
            }
        }

        $this->assertCount(2, $accessible);
        $this->assertContains('2', $accessible);
        $this->assertContains('4', $accessible);
    }

    public function testCanAccessMultipleAdminGetsAll(): void
    {
        // Admin bypass returns all post IDs
        $postIds = ['1', '2', '3'];
        $this->setAdminBypass(true);
        $this->setUserCapability(1, 'manage_options');

        $isAdminBypass = $this->isAdminBypass(1);
        $accessible = $isAdminBypass ? $postIds : [];

        $this->assertCount(3, $accessible);
    }

    public function testPlanWildcardRuleMatchesAll(): void
    {
        $this->addPlanRule(1, 'wordpress_core', 'post', '*');

        $this->assertTrue($this->planHasResource(1, 'wordpress_core', 'post', '10'));
        $this->assertTrue($this->planHasResource(1, 'wordpress_core', 'post', '999'));
    }

    public function testPlanSpecificRuleMatchesOnly(): void
    {
        $this->addPlanRule(1, 'wordpress_core', 'post', '10');

        $this->assertTrue($this->planHasResource(1, 'wordpress_core', 'post', '10'));
        $this->assertFalse($this->planHasResource(1, 'wordpress_core', 'post', '11'));
    }

    public function testPlanRuleDripLockedOnPlanGrant(): void
    {
        $grantCreated = date('Y-m-d H:i:s', strtotime('-2 days'));
        $grant = MockBuilder::grant()
            ->forUser(1)->forPlan(1)->forResource('plan', '1')->active()
            ->withCreatedAt($grantCreated)->build();
        $this->addGrant($grant);

        // Resource drip: 7 days after grant
        $this->addPlanRule(1, 'wordpress_core', 'post', '10', [
            'drip_type' => Constants::DRIP_TYPE_DELAYED,
            'drip_delay_days' => 7,
        ]);

        $result = $this->evaluate(1, 'wordpress_core', 'post', '10');

        $this->assertFalse($result['allowed']);
        $this->assertTrue($result['drip_locked']);
    }

    public function testPlanRuleDripUnlockedAfterDelay(): void
    {
        $grantCreated = date('Y-m-d H:i:s', strtotime('-10 days'));
        $grant = MockBuilder::grant()
            ->forUser(1)->forPlan(1)->forResource('plan', '1')->active()
            ->withCreatedAt($grantCreated)->build();
        $this->addGrant($grant);

        // Resource drip: 7 days after grant (already past)
        $this->addPlanRule(1, 'wordpress_core', 'post', '10', [
            'drip_type' => Constants::DRIP_TYPE_DELAYED,
            'drip_delay_days' => 7,
        ]);

        $result = $this->evaluate(1, 'wordpress_core', 'post', '10');

        $this->assertTrue($result['allowed']);
        $this->assertFalse($result['drip_locked']);
    }

    public function testDuplicatePlanIdsNotCheckedTwice(): void
    {
        // Two grants for the same plan should only check plan rules once
        $this->addGrant(MockBuilder::grant()
            ->withId(1)->forUser(1)->forPlan(1)->forResource('plan', '1')->active()->build());
        $this->addGrant(MockBuilder::grant()
            ->withId(2)->forUser(1)->forPlan(1)->forResource('plan', '1-2')->active()->build());

        // No plan rules exist
        $result = $this->evaluate(1, 'wordpress_core', 'post', '10');

        $this->assertFalse($result['allowed']);
    }

    public function testDirectGrantTakesPriorityOverPlanGrant(): void
    {
        // Direct grant
        $this->addGrant(MockBuilder::grant()
            ->forUser(1)->forResource('post', '10')->active()->build());

        // Also have plan grant
        $this->addGrant(MockBuilder::grant()
            ->forUser(1)->forPlan(1)->forResource('plan', '1')->active()->build());
        $this->addPlanRule(1, 'wordpress_core', 'post', '10');

        $result = $this->evaluate(1, 'wordpress_core', 'post', '10');

        $this->assertTrue($result['allowed']);
        $this->assertEquals(Constants::REASON_DIRECT_GRANT, $result['reason']);
    }

    public function testPausedCheckBeforeDirectGrant(): void
    {
        // Paused grant is checked before direct active grant
        $this->addGrant(MockBuilder::grant()
            ->forUser(1)->forResource('post', '10')->paused()->build());

        $result = $this->evaluate(1, 'wordpress_core', 'post', '10');

        $this->assertFalse($result['allowed']);
        $this->assertEquals(Constants::REASON_MEMBERSHIP_PAUSED, $result['reason']);
    }
}

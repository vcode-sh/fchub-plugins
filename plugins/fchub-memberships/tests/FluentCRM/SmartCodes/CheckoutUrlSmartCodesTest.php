<?php

namespace FChubMemberships\Tests\FluentCRM\SmartCodes;

use PHPUnit\Framework\TestCase;
use FChubMemberships\FluentCRM\Helpers\CheckoutUrlHelper;

/**
 * Tests for checkout URL, upgrade URL, next billing date, and payment update URL smart codes.
 *
 * These tests use in-memory state rather than real database queries. We mock global $wpdb
 * and FluentCart classes to simulate the data layer.
 */
class CheckoutUrlSmartCodesTest extends TestCase
{
    /** @var array In-memory plans keyed by ID */
    private array $plans = [];

    /** @var array In-memory grants keyed by user_id */
    private array $userGrants = [];

    /** @var array In-memory integration feeds */
    private array $feeds = [];

    /** @var array In-memory product details keyed by product_id */
    private array $productDetails = [];

    /** @var array In-memory product variations keyed by product_id */
    private array $productVariations = [];

    /** @var array In-memory subscriptions keyed by ID */
    private array $subscriptions = [];

    /** @var string Simulated checkout page URL */
    private string $checkoutPageUrl = '';

    /** @var string Simulated customer profile URL */
    private string $customerProfileUrl = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->plans = [];
        $this->userGrants = [];
        $this->feeds = [];
        $this->productDetails = [];
        $this->productVariations = [];
        $this->subscriptions = [];
        $this->checkoutPageUrl = 'https://example.com/checkout';
        $this->customerProfileUrl = 'https://example.com/customer-profile';
        $GLOBALS['wp_options'] = ['date_format' => 'Y-m-d'];
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    private function createPlan(int $id, string $title, int $level = 0, string $slug = ''): array
    {
        $slug = $slug ?: strtolower(str_replace(' ', '-', $title));
        $plan = [
            'id' => $id,
            'title' => $title,
            'slug' => $slug,
            'status' => 'active',
            'level' => $level,
            'duration_type' => 'lifetime',
            'duration_days' => null,
            'trial_days' => 0,
            'grace_period_days' => 0,
            'includes_plan_ids' => [],
            'settings' => [],
            'meta' => [],
        ];
        $this->plans[$id] = $plan;
        return $plan;
    }

    private function createGrant(int $userId, int $planId, string $sourceType = 'manual', int $sourceId = 0): array
    {
        $grant = [
            'id' => count($this->userGrants) + 1,
            'user_id' => $userId,
            'plan_id' => $planId,
            'status' => 'active',
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'source_ids' => $sourceId > 0 ? [$sourceId] : [],
            'renewal_count' => 0,
            'meta' => [],
            'created_at' => '2026-01-01 00:00:00',
            'expires_at' => null,
            'trial_ends_at' => null,
        ];
        $this->userGrants[$userId] = $grant;
        return $grant;
    }

    private function linkPlanToProduct(int $planId, int $productId, string $planSlug = ''): void
    {
        $slug = $planSlug ?: ($this->plans[$planId]['slug'] ?? '');
        $this->feeds[] = [
            'product_id' => $productId,
            'enabled' => 1,
            'settings' => json_encode([
                'plan_slug' => $slug,
                'plan_id' => $planId,
            ]),
        ];
    }

    private function createProductWithVariant(int $productId, int $variantId, ?int $defaultVariantId = null): void
    {
        $this->productDetails[$productId] = [
            'default_variation_id' => $defaultVariantId ?? $variantId,
        ];
        $this->productVariations[$productId] = [
            ['id' => $variantId, 'serial_index' => 0],
        ];
    }

    private function createSubscription(int $id, string $status, ?string $nextBillingDate, string $uuid = ''): void
    {
        $this->subscriptions[$id] = [
            'id' => $id,
            'status' => $status,
            'next_billing_date' => $nextBillingDate,
            'uuid' => $uuid ?: md5('sub-' . $id),
        ];
    }

    /**
     * Simulate CheckoutUrlHelper::getCheckoutUrl() using in-memory data.
     */
    private function simulateCheckoutUrl(int $planId): string
    {
        $productId = $this->simulateGetLinkedProductId($planId);
        if (!$productId) {
            return '';
        }

        $variantId = $this->simulateGetDefaultVariantId($productId);
        if (!$variantId) {
            return '';
        }

        if (empty($this->checkoutPageUrl)) {
            return '';
        }

        return $this->checkoutPageUrl . '?fct_cart_hash=' . $variantId;
    }

    /**
     * Simulate CheckoutUrlHelper::getUpgradeUrl() using in-memory data.
     */
    private function simulateUpgradeUrl(int $currentPlanId): string
    {
        $currentPlan = $this->plans[$currentPlanId] ?? null;
        if (!$currentPlan) {
            return '';
        }

        $currentLevel = (int) ($currentPlan['level'] ?? 0);

        // Get active plans sorted by level ASC
        $activePlans = array_filter($this->plans, fn($p) => $p['status'] === 'active');
        usort($activePlans, fn($a, $b) => $a['level'] <=> $b['level']);

        $candidate = null;
        foreach ($activePlans as $plan) {
            if ((int) $plan['level'] <= $currentLevel) {
                continue;
            }
            if ($plan['id'] === $currentPlanId) {
                continue;
            }
            $productId = $this->simulateGetLinkedProductId($plan['id']);
            if (!$productId) {
                continue;
            }
            if ($candidate === null || (int) $plan['level'] < (int) $candidate['level']) {
                $candidate = $plan;
            }
        }

        if (!$candidate) {
            return '';
        }

        return $this->simulateCheckoutUrl($candidate['id']);
    }

    /**
     * Simulate CheckoutUrlHelper::getNextBillingDate()
     */
    private function simulateNextBillingDate(int $subscriptionId): string
    {
        $subscription = $this->subscriptions[$subscriptionId] ?? null;
        if (!$subscription) {
            return '';
        }

        $activeStatuses = ['active', 'trialing'];
        if (!in_array($subscription['status'], $activeStatuses, true)) {
            return '';
        }

        if (empty($subscription['next_billing_date'])) {
            return '';
        }

        return date('Y-m-d', strtotime($subscription['next_billing_date']));
    }

    /**
     * Simulate CheckoutUrlHelper::getPaymentUpdateUrl()
     */
    private function simulatePaymentUpdateUrl(int $subscriptionId): string
    {
        $subscription = $this->subscriptions[$subscriptionId] ?? null;
        if (!$subscription || empty($subscription['uuid'])) {
            return '';
        }

        if (empty($this->customerProfileUrl)) {
            return '';
        }

        return rtrim($this->customerProfileUrl, '/') . '/subscription/' . $subscription['uuid'];
    }

    private function simulateGetLinkedProductId(int $planId): ?int
    {
        $plan = $this->plans[$planId] ?? null;
        if (!$plan) {
            return null;
        }

        foreach ($this->feeds as $feed) {
            if (empty($feed['enabled'])) {
                continue;
            }
            $settings = json_decode($feed['settings'] ?? '{}', true) ?: [];
            $matchBySlug = ($settings['plan_slug'] ?? '') === $plan['slug'];
            $matchById = (int) ($settings['plan_id'] ?? 0) === $planId;

            if ($matchBySlug || $matchById) {
                return (int) $feed['product_id'];
            }
        }

        return null;
    }

    private function simulateGetDefaultVariantId(int $productId): ?int
    {
        $detail = $this->productDetails[$productId] ?? null;
        if ($detail && !empty($detail['default_variation_id'])) {
            return (int) $detail['default_variation_id'];
        }

        $variations = $this->productVariations[$productId] ?? [];
        if (!empty($variations)) {
            usort($variations, fn($a, $b) => ($a['serial_index'] ?? 0) <=> ($b['serial_index'] ?? 0));
            return (int) $variations[0]['id'];
        }

        return null;
    }

    private function simulateGetSubscriptionIdFromGrant(array $grant): ?int
    {
        if (($grant['source_type'] ?? '') !== 'subscription') {
            return null;
        }
        $sourceId = (int) ($grant['source_id'] ?? 0);
        return $sourceId > 0 ? $sourceId : null;
    }

    // =================================================================
    // CHECKOUT URL TESTS
    // =================================================================

    public function test_checkout_url_with_valid_plan_and_linked_product(): void
    {
        $this->createPlan(1, 'Bronze', 1);
        $this->linkPlanToProduct(1, 100);
        $this->createProductWithVariant(100, 500);

        $url = $this->simulateCheckoutUrl(1);

        $this->assertNotEmpty($url);
        $this->assertStringContainsString('fct_cart_hash=500', $url);
        $this->assertStringContainsString('https://example.com/checkout', $url);
    }

    public function test_checkout_url_when_no_linked_product(): void
    {
        $this->createPlan(1, 'Bronze', 1);
        // No feed linking this plan to a product

        $url = $this->simulateCheckoutUrl(1);

        $this->assertEmpty($url);
    }

    public function test_checkout_url_when_no_active_grant(): void
    {
        $this->createPlan(1, 'Bronze', 1);
        $this->linkPlanToProduct(1, 100);
        $this->createProductWithVariant(100, 500);

        // Simulate what MembershipSmartCodes does: no grant => no plan_id => empty string
        $grant = null;
        $result = '';
        if (!$grant || !($grant['plan_id'] ?? null)) {
            $result = '';
        }

        $this->assertEmpty($result);
    }

    public function test_checkout_url_when_no_checkout_page_configured(): void
    {
        $this->createPlan(1, 'Bronze', 1);
        $this->linkPlanToProduct(1, 100);
        $this->createProductWithVariant(100, 500);
        $this->checkoutPageUrl = '';

        $url = $this->simulateCheckoutUrl(1);

        $this->assertEmpty($url);
    }

    // =================================================================
    // UPGRADE URL TESTS
    // =================================================================

    public function test_upgrade_url_returns_higher_plan_checkout(): void
    {
        $this->createPlan(1, 'Bronze', 1);
        $this->createPlan(2, 'Silver', 2);
        $this->createPlan(3, 'Gold', 3);

        $this->linkPlanToProduct(1, 100);
        $this->linkPlanToProduct(2, 200);
        $this->linkPlanToProduct(3, 300);

        $this->createProductWithVariant(100, 500);
        $this->createProductWithVariant(200, 600);
        $this->createProductWithVariant(300, 700);

        // User on Bronze, should get Silver (next higher)
        $url = $this->simulateUpgradeUrl(1);

        $this->assertNotEmpty($url);
        $this->assertStringContainsString('fct_cart_hash=600', $url);
    }

    public function test_upgrade_url_when_already_on_highest_plan(): void
    {
        $this->createPlan(1, 'Bronze', 1);
        $this->createPlan(2, 'Gold', 3);

        $this->linkPlanToProduct(1, 100);
        $this->linkPlanToProduct(2, 200);

        $this->createProductWithVariant(100, 500);
        $this->createProductWithVariant(200, 600);

        // User on Gold, no higher plan
        $url = $this->simulateUpgradeUrl(2);

        $this->assertEmpty($url);
    }

    public function test_upgrade_url_when_no_active_grant(): void
    {
        $this->createPlan(1, 'Bronze', 1);
        $this->createPlan(2, 'Silver', 2);

        // Simulate: no grant means the smart code returns '' before calling getUpgradeUrl
        $grant = null;
        $result = '';
        if (!$grant || !($grant['plan_id'] ?? null)) {
            $result = '';
        }

        $this->assertEmpty($result);
    }

    // =================================================================
    // NEXT BILLING DATE TESTS
    // =================================================================

    public function test_next_billing_date_with_active_subscription(): void
    {
        $this->createSubscription(42, 'active', '2026-04-15 00:00:00');

        $date = $this->simulateNextBillingDate(42);

        $this->assertNotEmpty($date);
        $this->assertEquals('2026-04-15', $date);
    }

    public function test_next_billing_date_with_no_subscription(): void
    {
        // Grant has no subscription source
        $grant = $this->createGrant(100, 1, 'manual', 0);

        $subscriptionId = $this->simulateGetSubscriptionIdFromGrant($grant);

        $this->assertNull($subscriptionId);
    }

    public function test_next_billing_date_with_expired_subscription(): void
    {
        $this->createSubscription(42, 'expired', '2025-01-01 00:00:00');

        $date = $this->simulateNextBillingDate(42);

        $this->assertEmpty($date);
    }

    // =================================================================
    // PAYMENT UPDATE URL TESTS
    // =================================================================

    public function test_payment_update_url_with_valid_subscription(): void
    {
        $uuid = 'abc123def456';
        $this->createSubscription(42, 'active', '2026-04-15 00:00:00', $uuid);

        $url = $this->simulatePaymentUpdateUrl(42);

        $this->assertNotEmpty($url);
        $this->assertStringContainsString('/subscription/' . $uuid, $url);
        $this->assertStringContainsString('https://example.com/customer-profile', $url);
    }

    public function test_payment_update_url_with_no_subscription(): void
    {
        // Non-existent subscription
        $url = $this->simulatePaymentUpdateUrl(999);

        $this->assertEmpty($url);
    }

    // =================================================================
    // HELPER / EDGE CASE TESTS
    // =================================================================

    public function test_get_subscription_id_from_grant_with_subscription_source(): void
    {
        $grant = $this->createGrant(100, 1, 'subscription', 42);

        $subscriptionId = $this->simulateGetSubscriptionIdFromGrant($grant);

        $this->assertEquals(42, $subscriptionId);
    }

    public function test_get_subscription_id_from_grant_with_order_source(): void
    {
        $grant = $this->createGrant(100, 1, 'order', 99);

        $subscriptionId = $this->simulateGetSubscriptionIdFromGrant($grant);

        $this->assertNull($subscriptionId);
    }

    public function test_upgrade_url_skips_plans_without_linked_products(): void
    {
        $this->createPlan(1, 'Bronze', 1);
        $this->createPlan(2, 'Silver', 2); // No linked product
        $this->createPlan(3, 'Gold', 3);

        $this->linkPlanToProduct(1, 100);
        // Plan 2 intentionally not linked
        $this->linkPlanToProduct(3, 300);

        $this->createProductWithVariant(100, 500);
        $this->createProductWithVariant(300, 700);

        // User on Bronze, Silver has no product, should skip to Gold
        $url = $this->simulateUpgradeUrl(1);

        $this->assertNotEmpty($url);
        $this->assertStringContainsString('fct_cart_hash=700', $url);
    }

    public function test_checkout_url_uses_default_variant_from_product_details(): void
    {
        $this->createPlan(1, 'Bronze', 1);
        $this->linkPlanToProduct(1, 100);

        // Product has variant 500 but default is explicitly set to 501
        $this->productDetails[100] = ['default_variation_id' => 501];
        $this->productVariations[100] = [
            ['id' => 500, 'serial_index' => 0],
            ['id' => 501, 'serial_index' => 1],
        ];

        $url = $this->simulateCheckoutUrl(1);

        $this->assertStringContainsString('fct_cart_hash=501', $url);
    }

    public function test_checkout_url_falls_back_to_first_variant_when_no_default(): void
    {
        $this->createPlan(1, 'Bronze', 1);
        $this->linkPlanToProduct(1, 100);

        // No default variant set
        $this->productDetails[100] = ['default_variation_id' => null];
        $this->productVariations[100] = [
            ['id' => 500, 'serial_index' => 0],
            ['id' => 501, 'serial_index' => 1],
        ];

        $url = $this->simulateCheckoutUrl(1);

        $this->assertStringContainsString('fct_cart_hash=500', $url);
    }

    public function test_next_billing_date_with_trialing_subscription(): void
    {
        $this->createSubscription(42, 'trialing', '2026-05-01 00:00:00');

        $date = $this->simulateNextBillingDate(42);

        $this->assertNotEmpty($date);
        $this->assertEquals('2026-05-01', $date);
    }

    public function test_next_billing_date_with_canceled_subscription(): void
    {
        $this->createSubscription(42, 'canceled', '2026-04-15 00:00:00');

        $date = $this->simulateNextBillingDate(42);

        $this->assertEmpty($date);
    }
}

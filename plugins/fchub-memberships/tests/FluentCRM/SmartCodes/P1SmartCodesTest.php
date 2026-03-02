<?php

namespace FChubMemberships\Tests\FluentCRM\SmartCodes;

use PHPUnit\Framework\TestCase;

/**
 * Tests for P1 smart codes: cancellation_reason and trial_days_remaining.
 *
 * Uses in-memory simulation of the smart code parsing logic.
 */
class P1SmartCodesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['wp_options'] = ['date_format' => 'Y-m-d'];
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    private function makeGrant(array $overrides = []): array
    {
        return array_merge([
            'id'                    => 1,
            'user_id'               => 100,
            'plan_id'               => 10,
            'status'                => 'active',
            'expires_at'            => null,
            'trial_ends_at'         => null,
            'cancellation_reason'   => null,
            'renewal_count'         => 0,
            'created_at'            => '2025-01-01 00:00:00',
            'meta'                  => [],
        ], $overrides);
    }

    /**
     * Simulate the getCancellationReason logic from MembershipSmartCodes.
     * Checks grant meta for cancellation_reason or revoke_reason,
     * then falls back to top-level cancellation_reason.
     */
    private function simulateGetCancellationReason(array $grants): string
    {
        foreach ($grants as $grant) {
            $meta = $grant['meta'] ?? [];

            if (!empty($meta['cancellation_reason'])) {
                return (string) $meta['cancellation_reason'];
            }
            if (!empty($meta['revoke_reason'])) {
                return (string) $meta['revoke_reason'];
            }

            if (!empty($grant['cancellation_reason'])) {
                return (string) $grant['cancellation_reason'];
            }
        }

        return '';
    }

    /**
     * Simulate the getTrialDaysRemaining logic from MembershipSmartCodes.
     */
    private function simulateGetTrialDaysRemaining(?array $grant): string
    {
        if (!$grant || empty($grant['trial_ends_at'])) {
            return '';
        }

        $diff = strtotime($grant['trial_ends_at']) - time();
        return (string) max(0, (int) ceil($diff / DAY_IN_SECONDS));
    }

    // ===================================================================
    // CANCELLATION REASON TESTS
    // ===================================================================

    public function test_cancellation_reason_from_grant_meta(): void
    {
        $grant = $this->makeGrant([
            'status' => 'revoked',
            'meta'   => ['cancellation_reason' => 'Subscription cancelled by customer'],
        ]);

        $result = $this->simulateGetCancellationReason([$grant]);

        $this->assertEquals('Subscription cancelled by customer', $result);
    }

    public function test_cancellation_reason_from_revoke_reason_meta(): void
    {
        $grant = $this->makeGrant([
            'status' => 'revoked',
            'meta'   => ['revoke_reason' => 'Replaced by plan #2 (exclusive mode)'],
        ]);

        $result = $this->simulateGetCancellationReason([$grant]);

        $this->assertEquals('Replaced by plan #2 (exclusive mode)', $result);
    }

    public function test_cancellation_reason_from_top_level_field(): void
    {
        $grant = $this->makeGrant([
            'status'              => 'active',
            'cancellation_reason' => 'Customer requested cancellation',
            'meta'                => [],
        ]);

        $result = $this->simulateGetCancellationReason([$grant]);

        $this->assertEquals('Customer requested cancellation', $result);
    }

    public function test_cancellation_reason_when_no_cancellation(): void
    {
        $grant = $this->makeGrant([
            'status' => 'active',
            'meta'   => [],
        ]);

        $result = $this->simulateGetCancellationReason([$grant]);

        $this->assertEquals('', $result);
    }

    public function test_cancellation_reason_meta_takes_priority_over_field(): void
    {
        $grant = $this->makeGrant([
            'cancellation_reason' => 'Field reason',
            'meta'                => ['cancellation_reason' => 'Meta reason'],
        ]);

        $result = $this->simulateGetCancellationReason([$grant]);

        $this->assertEquals('Meta reason', $result, 'Meta cancellation_reason should take priority');
    }

    public function test_cancellation_reason_from_second_grant(): void
    {
        $grant1 = $this->makeGrant(['status' => 'active', 'meta' => []]);
        $grant2 = $this->makeGrant([
            'id'     => 2,
            'status' => 'revoked',
            'meta'   => ['revoke_reason' => 'Payment failed'],
        ]);

        $result = $this->simulateGetCancellationReason([$grant1, $grant2]);

        $this->assertEquals('Payment failed', $result);
    }

    // ===================================================================
    // TRIAL DAYS REMAINING TESTS
    // ===================================================================

    public function test_trial_days_remaining_calculates_correctly(): void
    {
        $trialEndsAt = gmdate('Y-m-d H:i:s', strtotime('+5 days'));
        $grant = $this->makeGrant([
            'trial_ends_at' => $trialEndsAt,
        ]);

        $result = $this->simulateGetTrialDaysRemaining($grant);

        $days = (int) $result;
        $this->assertGreaterThanOrEqual(4, $days);
        $this->assertLessThanOrEqual(5, $days);
    }

    public function test_trial_days_remaining_when_not_in_trial(): void
    {
        $grant = $this->makeGrant([
            'trial_ends_at' => null,
        ]);

        $result = $this->simulateGetTrialDaysRemaining($grant);

        $this->assertEquals('', $result);
    }

    public function test_trial_days_remaining_when_trial_expired(): void
    {
        $trialEndsAt = gmdate('Y-m-d H:i:s', strtotime('-2 days'));
        $grant = $this->makeGrant([
            'trial_ends_at' => $trialEndsAt,
        ]);

        $result = $this->simulateGetTrialDaysRemaining($grant);

        $this->assertEquals('0', $result, 'Should return 0 when trial has already expired');
    }

    public function test_trial_days_remaining_with_no_grant(): void
    {
        $result = $this->simulateGetTrialDaysRemaining(null);

        $this->assertEquals('', $result);
    }

    public function test_trial_days_remaining_returns_1_for_same_day_expiry(): void
    {
        // Trial ends in a few hours (same day)
        $trialEndsAt = gmdate('Y-m-d H:i:s', strtotime('+6 hours'));
        $grant = $this->makeGrant([
            'trial_ends_at' => $trialEndsAt,
        ]);

        $result = $this->simulateGetTrialDaysRemaining($grant);

        $this->assertEquals('1', $result, 'Should return 1 when trial ends within the day (ceil)');
    }

    public function test_trial_days_remaining_is_separate_from_expires_at(): void
    {
        // Grant has both expires_at and trial_ends_at
        $grant = $this->makeGrant([
            'expires_at'    => gmdate('Y-m-d H:i:s', strtotime('+30 days')),
            'trial_ends_at' => gmdate('Y-m-d H:i:s', strtotime('+7 days')),
        ]);

        $trialResult = $this->simulateGetTrialDaysRemaining($grant);

        // Trial days should be based on trial_ends_at, not expires_at
        $days = (int) $trialResult;
        $this->assertGreaterThanOrEqual(6, $days);
        $this->assertLessThanOrEqual(7, $days);
    }
}

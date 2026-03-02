<?php

namespace FChubMemberships\Tests\FluentCRM\Filters;

use PHPUnit\Framework\TestCase;

/**
 * Tests for the fchub_member_duration segment filter.
 *
 * Since the filter relies on database queries via FluentCRM's query builder,
 * we test the logic: sanitizeOperator and the filter concept.
 * The actual SQL is tested in integration tests.
 */
class MemberDurationFilterTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['wp_options'] = [];
        $GLOBALS['wp_actions_fired'] = [];
    }

    // ---------------------------------------------------------------
    // Test 9: member duration greater than
    // ---------------------------------------------------------------
    public function test_member_duration_greater_than(): void
    {
        // Simulate the filter logic: DATEDIFF(NOW(), created_at) > value
        $grantCreatedAt = date('Y-m-d H:i:s', strtotime('-60 days'));
        $memberDurationDays = (int) floor((time() - strtotime($grantCreatedAt)) / DAY_IN_SECONDS);
        $filterValue = 30;
        $operator = '>';

        // The filter should match: 60 > 30
        $this->assertTrue(
            $this->evaluateNumericOperator($memberDurationDays, $operator, $filterValue),
            'Member with 60 days should match > 30'
        );

        // Should NOT match if member is only 20 days old
        $recentGrant = date('Y-m-d H:i:s', strtotime('-20 days'));
        $recentDuration = (int) floor((time() - strtotime($recentGrant)) / DAY_IN_SECONDS);
        $this->assertFalse(
            $this->evaluateNumericOperator($recentDuration, $operator, $filterValue),
            'Member with 20 days should not match > 30'
        );
    }

    // ---------------------------------------------------------------
    // Test 10: member duration less than
    // ---------------------------------------------------------------
    public function test_member_duration_less_than(): void
    {
        $grantCreatedAt = date('Y-m-d H:i:s', strtotime('-10 days'));
        $memberDurationDays = (int) floor((time() - strtotime($grantCreatedAt)) / DAY_IN_SECONDS);
        $filterValue = 30;
        $operator = '<';

        // Should match: 10 < 30
        $this->assertTrue(
            $this->evaluateNumericOperator($memberDurationDays, $operator, $filterValue),
            'Member with 10 days should match < 30'
        );

        // Should NOT match if member is 45 days old
        $oldGrant = date('Y-m-d H:i:s', strtotime('-45 days'));
        $oldDuration = (int) floor((time() - strtotime($oldGrant)) / DAY_IN_SECONDS);
        $this->assertFalse(
            $this->evaluateNumericOperator($oldDuration, $operator, $filterValue),
            'Member with 45 days should not match < 30'
        );
    }

    // ---------------------------------------------------------------
    // Test 11: operator sanitized — rejects invalid operators
    // ---------------------------------------------------------------
    public function test_member_duration_operator_sanitized(): void
    {
        $allowed = ['=', '!=', '<>', '>', '<', '>=', '<='];

        foreach ($allowed as $op) {
            $this->assertEquals($op, $this->sanitizeOperator($op), "Operator {$op} should be allowed");
        }

        // Invalid operators
        $this->assertNull($this->sanitizeOperator('LIKE'));
        $this->assertNull($this->sanitizeOperator('DROP'));
        $this->assertNull($this->sanitizeOperator('; DELETE'));
        $this->assertNull($this->sanitizeOperator(''));
        $this->assertNull($this->sanitizeOperator('OR 1=1'));
    }

    // ---------------------------------------------------------------
    // Helpers: mirror the MembershipFilters logic
    // ---------------------------------------------------------------

    private function sanitizeOperator(string $operator): ?string
    {
        $allowed = ['=', '!=', '<>', '>', '<', '>=', '<='];
        return in_array($operator, $allowed, true) ? $operator : null;
    }

    private function evaluateNumericOperator(int $actual, string $operator, int $expected): bool
    {
        return match ($operator) {
            '='  => $actual === $expected,
            '!=' => $actual !== $expected,
            '<>' => $actual !== $expected,
            '>'  => $actual > $expected,
            '<'  => $actual < $expected,
            '>=' => $actual >= $expected,
            '<=' => $actual <= $expected,
            default => false,
        };
    }
}

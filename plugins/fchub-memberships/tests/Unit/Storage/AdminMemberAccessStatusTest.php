<?php

declare(strict_types=1);

namespace FChubMemberships\Tests\Unit\Storage;

use FChubMemberships\Storage\AdminMemberQuery;
use FChubMemberships\Support\Clock;
use FChubMemberships\Tests\Unit\PluginTestCase;

/**
 * The member list derives the same access status the member profile does. A
 * membership that has not started yet is scheduled, not ended, and not active.
 */
final class AdminMemberAccessStatusTest extends PluginTestCase
{
    public function test_the_summary_counts_a_membership_that_has_not_started_as_scheduled(): void
    {
        $query = $this->captureSummaryQuery();

        self::assertStringContainsString("THEN 'scheduled'", $query);
        self::assertStringContainsString('AS scheduled', $query);
    }

    public function test_the_summary_no_longer_files_an_unstarted_membership_under_ended(): void
    {
        $query = $this->captureSummaryQuery();
        $endedExpression = $this->expressionFor($query, 'ended');

        self::assertStringContainsString("IN ('expired', 'revoked')", $endedExpression);
        self::assertStringNotContainsString('scheduled', $endedExpression);
    }

    public function test_the_summary_ranks_scheduled_above_paused_exactly_as_the_profile_does(): void
    {
        $query = $this->captureSummaryQuery();

        self::assertLessThan(
            strpos($query, "THEN 'paused'"),
            strpos($query, "THEN 'scheduled'"),
            'Scheduled must be tested before paused so both surfaces agree.'
        );
        self::assertLessThan(
            strpos($query, "THEN 'scheduled'"),
            strpos($query, "THEN 'active'"),
            'Access in force outranks access that has not started.'
        );
    }

    public function test_the_summary_returns_a_scheduled_total(): void
    {
        $GLOBALS['_fchub_test_wpdb_overrides']['get_row'] = static fn(): array => [
            'active' => '9',
            'expiring_soon' => '2',
            'scheduled' => '3',
            'paused' => '1',
            'ended' => '4',
        ];

        self::assertSame(
            ['active' => 9, 'expiring_soon' => 2, 'scheduled' => 3, 'paused' => 1, 'ended' => 4],
            $this->repository()->getAdminSummary(7)
        );
    }

    public function test_a_list_row_reports_the_derived_status_rather_than_the_alphabetically_first_one(): void
    {
        $query = $this->captureMembersQuery();

        self::assertStringNotContainsString('MIN(g.status) AS status', $query);
        self::assertStringContainsString('END AS status', $query);
        self::assertStringContainsString("THEN 'scheduled'", $query);
    }

    public function test_the_derived_status_calls_a_membership_active_only_while_access_is_in_force(): void
    {
        $expression = $this->derivedStatusExpression($this->captureMembersQuery(), 'status');

        self::assertStringContainsString(
            "g.starts_at IS NULL OR g.starts_at <= '2026-08-13 12:00:00'",
            $expression
        );
        self::assertStringContainsString(
            "g.expires_at IS NULL OR g.expires_at > '2026-08-13 12:00:00'",
            $expression
        );
    }

    public function test_the_derived_status_calls_a_membership_scheduled_only_when_its_start_is_ahead(): void
    {
        $expression = $this->derivedStatusExpression($this->captureMembersQuery(), 'status');
        $scheduled = substr($expression, 0, (int) strpos($expression, "THEN 'scheduled'"));

        self::assertStringContainsString('g.starts_at IS NOT NULL', $scheduled);
        self::assertStringContainsString("g.starts_at > '2026-08-13 12:00:00'", $scheduled);
    }

    public function test_the_derived_status_ranks_paused_above_revoked(): void
    {
        $expression = $this->derivedStatusExpression($this->captureMembersQuery(), 'status');

        self::assertLessThan(
            strpos($expression, "THEN 'revoked'"),
            strpos($expression, "THEN 'paused'"),
            'A paused membership can be resumed; a revoked one cannot, so paused is reported first.'
        );
    }

    public function test_the_status_filter_can_reach_a_scheduled_membership(): void
    {
        $where = $this->whereClauseOf($this->captureMembersQuery(['status' => 'scheduled']));

        self::assertStringContainsString("g.status = 'active'", $where);
        self::assertStringContainsString('g.starts_at IS NOT NULL', $where);
        self::assertStringContainsString("g.starts_at > '2026-08-13 12:00:00'", $where);
    }

    public function test_the_active_filter_still_excludes_a_membership_that_has_not_started(): void
    {
        $where = $this->whereClauseOf($this->captureMembersQuery(['status' => 'active']));

        self::assertStringContainsString("g.starts_at IS NULL OR g.starts_at <= '2026-08-13 12:00:00'", $where);
        self::assertStringContainsString("g.expires_at IS NULL OR g.expires_at > '2026-08-13 12:00:00'", $where);
    }

    public function test_an_unfiltered_list_constrains_nothing_by_status(): void
    {
        $where = $this->whereClauseOf($this->captureMembersQuery());

        self::assertStringNotContainsString('g.status', $where);
        self::assertStringNotContainsString('g.starts_at', $where);
    }

    public function test_the_paused_filter_asks_for_the_stored_status_and_nothing_else(): void
    {
        $where = $this->whereClauseOf($this->captureMembersQuery(['status' => 'paused']));

        self::assertStringContainsString("g.status = 'paused'", $where);
        self::assertStringNotContainsString('starts_at', $where);
    }

    /**
     * The derived-status expression repeats the same date comparisons in the
     * SELECT list, so a filter assertion has to read the WHERE clause alone.
     */
    private function whereClauseOf(string $query): string
    {
        $start = strpos($query, 'WHERE ');
        self::assertNotFalse($start, 'The member query has no WHERE clause.');
        $end = strpos($query, 'GROUP BY', (int) $start);

        return substr($query, (int) $start, $end === false ? null : $end - (int) $start);
    }

    /**
     * The derived-status expression nests a CASE inside each SUM(), and the
     * same date comparisons appear again in the WHERE clause and in the
     * summary's expiring_soon column. Anchor on the outer CASE — the only one
     * an aggregate follows — so a branch assertion reads that branch alone.
     */
    private function derivedStatusExpression(string $query, string $alias): string
    {
        $end = strpos($query, ' AS ' . $alias);
        self::assertNotFalse($end, "The query has no {$alias} column.");

        $matched = preg_match_all(
            '/CASE\s+WHEN SUM\(/',
            substr($query, 0, (int) $end),
            $matches,
            PREG_OFFSET_CAPTURE
        );
        self::assertGreaterThan(0, $matched, 'The query has no derived-status expression.');
        $start = (int) end($matches[0])[1];

        return substr($query, $start, (int) $end - $start);
    }

    private function repository(): AdminMemberQuery
    {
        return new AdminMemberQuery(new Clock(
            new \DateTimeImmutable('2026-08-13 12:00:00', new \DateTimeZone('UTC')),
            new \DateTimeZone('UTC')
        ));
    }

    private function captureSummaryQuery(): string
    {
        $query = '';
        $GLOBALS['_fchub_test_wpdb_overrides']['get_row'] = static function (string $sql) use (&$query): array {
            $query = $sql;

            return [];
        };

        $this->repository()->getAdminSummary(7);

        return $query;
    }

    /** @param array<string, mixed> $filters */
    private function captureMembersQuery(array $filters = []): string
    {
        $query = '';
        $GLOBALS['_fchub_test_wpdb_overrides']['get_results'] = static function (string $sql) use (&$query): array {
            $query = $sql;

            return [];
        };

        $this->repository()->getMembers($filters + ['per_page' => 20, 'page' => 1]);

        return $query;
    }

    private function expressionFor(string $query, string $alias): string
    {
        $end = strpos($query, ' AS ' . $alias);
        self::assertNotFalse($end, "The summary has no {$alias} column.");
        $start = strrpos(substr($query, 0, $end), 'SUM(');

        return substr($query, (int) $start, $end - (int) $start);
    }
}

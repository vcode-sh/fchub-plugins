<?php

namespace FChubMemberships\Storage;

use FChubMemberships\Support\Clock;

defined('ABSPATH') || exit;

/**
 * The read side that answers "who are my members?" for the admin list, the
 * dashboard, the CLI report and the reporting services.
 *
 * None of these queries sit on the access path — nothing here decides whether
 * a member may open a resource. They aggregate and paginate, which is why they
 * are separated from the grant reads that protection checks depend on.
 */
class AdminMemberQuery
{
    use GrantTableAccess;

    public function __construct(?Clock $clock = null)
    {
        $this->initGrantTable($clock);
    }

    /**
     * Paginated member list with filters.
     */
    public function getMembers(array $filters = []): array
    {
        global $wpdb;
        $now = $this->nowStorage();
        [$where, $params] = $this->buildAdminMemberWhere($filters, $now);
        $plansTable = \FChubMemberships\Support\CustomTableDatabase::identifier($wpdb->prefix . 'fchub_membership_plans');

        $perPage = (int) ($filters['per_page'] ?? 20);
        $page = max(1, (int) ($filters['page'] ?? 1));
        $offset = ($page - 1) * $perPage;

        $sql = "SELECT
                    MIN(g.id) AS id,
                    g.user_id,
                    g.plan_id,
                    g.source_type,
                    MAX(g.source_id) AS source_id,
                    MAX(g.feed_id) AS feed_id,
                    " . $this->accessStatusCase() . " AS status,
                    MIN(g.created_at) AS created_at,
                    MAX(g.updated_at) AS updated_at,
                    MAX(g.expires_at) AS expires_at,
                    MIN(g.starts_at) AS starts_at,
                    COUNT(*) AS grant_count,
                    u.user_email,
                    u.display_name,
                    COALESCE(MAX(p.title), '') AS plan_title
                FROM {$this->table} g
                LEFT JOIN {$wpdb->users} u ON g.user_id = u.ID
                LEFT JOIN {$plansTable} p ON g.plan_id = p.id
                WHERE " . implode(' AND ', $where) . "
                GROUP BY g.user_id, g.plan_id
                ORDER BY MIN(g.created_at) DESC";

        $sql .= \FChubMemberships\Support\CustomTableDatabase::prepare(' LIMIT %d OFFSET %d', $perPage, $offset)->sql();

        // The derived status is selected before the WHERE clause, so its three
        // current-time placeholders lead the bound parameters.
        $query = \FChubMemberships\Support\CustomTableDatabase::prepare($sql, $now, $now, $now, ...$params);

        $rows = \FChubMemberships\Support\CustomTableDatabase::getResults($query, ARRAY_A);

        return array_map(function ($row) {
            $row['grant_count'] = (int) ($row['grant_count'] ?? 1);
            $row['plan_title'] = (string) ($row['plan_title'] ?? '');
            return $this->hydrate($row);
        }, $rows ?: []);
    }

    public function countMembers(array $filters = []): int
    {
        global $wpdb;
        $now = $this->nowStorage();
        [$where, $params] = $this->buildAdminMemberWhere($filters, $now);

        $sql = "SELECT COUNT(*) FROM (
                    SELECT g.user_id, g.plan_id
                    FROM {$this->table} g
                    LEFT JOIN {$wpdb->users} u ON g.user_id = u.ID
                    WHERE " . implode(' AND ', $where) . "
                    GROUP BY g.user_id, g.plan_id
                ) AS grouped";

        $query = \FChubMemberships\Support\CustomTableDatabase::prepare($sql, ...$params);

        return (int) \FChubMemberships\Support\CustomTableDatabase::getVar($query);
    }

    /**
     * Return unfiltered access-assignment health totals for the admin list.
     *
     * @return array{active:int, expiring_soon:int, scheduled:int, paused:int, ended:int}
     */
    public function getAdminSummary(int $expiringDays = 7): array
    {
        global $wpdb;
        $now = $this->nowStorage();
        $future = $this->futureStorage($expiringDays, $now);

        $sql = "SELECT
                    SUM(CASE WHEN access_status = 'active' THEN 1 ELSE 0 END) AS active,
                    SUM(CASE WHEN access_status = 'active' AND expires_at IS NOT NULL AND expires_at > %s AND expires_at <= %s THEN 1 ELSE 0 END) AS expiring_soon,
                    SUM(CASE WHEN access_status = 'scheduled' THEN 1 ELSE 0 END) AS scheduled,
                    SUM(CASE WHEN access_status = 'paused' THEN 1 ELSE 0 END) AS paused,
                    SUM(CASE WHEN access_status IN ('expired', 'revoked') THEN 1 ELSE 0 END) AS ended
                FROM (
                    SELECT
                        g.user_id,
                        g.plan_id,
                        " . $this->accessStatusCase() . " AS access_status,
                        MAX(g.expires_at) AS expires_at
                    FROM {$this->table} g
                    GROUP BY g.user_id, g.plan_id
                ) access_rows";

        $row = \FChubMemberships\Support\CustomTableDatabase::getRow(
            \FChubMemberships\Support\CustomTableDatabase::prepare($sql, $now, $future, $now, $now, $now),
            ARRAY_A
        ) ?: [];

        return [
            'active' => (int) ($row['active'] ?? 0),
            'expiring_soon' => (int) ($row['expiring_soon'] ?? 0),
            'scheduled' => (int) ($row['scheduled'] ?? 0),
            'paused' => (int) ($row['paused'] ?? 0),
            'ended' => (int) ($row['ended'] ?? 0),
        ];
    }

    /**
     * Get grants expiring within X days.
     */
    public function getExpiringSoon(int $days = 7, int $limit = 50): array
    {
        global $wpdb;
        $now = $this->nowStorage();
        $future = $this->futureStorage($days);

        $rows = \FChubMemberships\Support\CustomTableDatabase::getResults(\FChubMemberships\Support\CustomTableDatabase::prepare(
            "SELECT * FROM {$this->table}
             WHERE status = 'active'
               AND expires_at IS NOT NULL
               AND expires_at > %s
               AND expires_at <= %s
             ORDER BY expires_at ASC
             LIMIT %d",
            $now,
            $future,
            $limit
        ), ARRAY_A);

        return array_map([$this, 'hydrate'], $rows ?: []);
    }

    /**
     * Count active grants that expire after now and no later than the requested window.
     */
    public function countExpiringSoon(int $days = 7): int
    {
        global $wpdb;

        $now = $this->nowStorage();
        $future = $this->futureStorage($days, $now);

        return (int) \FChubMemberships\Support\CustomTableDatabase::getVar(\FChubMemberships\Support\CustomTableDatabase::prepare(
            "SELECT COUNT(*) FROM {$this->table}
             WHERE status = 'active'
               AND (starts_at IS NULL OR starts_at <= %s)
               AND expires_at IS NOT NULL
               AND expires_at > %s
               AND expires_at <= %s",
            $now,
            $now,
            $future
        ));
    }

    /**
     * Get recently created or modified grants.
     */
    public function getRecentActivity(int $limit = 20): array
    {
        global $wpdb;

        $rows = \FChubMemberships\Support\CustomTableDatabase::getResults(\FChubMemberships\Support\CustomTableDatabase::prepare(
            "SELECT * FROM {$this->table} ORDER BY updated_at DESC LIMIT %d",
            $limit
        ), ARRAY_A);

        return array_map([$this, 'hydrate'], $rows ?: []);
    }

    /**
     * Count unique active members (users with at least one active grant).
     */
    public function countActiveMembers(?int $planId = null, ?string $asOf = null): int
    {
        global $wpdb;
        $now = $asOf ?: $this->nowStorage();

        $sql = "SELECT COUNT(DISTINCT user_id) FROM {$this->table}
                WHERE status = 'active'
                  AND (starts_at IS NULL OR starts_at <= %s)
                  AND (expires_at IS NULL OR expires_at > %s)";
        $params = [$now, $now];

        if ($planId !== null) {
            $sql .= ' AND plan_id = %d';
            $params[] = $planId;
        }

        return (int) \FChubMemberships\Support\CustomTableDatabase::getVar(\FChubMemberships\Support\CustomTableDatabase::prepare($sql, ...$params));
    }

    /**
     * Count new members in a date range.
     */
    public function countNewMembers(string $from, string $to, ?int $planId = null): int
    {
        global $wpdb;

        $sql = "SELECT COUNT(DISTINCT user_id) FROM {$this->table}
                WHERE created_at >= %s AND created_at <= %s";
        $params = [$from, $to];

        if ($planId !== null) {
            $sql .= ' AND plan_id = %d';
            $params[] = $planId;
        }

        return (int) \FChubMemberships\Support\CustomTableDatabase::getVar(\FChubMemberships\Support\CustomTableDatabase::prepare($sql, ...$params));
    }

    /**
     * Count churned members (revoked/expired) in a date range.
     */
    public function countChurnedMembers(string $from, string $to, ?int $planId = null): int
    {
        global $wpdb;

        $sql = "SELECT COUNT(DISTINCT user_id) FROM {$this->table}
                WHERE status IN ('revoked', 'expired')
                  AND updated_at >= %s AND updated_at <= %s";
        $params = [$from, $to];

        if ($planId !== null) {
            $sql .= ' AND plan_id = %d';
            $params[] = $planId;
        }

        return (int) \FChubMemberships\Support\CustomTableDatabase::getVar(\FChubMemberships\Support\CustomTableDatabase::prepare($sql, ...$params));
    }

    public function countByStatus(): array
    {
        global $wpdb;
        $rows = \FChubMemberships\Support\CustomTableDatabase::getResults(
            \FChubMemberships\Support\CustomTableDatabase::prepare(
                "SELECT status, COUNT(*) as count FROM {$this->table} WHERE 1 = %d GROUP BY status",
                1,
            ),
            ARRAY_A
        );
        $counts = [];
        foreach ($rows ?: [] as $row) {
            $counts[$row['status']] = (int) $row['count'];
        }
        return $counts;
    }

    /**
     * The access status a member-plan assignment actually has, in the same
     * precedence `MembershipGrouper` applies on the profile: access in force
     * first, then access that has not started, then paused, revoked, expired.
     *
     * Both `%s` placeholders take the current time.
     */
    private function accessStatusCase(string $alias = 'g'): string
    {
        return "CASE
                            WHEN SUM(CASE WHEN {$alias}.status = 'active' AND ({$alias}.starts_at IS NULL OR {$alias}.starts_at <= %s) AND ({$alias}.expires_at IS NULL OR {$alias}.expires_at > %s) THEN 1 ELSE 0 END) > 0 THEN 'active'
                            WHEN SUM(CASE WHEN {$alias}.status = 'active' AND {$alias}.starts_at IS NOT NULL AND {$alias}.starts_at > %s THEN 1 ELSE 0 END) > 0 THEN 'scheduled'
                            WHEN SUM(CASE WHEN {$alias}.status = 'paused' THEN 1 ELSE 0 END) > 0 THEN 'paused'
                            WHEN SUM(CASE WHEN {$alias}.status = 'revoked' THEN 1 ELSE 0 END) > 0 THEN 'revoked'
                            ELSE 'expired'
                        END";
    }

    /**
     * @return array{0:array<int,string>,1:array<int,mixed>}
     */
    private function buildAdminMemberWhere(array $filters, string $now): array
    {
        global $wpdb;

        $where = ['1=%d'];
        $params = [1];

        if (!empty($filters['status'])) {
            if ($filters['status'] === 'active') {
                $where[] = "g.status = 'active' AND (g.starts_at IS NULL OR g.starts_at <= %s) AND (g.expires_at IS NULL OR g.expires_at > %s)";
                $params[] = $now;
                $params[] = $now;
            } elseif ($filters['status'] === 'scheduled') {
                $where[] = "g.status = 'active' AND g.starts_at IS NOT NULL AND g.starts_at > %s";
                $params[] = $now;
            } elseif ($filters['status'] === 'paused') {
                $where[] = "g.status = 'paused'";
            } else {
                $where[] = 'g.status = %s';
                $params[] = $filters['status'];
            }
        }

        if (!empty($filters['plan_id'])) {
            $where[] = 'g.plan_id = %d';
            $params[] = (int) $filters['plan_id'];
        }

        if (!empty($filters['search'])) {
            $search = (string) $filters['search'];
            $like = '%' . $wpdb->esc_like($search) . '%';
            if (ctype_digit($search)) {
                $where[] = '(u.user_email LIKE %s OR u.display_name LIKE %s OR u.ID = %d)';
                $params[] = $like;
                $params[] = $like;
                $params[] = (int) $search;
            } else {
                $where[] = '(u.user_email LIKE %s OR u.display_name LIKE %s)';
                $params[] = $like;
                $params[] = $like;
            }
        }

        if (!empty($filters['source_type'])) {
            $where[] = 'g.source_type = %s';
            $params[] = $filters['source_type'];
        }

        if (!empty($filters['expires_within'])) {
            $days = (int) $filters['expires_within'];
            $future = $this->futureStorage($days, $now);
            $where[] = "g.status = 'active' AND (g.starts_at IS NULL OR g.starts_at <= %s) AND g.expires_at IS NOT NULL AND g.expires_at > %s AND g.expires_at <= %s";
            $params[] = $now;
            $params[] = $now;
            $params[] = $future;
        }

        return [$where, $params];
    }
}

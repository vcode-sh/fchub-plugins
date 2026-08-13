<?php

namespace FChubMemberships\Storage;

use FChubMemberships\Domain\AccessEvaluator;
use FChubMemberships\Domain\Access\ResourceAccessPolicy;
use FChubMemberships\Support\Clock;

defined('ABSPATH') || exit;

class GrantRepository
{
    private string $table;
    private Clock $clock;

    /** @var array<string, mixed> */
    private static array $requestCache = [];

    private static ?object $cacheContext = null;

    public function __construct(?Clock $clock = null)
    {
        global $wpdb;
        $this->table = \FChubMemberships\Support\CustomTableDatabase::identifier($wpdb->prefix . 'fchub_membership_grants');
        $this->clock = $clock ?? new Clock();
    }

    public function find(int $id): ?array
    {
        global $wpdb;
        $row = \FChubMemberships\Support\CustomTableDatabase::getRow(\FChubMemberships\Support\CustomTableDatabase::prepare(
            "SELECT * FROM {$this->table} WHERE id = %d",
            $id
        ), ARRAY_A);

        return $row ? $this->hydrate($row) : null;
    }

    public function getEntitlementBackfillWatermark(): int
    {
        global $wpdb;

        $watermark = \FChubMemberships\Support\CustomTableDatabase::getVar(
            \FChubMemberships\Support\CustomTableDatabase::prepare(
                "SELECT COALESCE(MAX(id), 0) FROM {$this->table} WHERE 1 = %d",
                1,
            ),
        );
        if ($watermark === null || !empty($wpdb->last_error)) {
            throw new \RuntimeException('The entitlement backfill watermark could not be read.');
        }

        return (int) $watermark;
    }

    public function getEntitlementBackfillBatch(int $after, int $through, int $limit): array
    {
        global $wpdb;

        $rows = \FChubMemberships\Support\CustomTableDatabase::getResults(\FChubMemberships\Support\CustomTableDatabase::prepare(
            "SELECT * FROM {$this->table}
             WHERE id > %d AND id <= %d
             ORDER BY id ASC
             LIMIT %d",
            $after,
            $through,
            $limit
        ), ARRAY_A);
        if (!is_array($rows) || !empty($wpdb->last_error)) {
            throw new \RuntimeException('The entitlement backfill grants could not be read.');
        }

        return array_map([$this, 'hydrate'], $rows ?: []);
    }

    public function findByGrantKey(string $grantKey): ?array
    {
        global $wpdb;
        $row = \FChubMemberships\Support\CustomTableDatabase::getRow(\FChubMemberships\Support\CustomTableDatabase::prepare(
            "SELECT * FROM {$this->table} WHERE grant_key = %s",
            $grantKey
        ), ARRAY_A);
        if (!empty($wpdb->last_error)) {
            throw new \RuntimeException('Unable to read grant by key.');
        }

        return $row ? $this->hydrate($row) : null;
    }

    /**
     * Generate the grant key (unique per user + resource combination).
     */
    public static function makeGrantKey(int $userId, string $provider, string $resourceType, string $resourceId): string
    {
        return md5($userId . $provider . $resourceType . $resourceId);
    }

    public function getByUserId(int $userId, array $filters = []): array
    {
        $this->ensureCacheContext();
        $cacheKey = 'user:' . $userId . ':' . md5(wp_json_encode($filters));
        if (array_key_exists($cacheKey, self::$requestCache)) {
            return self::$requestCache[$cacheKey];
        }

        global $wpdb;

        $where = ['user_id = %d'];
        $params = [$userId];

        if (!empty($filters['status'])) {
            $where[] = 'status = %s';
            $params[] = $filters['status'];
        }

        if (!empty($filters['plan_id'])) {
            $where[] = 'plan_id = %d';
            $params[] = (int) $filters['plan_id'];
        }

        if (!empty($filters['provider'])) {
            $where[] = 'provider = %s';
            $params[] = $filters['provider'];
        }

        $sql = "SELECT * FROM {$this->table} WHERE " . implode(' AND ', $where) . " ORDER BY created_at DESC";

        $rows = \FChubMemberships\Support\CustomTableDatabase::getResults(\FChubMemberships\Support\CustomTableDatabase::prepare($sql, ...$params), ARRAY_A);
        self::$requestCache[$cacheKey] = array_map([$this, 'hydrate'], $rows ?: []);
        return self::$requestCache[$cacheKey];
    }

    public function getByPlanId(int $planId, array $filters = []): array
    {
        global $wpdb;

        $where = ['plan_id = %d'];
        $params = [$planId];

        if (!empty($filters['status'])) {
            $where[] = 'status = %s';
            $params[] = $filters['status'];
        }

        $orderBy = 'created_at';
        $order = 'DESC';

        $sql = "SELECT * FROM {$this->table} WHERE " . implode(' AND ', $where) . " ORDER BY {$orderBy} {$order}";

        if (!empty($filters['per_page'])) {
            $page = max(1, (int) ($filters['page'] ?? 1));
            $perPage = (int) $filters['per_page'];
            $offset = ($page - 1) * $perPage;
            $sql .= \FChubMemberships\Support\CustomTableDatabase::prepare(' LIMIT %d OFFSET %d', $perPage, $offset)->sql();
        }

        $rows = \FChubMemberships\Support\CustomTableDatabase::getResults(\FChubMemberships\Support\CustomTableDatabase::prepare($sql, ...$params), ARRAY_A);
        return array_map([$this, 'hydrate'], $rows ?: []);
    }

    public function create(array $data): int
    {
        global $wpdb;

        $now = $this->nowStorage();
        $insert = [
            'user_id'          => (int) $data['user_id'],
            'plan_id'          => isset($data['plan_id']) ? (int) $data['plan_id'] : null,
            'provider'         => $data['provider'] ?? 'wordpress_core',
            'resource_type'    => $data['resource_type'],
            'resource_id'      => (string) $data['resource_id'],
            'source_type'      => $data['source_type'] ?? 'manual',
            'source_id'        => (int) ($data['source_id'] ?? 0),
            'feed_id'          => isset($data['feed_id']) ? (int) $data['feed_id'] : null,
            'grant_key'        => $data['grant_key'],
            'status'           => $data['status'] ?? 'active',
            'starts_at'        => $data['starts_at'] ?? null,
            'expires_at'       => $data['expires_at'] ?? null,
            'drip_available_at'         => $data['drip_available_at'] ?? null,
            'trial_ends_at'             => $data['trial_ends_at'] ?? null,
            'cancellation_requested_at' => $data['cancellation_requested_at'] ?? null,
            'cancellation_effective_at' => $data['cancellation_effective_at'] ?? null,
            'cancellation_reason'       => $data['cancellation_reason'] ?? null,
            'renewal_count'             => (int) ($data['renewal_count'] ?? 0),
            'source_ids'       => wp_json_encode($data['source_ids'] ?? []),
            'meta'             => wp_json_encode($data['meta'] ?? []),
            'created_at'       => $now,
            'updated_at'       => $now,
        ];

        \FChubMemberships\Support\CustomTableDatabase::insert($this->table, $insert);
        AccessEvaluator::clearCache();
        return (int) $wpdb->insert_id;
    }

    public function update(int $id, array $data): bool
    {
        global $wpdb;

        $update = ['updated_at' => $this->nowStorage()];

        $directFields = ['status', 'starts_at', 'expires_at', 'drip_available_at', 'source_type', 'trial_ends_at', 'cancellation_requested_at', 'cancellation_effective_at', 'cancellation_reason'];
        foreach ($directFields as $field) {
            if (array_key_exists($field, $data)) {
                $update[$field] = $data[$field];
            }
        }

        $intFields = ['plan_id', 'source_id', 'feed_id', 'renewal_count'];
        foreach ($intFields as $field) {
            if (array_key_exists($field, $data)) {
                $update[$field] = $data[$field] !== null ? (int) $data[$field] : null;
            }
        }

        $jsonFields = ['source_ids', 'meta'];
        foreach ($jsonFields as $field) {
            if (array_key_exists($field, $data)) {
                $update[$field] = wp_json_encode($data[$field]);
            }
        }

        $updated = \FChubMemberships\Support\CustomTableDatabase::update($this->table, $update, ['id' => $id]) !== false;
        if ($updated) {
            AccessEvaluator::clearCache();
        }
        return $updated;
    }

    public function delete(int $id): bool
    {
        global $wpdb;
        $deleted = \FChubMemberships\Support\CustomTableDatabase::delete($this->table, ['id' => $id]) !== false;
        if ($deleted) {
            AccessEvaluator::clearCache();
        }
        return $deleted;
    }

    /**
     * Check if a user has an active grant for a specific resource.
     */
    public function hasActiveGrant(int $userId, string $provider, string $resourceType, string $resourceId): bool
    {
        global $wpdb;
        $now = $this->nowStorage();

        return (bool) \FChubMemberships\Support\CustomTableDatabase::getVar(\FChubMemberships\Support\CustomTableDatabase::prepare(
            "SELECT COUNT(*) FROM {$this->table}
             WHERE user_id = %d
               AND provider = %s
               AND resource_type = %s
               AND resource_id = %s
               AND status = 'active'
               AND (starts_at IS NULL OR starts_at <= %s)
               AND (expires_at IS NULL OR expires_at > %s)",
            $userId,
            $provider,
            $resourceType,
            $resourceId,
            $now,
            $now
        ));
    }

    /**
     * Check if a user has an active grant for a resource, including drip availability.
     */
    public function hasAccessibleGrant(int $userId, string $provider, string $resourceType, string $resourceId): bool
    {
        global $wpdb;
        $now = $this->nowStorage();

        return (bool) \FChubMemberships\Support\CustomTableDatabase::getVar(\FChubMemberships\Support\CustomTableDatabase::prepare(
            "SELECT COUNT(*) FROM {$this->table}
             WHERE user_id = %d
               AND provider = %s
               AND resource_type = %s
               AND resource_id = %s
               AND status = 'active'
               AND (starts_at IS NULL OR starts_at <= %s)
               AND (expires_at IS NULL OR expires_at > %s)
               AND (drip_available_at IS NULL OR drip_available_at <= %s)",
            $userId,
            $provider,
            $resourceType,
            $resourceId,
            $now,
            $now,
            $now
        ));
    }

    /**
     * Get a user's active grant for a resource (returns the most recent).
     */
    public function getActiveGrant(int $userId, string $provider, string $resourceType, string $resourceId): ?array
    {
        $this->ensureCacheContext();
        $cacheKey = 'active:' . implode("\0", [$userId, $provider, $resourceType, $resourceId]);
        if (array_key_exists($cacheKey, self::$requestCache)) {
            return self::$requestCache[$cacheKey];
        }

        global $wpdb;
        $now = $this->nowStorage();

        $row = \FChubMemberships\Support\CustomTableDatabase::getRow(\FChubMemberships\Support\CustomTableDatabase::prepare(
            "SELECT * FROM {$this->table}
             WHERE user_id = %d
               AND provider = %s
               AND resource_type = %s
               AND resource_id = %s
               AND status = 'active'
               AND (starts_at IS NULL OR starts_at <= %s)
               AND (expires_at IS NULL OR expires_at > %s)
             ORDER BY created_at DESC
             LIMIT 1",
            $userId,
            $provider,
            $resourceType,
            $resourceId,
            $now,
            $now
        ), ARRAY_A);

        self::$requestCache[$cacheKey] = $row ? $this->hydrate($row) : null;
        return self::$requestCache[$cacheKey];
    }

    /**
     * Return currently effective plan lineages for scalar access evaluation.
     *
     * Typed entitlement edges are authoritative for managed lineages. Manual
     * and legacy grants remain eligible only when they are not compatibility
     * mirrors of a typed edge.
     *
     * @return list<array<string, mixed>>
     */
    public function getEffectivePlanMembershipsForUser(int $userId): array
    {
        return $this->getEffectivePlanMemberships($userId);
    }

    /**
     * Return currently effective membership lineages for one plan.
     *
     * @return list<array<string, mixed>>
     */
    public function getEffectivePlanMembershipsForUserByPlan(int $userId, int $planId): array
    {
        if ($planId <= 0) {
            return [];
        }

        return $this->getEffectivePlanMemberships($userId, $planId);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function getEffectivePlanMemberships(int $userId, ?int $planId = null): array
    {
        $this->ensureCacheContext();
        $cacheKey = 'memberships:' . $userId . ':' . ($planId ?? 'all');
        if (array_key_exists($cacheKey, self::$requestCache)) {
            return self::$requestCache[$cacheKey];
        }

        global $wpdb;
        $now = $this->nowStorage();
        $edgeTable = \FChubMemberships\Support\CustomTableDatabase::identifier($wpdb->prefix . 'fchub_membership_entitlement_edges');
        $edgePlanSql = '';
        $edgeParams = [$userId, $now, $now];
        if ($planId !== null) {
            $edgePlanSql = ' AND edge.plan_id = %d';
            $edgeParams[] = $planId;
        }
        $sql = \FChubMemberships\Support\CustomTableDatabase::prepare(
            "SELECT edge.id, edge.plan_id, edge.provider, edge.resource_type, edge.resource_id,
                    edge.starts_at, edge.expires_at, edge.created_at, edge.drip_available_at,
                    NULL AS trial_ends_at, edge.access_status
             FROM {$edgeTable} edge
             WHERE edge.user_id = %d
               AND edge.plan_id > 0
               AND edge.lifecycle = 'active'
               AND edge.access_status = 'active'
               AND (edge.starts_at IS NULL OR edge.starts_at <= %s)
               AND (edge.expires_at IS NULL OR edge.expires_at > %s)
               {$edgePlanSql}",
            ...$edgeParams
        );
        $rows = \FChubMemberships\Support\CustomTableDatabase::getResults($sql, ARRAY_A);
        if (!is_array($rows) || !empty($wpdb->last_error)) {
            throw new \RuntimeException('Unable to read effective plan memberships.');
        }
        $rows = array_values(array_filter(
            $rows,
            static fn(array $row): bool => (int) ($row['plan_id'] ?? 0) > 0
        ));

        $identityRows = \FChubMemberships\Support\CustomTableDatabase::getResults(\FChubMemberships\Support\CustomTableDatabase::prepare(
            "SELECT DISTINCT edge.provider, edge.resource_type, edge.resource_id
             FROM {$edgeTable} edge
             WHERE edge.user_id = %d",
            $userId
        ), ARRAY_A);
        if (!is_array($identityRows) || !empty($wpdb->last_error)) {
            throw new \RuntimeException('Unable to read typed membership identities.');
        }
        $typedIdentities = [];
        foreach ($identityRows as $identityRow) {
            $typedIdentities[implode("\0", [
                (string) ($identityRow['provider'] ?? ''),
                (string) ($identityRow['resource_type'] ?? ''),
                (string) ($identityRow['resource_id'] ?? ''),
            ])] = true;
        }

        $grantFilters = ['status' => 'active'];
        if ($planId !== null) {
            $grantFilters['plan_id'] = $planId;
        }
        foreach ($this->getByUserId($userId, $grantFilters) as $grant) {
            if ((int) ($grant['plan_id'] ?? 0) <= 0 || !$this->grantIsCurrentlyAccessible($grant, $now)) {
                continue;
            }
            $identity = implode("\0", [
                (string) ($grant['provider'] ?? ''),
                (string) ($grant['resource_type'] ?? ''),
                (string) ($grant['resource_id'] ?? ''),
            ]);
            $hasCompleteIdentity = isset($grant['provider'], $grant['resource_type'], $grant['resource_id']);
            if ($hasCompleteIdentity && isset($typedIdentities[$identity])) {
                continue;
            }
            $grant['access_status'] = 'active';
            $rows[] = $grant;
        }

        self::$requestCache[$cacheKey] = array_map(static function (array $row): array {
            $row['plan_id'] = (int) $row['plan_id'];
            $row['status'] = 'active';
            return $row;
        }, $rows);

        return self::$requestCache[$cacheKey];
    }

    /**
     * Count distinct users with effective access for a keyed resource batch.
     *
     * @param array<int|string, ResourceAccessPolicy> $policies
     * @return array<int|string, int>
     */
    public function countDistinctUsersWithResourceAccessBatch(array $policies): array
    {
        $counts = array_fill_keys(array_keys($policies), 0);
        if ($policies === []) {
            return $counts;
        }

        global $wpdb;
        $now = $this->nowStorage();
        $edgeTable = \FChubMemberships\Support\CustomTableDatabase::identifier($wpdb->prefix . 'fchub_membership_entitlement_edges');
        $selects = [];

        foreach ($policies as $resourceKey => $policy) {
            if (!$policy instanceof ResourceAccessPolicy) {
                throw new \InvalidArgumentException('Resource access policies must be ResourceAccessPolicy instances.');
            }

            $key = (string) $resourceKey;
            $selects[] = \FChubMemberships\Support\CustomTableDatabase::prepare(
                "SELECT %s AS _resource_key, direct.user_id
                 FROM {$this->table} direct
                 WHERE direct.provider = %s
                   AND direct.resource_type = %s
                   AND direct.resource_id IN (%s, '*')
                   AND direct.status = 'active'
                   AND (direct.starts_at IS NULL OR direct.starts_at <= %s)
                   AND (direct.expires_at IS NULL OR direct.expires_at > %s)
                   AND (direct.drip_available_at IS NULL OR direct.drip_available_at <= %s)",
                $key,
                $policy->provider(),
                $policy->resourceType(),
                $policy->resourceId(),
                $now,
                $now,
                $now
            )->sql();

            if (!$policy->hasPlanAccess()) {
                continue;
            }

            $edgePlanPredicate = $this->policyPlanSql($policy, 'edge', $now);
            $selects[] = \FChubMemberships\Support\CustomTableDatabase::prepare(
                "SELECT %s AS _resource_key, edge.user_id
                 FROM {$edgeTable} edge
                 WHERE edge.lifecycle = 'active'
                   AND edge.access_status = 'active'
                   AND (edge.starts_at IS NULL OR edge.starts_at <= %s)
                   AND (edge.expires_at IS NULL OR edge.expires_at > %s)
                   AND ({$edgePlanPredicate})",
                $key,
                $now,
                $now
            )->sql();

            $legacyPlanPredicate = $this->policyPlanSql($policy, 'membership', $now);
            $selects[] = \FChubMemberships\Support\CustomTableDatabase::prepare(
                "SELECT %s AS _resource_key, membership.user_id
                 FROM {$this->table} membership
                 WHERE membership.plan_id IS NOT NULL
                   AND membership.status = 'active'
                   AND (membership.starts_at IS NULL OR membership.starts_at <= %s)
                   AND (membership.expires_at IS NULL OR membership.expires_at > %s)
                   AND ({$legacyPlanPredicate})
                   AND NOT EXISTS (
                       SELECT 1 FROM {$edgeTable} typed
                       WHERE typed.user_id = membership.user_id
                         AND typed.provider = membership.provider
                         AND typed.resource_type = membership.resource_type
                         AND typed.resource_id = membership.resource_id
                   )",
                $key,
                $now,
                $now
            )->sql();
        }

        $sql = "SELECT access_rows._resource_key, COUNT(DISTINCT access_rows.user_id) AS member_count
                FROM (" . implode("\nUNION ALL\n", $selects) . ") access_rows
                GROUP BY access_rows._resource_key
                HAVING %d = 1";
        $rows = \FChubMemberships\Support\CustomTableDatabase::getResults(
            \FChubMemberships\Support\CustomTableDatabase::prepare($sql, 1),
            ARRAY_A,
        );
        if (!is_array($rows) || !empty($wpdb->last_error)) {
            throw new \RuntimeException('Unable to read effective resource access counts.');
        }
        foreach ($rows ?: [] as $row) {
            $key = (string) ($row['_resource_key'] ?? '');
            if (array_key_exists($key, $counts)) {
                $counts[$key] = (int) ($row['member_count'] ?? 0);
            }
        }

        return $counts;
    }

    public static function clearRequestCache(): void
    {
        self::$requestCache = [];
        self::$cacheContext = null;
    }

    private function policyPlanSql(ResourceAccessPolicy $policy, string $alias, string $now): string
    {
        global $wpdb;

        if ($policy->allowsAnyActivePlan()) {
            return "{$alias}.plan_id > 0";
        }

        $predicates = [];
        foreach ($policy->eligiblePlanIds() as $planId) {
            $pathPredicates = [];
            foreach ($policy->pathsForPlan($planId) as $path) {
                $pathPredicates[] = $this->policyPathSql($path, $alias, $now);
            }
            $predicates[] = \FChubMemberships\Support\CustomTableDatabase::prepare(
                "{$alias}.plan_id = %d AND (" . implode(' OR ', $pathPredicates) . ')',
                $planId
            )->sql();
        }

        return $predicates === [] ? '0 = 1' : implode(' OR ', array_map(
            static fn(string $predicate): string => '(' . $predicate . ')',
            $predicates
        ));
    }

    /** @param array<string, mixed> $path */
    private function policyPathSql(array $path, string $alias, string $now): string
    {
        global $wpdb;
        $conditions = [];
        if (($path['basis'] ?? 'membership') === 'resource') {
            $qualifier = is_array($path['qualifier'] ?? null) ? $path['qualifier'] : [];
            $conditions[] = \FChubMemberships\Support\CustomTableDatabase::prepare(
                "{$alias}.provider = %s AND {$alias}.resource_type = %s AND {$alias}.resource_id = %s",
                (string) ($qualifier['provider'] ?? ''),
                (string) ($qualifier['resource_type'] ?? ''),
                (string) ($qualifier['resource_id'] ?? '')
            )->sql();
            $conditions[] = \FChubMemberships\Support\CustomTableDatabase::prepare(
                "({$alias}.drip_available_at IS NULL OR {$alias}.drip_available_at <= %s)",
                $now
            )->sql();
        }

        $type = (string) ($path['drip_type'] ?? 'immediate');
        if ($type === 'delayed') {
            $days = max(0, (int) ($path['drip_delay_days'] ?? 0));
            $conditions[] = \FChubMemberships\Support\CustomTableDatabase::prepare(
                "DATE_ADD({$alias}.created_at, INTERVAL {$days} DAY) <= %s",
                $now
            )->sql();
        }
        if ($type === 'fixed_date' && !empty($path['drip_date'])) {
            $conditions[] = \FChubMemberships\Support\CustomTableDatabase::prepare(
                '%s >= %s',
                $now,
                (string) $path['drip_date'],
            )->sql();
        }

        return $conditions === [] ? '1 = 1' : implode(' AND ', $conditions);
    }

    /** @param array<string, mixed> $grant */
    private function grantIsCurrentlyAccessible(array $grant, string $now): bool
    {
        if (!empty($grant['starts_at']) && strcmp((string) $grant['starts_at'], $now) > 0) {
            return false;
        }

        return empty($grant['expires_at']) || strcmp((string) $grant['expires_at'], $now) > 0;
    }

    private function ensureCacheContext(): void
    {
        global $wpdb;
        if (self::$cacheContext === $wpdb) {
            return;
        }

        self::$requestCache = [];
        self::$cacheContext = $wpdb;
    }

    /**
     * Get grants that contain a specific source ID.
     * Tries the junction table first, falls back to JSON search.
     */
    public function getBySourceId(int $sourceId, string $sourceType = 'order'): array
    {
        // Try junction table first
        $junctionResults = $this->getBySourceIdFromJunction($sourceId, $sourceType);
        if (!empty($junctionResults)) {
            return $junctionResults;
        }

        global $wpdb;

        // Fall back to JSON source_ids search
        $rows = \FChubMemberships\Support\CustomTableDatabase::getResults(\FChubMemberships\Support\CustomTableDatabase::prepare(
            "SELECT * FROM {$this->table}
             WHERE source_type = %s
               AND (source_id = %d OR source_ids LIKE %s)",
            $sourceType,
            $sourceId,
            '%' . $wpdb->esc_like('"' . $sourceId . '"') . '%'
        ), ARRAY_A);

        // Filter in PHP for exact JSON match
        return array_map([$this, 'hydrate'], array_filter($rows ?: [], function ($row) use ($sourceId) {
            $sourceIds = json_decode($row['source_ids'] ?? '[]', true) ?: [];
            return in_array($sourceId, $sourceIds, false);
        }));
    }

    /**
     * Get grants by source ID using the junction table.
     */
    public function getBySourceIdFromJunction(int $sourceId, string $sourceType = 'order'): array
    {
        global $wpdb;
        $sourcesTable = \FChubMemberships\Support\CustomTableDatabase::identifier($wpdb->prefix . 'fchub_membership_grant_sources');

        // Check if junction table exists
        if (!\FChubMemberships\Support\CustomTableDatabase::getVar(
            \FChubMemberships\Support\CustomTableDatabase::prepare(
                'SHOW TABLES LIKE %s',
                $sourcesTable,
            ),
        )) {
            return [];
        }

        $rows = \FChubMemberships\Support\CustomTableDatabase::getResults(\FChubMemberships\Support\CustomTableDatabase::prepare(
            "SELECT g.* FROM {$this->table} g
             INNER JOIN {$sourcesTable} gs ON g.id = gs.grant_id
             WHERE gs.source_id = %d AND gs.source_type = %s",
            $sourceId,
            $sourceType
        ), ARRAY_A);

        return array_map([$this, 'hydrate'], $rows ?: []);
    }

    /**
     * Get all active grants for a user grouped by plan.
     */
    public function getActiveByUserGroupedByPlan(int $userId): array
    {
        global $wpdb;
        $now = $this->nowStorage();

        $rows = \FChubMemberships\Support\CustomTableDatabase::getResults(\FChubMemberships\Support\CustomTableDatabase::prepare(
            "SELECT * FROM {$this->table}
             WHERE user_id = %d
               AND status = 'active'
               AND (starts_at IS NULL OR starts_at <= %s)
               AND (expires_at IS NULL OR expires_at > %s)
             ORDER BY plan_id ASC, created_at ASC",
            $userId,
            $now,
            $now
        ), ARRAY_A);

        $grouped = [];
        foreach ($rows ?: [] as $row) {
            $hydrated = $this->hydrate($row);
            $planId = $hydrated['plan_id'] ?? 0;
            $grouped[$planId][] = $hydrated;
        }

        return $grouped;
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

    /**
     * Get active grants whose expires_at has passed (overdue for expiration).
     * Excludes anchor grants — those get paused, not expired.
     */
    public function getOverdueGrants(): array
    {
        global $wpdb;
        $now = $this->nowStorage();

        $rows = \FChubMemberships\Support\CustomTableDatabase::getResults(\FChubMemberships\Support\CustomTableDatabase::prepare(
            "SELECT * FROM {$this->table}
             WHERE status = 'active'
               AND expires_at IS NOT NULL
               AND expires_at <= %s
               AND (meta IS NULL OR meta NOT LIKE %s)",
            $now,
            '%"billing_anchor_day"%'
        ), ARRAY_A);

        return array_map([$this, 'hydrate'], $rows ?: []);
    }

    /**
     * Bulk expire grants whose expires_at has passed.
     * Excludes anchor grants — those get paused, not expired.
     */
    public function expireOverdueGrants(): int
    {
        global $wpdb;
        $now = $this->nowStorage();

        return (int) \FChubMemberships\Support\CustomTableDatabase::query(\FChubMemberships\Support\CustomTableDatabase::prepare(
            "UPDATE {$this->table}
             SET status = 'expired', updated_at = %s
             WHERE status = 'active'
               AND expires_at IS NOT NULL
               AND expires_at <= %s
               AND (meta IS NULL OR meta NOT LIKE %s)",
            $now,
            $now,
            '%"billing_anchor_day"%'
        ));
    }

    /**
     * Get active anchor grants whose expires_at has passed.
     * These should be paused (recoverable), not expired (terminal).
     */
    public function getOverdueAnchorGrants(): array
    {
        global $wpdb;
        $now = $this->nowStorage();

        $rows = \FChubMemberships\Support\CustomTableDatabase::getResults(\FChubMemberships\Support\CustomTableDatabase::prepare(
            "SELECT * FROM {$this->table}
             WHERE status = 'active'
               AND expires_at IS NOT NULL
               AND expires_at <= %s
               AND meta LIKE %s",
            $now,
            '%"billing_anchor_day"%'
        ), ARRAY_A);

        return array_map([$this, 'hydrate'], $rows ?: []);
    }

    /**
     * Get all user IDs with active grants for a specific plan.
     */
    public function getUserIdsForPlan(int $planId): array
    {
        global $wpdb;
        $now = $this->nowStorage();

        $rows = \FChubMemberships\Support\CustomTableDatabase::getResults(\FChubMemberships\Support\CustomTableDatabase::prepare(
            "SELECT DISTINCT user_id FROM {$this->table}
             WHERE plan_id = %d
               AND status = 'active'
               AND (starts_at IS NULL OR starts_at <= %s)
               AND (expires_at IS NULL OR expires_at > %s)",
            $planId,
            $now,
            $now
        ), ARRAY_A);

        return array_column($rows ?: [], 'user_id');
    }

    public function getPausedGrants(int $userId): array
    {
        global $wpdb;
        $rows = \FChubMemberships\Support\CustomTableDatabase::getResults(\FChubMemberships\Support\CustomTableDatabase::prepare(
            "SELECT * FROM {$this->table} WHERE user_id = %d AND status = 'paused' ORDER BY updated_at DESC",
            $userId
        ), ARRAY_A);
        return array_map([$this, 'hydrate'], $rows ?: []);
    }

    /**
     * @return int[]
     */
    public function getActiveSubscriptionSourceIds(): array
    {
        global $wpdb;

        $rows = \FChubMemberships\Support\CustomTableDatabase::getResults(
            \FChubMemberships\Support\CustomTableDatabase::prepare("SELECT DISTINCT source_id
             FROM {$this->table}
             WHERE status = %s
               AND source_type = %s
               AND source_id > %d",
                'active',
                'subscription',
                0,
            ),
            ARRAY_A
        );

        return array_map('intval', array_column($rows ?: [], 'source_id'));
    }

    /**
     * Get active/paused grants whose membership term has expired.
     * SQL pre-filters by meta LIKE, PHP post-filters for precise date comparison.
     */
    public function getTermExpiredGrants(?string $now = null): array
    {
        global $wpdb;
        $now = $now ?? $this->nowStorage();

        $rows = \FChubMemberships\Support\CustomTableDatabase::getResults(\FChubMemberships\Support\CustomTableDatabase::prepare(
            "SELECT * FROM {$this->table}
             WHERE status IN ('active', 'paused')
               AND meta LIKE %s",
            '%"membership_term_ends_at"%'
        ), ARRAY_A);

        if (empty($rows)) {
            return [];
        }

        $expired = [];
        foreach ($rows as $row) {
            $hydrated = $this->hydrate($row);
            $termEndsAt = $hydrated['meta']['membership_term_ends_at'] ?? null;
            if (
                $termEndsAt
                && $this->clock->parseLocal($termEndsAt)->getTimestamp()
                    <= $this->clock->parseLocal($now)->getTimestamp()
            ) {
                $expired[] = $hydrated;
            }
        }

        return $expired;
    }

    public function getDueGracePeriodGrants(int $limit = 100): array
    {
        global $wpdb;
        $now = $this->nowStorage();

        $rows = \FChubMemberships\Support\CustomTableDatabase::getResults(\FChubMemberships\Support\CustomTableDatabase::prepare(
            "SELECT * FROM {$this->table}
             WHERE status = 'active'
               AND cancellation_effective_at IS NOT NULL
               AND cancellation_effective_at <= %s
             LIMIT %d",
            $now,
            $limit
        ), ARRAY_A);

        return array_map([$this, 'hydrate'], $rows ?: []);
    }

    /**
     * Get all active resource IDs for a user, grouped by resource_type.
     *
     * @return array<string, string[]> e.g. ['post' => ['1','2'], 'page' => ['5'], ...]
     */
    public function getAllUserResourceIds(int $userId): array
    {
        global $wpdb;
        $now = $this->nowStorage();

        $rows = \FChubMemberships\Support\CustomTableDatabase::getResults(\FChubMemberships\Support\CustomTableDatabase::prepare(
            "SELECT DISTINCT resource_type, resource_id FROM {$this->table}
             WHERE user_id = %d
               AND status = 'active'
               AND (starts_at IS NULL OR starts_at <= %s)
               AND (expires_at IS NULL OR expires_at > %s)",
            $userId,
            $now,
            $now
        ), ARRAY_A);

        $grouped = [];
        foreach ($rows ?: [] as $row) {
            $grouped[$row['resource_type']][] = $row['resource_id'];
        }

        return $grouped;
    }

    /**
     * Get distinct plan IDs where user has active grants (not expired/revoked).
     *
     * @return int[]
     */
    public function getUserActivePlanIds(int $userId): array
    {
        global $wpdb;
        $now = $this->nowStorage();

        $rows = \FChubMemberships\Support\CustomTableDatabase::getResults(\FChubMemberships\Support\CustomTableDatabase::prepare(
            "SELECT DISTINCT plan_id FROM {$this->table}
             WHERE user_id = %d
               AND plan_id IS NOT NULL
               AND status = 'active'
               AND (starts_at IS NULL OR starts_at <= %s)
               AND (expires_at IS NULL OR expires_at > %s)",
            $userId,
            $now,
            $now
        ), ARRAY_A);

        return array_map('intval', array_column($rows ?: [], 'plan_id'));
    }

    private function nowStorage(): string
    {
        return $this->clock->storage($this->clock->now());
    }

    private function futureStorage(int $days, ?string $from = null): string
    {
        $base = $from === null ? $this->clock->now() : $this->clock->parseLocal($from);
        return $this->clock->storage($this->clock->plusDays($days, $base));
    }

    /**
     * Get the highest plan level among user's active grants.
     */
    public function getHighestActivePlanLevel(int $userId): int
    {
        $planIds = $this->getUserActivePlanIds($userId);
        if (empty($planIds)) {
            return 0;
        }

        $planRepo = new PlanRepository();
        $maxLevel = 0;
        foreach ($planIds as $planId) {
            $plan = $planRepo->find($planId);
            if ($plan) {
                $maxLevel = max($maxLevel, (int) ($plan['level'] ?? 0));
            }
        }

        return $maxLevel;
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

    private function hydrate(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $row['user_id'] = (int) $row['user_id'];
        $row['plan_id'] = $row['plan_id'] !== null ? (int) $row['plan_id'] : null;
        $row['source_id'] = (int) $row['source_id'];
        $row['feed_id'] = $row['feed_id'] !== null ? (int) $row['feed_id'] : null;
        $row['trial_ends_at'] = $row['trial_ends_at'] ?? null;
        $row['cancellation_requested_at'] = $row['cancellation_requested_at'] ?? null;
        $row['cancellation_effective_at'] = $row['cancellation_effective_at'] ?? null;
        $row['cancellation_reason'] = $row['cancellation_reason'] ?? null;
        $row['renewal_count'] = (int) ($row['renewal_count'] ?? 0);
        $row['source_ids'] = json_decode($row['source_ids'] ?? '[]', true) ?: [];
        $row['meta'] = json_decode($row['meta'] ?? '{}', true) ?: [];
        return $row;
    }
}

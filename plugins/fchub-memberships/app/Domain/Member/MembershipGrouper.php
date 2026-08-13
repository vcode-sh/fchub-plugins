<?php

namespace FChubMemberships\Domain\Member;

use FChubMemberships\Support\Clock;

defined('ABSPATH') || exit;

/**
 * Collapses grant rows into the memberships an administrator recognises.
 *
 * A plan writes one grant row per rule, so the storage row is never the unit a
 * member holds. Group by plan, keep plan-less grants standalone, and let the
 * rows survive only as the resources a membership unlocks.
 */
final class MembershipGrouper
{
    private const STATUS_RANK = ['active' => 0, 'scheduled' => 1, 'paused' => 2, 'revoked' => 3, 'expired' => 4];

    public function __construct(private ?Clock $clock = null)
    {
        $this->clock ??= new Clock();
    }

    /**
     * @param list<array<string, mixed>> $rows grant rows ordered newest first
     * @param array<int, string> $planTitles plan id to title
     * @return list<array<string, mixed>>
     */
    public function group(array $rows, array $planTitles): array
    {
        $now = $this->clock->storage($this->clock->now());
        $buckets = [];

        foreach ($rows as $row) {
            $planId = $row['plan_id'] ?? null;
            $key = $planId ? 'plan:' . $planId : 'grant:' . (int) $row['id'];
            $buckets[$key][] = $row;
        }

        $memberships = [];
        foreach ($buckets as $key => $bucket) {
            usort($bucket, static fn(array $a, array $b): int => strcmp(
                (string) ($a['created_at'] ?? ''),
                (string) ($b['created_at'] ?? '')
            ));
            $memberships[] = $this->compose($key, $bucket, $planTitles, $now);
        }

        usort($memberships, static fn(array $a, array $b): int => (
            [self::STATUS_RANK[$a['status']], $b['created_at']] <=> [self::STATUS_RANK[$b['status']], $a['created_at']]
        ));

        return $memberships;
    }

    /**
     * @param list<array<string, mixed>> $rows ordered oldest first
     * @param array<int, string> $planTitles
     * @return array<string, mixed>
     */
    private function compose(string $key, array $rows, array $planTitles, string $now): array
    {
        $first = $rows[0];
        $planId = $first['plan_id'] ?? null;

        return [
            'key' => $key,
            'plan_id' => $planId,
            'plan_title' => $this->title($planId, $planTitles),
            'status' => $this->status($rows, $now),
            'created_at' => $first['created_at'] ?? null,
            'starts_at' => $this->earliest(array_column($rows, 'starts_at')),
            'expires_at' => $this->latestOrLifetime(array_column($rows, 'expires_at')),
            'paused_at' => $this->pausedAt($rows),
            'trial_ends_at' => $first['trial_ends_at'] ?? null,
            'source_type' => $first['source_type'] ?? '',
            'source_id' => (int) ($first['source_id'] ?? 0),
            'renewal_count' => (int) ($first['renewal_count'] ?? 0),
            'grant_ids' => array_map(static fn(array $row): int => (int) $row['id'], $rows),
            'resources' => array_map(fn(array $row): array => $this->resource($row, $now), $rows),
        ];
    }

    /** @param array<int, string> $planTitles */
    private function title(?int $planId, array $planTitles): string
    {
        if (!$planId) {
            return __('Direct grant', 'fchub-memberships');
        }

        return $planTitles[$planId]
            ?? sprintf(/* translators: %d: plan id */ __('Plan #%d', 'fchub-memberships'), $planId);
    }

    /** @param list<array<string, mixed>> $rows */
    private function status(array $rows, string $now): string
    {
        $best = 'expired';
        foreach ($rows as $row) {
            $status = $this->resourceStatus($row, $now);
            if (self::STATUS_RANK[$status] < self::STATUS_RANK[$best]) {
                $best = $status;
            }
        }

        return $best;
    }

    /** @param array<string, mixed> $row */
    private function resourceStatus(array $row, string $now): string
    {
        $status = (string) ($row['status'] ?? 'expired');
        if ($status !== 'active') {
            return isset(self::STATUS_RANK[$status]) ? $status : 'expired';
        }

        if (!empty($row['starts_at']) && strcmp((string) $row['starts_at'], $now) > 0) {
            return 'scheduled';
        }

        if (!empty($row['expires_at']) && strcmp((string) $row['expires_at'], $now) <= 0) {
            return 'expired';
        }

        return 'active';
    }

    /** @param array<string, mixed> $row */
    private function resource(array $row, string $now): array
    {
        return [
            'grant_id' => (int) $row['id'],
            'provider' => (string) ($row['provider'] ?? ''),
            'resource_type' => (string) ($row['resource_type'] ?? ''),
            'resource_id' => (string) ($row['resource_id'] ?? ''),
            'status' => $this->resourceStatus($row, $now),
            'drip_available_at' => $row['drip_available_at'] ?? null,
        ];
    }

    /** @param list<mixed> $values */
    private function earliest(array $values): ?string
    {
        $dates = array_filter(array_map(static fn($value): string => (string) ($value ?? ''), $values));

        return $dates === [] ? null : min($dates);
    }

    /** @param list<mixed> $values */
    private function latestOrLifetime(array $values): ?string
    {
        foreach ($values as $value) {
            if (empty($value)) {
                return null;
            }
        }

        return $values === [] ? null : max(array_map(static fn($value): string => (string) $value, $values));
    }

    /** @param list<array<string, mixed>> $rows */
    private function pausedAt(array $rows): ?string
    {
        $dates = [];
        foreach ($rows as $row) {
            $pausedAt = $row['meta']['paused_at'] ?? null;
            if ($row['status'] === 'paused' && $pausedAt) {
                $dates[] = (string) $pausedAt;
            }
        }

        return $dates === [] ? null : max($dates);
    }
}

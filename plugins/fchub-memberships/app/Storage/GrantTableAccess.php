<?php

namespace FChubMemberships\Storage;

use FChubMemberships\Support\Clock;

defined('ABSPATH') || exit;

/**
 * How a grant row is reached and read.
 *
 * Both readers of the grants table need the same table identifier, the same
 * clock, and the same row hydration. Keeping that in one place stops the two
 * from drifting apart on what a stored date means.
 */
trait GrantTableAccess
{
    private string $table;
    private Clock $clock;

    private function initGrantTable(?Clock $clock): void
    {
        global $wpdb;
        $this->table = \FChubMemberships\Support\CustomTableDatabase::identifier($wpdb->prefix . 'fchub_membership_grants');
        $this->clock = $clock ?? new Clock();
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

    /** @param array<string, mixed> $row */
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

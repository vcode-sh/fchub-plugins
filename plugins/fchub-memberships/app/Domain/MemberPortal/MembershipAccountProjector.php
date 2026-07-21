<?php

declare(strict_types=1);

namespace FChubMemberships\Domain\MemberPortal;

defined('ABSPATH') || exit;

final class MembershipAccountProjector
{
    private const CURRENT_STATUSES = ['active', 'paused'];
    private const TERMINAL_STATUSES = ['expired', 'revoked'];

    /**
     * Convert resource-level grants into membership-level episodes.
     *
     * @return array{current: array<int, array>, history: array<int, array>}
     */
    public function project(array $grants, ?string $now = null): array
    {
        $now ??= current_time('mysql');
        $currentGroups = [];
        $historyGroups = [];

        foreach ($grants as $grant) {
            $status = (string) ($grant['status'] ?? '');

            if (in_array($status, self::CURRENT_STATUSES, true)) {
                if (!$this->isCurrentAt($grant, $now)) {
                    continue;
                }

                $key = $this->episodeKey($grant);
                $currentGroups[$key][] = $grant;
                continue;
            }

            if (in_array($status, self::TERMINAL_STATUSES, true)) {
                $key = $this->episodeKey($grant);
                $historyGroups[$key][] = $grant;
            }
        }

        return [
            'current' => array_values(array_map([$this, 'summariseEpisode'], $currentGroups)),
            'history' => array_values(array_map([$this, 'summariseEpisode'], $historyGroups)),
        ];
    }

    /**
     * @return array{kind: 'lifetime'|'fixed'|'varies', expires_at: ?string}
     */
    public function summariseAccessDates(array $grants): array
    {
        $values = array_map(
            static fn(array $grant): ?string => !empty($grant['expires_at'])
                ? (string) $grant['expires_at']
                : null,
            $grants
        );

        $nonEmpty = array_values(array_unique(array_filter($values)));

        if ($nonEmpty === []) {
            return ['kind' => 'lifetime', 'expires_at' => null];
        }

        if (count($nonEmpty) === 1 && count($nonEmpty) === count(array_unique($values, SORT_REGULAR))) {
            return ['kind' => 'fixed', 'expires_at' => $nonEmpty[0]];
        }

        return ['kind' => 'varies', 'expires_at' => null];
    }

    private function episodeKey(array $grant): string
    {
        $planId = isset($grant['plan_id']) ? (int) $grant['plan_id'] : 0;
        $sourceType = (string) ($grant['source_type'] ?? 'manual');
        $sourceId = (int) ($grant['source_id'] ?? 0);

        return implode(':', [$planId, $sourceType, $sourceId]);
    }

    private function isCurrentAt(array $grant, string $now): bool
    {
        if (!empty($grant['starts_at']) && $grant['starts_at'] > $now) {
            return false;
        }

        return empty($grant['expires_at']) || $grant['expires_at'] > $now;
    }

    private function summariseEpisode(array $grants): array
    {
        $first = $grants[0];
        $accessDates = $this->summariseAccessDates($grants);
        $statuses = array_column($grants, 'status');

        $status = match (true) {
            in_array('active', $statuses, true) => 'active',
            in_array('paused', $statuses, true) => 'paused',
            in_array('revoked', $statuses, true) => 'revoked',
            default => 'expired',
        };

        $createdAt = array_values(array_filter(array_column($grants, 'created_at')));
        $updatedAt = array_values(array_filter(array_column($grants, 'updated_at')));

        return [
            'plan_id' => isset($first['plan_id']) ? (int) $first['plan_id'] : null,
            'status' => $status,
            'source_type' => (string) ($first['source_type'] ?? 'manual'),
            'source_id' => (int) ($first['source_id'] ?? 0),
            'resource_count' => count($grants),
            'starts_at' => $createdAt ? min($createdAt) : null,
            'updated_at' => $updatedAt ? max($updatedAt) : null,
            'expires_at' => $accessDates['expires_at'],
            'access_date_kind' => $accessDates['kind'],
            'cancellation_requested_at' => $this->latestValue($grants, 'cancellation_requested_at'),
            'cancellation_effective_at' => $this->latestValue($grants, 'cancellation_effective_at'),
            'grants' => array_values($grants),
        ];
    }

    private function latestValue(array $grants, string $key): ?string
    {
        $values = array_values(array_filter(array_column($grants, $key)));
        return $values ? max($values) : null;
    }
}

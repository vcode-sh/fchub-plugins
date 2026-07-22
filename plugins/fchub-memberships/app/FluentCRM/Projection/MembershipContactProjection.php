<?php

declare(strict_types=1);

namespace FChubMemberships\FluentCRM\Projection;

defined('ABSPATH') || exit;

final readonly class MembershipContactProjection
{
    private const STATUS_PRECEDENCE = [
        'none' => 0,
        'revoked' => 1,
        'expired' => 2,
        'paused' => 3,
        'active' => 4,
        'trial' => 5,
    ];

    /**
     * @param list<int> $activePlanIds
     * @param list<string> $activePlanTitles
     * @param list<string> $managedPlanTagNames
     */
    private function __construct(
        public int $userId,
        public string $status,
        public array $activePlanIds,
        public array $activePlanTitles,
        public array $managedPlanTagNames,
        public ?string $expiresAt,
        public ?string $trialEndsAt,
        public int $renewalCount,
        public ?string $memberSince,
        public bool $hasActiveMembership
    ) {
    }

    public static function fromGrants(int $userId, array $grants, array $plans): self
    {
        $plansById = self::indexPlans($plans);
        $groups = self::groupGrants($grants);
        $status = 'none';
        $activePlans = [];
        $activeExpiries = [];
        $hasLifetimeAccess = false;
        $trialEnds = [];
        $renewalCount = 0;
        $memberSince = null;

        foreach ($groups as $group) {
            $groupStatus = self::resolveStatus($group['grants']);
            if (self::STATUS_PRECEDENCE[$groupStatus] > self::STATUS_PRECEDENCE[$status]) {
                $status = $groupStatus;
            }

            $groupRenewalCount = 0;
            foreach ($group['grants'] as $grant) {
                $groupRenewalCount = max($groupRenewalCount, (int) ($grant['renewal_count'] ?? 0));
                $memberSince = self::earliestDate($memberSince, $grant['created_at'] ?? null);

                if (($grant['status'] ?? '') !== 'active') {
                    continue;
                }

                if (!array_key_exists('expires_at', $grant) || empty($grant['expires_at'])) {
                    $hasLifetimeAccess = true;
                } else {
                    $activeExpiries[] = (string) $grant['expires_at'];
                }

                if (!empty($grant['trial_ends_at'])) {
                    $trialEnds[] = (string) $grant['trial_ends_at'];
                }
            }
            $renewalCount += $groupRenewalCount;

            if ($group['plan_id'] === null || !in_array($groupStatus, ['active', 'trial'], true)) {
                continue;
            }

            $planId = $group['plan_id'];
            $plan = $plansById[$planId] ?? [];
            $title = trim((string) ($plan['title'] ?? ''));
            if ($title === '') {
                $title = 'Plan #' . $planId;
            }
            $tagSource = (string) ($plan['slug'] ?? $title);

            $activePlans[$planId] = [
                'title' => $title,
                'tag' => self::sanitiseTagName($tagSource),
            ];
        }

        ksort($activePlans, SORT_NUMERIC);

        $tagNames = array_column($activePlans, 'tag');
        $tagNames = array_values(array_unique(array_filter(
            $tagNames,
            static fn(string $tagName): bool => $tagName !== ''
        )));
        $hasActiveMembership = in_array($status, ['active', 'trial'], true);

        return new self(
            $userId,
            $status,
            array_map('intval', array_keys($activePlans)),
            array_values(array_column($activePlans, 'title')),
            $tagNames,
            $hasLifetimeAccess ? null : self::latestDate($activeExpiries),
            self::latestDate($trialEnds),
            $renewalCount,
            $memberSince,
            $hasActiveMembership
        );
    }

    /** @return array<int, array<string, mixed>> */
    private static function indexPlans(array $plans): array
    {
        $indexed = [];

        foreach ($plans as $key => $plan) {
            if (!is_array($plan)) {
                continue;
            }

            $planId = (int) ($plan['id'] ?? $key);
            if ($planId > 0) {
                $indexed[$planId] = $plan;
            }
        }

        return $indexed;
    }

    /**
     * @return list<array{plan_id:?int, grants:list<array<string, mixed>>}>
     */
    private static function groupGrants(array $grants): array
    {
        $groups = [];

        foreach (array_values($grants) as $grant) {
            if (!is_array($grant)) {
                continue;
            }

            $planId = isset($grant['plan_id']) && (int) $grant['plan_id'] > 0
                ? (int) $grant['plan_id']
                : null;
            $key = $planId !== null
                ? 'plan:' . $planId
                : 'direct';

            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'plan_id' => $planId,
                    'grants' => [],
                ];
            }

            $groups[$key]['grants'][] = $grant;
        }

        return array_values($groups);
    }

    private static function resolveStatus(array $grants): string
    {
        $status = 'none';

        foreach ($grants as $grant) {
            $grantStatus = (string) ($grant['status'] ?? 'none');
            if ($grantStatus === 'active' && !empty($grant['trial_ends_at'])) {
                $grantStatus = 'trial';
            }

            if (
                isset(self::STATUS_PRECEDENCE[$grantStatus])
                && self::STATUS_PRECEDENCE[$grantStatus] > self::STATUS_PRECEDENCE[$status]
            ) {
                $status = $grantStatus;
            }
        }

        return $status;
    }

    private static function earliestDate(?string $current, mixed $candidate): ?string
    {
        if (!is_string($candidate) || $candidate === '' || strtotime($candidate) === false) {
            return $current;
        }

        if ($current === null || strtotime($candidate) < strtotime($current)) {
            return $candidate;
        }

        return $current;
    }

    /** @param list<string> $dates */
    private static function latestDate(array $dates): ?string
    {
        $latest = null;

        foreach ($dates as $date) {
            if ($date === '' || strtotime($date) === false) {
                continue;
            }

            if ($latest === null || strtotime($date) > strtotime($latest)) {
                $latest = $date;
            }
        }

        return $latest;
    }

    private static function sanitiseTagName(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';

        return trim($value, '-');
    }
}

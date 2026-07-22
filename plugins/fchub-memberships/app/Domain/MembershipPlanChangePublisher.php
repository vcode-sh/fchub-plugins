<?php

namespace FChubMemberships\Domain;

defined('ABSPATH') || exit;

final class MembershipPlanChangePublisher
{
    public function publish(int $userId, array $fromPlanIds, int $toPlanId, string $changeType, array $context = []): void
    {
        $fromPlanIds = array_values(array_unique(array_map('intval', $fromPlanIds)));
        sort($fromPlanIds, SORT_NUMERIC);

        do_action('fchub_memberships/plan_changed', [
            'user_id' => $userId,
            'from_plan_ids' => $fromPlanIds,
            'to_plan_id' => $toPlanId,
            'change_type' => $changeType,
            'source_type' => (string) ($context['source_type'] ?? 'manual'),
            'source_id' => (int) ($context['source_id'] ?? 0),
            'occurred_at' => current_time('mysql'),
        ]);
    }
}

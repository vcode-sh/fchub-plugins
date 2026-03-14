<?php

namespace FChubMemberships\Domain\Grant;

use FChubMemberships\Domain\AuditLogger;
use FChubMemberships\Domain\GrantAdapterRegistry;
use FChubMemberships\Storage\DripScheduleRepository;
use FChubMemberships\Storage\GrantRepository;
use FChubMemberships\Storage\GrantSourceRepository;

defined('ABSPATH') || exit;

final class GrantCreationService
{
    public function __construct(
        private GrantRepository $grants,
        private GrantSourceRepository $sources,
        private DripScheduleRepository $drips,
        private GrantAdapterRegistry $adapters
    ) {
    }

    public function grantResource(int $userId, string $provider, string $resourceType, string $resourceId, array $context = []): array
    {
        $grantKey = GrantRepository::makeGrantKey($userId, $provider, $resourceType, $resourceId);
        $existing = $this->grants->findByGrantKey($grantKey);
        $sourceId = (int) ($context['source_id'] ?? 0);

        if ($existing) {
            $sourceIds = $existing['source_ids'];
            if ($sourceId && !in_array($sourceId, $sourceIds, false)) {
                $sourceIds[] = $sourceId;
            }

            $updateData = [
                'status' => 'active',
                'source_ids' => $sourceIds,
                'renewal_count' => ($existing['renewal_count'] ?? 0) + 1,
            ];

            if (!empty($context['expires_at'])) {
                if (empty($existing['expires_at']) || strtotime($context['expires_at']) > strtotime($existing['expires_at'])) {
                    $updateData['expires_at'] = $context['expires_at'];
                }
            }

            if (isset($context['plan_id'])) {
                $updateData['plan_id'] = $context['plan_id'];
            }

            // Merge context meta into existing grant meta (preserves existing keys,
            // overwrites overlapping ones like membership_term_ends_at on renewal)
            $contextMeta = $context['meta'] ?? [];
            $existingMeta = $existing['meta'] ?? [];
            if ($contextMeta || $existingMeta) {
                $updateData['meta'] = array_merge($existingMeta, $contextMeta);
            }

            $this->grants->update($existing['id'], $updateData);

            if ($sourceId) {
                $this->sources->addSource($existing['id'], $context['source_type'] ?? 'manual', $sourceId);
            }

            AuditLogger::logGrantChange($existing['id'], 'renewed', $existing, $updateData);
            do_action('fchub_memberships/grant_renewed', $existing, $updateData['renewal_count']);

            return ['action' => 'updated', 'grant_id' => $existing['id']];
        }

        $dripAvailableAt = $this->calculateDripDate($context['drip_rule'] ?? null);
        $isTrial = !empty($context['is_trial']);

        $grantData = [
            'user_id' => $userId,
            'plan_id' => $context['plan_id'] ?? null,
            'provider' => $provider,
            'resource_type' => $resourceType,
            'resource_id' => $resourceId,
            'source_type' => $isTrial ? 'trial' : ($context['source_type'] ?? 'manual'),
            'source_id' => $sourceId,
            'feed_id' => $context['feed_id'] ?? null,
            'grant_key' => $grantKey,
            'status' => 'active',
            'expires_at' => $isTrial ? ($context['trial_ends_at'] ?? null) : ($context['expires_at'] ?? null),
            'trial_ends_at' => $context['trial_ends_at'] ?? null,
            'drip_available_at' => $dripAvailableAt,
            'source_ids' => $sourceId ? [$sourceId] : [],
            'meta' => $context['meta'] ?? [],
        ];

        $grantId = $this->grants->create($grantData);

        if ($sourceId) {
            $this->sources->addSource($grantId, $grantData['source_type'], $sourceId);
        }

        AuditLogger::logGrantChange($grantId, 'created', [], $grantData);

        if ($dripAvailableAt && isset($context['drip_rule'])) {
            $this->drips->schedule([
                'grant_id' => $grantId,
                'plan_rule_id' => $context['drip_rule']['id'] ?? 0,
                'user_id' => $userId,
                'notify_at' => $dripAvailableAt,
            ]);
        }

        $adapter = $this->adapters->resolve($provider);
        if ($adapter) {
            $adapter->grant($userId, $resourceType, $resourceId, $context);
        }

        return ['action' => 'created', 'grant_id' => $grantId];
    }

    private function calculateDripDate(?array $dripRule): ?string
    {
        if (!$dripRule || $dripRule['drip_type'] === 'immediate') {
            return null;
        }

        if ($dripRule['drip_type'] === 'delayed' && $dripRule['drip_delay_days'] > 0) {
            return date('Y-m-d H:i:s', strtotime('+' . (int) $dripRule['drip_delay_days'] . ' days'));
        }

        if ($dripRule['drip_type'] === 'fixed_date' && !empty($dripRule['drip_date'])) {
            return $dripRule['drip_date'];
        }

        return null;
    }
}

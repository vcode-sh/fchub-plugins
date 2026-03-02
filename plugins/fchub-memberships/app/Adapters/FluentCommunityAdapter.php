<?php

namespace FChubMemberships\Adapters;

defined('ABSPATH') || exit;

use FChubMemberships\Adapters\Contracts\AccessAdapterInterface;
use FChubMemberships\Http\Controllers\SettingsController;

class FluentCommunityAdapter implements AccessAdapterInterface
{
    public function supports(string $resourceType): bool
    {
        return in_array($resourceType, ['fc_space', 'fc_group'], true);
    }

    public function grant(int $userId, string $resourceType, string $resourceId, array $context = []): array
    {
        if (!$this->isActive()) {
            return [
                'success' => true,
                'message' => __('FluentCommunity not active. Grant recorded in membership grants.', 'fchub-memberships'),
            ];
        }

        if ($resourceType === 'fc_space') {
            $result = $this->addToSpace($userId, (int) $resourceId);
            if ($result) {
                $this->maybeAssignBadge($userId, $context);
                return [
                    'success' => true,
                    'message' => sprintf(
                        __('Added to space: %s', 'fchub-memberships'),
                        $this->getResourceLabel($resourceType, $resourceId)
                    ),
                ];
            }

            return [
                'success' => false,
                'message' => __('Failed to add user to FluentCommunity space.', 'fchub-memberships'),
            ];
        }

        if ($resourceType === 'fc_group') {
            $result = $this->addToGroup($userId, (int) $resourceId);
            if ($result) {
                $this->maybeAssignBadge($userId, $context);
                return [
                    'success' => true,
                    'message' => sprintf(
                        __('Added to group: %s', 'fchub-memberships'),
                        $this->getResourceLabel($resourceType, $resourceId)
                    ),
                ];
            }

            return [
                'success' => false,
                'message' => __('Failed to add user to FluentCommunity group.', 'fchub-memberships'),
            ];
        }

        return [
            'success' => false,
            'message' => __('Unsupported FluentCommunity resource type.', 'fchub-memberships'),
        ];
    }

    public function revoke(int $userId, string $resourceType, string $resourceId, array $context = []): array
    {
        if (!$this->isActive()) {
            return [
                'success' => true,
                'message' => __('FluentCommunity not active. Revocation recorded in membership grants.', 'fchub-memberships'),
            ];
        }

        if ($resourceType === 'fc_space') {
            $this->removeFromSpace($userId, (int) $resourceId);
            $this->maybeRevokeBadge($userId, $context);

            return [
                'success' => true,
                'message' => sprintf(
                    __('Removed from space: %s', 'fchub-memberships'),
                    $this->getResourceLabel($resourceType, $resourceId)
                ),
            ];
        }

        if ($resourceType === 'fc_group') {
            $this->removeFromGroup($userId, (int) $resourceId);
            $this->maybeRevokeBadge($userId, $context);

            return [
                'success' => true,
                'message' => sprintf(
                    __('Removed from group: %s', 'fchub-memberships'),
                    $this->getResourceLabel($resourceType, $resourceId)
                ),
            ];
        }

        return [
            'success' => false,
            'message' => __('Unsupported FluentCommunity resource type.', 'fchub-memberships'),
        ];
    }

    public function check(int $userId, string $resourceType, string $resourceId): bool
    {
        if (!$this->isActive()) {
            return false;
        }

        if ($resourceType === 'fc_space') {
            return $this->isSpaceMember($userId, (int) $resourceId);
        }

        if ($resourceType === 'fc_group') {
            return $this->isGroupMember($userId, (int) $resourceId);
        }

        return false;
    }

    public function getResourceLabel(string $resourceType, string $resourceId): string
    {
        if (!$this->isActive()) {
            $prefix = $resourceType === 'fc_space'
                ? __('Space', 'fchub-memberships')
                : __('Group', 'fchub-memberships');

            return sprintf('%s #%s', $prefix, $resourceId);
        }

        if ($resourceType === 'fc_space') {
            $space = $this->findSpace((int) $resourceId);
            return $space ? $space->title : sprintf(__('Space #%s', 'fchub-memberships'), $resourceId);
        }

        if ($resourceType === 'fc_group') {
            $group = $this->findGroup((int) $resourceId);
            return $group ? $group->title : sprintf(__('Group #%s', 'fchub-memberships'), $resourceId);
        }

        return sprintf('#%s', $resourceId);
    }

    public function searchResources(string $query, string $resourceType, int $limit = 20): array
    {
        if (!$this->isActive()) {
            return [];
        }

        if ($resourceType === 'fc_space') {
            return $this->searchSpaces($query, $limit);
        }

        if ($resourceType === 'fc_group') {
            return $this->searchGroups($query, $limit);
        }

        return [];
    }

    public function getResourceTypes(): array
    {
        return [
            'fc_space' => __('Community Space', 'fchub-memberships'),
            'fc_group' => __('Community Group', 'fchub-memberships'),
        ];
    }

    private function addToSpace(int $userId, int $spaceId): bool
    {
        if (!class_exists('FluentCommunity\App\Models\Space')) {
            return false;
        }

        $space = \FluentCommunity\App\Models\Space::find($spaceId);
        if (!$space) {
            return false;
        }

        $space->addMember($userId, 'member');
        return true;
    }

    private function removeFromSpace(int $userId, int $spaceId): void
    {
        if (!class_exists('FluentCommunity\App\Models\Space')) {
            return;
        }

        $space = \FluentCommunity\App\Models\Space::find($spaceId);
        if ($space) {
            $space->removeMember($userId);
        }
    }

    private function isSpaceMember(int $userId, int $spaceId): bool
    {
        if (!class_exists('FluentCommunity\App\Models\Space')) {
            return false;
        }

        $space = \FluentCommunity\App\Models\Space::find($spaceId);
        if (!$space) {
            return false;
        }

        return $space->members()->where('user_id', $userId)->exists();
    }

    private function findSpace(int $spaceId): ?object
    {
        if (!class_exists('FluentCommunity\App\Models\Space')) {
            return null;
        }

        return \FluentCommunity\App\Models\Space::find($spaceId);
    }

    private function searchSpaces(string $query, int $limit): array
    {
        if (!class_exists('FluentCommunity\App\Models\Space')) {
            return [];
        }

        $builder = \FluentCommunity\App\Models\Space::query();
        if ($query !== '') {
            $builder->where('title', 'LIKE', '%' . $query . '%');
        }

        $spaces = $builder->limit($limit)->get();
        $results = [];

        foreach ($spaces as $space) {
            $results[] = [
                'id'    => (string) $space->id,
                'label' => $space->title,
            ];
        }

        return $results;
    }

    private function addToGroup(int $userId, int $groupId): bool
    {
        if (!class_exists('FluentCommunity\App\Models\SpaceGroup')) {
            return false;
        }

        $group = \FluentCommunity\App\Models\SpaceGroup::find($groupId);
        if (!$group) {
            return false;
        }

        $group->addMember($userId, 'member');
        return true;
    }

    private function removeFromGroup(int $userId, int $groupId): void
    {
        if (!class_exists('FluentCommunity\App\Models\SpaceGroup')) {
            return;
        }

        $group = \FluentCommunity\App\Models\SpaceGroup::find($groupId);
        if ($group) {
            $group->removeMember($userId);
        }
    }

    private function isGroupMember(int $userId, int $groupId): bool
    {
        if (!class_exists('FluentCommunity\App\Models\SpaceGroup')) {
            return false;
        }

        $group = \FluentCommunity\App\Models\SpaceGroup::find($groupId);
        if (!$group) {
            return false;
        }

        return $group->members()->where('user_id', $userId)->exists();
    }

    private function findGroup(int $groupId): ?object
    {
        if (!class_exists('FluentCommunity\App\Models\SpaceGroup')) {
            return null;
        }

        return \FluentCommunity\App\Models\SpaceGroup::find($groupId);
    }

    private function searchGroups(string $query, int $limit): array
    {
        if (!class_exists('FluentCommunity\App\Models\SpaceGroup')) {
            return [];
        }

        $builder = \FluentCommunity\App\Models\SpaceGroup::query();
        if ($query !== '') {
            $builder->where('title', 'LIKE', '%' . $query . '%');
        }

        $groups = $builder->limit($limit)->get();
        $results = [];

        foreach ($groups as $group) {
            $results[] = [
                'id'    => (string) $group->id,
                'label' => $group->title,
            ];
        }

        return $results;
    }

    private function maybeAssignBadge(int $userId, array $context): void
    {
        if (!class_exists('FluentCommunity\App\Models\Badge')) {
            return;
        }

        $planId = $context['plan_id'] ?? null;
        if (!$planId) {
            return;
        }

        $settings = SettingsController::getSettings();
        $badgeMappings = $settings['fc_badge_mappings'] ?? [];
        $badgeId = $badgeMappings[$planId] ?? null;

        if (!$badgeId) {
            return;
        }

        $badge = \FluentCommunity\App\Models\Badge::find((int) $badgeId);
        if ($badge) {
            $badge->assignToUser($userId);
        }
    }

    private function maybeRevokeBadge(int $userId, array $context): void
    {
        if (!class_exists('FluentCommunity\App\Models\Badge')) {
            return;
        }

        $settings = SettingsController::getSettings();
        if (($settings['fc_remove_badge_on_revoke'] ?? 'no') !== 'yes') {
            return;
        }

        $planId = $context['plan_id'] ?? null;
        if (!$planId) {
            return;
        }

        $badgeMappings = $settings['fc_badge_mappings'] ?? [];
        $badgeId = $badgeMappings[$planId] ?? null;

        if (!$badgeId) {
            return;
        }

        $badge = \FluentCommunity\App\Models\Badge::find((int) $badgeId);
        if ($badge) {
            $badge->removeFromUser($userId);
        }
    }

    private function isActive(): bool
    {
        return defined('FLUENT_COMMUNITY_PLUGIN_VERSION');
    }
}

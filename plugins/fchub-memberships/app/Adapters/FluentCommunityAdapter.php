<?php

namespace FChubMemberships\Adapters;

defined('ABSPATH') || exit;

use FChubMemberships\Adapters\Contracts\AccessAdapterInterface;
use FChubMemberships\Http\Controllers\SettingsController;

class FluentCommunityAdapter implements AccessAdapterInterface
{
    public function supports(string $resourceType): bool
    {
        return in_array($resourceType, ['fc_space', 'fc_course'], true);
    }

    public function grant(int $userId, string $resourceType, string $resourceId, array $context = []): array
    {
        if (!$this->supports($resourceType)) {
            return $this->unsupportedResourceResponse();
        }

        if (!$this->isActive()) {
            return [
                'success' => false,
                'message' => __('FluentCommunity is not active, so provider access could not be granted.', 'fchub-memberships'),
            ];
        }

        if ($resourceType === 'fc_space') {
            $result = $this->grantSpace($userId, (int) $resourceId);
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

        if ($resourceType === 'fc_course') {
            $result = $this->grantCourse($userId, (int) $resourceId);
            if ($result) {
                $this->maybeAssignBadge($userId, $context);
                return [
                    'success' => true,
                    'message' => sprintf(
                        __('Enrolled in course: %s', 'fchub-memberships'),
                        $this->getResourceLabel($resourceType, $resourceId)
                    ),
                ];
            }

            return [
                'success' => false,
                'message' => __('Failed to enroll user in FluentCommunity course.', 'fchub-memberships'),
            ];
        }

        return $this->unsupportedResourceResponse();
    }

    public function revoke(int $userId, string $resourceType, string $resourceId, array $context = []): array
    {
        if (!$this->supports($resourceType)) {
            return $this->unsupportedResourceResponse();
        }

        if (!$this->isActive()) {
            return [
                'success' => false,
                'message' => __('FluentCommunity is not active, so provider access could not be revoked.', 'fchub-memberships'),
            ];
        }

        if ($resourceType === 'fc_space') {
            $result = $this->revokeSpace($userId, (int) $resourceId);
        }

        if ($resourceType === 'fc_course') {
            $result = $this->revokeCourse($userId, (int) $resourceId);
        }

        if ($result) {
            $this->maybeRevokeBadge($userId, $context);

            return [
                'success' => true,
                'message' => sprintf(
                    $resourceType === 'fc_space'
                        ? __('Removed from space: %s', 'fchub-memberships')
                        : __('Removed from course: %s', 'fchub-memberships'),
                    $this->getResourceLabel($resourceType, $resourceId)
                ),
            ];
        }

        return [
            'success' => false,
            'message' => $resourceType === 'fc_space'
                ? __('Failed to remove user from FluentCommunity space.', 'fchub-memberships')
                : __('Failed to remove user from FluentCommunity course.', 'fchub-memberships'),
        ];
    }

    public function check(int $userId, string $resourceType, string $resourceId): bool
    {
        if (!$this->isActive()) {
            return false;
        }

        return $this->supports($resourceType)
            && $this->isCommunityMember($userId, (int) $resourceId);
    }

    public function getResourceLabel(string $resourceType, string $resourceId): string
    {
        if (!$this->isActive()) {
            $prefix = $resourceType === 'fc_space'
                ? __('Space', 'fchub-memberships')
                : __('Course', 'fchub-memberships');

            return sprintf('%s #%s', $prefix, $resourceId);
        }

        if ($resourceType === 'fc_space') {
            $space = $this->findSpace((int) $resourceId);
            return $space ? $space->title : sprintf(__('Space #%s', 'fchub-memberships'), $resourceId);
        }

        if ($resourceType === 'fc_course') {
            $course = $this->findCourse((int) $resourceId);
            return $course ? $course->title : sprintf(__('Course #%s', 'fchub-memberships'), $resourceId);
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

        if ($resourceType === 'fc_course') {
            return $this->searchCourses($query, $limit);
        }

        return [];
    }

    public function getResourceTypes(): array
    {
        return [
            'fc_space' => __('Community Space', 'fchub-memberships'),
            'fc_course' => __('Community Course', 'fchub-memberships'),
        ];
    }

    private function grantSpace(int $userId, int $spaceId): bool
    {
        if (!class_exists('FluentCommunity\App\Services\Helper')) {
            return false;
        }

        if ($this->isCommunityMember($userId, $spaceId)) {
            return $this->refreshAccessSpaceCache($userId);
        }

        \FluentCommunity\App\Services\Helper::addToSpace($spaceId, $userId, 'member', 'by_admin');

        return $this->isCommunityMember($userId, $spaceId)
            && $this->refreshAccessSpaceCache($userId);
    }

    private function revokeSpace(int $userId, int $spaceId): bool
    {
        if (!class_exists('FluentCommunity\App\Services\Helper')) {
            return false;
        }

        if (!$this->isCommunityMember($userId, $spaceId)) {
            return true;
        }

        \FluentCommunity\App\Services\Helper::removeFromSpace($spaceId, $userId, 'by_admin');

        return !$this->isCommunityMember($userId, $spaceId);
    }

    private function grantCourse(int $userId, int $courseId): bool
    {
        if (!class_exists('FluentCommunity\Modules\Course\Services\CourseHelper')) {
            return false;
        }

        if ($this->isCommunityMember($userId, $courseId)) {
            return $this->refreshAccessSpaceCache($userId);
        }

        \FluentCommunity\Modules\Course\Services\CourseHelper::enrollCourse($courseId, $userId, 'by_admin');

        return $this->isCommunityMember($userId, $courseId)
            && $this->refreshAccessSpaceCache($userId);
    }

    private function revokeCourse(int $userId, int $courseId): bool
    {
        if (!class_exists('FluentCommunity\Modules\Course\Services\CourseHelper')) {
            return false;
        }

        if (!$this->isCommunityMember($userId, $courseId)) {
            return true;
        }

        \FluentCommunity\Modules\Course\Services\CourseHelper::leaveCourse($courseId, $userId, 'by_admin');

        return !$this->isCommunityMember($userId, $courseId);
    }

    private function isCommunityMember(int $userId, int $resourceId): bool
    {
        if (!class_exists('FluentCommunity\App\Services\Helper')) {
            return false;
        }

        return \FluentCommunity\App\Services\Helper::isUserInSpace($userId, $resourceId);
    }

    private function refreshAccessSpaceCache(int $userId): bool
    {
        if (!class_exists('FluentCommunity\App\Models\User')) {
            return false;
        }

        $user = \FluentCommunity\App\Models\User::find($userId);
        if (!$user) {
            return false;
        }

        $user->cacheAccessSpaces();

        return true;
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

    private function findCourse(int $courseId): ?object
    {
        if (!class_exists('FluentCommunity\Modules\Course\Model\Course')) {
            return null;
        }

        return \FluentCommunity\Modules\Course\Model\Course::find($courseId);
    }

    private function searchCourses(string $query, int $limit): array
    {
        if (!class_exists('FluentCommunity\Modules\Course\Model\Course')) {
            return [];
        }

        $builder = \FluentCommunity\Modules\Course\Model\Course::query();
        if ($query !== '') {
            $builder->where('title', 'LIKE', '%' . $query . '%');
        }
        $courses = $builder->limit($limit)->get();
        $results = [];

        foreach ($courses as $course) {
            $results[] = [
                'id'    => (string) $course->id,
                'label' => $course->title,
            ];
        }

        return $results;
    }

    private function unsupportedResourceResponse(): array
    {
        return [
            'success' => false,
            'message' => __('Unsupported FluentCommunity resource type.', 'fchub-memberships'),
        ];
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

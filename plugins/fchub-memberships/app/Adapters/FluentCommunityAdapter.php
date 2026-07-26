<?php

namespace FChubMemberships\Adapters;

defined('ABSPATH') || exit;

use FChubMemberships\Adapters\Contracts\AccessAdapterInterface;
use FChubMemberships\Adapters\Contracts\BatchResourceLabelAdapterInterface;
use FChubMemberships\Integration\Community\CommunityCapabilityRegistry;

class FluentCommunityAdapter implements AccessAdapterInterface, BatchResourceLabelAdapterInterface
{
    private const BADGE_LOCK_TIMEOUT_SECONDS = 10;

    public function __construct(private ?CommunityCapabilityRegistry $communityCapabilities = null)
    {
        $this->communityCapabilities ??= new CommunityCapabilityRegistry();
    }

    public function supports(string $resourceType): bool
    {
        $capability = match ($resourceType) {
            'fc_space' => 'spaces',
            'fc_course' => 'courses',
            'fc_badge' => 'badges',
            default => null,
        };

        return $capability !== null && $this->communityCapabilities->supports($capability);
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
                return [
                    'success' => true,
                    'message' => sprintf(
                        /* translators: Placeholder values are runtime membership details included in this message. */
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
                return [
                    'success' => true,
                    'message' => sprintf(
                        /* translators: Placeholder values are runtime membership details included in this message. */
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

        if ($resourceType === 'fc_badge') {
            return $this->grantBadge($userId, $resourceId);
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

        if ($resourceType === 'fc_badge') {
            return $this->revokeBadge($userId, $resourceId);
        }

        if ($result) {
            return [
                'success' => true,
                'message' => sprintf(
                    $resourceType === 'fc_space'
                        /* translators: Placeholder values are runtime membership details included in this message. */
                        ? __('Removed from space: %s', 'fchub-memberships')
                        /* translators: Placeholder values are runtime membership details included in this message. */
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

        if (!$this->supports($resourceType)) {
            return false;
        }

        if ($resourceType === 'fc_badge') {
            return $this->hasBadge($userId, $resourceId);
        }

        return $this->isCommunityMember($userId, (int) $resourceId);
    }

    public function getResourceLabel(string $resourceType, string $resourceId): string
    {
        if ($resourceType === 'fc_badge') {
            return $this->badgeLabel($resourceId);
        }

        if (!$this->isActive()) {
            $prefix = $resourceType === 'fc_space'
                ? __('Space', 'fchub-memberships')
                : __('Course', 'fchub-memberships');

            return sprintf('%s #%s', $prefix, $resourceId);
        }

        if ($resourceType === 'fc_space') {
            $space = $this->findSpace((int) $resourceId);
            /* translators: Placeholder values are runtime membership details included in this message. */
            return $space ? $space->title : sprintf(__('Space #%s', 'fchub-memberships'), $resourceId);
        }

        if ($resourceType === 'fc_course') {
            $course = $this->findCourse((int) $resourceId);
            /* translators: Placeholder values are runtime membership details included in this message. */
            return $course ? $course->title : sprintf(__('Course #%s', 'fchub-memberships'), $resourceId);
        }

        return sprintf('#%s', $resourceId);
    }

    public function getResourceLabels(string $resourceType, array $resourceIds): array
    {
        $resourceIds = array_values(array_unique(array_map('strval', $resourceIds)));
        if ($resourceType === 'fc_badge') {
            $labels = [];
            foreach ($resourceIds as $resourceId) {
                $labels[$resourceId] = $this->badgeLabel($resourceId);
            }

            return $labels;
        }
        $prefix = $resourceType === 'fc_space'
            ? __('Space', 'fchub-memberships')
            : __('Course', 'fchub-memberships');
        $labels = [];
        foreach ($resourceIds as $resourceId) {
            $labels[$resourceId] = sprintf('%s #%s', $prefix, $resourceId);
        }
        if ($resourceIds === [] || !$this->isActive()) {
            return $labels;
        }

        $modelClass = $resourceType === 'fc_space'
            ? \FluentCommunity\App\Models\Space::class
            : \FluentCommunity\Modules\Course\Model\Course::class;
        if (!class_exists($modelClass)) {
            return $labels;
        }

        $models = $modelClass::query()
            ->whereIn('id', array_values(array_filter(array_map('intval', $resourceIds))))
            ->get();
        foreach ($models as $model) {
            $id = (string) $model->id;
            if (array_key_exists($id, $labels)) {
                $labels[$id] = (string) $model->title;
            }
        }

        return $labels;
    }

    public function searchResources(string $query, string $resourceType, int $limit = 20): array
    {
        if (!$this->isActive() || !$this->supports($resourceType)) {
            return [];
        }

        if ($resourceType === 'fc_space') {
            return $this->searchSpaces($query, $limit);
        }

        if ($resourceType === 'fc_course') {
            return $this->searchCourses($query, $limit);
        }

        if ($resourceType === 'fc_badge') {
            return $this->searchBadges($query, $limit);
        }

        return [];
    }

    public function getResourceTypes(): array
    {
        $types = [];
        if ($this->supports('fc_space')) {
            $types['fc_space'] = __('Community Space', 'fchub-memberships');
        }
        if ($this->supports('fc_course')) {
            $types['fc_course'] = __('Community Course', 'fchub-memberships');
        }
        if ($this->supports('fc_badge')) {
            $types['fc_badge'] = __('Community Badge', 'fchub-memberships');
        }

        return $types;
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

    private function supportsBadges(): bool
    {
        return $this->communityCapabilities->supports('badges');
    }

    /** @return array<string, mixed> */
    private function badgeDefinitions(): array
    {
        if (!$this->supportsBadges()
            || !is_callable(['FluentCommunity\\App\\Functions\\Utility', 'getOption'])
        ) {
            return [];
        }

        $badges = \FluentCommunity\App\Functions\Utility::getOption('user_badges', []);

        return is_array($badges) ? $badges : [];
    }

    private function hasInstalledBadgeSlug(string $badgeSlug): bool
    {
        return $badgeSlug !== ''
            && sanitize_title($badgeSlug) === $badgeSlug
            && array_key_exists($badgeSlug, $this->badgeDefinitions());
    }

    private function grantBadge(int $userId, string $badgeSlug): array
    {
        return $this->withBadgeMutationLock(
            $userId,
            fn(): array => $this->grantBadgeLocked($userId, $badgeSlug)
        );
    }

    private function grantBadgeLocked(int $userId, string $badgeSlug): array
    {
        if (!$this->hasInstalledBadgeSlug($badgeSlug)) {
            return $this->unsupportedResourceResponse();
        }

        $profile = $this->findProfile($userId);
        if ($profile === null) {
            return [
                'success' => false,
                'message' => __('Failed to find the FluentCommunity profile for the badge assignment.', 'fchub-memberships'),
            ];
        }
        $meta = $this->profileMeta($profile);
        $badges = (array) ($meta['badge_slug'] ?? []);
        if (in_array($badgeSlug, $badges, true)) {
            return [
                'success' => true,
                /* translators: Placeholder values are runtime membership details included in this message. */
                'message' => sprintf(__('Badge already assigned: %s', 'fchub-memberships'), $this->badgeLabel($badgeSlug)),
            ];
        }

        $meta['badge_slug'] = array_values(array_unique(array_merge($badges, [$badgeSlug])));
        $profile->meta = $meta;

        if (!$profile->save() || !$this->hasBadge($userId, $badgeSlug)) {
            return [
                'success' => false,
                'message' => __('Failed to assign the FluentCommunity badge.', 'fchub-memberships'),
            ];
        }

        return [
            'success' => true,
            /* translators: Placeholder values are runtime membership details included in this message. */
            'message' => sprintf(__('Assigned badge: %s', 'fchub-memberships'), $this->badgeLabel($badgeSlug)),
        ];
    }

    private function revokeBadge(int $userId, string $badgeSlug): array
    {
        return $this->withBadgeMutationLock(
            $userId,
            fn(): array => $this->revokeBadgeLocked($userId, $badgeSlug)
        );
    }

    private function revokeBadgeLocked(int $userId, string $badgeSlug): array
    {
        if (!$this->hasInstalledBadgeSlug($badgeSlug)) {
            return $this->unsupportedResourceResponse();
        }

        $profile = $this->findProfile($userId);
        if ($profile === null) {
            return [
                'success' => false,
                'message' => __('Failed to find the FluentCommunity profile for the badge removal.', 'fchub-memberships'),
            ];
        }
        $meta = $this->profileMeta($profile);
        $badges = (array) ($meta['badge_slug'] ?? []);
        if (!in_array($badgeSlug, $badges, true)) {
            return [
                'success' => true,
                /* translators: Placeholder values are runtime membership details included in this message. */
                'message' => sprintf(__('Badge already absent: %s', 'fchub-memberships'), $this->badgeLabel($badgeSlug)),
            ];
        }

        $meta['badge_slug'] = array_values(array_diff($badges, [$badgeSlug]));
        $profile->meta = $meta;

        if (!$profile->save() || $this->hasBadge($userId, $badgeSlug)) {
            return [
                'success' => false,
                'message' => __('Failed to remove the FluentCommunity badge.', 'fchub-memberships'),
            ];
        }

        return [
            'success' => true,
            /* translators: Placeholder values are runtime membership details included in this message. */
            'message' => sprintf(__('Removed badge: %s', 'fchub-memberships'), $this->badgeLabel($badgeSlug)),
        ];
    }

    private function withBadgeMutationLock(int $userId, callable $mutation): array
    {
        $lockName = $this->badgeMutationLockName($userId);
        try {
            $acquired = $this->acquireBadgeMutationLock($lockName);
        } catch (\Throwable) {
            $acquired = false;
        }
        if (!$acquired) {
            return $this->badgeLockFailureResponse();
        }

        $result = $this->badgeLockFailureResponse();
        $released = false;
        try {
            $result = $mutation();
        } catch (\Throwable) {
            $result = $this->badgeLockFailureResponse();
        } finally {
            try {
                $released = $this->releaseBadgeMutationLock($lockName);
            } catch (\Throwable) {
                $released = false;
            }
        }

        return $released ? $result : $this->badgeLockFailureResponse();
    }

    private function acquireBadgeMutationLock(string $lockName): bool
    {
        global $wpdb;

        return (int) \FChubMemberships\Support\CustomTableDatabase::getVar(\FChubMemberships\Support\CustomTableDatabase::prepare(
            'SELECT GET_LOCK(%s, %d)',
            $lockName,
            self::BADGE_LOCK_TIMEOUT_SECONDS
        )) === 1;
    }

    private function releaseBadgeMutationLock(string $lockName): bool
    {
        global $wpdb;

        return (int) \FChubMemberships\Support\CustomTableDatabase::getVar(\FChubMemberships\Support\CustomTableDatabase::prepare(
            'SELECT RELEASE_LOCK(%s)',
            $lockName
        )) === 1;
    }

    private function badgeMutationLockName(int $userId): string
    {
        global $wpdb;

        $blogId = function_exists('get_current_blog_id') ? (int) get_current_blog_id() : 0;
        $scope = (string) ($wpdb->dbname ?? '')
            . "\0"
            . (string) ($wpdb->prefix ?? '')
            . "\0"
            . $blogId
            . "\0"
            . $userId;

        return 'fchub_fc_badge_' . substr(hash('sha256', $scope), 0, 49);
    }

    private function badgeLockFailureResponse(): array
    {
        return [
            'success' => false,
            'message' => __('FluentCommunity badge state is busy. Please retry.', 'fchub-memberships'),
        ];
    }

    private function hasBadge(int $userId, string $badgeSlug): bool
    {
        if (!$this->hasInstalledBadgeSlug($badgeSlug)) {
            return false;
        }

        $profile = $this->findProfile($userId);
        if ($profile === null) {
            return false;
        }

        return in_array($badgeSlug, (array) ($this->profileMeta($profile)['badge_slug'] ?? []), true);
    }

    private function findProfile(int $userId): ?object
    {
        if (!class_exists('FluentCommunity\\App\\Models\\XProfile')) {
            return null;
        }

        try {
            return \FluentCommunity\App\Models\XProfile::where('user_id', $userId)->first();
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return array<string, mixed> */
    private function profileMeta(object $profile): array
    {
        $meta = $profile->meta ?? [];

        return is_array($meta) ? $meta : [];
    }

    private function badgeLabel(string $badgeSlug): string
    {
        $definition = $this->badgeDefinitions()[$badgeSlug] ?? null;
        if (is_array($definition)) {
            foreach (['title', 'label', 'name'] as $key) {
                if (is_string($definition[$key] ?? null) && trim($definition[$key]) !== '') {
                    return trim($definition[$key]);
                }
            }
        }
        if (is_string($definition) && trim($definition) !== '') {
            return trim($definition);
        }

        /* translators: Placeholder values are runtime membership details included in this message. */
        return sprintf(__('Badge %s', 'fchub-memberships'), $badgeSlug);
    }

    private function searchBadges(string $query, int $limit): array
    {
        $needle = strtolower(trim($query));
        $results = [];
        foreach ($this->badgeDefinitions() as $badgeSlug => $_definition) {
            if (!is_string($badgeSlug) || !$this->hasInstalledBadgeSlug($badgeSlug)) {
                continue;
            }
            $label = $this->badgeLabel($badgeSlug);
            if ($needle !== ''
                && !str_contains(strtolower($badgeSlug), $needle)
                && !str_contains(strtolower($label), $needle)
            ) {
                continue;
            }
            $results[] = ['id' => $badgeSlug, 'label' => $label];
            if (count($results) >= $limit) {
                break;
            }
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

    private function isActive(): bool
    {
        return defined('FLUENT_COMMUNITY_PLUGIN_VERSION');
    }
}

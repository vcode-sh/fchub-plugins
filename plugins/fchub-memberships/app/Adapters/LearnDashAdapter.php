<?php

namespace FChubMemberships\Adapters;

defined('ABSPATH') || exit;

use FChubMemberships\Adapters\Contracts\AccessAdapterInterface;
use FChubMemberships\Adapters\Contracts\BatchResourceLabelAdapterInterface;

class LearnDashAdapter implements AccessAdapterInterface, BatchResourceLabelAdapterInterface
{
    public function supports(string $resourceType): bool
    {
        return in_array($resourceType, ['ld_course', 'ld_group'], true);
    }

    public function grant(int $userId, string $resourceType, string $resourceId, array $context = []): array
    {
        if (!$this->supports($resourceType)) {
            return $this->unsupportedResourceResponse();
        }

        if (!$this->isLearnDashActive()) {
            return [
                'success' => false,
                'message' => __('LearnDash is not active, so provider access could not be granted.', 'fchub-memberships'),
            ];
        }

        if ($resourceType === 'ld_course') {
            ld_update_course_access($userId, (int) $resourceId);

            if (!$this->check($userId, $resourceType, $resourceId)) {
                return [
                    'success' => false,
                    'message' => __('LearnDash did not confirm the course enrolment.', 'fchub-memberships'),
                ];
            }

            return [
                'success' => true,
                'message' => sprintf(
                    __('Course access granted: %s', 'fchub-memberships'),
                    get_the_title((int) $resourceId)
                ),
            ];
        }

        if ($resourceType === 'ld_group') {
            $currentGroups = learndash_get_users_group_ids($userId);
            $groupId = (int) $resourceId;

            if (!in_array($groupId, $currentGroups, true)) {
                $currentGroups[] = $groupId;
                learndash_set_users_group_ids($userId, $currentGroups);
            }

            if (!$this->check($userId, $resourceType, $resourceId)) {
                return [
                    'success' => false,
                    'message' => __('LearnDash did not confirm the group assignment.', 'fchub-memberships'),
                ];
            }

            return [
                'success' => true,
                'message' => sprintf(
                    __('Group access granted: %s', 'fchub-memberships'),
                    get_the_title($groupId)
                ),
            ];
        }

        return $this->unsupportedResourceResponse();
    }

    public function revoke(int $userId, string $resourceType, string $resourceId, array $context = []): array
    {
        if (!$this->supports($resourceType)) {
            return $this->unsupportedResourceResponse();
        }

        if (!$this->isLearnDashActive()) {
            return [
                'success' => false,
                'message' => __('LearnDash is not active, so provider access could not be revoked.', 'fchub-memberships'),
            ];
        }

        if ($resourceType === 'ld_course') {
            ld_update_course_access($userId, (int) $resourceId, true);

            if ($this->check($userId, $resourceType, $resourceId)) {
                return [
                    'success' => false,
                    'message' => __('LearnDash did not confirm the course removal.', 'fchub-memberships'),
                ];
            }

            return [
                'success' => true,
                'message' => sprintf(
                    __('Course access revoked: %s', 'fchub-memberships'),
                    get_the_title((int) $resourceId)
                ),
            ];
        }

        if ($resourceType === 'ld_group') {
            $currentGroups = learndash_get_users_group_ids($userId);
            $groupId = (int) $resourceId;
            $currentGroups = array_values(array_diff($currentGroups, [$groupId]));
            learndash_set_users_group_ids($userId, $currentGroups);

            if ($this->check($userId, $resourceType, $resourceId)) {
                return [
                    'success' => false,
                    'message' => __('LearnDash did not confirm the group removal.', 'fchub-memberships'),
                ];
            }

            return [
                'success' => true,
                'message' => sprintf(
                    __('Group access revoked: %s', 'fchub-memberships'),
                    get_the_title($groupId)
                ),
            ];
        }

        return $this->unsupportedResourceResponse();
    }

    public function check(int $userId, string $resourceType, string $resourceId): bool
    {
        if (!$this->isLearnDashActive()) {
            return false;
        }

        if ($resourceType === 'ld_course') {
            return sfwd_lms_has_access((int) $resourceId, $userId);
        }

        if ($resourceType === 'ld_group') {
            return learndash_is_user_in_group($userId, (int) $resourceId);
        }

        return false;
    }

    public function getResourceLabel(string $resourceType, string $resourceId): string
    {
        if (!$this->isLearnDashActive()) {
            $prefix = $resourceType === 'ld_course'
                ? __('Course', 'fchub-memberships')
                : __('Group', 'fchub-memberships');

            return sprintf('%s #%s', $prefix, $resourceId);
        }

        $title = get_the_title((int) $resourceId);
        if ($title) {
            return $title;
        }

        $prefix = $resourceType === 'ld_course'
            ? __('Course', 'fchub-memberships')
            : __('Group', 'fchub-memberships');

        return sprintf('%s #%s', $prefix, $resourceId);
    }

    public function getResourceLabels(string $resourceType, array $resourceIds): array
    {
        $resourceIds = array_values(array_unique(array_map('strval', $resourceIds)));
        $prefix = $resourceType === 'ld_course'
            ? __('Course', 'fchub-memberships')
            : __('Group', 'fchub-memberships');
        $labels = [];
        foreach ($resourceIds as $resourceId) {
            $labels[$resourceId] = sprintf('%s #%s', $prefix, $resourceId);
        }
        if ($resourceIds === [] || !$this->isLearnDashActive()) {
            return $labels;
        }

        $postType = $resourceType === 'ld_course' ? 'sfwd-courses' : 'groups';
        $posts = get_posts([
            'post_type' => $postType,
            'post_status' => 'any',
            'posts_per_page' => -1,
            'post__in' => array_values(array_filter(array_map('intval', $resourceIds))),
            'orderby' => 'post__in',
        ]);
        foreach ($posts as $post) {
            $id = (string) $post->ID;
            if (array_key_exists($id, $labels) && $post->post_title !== '') {
                $labels[$id] = (string) $post->post_title;
            }
        }

        return $labels;
    }

    public function searchResources(string $query, string $resourceType, int $limit = 20): array
    {
        if (!$this->isLearnDashActive()) {
            return [];
        }

        $postType = $resourceType === 'ld_course' ? 'sfwd-courses' : 'groups';

        $args = [
            'post_type'      => $postType,
            'post_status'    => 'publish',
            'posts_per_page' => $limit,
            'orderby'        => 'title',
            'order'          => 'ASC',
        ];

        if ($query !== '') {
            $args['s'] = $query;
        }

        $wpQuery = new \WP_Query($args);
        $results = [];

        foreach ($wpQuery->posts as $post) {
            $results[] = [
                'id'    => (string) $post->ID,
                'label' => $post->post_title,
            ];
        }

        return $results;
    }

    public function getResourceTypes(): array
    {
        return [
            'ld_course' => __('LearnDash Course', 'fchub-memberships'),
            'ld_group'  => __('LearnDash Group', 'fchub-memberships'),
        ];
    }

    private function isLearnDashActive(): bool
    {
        return function_exists('sfwd_lms_has_access');
    }

    private function unsupportedResourceResponse(): array
    {
        return [
            'success' => false,
            'message' => __('Unsupported LearnDash resource type.', 'fchub-memberships'),
        ];
    }
}

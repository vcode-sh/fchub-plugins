<?php

namespace FChubMemberships\Adapters;

defined('ABSPATH') || exit;

use FChubMemberships\Adapters\Contracts\AccessAdapterInterface;
use FChubMemberships\Adapters\Contracts\BatchResourceLabelAdapterInterface;

class FluentCrmAdapter implements AccessAdapterInterface, BatchResourceLabelAdapterInterface
{
    public function supports(string $resourceType): bool
    {
        return in_array($resourceType, ['fluentcrm_tag', 'fluentcrm_list'], true);
    }

    public function grant(int $userId, string $resourceType, string $resourceId, array $context = []): array
    {
        if (!$this->supports($resourceType)) {
            return $this->unsupportedResourceResponse();
        }

        if (!$this->isFluentCrmActive()) {
            return [
                'success' => false,
                'message' => __('FluentCRM is not active, so provider access could not be granted.', 'fchub-memberships'),
            ];
        }

        $contact = $this->getOrCreateContact($userId);
        if (!$contact) {
            return [
                'success' => false,
                'message' => __('Could not find or create FluentCRM contact.', 'fchub-memberships'),
            ];
        }

        $id = (int) $resourceId;

        if ($resourceType === 'fluentcrm_tag') {
            $contact->attachTags([$id]);

            if (!$this->contactHasResource($contact, $resourceType, $id)) {
                return [
                    'success' => false,
                    'message' => __('FluentCRM did not confirm the tag assignment.', 'fchub-memberships'),
                ];
            }

            return [
                'success' => true,
                'message' => sprintf(
                    __('FluentCRM tag applied: %s', 'fchub-memberships'),
                    $this->getResourceLabel($resourceType, $resourceId)
                ),
            ];
        }

        if ($resourceType === 'fluentcrm_list') {
            $contact->attachLists([$id]);

            if (!$this->contactHasResource($contact, $resourceType, $id)) {
                return [
                    'success' => false,
                    'message' => __('FluentCRM did not confirm the list assignment.', 'fchub-memberships'),
                ];
            }

            return [
                'success' => true,
                'message' => sprintf(
                    __('FluentCRM list assigned: %s', 'fchub-memberships'),
                    $this->getResourceLabel($resourceType, $resourceId)
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

        if (!$this->isFluentCrmActive()) {
            return [
                'success' => false,
                'message' => __('FluentCRM is not active, so provider access could not be revoked.', 'fchub-memberships'),
            ];
        }

        $contact = FluentCrmApi('contacts')->getContactByUserRef($userId);
        if (!$contact) {
            return [
                'success' => true,
                'message' => __('No FluentCRM contact found for user.', 'fchub-memberships'),
            ];
        }

        $id = (int) $resourceId;

        if ($resourceType === 'fluentcrm_tag') {
            $contact->detachTags([$id]);

            if ($this->contactHasResource($contact, $resourceType, $id)) {
                return [
                    'success' => false,
                    'message' => __('FluentCRM did not confirm the tag removal.', 'fchub-memberships'),
                ];
            }

            return [
                'success' => true,
                'message' => sprintf(
                    __('FluentCRM tag removed: %s', 'fchub-memberships'),
                    $this->getResourceLabel($resourceType, $resourceId)
                ),
            ];
        }

        if ($resourceType === 'fluentcrm_list') {
            $contact->detachLists([$id]);

            if ($this->contactHasResource($contact, $resourceType, $id)) {
                return [
                    'success' => false,
                    'message' => __('FluentCRM did not confirm the list removal.', 'fchub-memberships'),
                ];
            }

            return [
                'success' => true,
                'message' => sprintf(
                    __('FluentCRM list removed: %s', 'fchub-memberships'),
                    $this->getResourceLabel($resourceType, $resourceId)
                ),
            ];
        }

        return $this->unsupportedResourceResponse();
    }

    public function check(int $userId, string $resourceType, string $resourceId): bool
    {
        if (!$this->isFluentCrmActive()) {
            return false;
        }

        $contact = FluentCrmApi('contacts')->getContactByUserRef($userId);
        if (!$contact) {
            return false;
        }

        $id = (int) $resourceId;

        return $this->contactHasResource($contact, $resourceType, $id);
    }

    public function getResourceLabel(string $resourceType, string $resourceId): string
    {
        if (!$this->isFluentCrmActive()) {
            $prefix = $resourceType === 'fluentcrm_tag'
                ? __('Tag', 'fchub-memberships')
                : __('List', 'fchub-memberships');

            return sprintf('%s #%s', $prefix, $resourceId);
        }

        if ($resourceType === 'fluentcrm_tag') {
            $tag = FluentCrmApi('tags')->find((int) $resourceId);
            return $tag ? $tag->title : sprintf(__('Tag #%s', 'fchub-memberships'), $resourceId);
        }

        if ($resourceType === 'fluentcrm_list') {
            $list = FluentCrmApi('lists')->find((int) $resourceId);
            return $list ? $list->title : sprintf(__('List #%s', 'fchub-memberships'), $resourceId);
        }

        return sprintf('#%s', $resourceId);
    }

    public function getResourceLabels(string $resourceType, array $resourceIds): array
    {
        $resourceIds = array_values(array_unique(array_map('strval', $resourceIds)));
        $prefix = $resourceType === 'fluentcrm_tag'
            ? __('Tag', 'fchub-memberships')
            : __('List', 'fchub-memberships');
        $labels = [];
        foreach ($resourceIds as $resourceId) {
            $labels[$resourceId] = sprintf('%s #%s', $prefix, $resourceId);
        }
        if ($resourceIds === [] || !$this->isFluentCrmActive()) {
            return $labels;
        }

        $resource = $resourceType === 'fluentcrm_tag' ? 'tags' : 'lists';
        $models = FluentCrmApi($resource)
            ->getInstance()
            ->newQuery()
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
        if (!$this->isFluentCrmActive()) {
            return [];
        }

        if ($resourceType === 'fluentcrm_tag') {
            return $this->searchTags($query, $limit);
        }

        if ($resourceType === 'fluentcrm_list') {
            return $this->searchLists($query, $limit);
        }

        return [];
    }

    public function getResourceTypes(): array
    {
        return [
            'fluentcrm_tag'  => __('FluentCRM Tag', 'fchub-memberships'),
            'fluentcrm_list' => __('FluentCRM List', 'fchub-memberships'),
        ];
    }

    private function searchTags(string $query, int $limit): array
    {
        $tagsApi = FluentCrmApi('tags');
        $builder = $tagsApi->getInstance()->newQuery();

        if ($query !== '') {
            $builder->where('title', 'LIKE', '%' . $query . '%');
        }

        $tags = $builder->limit($limit)->get();
        $results = [];

        foreach ($tags as $tag) {
            $results[] = [
                'id'    => (string) $tag->id,
                'label' => $tag->title,
            ];
        }

        return $results;
    }

    private function searchLists(string $query, int $limit): array
    {
        $listsApi = FluentCrmApi('lists');
        $builder = $listsApi->getInstance()->newQuery();

        if ($query !== '') {
            $builder->where('title', 'LIKE', '%' . $query . '%');
        }

        $lists = $builder->limit($limit)->get();
        $results = [];

        foreach ($lists as $list) {
            $results[] = [
                'id'    => (string) $list->id,
                'label' => $list->title,
            ];
        }

        return $results;
    }

    private function getOrCreateContact(int $userId): ?object
    {
        $contact = FluentCrmApi('contacts')->getContactByUserRef($userId);
        if ($contact) {
            return $contact;
        }

        $user = get_userdata($userId);
        if (!$user) {
            return null;
        }

        return FluentCrmApi('contacts')->createOrUpdate([
            'email'   => $user->user_email,
            'user_id' => $userId,
            'first_name' => $user->first_name,
            'last_name'  => $user->last_name,
        ]);
    }

    private function isFluentCrmActive(): bool
    {
        return defined('FLUENTCRM') && function_exists('FluentCrmApi');
    }

    private function contactHasResource(object $contact, string $resourceType, int $resourceId): bool
    {
        if ($resourceType === 'fluentcrm_tag') {
            $resourceIds = $contact->tags->pluck('id')->toArray();
            return in_array($resourceId, array_map('intval', $resourceIds), true);
        }

        if ($resourceType === 'fluentcrm_list') {
            $resourceIds = $contact->lists->pluck('id')->toArray();
            return in_array($resourceId, array_map('intval', $resourceIds), true);
        }

        return false;
    }

    private function unsupportedResourceResponse(): array
    {
        return [
            'success' => false,
            'message' => __('Unsupported FluentCRM resource type.', 'fchub-memberships'),
        ];
    }
}

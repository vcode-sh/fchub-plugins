<?php

declare(strict_types=1);

namespace FChubMemberships\FluentCRM\Projection;

defined('ABSPATH') || exit;

use FChubMemberships\Http\Controllers\SettingsController;
use FChubMemberships\Storage\FluentCrmProjectionStateRepository;
use FChubMemberships\Storage\GrantRepository;
use FChubMemberships\Storage\PlanRepository;

final class MembershipContactProjector
{
    private FluentCrmProjectionStateRepository $stateRepository;

    /** @var \Closure(int): array */
    private \Closure $grantResolver;

    /** @var \Closure(): array */
    private \Closure $planResolver;

    /** @var \Closure(): array */
    private \Closure $settingsResolver;

    /** @var \Closure(string): object */
    private \Closure $apiResolver;

    /** @var \Closure(int): object|false */
    private \Closure $userResolver;

    public function __construct(
        ?FluentCrmProjectionStateRepository $stateRepository = null,
        ?callable $grantResolver = null,
        ?callable $planResolver = null,
        ?callable $settingsResolver = null,
        ?callable $apiResolver = null,
        ?callable $userResolver = null
    ) {
        $this->stateRepository = $stateRepository ?? new FluentCrmProjectionStateRepository();
        $this->grantResolver = \Closure::fromCallable($grantResolver ?? static fn(int $userId): array => (
            new GrantRepository()
        )->getByUserId($userId));
        $this->planResolver = \Closure::fromCallable($planResolver ?? static fn(): array => (
            new PlanRepository()
        )->all());
        $this->settingsResolver = \Closure::fromCallable(
            $settingsResolver ?? static fn(): array => SettingsController::getSettings()
        );
        $this->apiResolver = \Closure::fromCallable(
            $apiResolver ?? static fn(string $resource): object => FluentCrmApi($resource)
        );
        $this->userResolver = \Closure::fromCallable(
            $userResolver ?? static fn(int $userId): object|false => get_userdata($userId)
        );
    }

    /**
     * @return array{
     *     success:bool,
     *     attached_tags:list<int>,
     *     detached_tags:list<int>,
     *     attached_lists:list<int>,
     *     detached_lists:list<int>,
     *     custom_fields:array<string, string>,
     *     degraded:bool,
     *     errors:list<string>
     * }
     */
    public function reconcile(int $userId): array
    {
        $result = $this->emptyResult();
        if ($userId <= 0) {
            $result['errors'][] = 'invalid_user';
            return $result;
        }

        try {
            $projection = MembershipContactProjection::fromGrants(
                $userId,
                ($this->grantResolver)($userId),
                ($this->planResolver)()
            );
            $settings = ($this->settingsResolver)();
            $contactsApi = ($this->apiResolver)('contacts');
            $tagsApi = ($this->apiResolver)('tags');
        } catch (\Throwable) {
            $result['errors'][] = 'projection_load_failed';
            return $result;
        }

        $contact = $this->resolveContact($contactsApi, $userId, $result['errors']);
        if (!$contact) {
            return $result;
        }

        $contactId = max(0, (int) ($contact->id ?? $contact->ID ?? 0));
        if ($contactId === 0) {
            $result['errors'][] = 'contact_missing_id';
            return $result;
        }

        $state = $this->stateRepository->get($userId);
        if ($state['contact_id'] !== 0 && $state['contact_id'] !== $contactId) {
            $state['tag_ids'] = [];
            $state['list_ids'] = [];
        }
        $state['contact_id'] = $contactId;

        $desiredTagNames = $this->desiredTagNames($projection, $settings);

        $desiredTagIds = [];
        $tagResolutionSucceeded = true;
        foreach ($desiredTagNames as $tagName) {
            $resolved = false;
            $tagId = $this->resolveTagId($tagsApi, $tagName, $settings, $result['errors'], $resolved);
            $tagResolutionSucceeded = $tagResolutionSucceeded && $resolved;
            if ($tagId > 0) {
                $desiredTagIds[] = $tagId;
            }
        }
        $desiredTagIds = $this->normaliseIds($desiredTagIds);

        if ($tagResolutionSucceeded) {
            $tagReadSucceeded = false;
            $currentTagIds = $this->readRelationIds($contact, 'tags', $result['errors'], $tagReadSucceeded);
        } else {
            $tagReadSucceeded = false;
            $currentTagIds = [];
        }
        $visibleOwnedTagIds = [];
        if ($tagResolutionSucceeded && $tagReadSucceeded) {
            $visibleOwnedTagIds = $this->normaliseIds(array_intersect($state['tag_ids'], $currentTagIds));
            $state['tag_ids'] = $this->applyOwnedDelta(
                $contact,
                'attachTags',
                'detachTags',
                $desiredTagIds,
                $currentTagIds,
                $state['tag_ids'],
                'tag',
                $result['attached_tags'],
                $result['detached_tags'],
                $result['errors']
            );
        }

        $defaultListId = max(0, (int) ($settings['fluentcrm_default_list'] ?? 0));
        $desiredListIds = $projection->hasActiveMembership && $defaultListId > 0
            ? [$defaultListId]
            : [];
        $listReadSucceeded = false;
        $currentListIds = $this->readRelationIds($contact, 'lists', $result['errors'], $listReadSucceeded);
        $visibleOwnedListIds = [];
        if ($listReadSucceeded) {
            $visibleOwnedListIds = $this->normaliseIds(array_intersect($state['list_ids'], $currentListIds));
            $state['list_ids'] = $this->applyOwnedDelta(
                $contact,
                'attachLists',
                'detachLists',
                $desiredListIds,
                $currentListIds,
                $state['list_ids'],
                'list',
                $result['attached_lists'],
                $result['detached_lists'],
                $result['errors']
            );
        }

        if (!$this->stateRepository->save($userId, $state)) {
            $result['errors'][] = 'state_save_failed';
            $compensated = $this->rollbackNewAttachments(
                $contact,
                'tag',
                'detachTags',
                $result['attached_tags'],
                $result['errors']
            );
            $compensated = $this->rollbackNewAttachments(
                $contact,
                'list',
                'detachLists',
                $result['attached_lists'],
                $result['errors']
            ) && $compensated;
            $compensated = $this->restoreDetachedOwnedRelations(
                $contact,
                'tag',
                'attachTags',
                $visibleOwnedTagIds,
                $result['detached_tags'],
                $result['errors']
            ) && $compensated;
            $compensated = $this->restoreDetachedOwnedRelations(
                $contact,
                'list',
                'attachLists',
                $visibleOwnedListIds,
                $result['detached_lists'],
                $result['errors']
            ) && $compensated;
            $result['degraded'] = !$compensated;
        } else {
            $result['custom_fields'] = $this->syncCustomFields(
                $contactsApi,
                $contact,
                $projection,
                $result['errors']
            );
            if (in_array('custom_field_sync_failed', $result['errors'], true)) {
                $result['degraded'] = true;
            }
        }

        $result['attached_tags'] = $this->normaliseIds($result['attached_tags']);
        $result['detached_tags'] = $this->normaliseIds($result['detached_tags']);
        $result['attached_lists'] = $this->normaliseIds($result['attached_lists']);
        $result['detached_lists'] = $this->normaliseIds($result['detached_lists']);
        $result['errors'] = array_values(array_unique($result['errors']));
        $result['success'] = $result['errors'] === [];

        return $result;
    }

    /**
     * Resolve the same projection inputs as reconciliation without creating contacts or tags,
     * changing contact relations, syncing custom fields, or writing ownership state.
     *
     * @return array{
     *     success:bool,
     *     desired:array{tag_names:list<string>, tag_ids:list<int>, list_ids:list<int>, custom_fields:array<string, string>},
     *     current:array{contact_id:int, owned_tag_ids:list<int>, owned_list_ids:list<int>, tag_ids:list<int>, list_ids:list<int>, custom_fields:array<string, string>},
     *     drift:int,
     *     drift_scope:'owned_assets',
     *     errors:list<string>
     * }
     */
    public function preview(int $userId): array
    {
        $result = [
            'success' => false,
            'desired' => ['tag_names' => [], 'tag_ids' => [], 'list_ids' => [], 'custom_fields' => []],
            'current' => [
                'contact_id' => 0,
                'owned_tag_ids' => [],
                'owned_list_ids' => [],
                'tag_ids' => [],
                'list_ids' => [],
                'custom_fields' => [],
            ],
            'drift' => 0,
            'drift_scope' => 'owned_assets',
            'errors' => [],
        ];
        if ($userId <= 0) {
            $result['errors'][] = 'invalid_user';
            return $result;
        }

        try {
            $projection = MembershipContactProjection::fromGrants(
                $userId,
                ($this->grantResolver)($userId),
                ($this->planResolver)()
            );
            $settings = ($this->settingsResolver)();
            $contactsApi = ($this->apiResolver)('contacts');
            $tagsApi = ($this->apiResolver)('tags');
        } catch (\Throwable) {
            $result['errors'][] = 'projection_load_failed';
            return $result;
        }

        $result['desired']['tag_names'] = $this->desiredTagNames($projection, $settings);
        $result['desired']['list_ids'] = $projection->hasActiveMembership
            ? $this->normaliseIds([max(0, (int) ($settings['fluentcrm_default_list'] ?? 0))])
            : [];

        foreach ($result['desired']['tag_names'] as $tagName) {
            $tagId = $this->findTagId($tagsApi, $tagName, $result['errors']);
            if ($tagId > 0) {
                $result['desired']['tag_ids'][] = $tagId;
            }
        }
        $result['desired']['tag_ids'] = $this->normaliseIds($result['desired']['tag_ids']);
        $result['desired']['custom_fields'] = $this->projectCustomFields($contactsApi, $projection, $result['errors']);

        $contact = $this->findExistingContact($contactsApi, $userId, $result['errors']);
        if ($contact === null) {
            $result['drift'] = count($result['desired']['tag_names'])
                + count($result['desired']['list_ids'])
                + count(array_filter(
                    $result['desired']['custom_fields'],
                    static fn(string $value): bool => $value !== ''
                ));
            $result['success'] = $result['errors'] === [];
            return $result;
        }

        $contactId = max(0, (int) ($contact->id ?? $contact->ID ?? 0));
        if ($contactId === 0) {
            $result['errors'][] = 'contact_missing_id';
            return $result;
        }

        $state = $this->stateRepository->get($userId);
        if ($state['contact_id'] !== 0 && $state['contact_id'] !== $contactId) {
            $state['tag_ids'] = [];
            $state['list_ids'] = [];
        }

        $result['current']['contact_id'] = $contactId;
        $result['current']['owned_tag_ids'] = $state['tag_ids'];
        $result['current']['owned_list_ids'] = $state['list_ids'];
        $tagsRead = false;
        $listsRead = false;
        $result['current']['tag_ids'] = $this->readRelationIds($contact, 'tags', $result['errors'], $tagsRead);
        $result['current']['list_ids'] = $this->readRelationIds($contact, 'lists', $result['errors'], $listsRead);
        $customFieldsRead = false;
        $result['current']['custom_fields'] = $this->readCustomFields(
            $contact,
            array_keys($result['desired']['custom_fields']),
            $result['errors'],
            $customFieldsRead
        );

        if ($tagsRead) {
            $result['drift'] += count(array_diff($state['tag_ids'], $result['desired']['tag_ids']));
            $result['drift'] += count(array_diff($result['desired']['tag_ids'], $result['current']['tag_ids']));
            $result['drift'] += count($result['desired']['tag_names']) - count($result['desired']['tag_ids']);
        }
        if ($listsRead) {
            $result['drift'] += count(array_diff($state['list_ids'], $result['desired']['list_ids']));
            $result['drift'] += count(array_diff($result['desired']['list_ids'], $result['current']['list_ids']));
        }
        if ($customFieldsRead) {
            foreach ($result['desired']['custom_fields'] as $key => $value) {
                if (($result['current']['custom_fields'][$key] ?? '') !== $value) {
                    $result['drift']++;
                }
            }
        }

        $result['errors'] = array_values(array_unique($result['errors']));
        $result['success'] = $result['errors'] === [];

        return $result;
    }

    /** @return list<string> */
    private function desiredTagNames(MembershipContactProjection $projection, array $settings): array
    {
        $prefix = (string) ($settings['fluentcrm_tag_prefix'] ?? 'member:');
        $names = array_map(static fn(string $slug): string => $prefix . $slug, $projection->managedPlanTagNames);
        if (in_array($projection->status, ['trial', 'paused', 'expired', 'revoked'], true)) {
            $names[] = $prefix . $projection->status;
        }

        return array_values(array_unique(array_filter(
            $names,
            static fn(string $name): bool => trim($name) !== ''
        )));
    }

    private function findExistingContact(object $contactsApi, int $userId, array &$errors): ?object
    {
        try {
            $contact = $contactsApi->getContactByUserId($userId);
            if (is_object($contact)) {
                return $contact;
            }
        } catch (\Throwable) {
            $errors[] = 'contact_resolve_failed';
            return null;
        }

        return null;
    }

    private function findTagId(object $tagsApi, string $tagName, array &$errors): int
    {
        try {
            $existing = $tagsApi->getInstance()->newQuery()->where('title', $tagName)->first();

            return is_object($existing) ? max(0, (int) ($existing->id ?? 0)) : 0;
        } catch (\Throwable) {
            $errors[] = 'tag_resolve_failed';
            return 0;
        }
    }

    private function resolveContact(object $contactsApi, int $userId, array &$errors): ?object
    {
        try {
            $contact = $contactsApi->getContactByUserRef($userId);
            if (is_object($contact)) {
                return $contact;
            }

            $user = ($this->userResolver)($userId);
            if (!is_object($user) || empty($user->user_email)) {
                $errors[] = 'contact_unavailable';
                return null;
            }

            $contact = $contactsApi->createOrUpdate([
                'email' => (string) $user->user_email,
                'user_id' => $userId,
                'first_name' => (string) ($user->first_name ?? ''),
                'last_name' => (string) ($user->last_name ?? ''),
            ]);

            if (!is_object($contact)) {
                $errors[] = 'contact_unavailable';
                return null;
            }

            return $contact;
        } catch (\Throwable) {
            $errors[] = 'contact_resolve_failed';
            return null;
        }
    }

    private function resolveTagId(
        object $tagsApi,
        string $tagName,
        array $settings,
        array &$errors,
        bool &$succeeded
    ): int {
        $succeeded = false;
        try {
            $existing = $tagsApi->getInstance()->newQuery()
                ->where('title', $tagName)
                ->first();
            if (is_object($existing) && !empty($existing->id)) {
                $succeeded = true;
                return (int) $existing->id;
            }

            if (($settings['fluentcrm_auto_create_tags'] ?? 'yes') !== 'yes') {
                $succeeded = true;
                return 0;
            }

            $created = $tagsApi->importBulk([[
                'title' => $tagName,
                'slug' => sanitize_title($tagName),
            ]]);
            if (is_array($created) && isset($created[0]) && is_object($created[0])) {
                $succeeded = true;
                return max(0, (int) ($created[0]->id ?? 0));
            }

            $existing = $tagsApi->getInstance()->newQuery()
                ->where('title', $tagName)
                ->first();

            $succeeded = true;
            return is_object($existing) ? max(0, (int) ($existing->id ?? 0)) : 0;
        } catch (\Throwable) {
            $errors[] = 'tag_resolve_failed';
            return 0;
        }
    }

    /** @return list<int> */
    private function readRelationIds(
        object $contact,
        string $relation,
        array &$errors,
        bool &$succeeded
    ): array
    {
        $succeeded = false;
        try {
            $items = null;
            if (isset($contact->{$relation}) || property_exists($contact, $relation)) {
                $items = $contact->{$relation};
            } elseif (is_callable([$contact, $relation])) {
                $items = $contact->{$relation}();
            }

            // FluentCRM's ORM Collection has both all() and a keyed get($key).
            // Calling get() without a key raises ArgumentCountError, so consume
            // collection values before considering query-like relation objects.
            if (is_object($items) && method_exists($items, 'all')) {
                $items = $items->all();
            } elseif ($items instanceof \Traversable) {
                $items = iterator_to_array($items);
            } elseif (is_object($items) && is_callable([$items, 'get'])) {
                $items = $items->get();
                if (is_object($items) && method_exists($items, 'all')) {
                    $items = $items->all();
                } elseif ($items instanceof \Traversable) {
                    $items = iterator_to_array($items);
                }
            }

            if (!is_array($items)) {
                $errors[] = $relation . '_read_failed';
                return [];
            }

            $ids = [];
            foreach ($items as $item) {
                $id = is_array($item) ? ($item['id'] ?? 0) : ($item->id ?? 0);
                if ((int) $id > 0) {
                    $ids[] = (int) $id;
                }
            }

            $succeeded = true;
            return $this->normaliseIds($ids);
        } catch (\Throwable) {
            $errors[] = $relation . '_read_failed';
            return [];
        }
    }

    /**
     * @param list<int> $desiredIds
     * @param list<int> $currentIds
     * @param list<int> $ownedIds
     * @param list<int> $attached
     * @param list<int> $detached
     * @param list<string> $errors
     * @return list<int>
     */
    private function applyOwnedDelta(
        object $contact,
        string $attachMethod,
        string $detachMethod,
        array $desiredIds,
        array $currentIds,
        array $ownedIds,
        string $assetType,
        array &$attached,
        array &$detached,
        array &$errors
    ): array {
        $ownedIds = $this->normaliseIds($ownedIds);
        $relation = $assetType . 's';

        foreach (array_values(array_diff($ownedIds, $desiredIds)) as $id) {
            if (!in_array($id, $currentIds, true)) {
                $ownedIds = array_values(array_diff($ownedIds, [$id]));
                continue;
            }

            try {
                $contact->{$detachMethod}([$id]);
                $verified = false;
                $currentIds = $this->readRelationIds($contact, $relation, $errors, $verified);
                if (!$verified) {
                    continue;
                }
                if (in_array($id, $currentIds, true)) {
                    $errors[] = $assetType . '_detach_unconfirmed';
                    continue;
                }
                $detached[] = $id;
                $ownedIds = array_values(array_diff($ownedIds, [$id]));
            } catch (\Throwable) {
                $errors[] = $assetType . '_detach_failed';
            }
        }

        foreach ($desiredIds as $id) {
            if (in_array($id, $currentIds, true)) {
                continue;
            }

            try {
                $contact->{$attachMethod}([$id]);
                $verified = false;
                $currentIds = $this->readRelationIds($contact, $relation, $errors, $verified);
                if (!$verified) {
                    continue;
                }
                if (!in_array($id, $currentIds, true)) {
                    $errors[] = $assetType . '_attach_unconfirmed';
                    continue;
                }
                $attached[] = $id;
                if (!in_array($id, $ownedIds, true)) {
                    $ownedIds[] = $id;
                }
            } catch (\Throwable) {
                $errors[] = $assetType . '_attach_failed';
            }
        }

        return $this->normaliseIds($ownedIds);
    }

    /**
     * Compensate only assignments proven to have been added in this attempt.
     * Pre-existing and previously-owned assets are deliberately out of scope.
     *
     * @param list<int> $attachedIds
     * @param list<string> $errors
     */
    private function rollbackNewAttachments(
        object $contact,
        string $assetType,
        string $detachMethod,
        array &$attachedIds,
        array &$errors
    ): bool {
        $relation = $assetType . 's';
        $compensated = true;

        foreach ($attachedIds as $id) {
            try {
                $contact->{$detachMethod}([$id]);
                $verified = false;
                $currentIds = $this->readRelationIds($contact, $relation, $errors, $verified);
                if (!$verified) {
                    $errors[] = $assetType . '_rollback_verification_failed';
                    $compensated = false;
                    continue;
                }
                if (in_array($id, $currentIds, true)) {
                    $errors[] = $assetType . '_rollback_unconfirmed';
                    $compensated = false;
                    continue;
                }

                $attachedIds = array_values(array_diff($attachedIds, [$id]));
            } catch (\Throwable) {
                $errors[] = $assetType . '_rollback_failed';
                $compensated = false;
            }
        }

        return $compensated;
    }

    /**
     * @param list<int> $visibleOwnedIds
     * @param list<int> $detachedIds
     * @param list<string> $errors
     */
    private function restoreDetachedOwnedRelations(
        object $contact,
        string $assetType,
        string $attachMethod,
        array $visibleOwnedIds,
        array &$detachedIds,
        array &$errors
    ): bool {
        $relation = $assetType . 's';
        $compensated = true;
        $restoreIds = $this->normaliseIds(array_intersect($visibleOwnedIds, $detachedIds));

        foreach ($restoreIds as $id) {
            try {
                $contact->{$attachMethod}([$id]);
                $verified = false;
                $currentIds = $this->readRelationIds($contact, $relation, $errors, $verified);
                if (!$verified) {
                    $errors[] = $assetType . '_compensation_verification_failed';
                    $compensated = false;
                    continue;
                }
                if (!in_array($id, $currentIds, true)) {
                    $errors[] = $assetType . '_compensation_attach_unconfirmed';
                    $compensated = false;
                    continue;
                }

                $detachedIds = array_values(array_diff($detachedIds, [$id]));
            } catch (\Throwable) {
                $errors[] = $assetType . '_compensation_attach_failed';
                $compensated = false;
            }
        }

        return $compensated;
    }

    /** @param list<string> $ownedKeys @return array<string, string> */
    private function readCustomFields(
        object $contact,
        array $ownedKeys,
        array &$errors,
        bool &$succeeded
    ): array {
        $succeeded = false;
        try {
            $values = $contact->custom_fields();
            if (!is_array($values)) {
                $errors[] = 'custom_field_read_failed';
                return [];
            }

            $ownedValues = [];
            foreach ($ownedKeys as $key) {
                if (array_key_exists($key, $values) && is_scalar($values[$key])) {
                    $ownedValues[$key] = (string) $values[$key];
                }
            }

            $succeeded = true;
            return $ownedValues;
        } catch (\Throwable) {
            $errors[] = 'custom_field_read_failed';
            return [];
        }
    }

    /** @return array<string, string> */
    private function syncCustomFields(
        object $contactsApi,
        object $contact,
        MembershipContactProjection $projection,
        array &$errors
    ): array {
        try {
            $values = $this->projectCustomFields($contactsApi, $projection, $errors);

            if ($values !== []) {
                // FluentCRM only deletes supplied empty keys when this flag is true.
                // Non-empty unrelated custom fields remain untouched.
                $contact->syncCustomFieldValues($values, true);
            }

            return $values;
        } catch (\Throwable) {
            $errors[] = 'custom_field_sync_failed';
            return [];
        }
    }

    /** @return array<string, string> */
    private function projectCustomFields(
        object $contactsApi,
        MembershipContactProjection $projection,
        array &$errors
    ): array {
        try {
            $available = [];
            foreach ($contactsApi->getCustomFields() as $field) {
                if (is_array($field) && !empty($field['slug'])) {
                    $available[(string) $field['slug']] = true;
                }
            }

            return array_intersect_key([
                'membership_plan' => implode(', ', $projection->activePlanTitles),
                'membership_status' => $projection->status,
                'membership_expires' => $projection->expiresAt ?? '',
            ], $available);
        } catch (\Throwable) {
            $errors[] = 'custom_field_sync_failed';
            return [];
        }
    }

    /** @return list<int> */
    private function normaliseIds(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(
            array_map('intval', $ids),
            static fn(int $id): bool => $id > 0
        )));
        sort($ids, SORT_NUMERIC);

        return $ids;
    }

    /** @return array<string, mixed> */
    private function emptyResult(): array
    {
        return [
            'success' => false,
            'attached_tags' => [],
            'detached_tags' => [],
            'attached_lists' => [],
            'detached_lists' => [],
            'custom_fields' => [],
            'degraded' => false,
            'errors' => [],
        ];
    }
}

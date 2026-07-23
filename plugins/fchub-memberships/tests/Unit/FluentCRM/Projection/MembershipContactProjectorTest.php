<?php

declare(strict_types=1);

namespace FChubMemberships\Tests\Unit\FluentCRM\Projection;

use FChubMemberships\FluentCRM\Projection\MembershipContactProjector;
use FChubMemberships\Storage\FluentCrmProjectionStateRepository;
use PHPUnit\Framework\TestCase;

final class MembershipContactProjectorTest extends TestCase
{
    public function test_dry_run_reports_desired_and_current_owned_state_without_mutation(): void
    {
        $fixture = ProjectorFixture::make([self::grant(5)]);
        $matchingTagId = $fixture->tags->seed('member:gold', 31);
        $fixture->contact->tagIds = [$matchingTagId];
        $fixture->contact->listIds = [9];
        $fixture->contact->storedCustomFields = [
            'membership_plan' => 'Old plan',
            'membership_status' => 'paused',
            'membership_expires' => '2026-01-01 00:00:00',
            'manual_note' => 'Keep me',
        ];

        $preview = $fixture->projector->preview(21);

        self::assertTrue($preview['success']);
        self::assertSame(['member:gold'], $preview['desired']['tag_names']);
        self::assertSame([9], $preview['desired']['list_ids']);
        self::assertSame([], $preview['current']['owned_tag_ids']);
        self::assertSame([], $fixture->contact->attachedTagIds);
        self::assertSame(0, $fixture->tags->importCalls);
        self::assertSame([], $fixture->state);
        self::assertSame([], $fixture->contact->lastCustomFields);
        self::assertSame([
            'membership_plan' => 'Old plan',
            'membership_status' => 'paused',
            'membership_expires' => '2026-01-01 00:00:00',
        ], $preview['current']['custom_fields']);
        self::assertSame(3, $preview['drift']);
        self::assertSame(1, $fixture->contacts->getContactByUserIdCalls);
        self::assertSame(0, $fixture->contacts->getContactByUserRefCalls);
        self::assertSame(0, $fixture->contacts->createOrUpdateCalls);
        self::assertSame(1, $fixture->contact->customFieldReadCalls);
    }

    public function test_dry_run_treats_a_missing_contact_as_reconcilable_drift_without_mutation(): void
    {
        $fixture = ProjectorFixture::make([self::grant(5)]);
        $fixture->contacts->contact = null;

        $preview = $fixture->projector->preview(21);

        self::assertTrue($preview['success']);
        self::assertSame([], $preview['errors']);
        self::assertGreaterThan(0, $preview['drift']);
        self::assertSame(0, $preview['current']['contact_id']);
        self::assertSame(0, $fixture->tags->importCalls);
        self::assertSame([], $fixture->state);
    }

    public function test_disabled_tag_auto_creation_leaves_observable_drift_after_a_successful_reconciliation(): void
    {
        $fixture = ProjectorFixture::make([self::grant(5)], [
            'fluentcrm_auto_create_tags' => 'no',
            'fluentcrm_default_list' => '',
        ]);

        $before = $fixture->projector->preview(21);
        $applied = $fixture->projector->reconcile(21);
        $after = $fixture->projector->preview(21);

        self::assertTrue($applied['success']);
        self::assertGreaterThan(0, $before['drift']);
        self::assertSame(3, max(0, $before['drift'] - $after['drift']));
        self::assertGreaterThan(0, $after['drift']);
        self::assertSame(0, $fixture->tags->importCalls);
    }

    public function test_stacked_plans_attach_the_complete_owned_delta_and_project_fields(): void
    {
        $fixture = ProjectorFixture::make([
            self::grant(5, 'active', '2026-09-01 00:00:00'),
            self::grant(7, 'active', '2026-12-01 00:00:00'),
        ]);

        $result = $fixture->projector->reconcile(21);

        self::assertTrue($result['success']);
        self::assertCount(2, $result['attached_tags']);
        self::assertSame([9], $result['attached_lists']);
        self::assertSame([
            'membership_plan' => 'Gold, Pro',
            'membership_status' => 'active',
            'membership_expires' => '2026-12-01 00:00:00',
        ], $result['custom_fields']);
        self::assertSame($result['attached_tags'], $fixture->state[21]['tag_ids']);
        self::assertSame([9], $fixture->state[21]['list_ids']);
    }

    public function test_revoking_one_stacked_plan_detaches_only_its_owned_tag(): void
    {
        $grants = [self::grant(5), self::grant(7)];
        $fixture = ProjectorFixture::make($grants);
        $first = $fixture->projector->reconcile(21);
        $goldTagId = $fixture->tags->idForTitle('member:gold');

        $fixture->grants = [self::grant(5, 'revoked'), self::grant(7)];
        $result = $fixture->projector->reconcile(21);

        self::assertSame([$goldTagId], $result['detached_tags']);
        self::assertSame([], $result['detached_lists']);
        self::assertContains($fixture->tags->idForTitle('member:pro'), $fixture->contact->tagIds);
        self::assertContains(9, $fixture->contact->listIds);
        self::assertTrue($first['success'] && $result['success']);
    }

    public function test_final_revoke_detaches_owned_active_assets_and_attaches_revoked_status(): void
    {
        $fixture = ProjectorFixture::make([self::grant(5)]);
        $fixture->projector->reconcile(21);
        $planTagId = $fixture->tags->idForTitle('member:gold');

        $fixture->grants = [self::grant(5, 'revoked')];
        $result = $fixture->projector->reconcile(21);

        self::assertSame([$planTagId], $result['detached_tags']);
        self::assertSame([9], $result['detached_lists']);
        self::assertContains($fixture->tags->idForTitle('member:revoked'), $result['attached_tags']);
        self::assertSame('revoked', $result['custom_fields']['membership_status']);
        self::assertSame(
            ['membership_status' => 'revoked'],
            $fixture->contact->storedCustomFields,
            'FluentCRM must delete the stale plan and expiry values on final revoke.'
        );
    }

    public function test_paused_plan_does_not_make_another_active_plan_globally_paused(): void
    {
        $fixture = ProjectorFixture::make([
            self::grant(5, 'paused'),
            self::grant(7, 'active'),
        ]);

        $result = $fixture->projector->reconcile(21);

        self::assertSame('active', $result['custom_fields']['membership_status']);
        self::assertSame([9], $result['attached_lists']);
        self::assertSame(['member:pro'], $fixture->tags->titlesForIds($result['attached_tags']));
    }

    public function test_renewal_reconciliation_projects_the_latest_expiry(): void
    {
        $fixture = ProjectorFixture::make([self::grant(5, 'active', '2026-08-01 00:00:00', 1)]);
        $fixture->projector->reconcile(21);
        $fixture->grants = [self::grant(5, 'active', '2027-08-01 00:00:00', 2)];

        $result = $fixture->projector->reconcile(21);

        self::assertSame('2027-08-01 00:00:00', $result['custom_fields']['membership_expires']);
        self::assertSame('2027-08-01 00:00:00', $fixture->contact->lastCustomFields['membership_expires']);
    }

    public function test_unrelated_pre_existing_assets_are_never_detached(): void
    {
        $fixture = ProjectorFixture::make([self::grant(5)]);
        $fixture->contact->tagIds = [77];
        $fixture->contact->listIds = [88];
        $fixture->projector->reconcile(21);
        $fixture->grants = [self::grant(5, 'revoked')];

        $fixture->projector->reconcile(21);

        self::assertContains(77, $fixture->contact->tagIds);
        self::assertContains(88, $fixture->contact->listIds);
        self::assertNotContains(77, $fixture->contact->detachedTagIds);
        self::assertNotContains(88, $fixture->contact->detachedListIds);
    }

    public function test_pre_existing_matching_assets_are_not_adopted_or_later_detached(): void
    {
        $fixture = ProjectorFixture::make([self::grant(5)]);
        $matchingTagId = $fixture->tags->seed('member:gold', 31);
        $fixture->contact->tagIds = [$matchingTagId];
        $fixture->contact->listIds = [9];

        $first = $fixture->projector->reconcile(21);
        self::assertSame([], $first['attached_tags']);
        self::assertSame([], $first['attached_lists']);
        self::assertSame([], $fixture->state[21]['tag_ids']);
        self::assertSame([], $fixture->state[21]['list_ids']);

        $fixture->grants = [self::grant(5, 'revoked')];
        $fixture->projector->reconcile(21);

        self::assertContains($matchingTagId, $fixture->contact->tagIds);
        self::assertContains(9, $fixture->contact->listIds);
    }

    public function test_contact_id_change_resets_ownership_without_detaching_from_replacement(): void
    {
        $fixture = ProjectorFixture::make([self::grant(5)]);
        $fixture->projector->reconcile(21);
        $oldOwnedTags = $fixture->state[21]['tag_ids'];

        $replacement = new ProjectorContact(202);
        $replacement->tagIds = $oldOwnedTags;
        $replacement->listIds = [9];
        $fixture->contacts->contact = $replacement;
        $fixture->contact = $replacement;

        $result = $fixture->projector->reconcile(21);

        self::assertSame([], $result['detached_tags']);
        self::assertSame([], $result['detached_lists']);
        self::assertSame([], $fixture->state[21]['tag_ids']);
        self::assertSame([], $fixture->state[21]['list_ids']);
        self::assertSame(202, $fixture->state[21]['contact_id']);
    }

    public function test_disabled_tag_auto_create_skips_missing_tags_without_blocking_other_projection(): void
    {
        $fixture = ProjectorFixture::make([self::grant(5)], ['fluentcrm_auto_create_tags' => 'no']);

        $result = $fixture->projector->reconcile(21);

        self::assertTrue($result['success']);
        self::assertSame([], $result['attached_tags']);
        self::assertSame([9], $result['attached_lists']);
        self::assertSame(0, $fixture->tags->importCalls);
        self::assertSame('active', $result['custom_fields']['membership_status']);
    }

    public function test_missing_custom_fields_are_ignored_without_erasing_provider_values(): void
    {
        $fixture = ProjectorFixture::make([self::grant(5)]);
        $fixture->contacts->customFields = [['slug' => 'membership_status']];

        $result = $fixture->projector->reconcile(21);

        self::assertSame(['membership_status' => 'active'], $result['custom_fields']);
        self::assertSame(['membership_status' => 'active'], $fixture->contact->lastCustomFields);
        self::assertTrue($fixture->contact->lastDeleteOtherValues);
    }

    public function test_provider_failures_are_sanitised_and_failed_assets_are_not_claimed(): void
    {
        $fixture = ProjectorFixture::make([self::grant(5)]);
        $fixture->contact->throwOnAttachTags = true;

        $result = $fixture->projector->reconcile(21);

        self::assertFalse($result['success']);
        self::assertContains('tag_attach_failed', $result['errors']);
        self::assertStringNotContainsString('secret-token', implode(' ', $result['errors']));
        self::assertSame([], $fixture->state[21]['tag_ids']);
        self::assertSame([9], $fixture->state[21]['list_ids']);
    }

    public function test_relation_read_failure_does_not_turn_unknown_existing_assets_into_owned_assets(): void
    {
        $fixture = ProjectorFixture::make([self::grant(5)]);
        $fixture->tags->seed('member:gold', 31);
        $fixture->contact->throwOnTagsRead = true;

        $result = $fixture->projector->reconcile(21);

        self::assertFalse($result['success']);
        self::assertContains('tags_read_failed', $result['errors']);
        self::assertSame([], $fixture->contact->attachedTagIds);
        self::assertSame([], $fixture->state[21]['tag_ids']);
    }

    public function test_silent_provider_attach_failure_is_verified_and_not_claimed(): void
    {
        $fixture = ProjectorFixture::make([self::grant(5)]);
        $fixture->contact->silentlyIgnoreTagAttach = true;

        $result = $fixture->projector->reconcile(21);

        self::assertFalse($result['success']);
        self::assertContains('tag_attach_unconfirmed', $result['errors']);
        self::assertSame([], $result['attached_tags']);
        self::assertSame([], $fixture->state[21]['tag_ids']);
    }

    public function test_provider_detach_failure_retains_ownership_for_a_safe_retry(): void
    {
        $fixture = ProjectorFixture::make([self::grant(5)]);
        $fixture->projector->reconcile(21);
        $ownedTagId = $fixture->tags->idForTitle('member:gold');
        $fixture->contact->throwOnDetachTags = true;
        $fixture->grants = [self::grant(5, 'revoked')];

        $result = $fixture->projector->reconcile(21);

        self::assertFalse($result['success']);
        self::assertContains('tag_detach_failed', $result['errors']);
        self::assertContains($ownedTagId, $fixture->state[21]['tag_ids']);
        self::assertStringNotContainsString('private-key', implode(' ', $result['errors']));
    }

    public function test_tag_resolution_failure_never_detaches_a_still_desired_owned_tag(): void
    {
        $fixture = ProjectorFixture::make([self::grant(5)]);
        $fixture->projector->reconcile(21);
        $ownedTagId = $fixture->tags->idForTitle('member:gold');
        $fixture->tags->throwOnFind = true;

        $result = $fixture->projector->reconcile(21);

        self::assertFalse($result['success']);
        self::assertContains('tag_resolve_failed', $result['errors']);
        self::assertSame([], $result['detached_tags']);
        self::assertContains($ownedTagId, $fixture->contact->tagIds);
        self::assertContains($ownedTagId, $fixture->state[21]['tag_ids']);
    }

    public function test_failed_state_save_rolls_back_only_newly_attached_assets(): void
    {
        $fixture = ProjectorFixture::make([self::grant(5)]);
        $fixture->stateSaveFails = true;

        $result = $fixture->projector->reconcile(21);

        self::assertFalse($result['success']);
        self::assertContains('state_save_failed', $result['errors']);
        self::assertSame([], $result['attached_tags']);
        self::assertSame([], $result['attached_lists']);
        self::assertSame([], $fixture->contact->tagIds);
        self::assertSame([], $fixture->contact->listIds);
        self::assertArrayNotHasKey(21, $fixture->state);
        self::assertFalse($result['degraded']);
        self::assertSame([], $fixture->contact->lastCustomFields);
    }

    public function test_detach_only_state_save_failure_restores_visible_owned_relations(): void
    {
        $fixture = ProjectorFixture::make([self::grant(5)]);
        $fixture->projector->reconcile(21);
        $ownedState = $fixture->state[21];
        $fixture->contact->lastCustomFields = [];
        $fixture->grants = [];
        $fixture->stateSaveFails = true;

        $result = $fixture->projector->reconcile(21);

        self::assertFalse($result['success']);
        self::assertFalse($result['degraded']);
        self::assertContains('state_save_failed', $result['errors']);
        self::assertSame($ownedState['tag_ids'], $fixture->contact->tagIds);
        self::assertSame($ownedState['list_ids'], $fixture->contact->listIds);
        self::assertSame([], $result['detached_tags']);
        self::assertSame([], $result['detached_lists']);
        self::assertSame($ownedState, $fixture->state[21]);
        self::assertSame([], $fixture->contact->lastCustomFields);
    }

    public function test_mixed_delta_state_save_failure_restores_exact_pre_attempt_owned_state(): void
    {
        $fixture = ProjectorFixture::make([self::grant(5)]);
        $fixture->projector->reconcile(21);
        $ownedState = $fixture->state[21];
        $goldTagId = $fixture->tags->idForTitle('member:gold');
        $fixture->contact->lastCustomFields = [];
        $fixture->grants = [self::grant(7)];
        $fixture->stateSaveFails = true;

        $result = $fixture->projector->reconcile(21);

        self::assertFalse($result['success']);
        self::assertFalse($result['degraded']);
        self::assertSame([$goldTagId], $fixture->contact->tagIds);
        self::assertNotContains($fixture->tags->idForTitle('member:pro'), $fixture->contact->tagIds);
        self::assertSame([], $result['attached_tags']);
        self::assertSame([], $result['detached_tags']);
        self::assertSame($ownedState, $fixture->state[21]);
        self::assertSame([], $fixture->contact->lastCustomFields);
    }

    public function test_incomplete_reattach_compensation_is_explicitly_degraded(): void
    {
        $fixture = ProjectorFixture::make([self::grant(5)]);
        $fixture->projector->reconcile(21);
        $ownedTagId = $fixture->tags->idForTitle('member:gold');
        $fixture->grants = [];
        $fixture->stateSaveFails = true;
        $fixture->contact->throwOnAttachTags = true;

        $result = $fixture->projector->reconcile(21);

        self::assertFalse($result['success']);
        self::assertTrue($result['degraded']);
        self::assertContains('tag_compensation_attach_failed', $result['errors']);
        self::assertNotContains($ownedTagId, $fixture->contact->tagIds);
        self::assertContains($ownedTagId, $fixture->state[21]['tag_ids']);
        self::assertStringNotContainsString('secret-token', implode(' ', $result['errors']));
    }

    public function test_compensation_never_reattaches_an_owned_relation_absent_before_the_attempt(): void
    {
        $fixture = ProjectorFixture::make([self::grant(5)]);
        $fixture->projector->reconcile(21);
        $ownedTagId = $fixture->tags->idForTitle('member:gold');
        $fixture->contact->tagIds = [];
        $fixture->contact->attachedTagIds = [];
        $fixture->grants = [];
        $fixture->stateSaveFails = true;

        $result = $fixture->projector->reconcile(21);

        self::assertFalse($result['success']);
        self::assertFalse($result['degraded']);
        self::assertNotContains($ownedTagId, $fixture->contact->tagIds);
        self::assertNotContains($ownedTagId, $fixture->contact->attachedTagIds);
        self::assertSame([], $result['detached_tags']);
        self::assertSame([9], $fixture->contact->listIds);
    }

    public function test_custom_fields_run_only_after_state_commit_and_retry_idempotently(): void
    {
        $fixture = ProjectorFixture::make([self::grant(5)]);
        $fixture->contact->throwOnCustomFieldSync = true;

        $failed = $fixture->projector->reconcile(21);

        self::assertFalse($failed['success']);
        self::assertTrue($failed['degraded']);
        self::assertContains('custom_field_sync_failed', $failed['errors']);
        self::assertArrayHasKey(21, $fixture->state);
        self::assertSame(1, $fixture->stateSaveCalls);
        self::assertSame([], $fixture->contact->storedCustomFields);

        $fixture->contact->throwOnCustomFieldSync = false;
        $retry = $fixture->projector->reconcile(21);

        self::assertTrue($retry['success']);
        self::assertFalse($retry['degraded']);
        self::assertSame(2, $fixture->stateSaveCalls);
        self::assertSame('active', $fixture->contact->storedCustomFields['membership_status']);
        self::assertSame([9], $fixture->contact->listIds);
    }

    public function test_failed_state_save_reports_sanitised_rollback_failure_and_leaves_asset_unowned(): void
    {
        $fixture = ProjectorFixture::make([self::grant(5)]);
        $fixture->stateSaveFails = true;
        $fixture->contact->silentlyIgnoreTagDetach = true;

        $result = $fixture->projector->reconcile(21);

        self::assertFalse($result['success']);
        self::assertContains('tag_rollback_unconfirmed', $result['errors']);
        self::assertStringNotContainsString('rollback-secret', implode(' ', $result['errors']));
        self::assertNotSame([], $fixture->contact->tagIds);
        self::assertSame([], $fixture->contact->listIds);
        self::assertArrayNotHasKey(21, $fixture->state);
    }

    public function test_failed_state_save_sanitises_a_thrown_rollback_failure(): void
    {
        $fixture = ProjectorFixture::make([self::grant(5)]);
        $fixture->stateSaveFails = true;
        $fixture->contact->throwOnDetachTags = true;

        $result = $fixture->projector->reconcile(21);

        self::assertFalse($result['success']);
        self::assertContains('tag_rollback_failed', $result['errors']);
        self::assertStringNotContainsString('private-key', implode(' ', $result['errors']));
        self::assertNotSame([], $fixture->contact->tagIds);
        self::assertSame([], $fixture->contact->listIds);
        self::assertArrayNotHasKey(21, $fixture->state);
    }

    public function test_retry_after_compensated_state_failure_can_be_owned_and_revoked_safely(): void
    {
        $fixture = ProjectorFixture::make([self::grant(5)]);
        $fixture->stateSaveFails = true;
        $fixture->projector->reconcile(21);
        $fixture->stateSaveFails = false;

        $retry = $fixture->projector->reconcile(21);
        self::assertTrue($retry['success']);
        self::assertNotSame([], $fixture->state[21]['tag_ids']);
        self::assertSame([9], $fixture->state[21]['list_ids']);

        $fixture->grants = [self::grant(5, 'revoked')];
        $revoked = $fixture->projector->reconcile(21);

        self::assertTrue($revoked['success']);
        self::assertSame([], $fixture->contact->listIds);
        self::assertNotContains($fixture->tags->idForTitle('member:gold'), $fixture->contact->tagIds);
    }

    public function test_state_save_compensation_never_touches_pre_existing_matching_assets(): void
    {
        $fixture = ProjectorFixture::make([self::grant(5)]);
        $matchingTagId = $fixture->tags->seed('member:gold', 31);
        $fixture->contact->tagIds = [$matchingTagId];
        $fixture->contact->listIds = [9];
        $fixture->stateSaveFails = true;

        $result = $fixture->projector->reconcile(21);

        self::assertFalse($result['success']);
        self::assertContains($matchingTagId, $fixture->contact->tagIds);
        self::assertContains(9, $fixture->contact->listIds);
        self::assertSame([], $fixture->contact->detachedTagIds);
        self::assertSame([], $fixture->contact->detachedListIds);
    }

    public function test_fluentcrm_relation_collections_are_read_through_all_without_calling_keyed_get(): void
    {
        $fixture = ProjectorFixture::make([self::grant(5)]);
        $fixture->contact->returnRuntimeRelationCollections = true;

        $result = $fixture->projector->reconcile(21);

        self::assertTrue($result['success']);
        self::assertNotContains('tags_read_failed', $result['errors']);
        self::assertNotContains('lists_read_failed', $result['errors']);
        self::assertNotSame([], $fixture->contact->tagIds);
        self::assertSame([9], $fixture->contact->listIds);
    }

    /** @return array<string, mixed> */
    private static function grant(
        int $planId,
        string $status = 'active',
        ?string $expiresAt = '2026-09-01 00:00:00',
        int $renewalCount = 0
    ): array {
        return [
            'user_id' => 21,
            'plan_id' => $planId,
            'status' => $status,
            'expires_at' => $expiresAt,
            'renewal_count' => $renewalCount,
            'created_at' => '2026-01-01 00:00:00',
        ];
    }
}

final class ProjectorFixture
{
    /** @var list<array<string, mixed>> */
    public array $grants;
    /** @var array<int, array{contact_id:int, tag_ids:list<int>, list_ids:list<int>}> */
    public array $state = [];
    public bool $stateSaveFails = false;
    public int $stateSaveCalls = 0;

    private function __construct(
        public MembershipContactProjector $projector,
        array $grants,
        public ProjectorContact $contact,
        public ProjectorContactsApi $contacts,
        public ProjectorTagsApi $tags
    ) {
        $this->grants = $grants;
    }

    /** @param list<array<string, mixed>> $grants */
    public static function make(array $grants, array $settings = []): self
    {
        $contact = new ProjectorContact(101);
        $contacts = new ProjectorContactsApi($contact);
        $tags = new ProjectorTagsApi();
        $fixture = new self(new MembershipContactProjector(), $grants, $contact, $contacts, $tags);
        $stateRepository = new FluentCrmProjectionStateRepository(
            static function (int $userId, string $key) use ($fixture): mixed {
                return $fixture->state[$userId] ?? '';
            },
            static function (int $userId, string $key, array $state) use ($fixture): bool {
                $fixture->stateSaveCalls++;
                if ($fixture->stateSaveFails) {
                    return false;
                }
                $fixture->state[$userId] = $state;
                return true;
            }
        );
        $resolvedSettings = array_merge([
            'fluentcrm_tag_prefix' => 'member:',
            'fluentcrm_default_list' => '9',
            'fluentcrm_auto_create_tags' => 'yes',
        ], $settings);

        $fixture->projector = new MembershipContactProjector(
            $stateRepository,
            static fn(int $userId): array => $fixture->grants,
            static fn(): array => [
                ['id' => 5, 'title' => 'Gold', 'slug' => 'gold'],
                ['id' => 7, 'title' => 'Pro', 'slug' => 'pro'],
            ],
            static fn(): array => $resolvedSettings,
            static fn(string $resource): object => $resource === 'contacts' ? $contacts : $tags,
            static fn(int $userId): object => (object) [
                'ID' => $userId,
                'user_email' => 'member@example.test',
                'first_name' => 'Member',
                'last_name' => 'Example',
            ]
        );

        return $fixture;
    }
}

final class ProjectorContact
{
    /** @var list<int> */
    public array $tagIds = [];
    /** @var list<int> */
    public array $listIds = [];
    /** @var list<int> */
    public array $detachedTagIds = [];
    /** @var list<int> */
    public array $detachedListIds = [];
    /** @var array<string, string> */
    public array $lastCustomFields = [];
    /** @var array<string, string> */
    public array $storedCustomFields = [];
    public bool $lastDeleteOtherValues = true;
    public bool $throwOnAttachTags = false;
    public bool $throwOnTagsRead = false;
    public bool $silentlyIgnoreTagAttach = false;
    public bool $throwOnDetachTags = false;
    public bool $silentlyIgnoreTagDetach = false;
    public bool $returnRuntimeRelationCollections = false;
    public bool $throwOnCustomFieldSync = false;
    public int $customFieldReadCalls = 0;
    /** @var list<int> */
    public array $attachedTagIds = [];

    public function __construct(public int $id)
    {
    }

    /** @return list<object{id:int}> */
    public function tags(): array|RuntimeLikeRelationCollection
    {
        if ($this->throwOnTagsRead) {
            throw new \RuntimeException('secret relation failure');
        }
        $items = array_map(static fn(int $id): object => (object) ['id' => $id], $this->tagIds);
        return $this->returnRuntimeRelationCollections ? new RuntimeLikeRelationCollection($items) : $items;
    }

    /** @return list<object{id:int}> */
    public function lists(): array|RuntimeLikeRelationCollection
    {
        $items = array_map(static fn(int $id): object => (object) ['id' => $id], $this->listIds);
        return $this->returnRuntimeRelationCollections ? new RuntimeLikeRelationCollection($items) : $items;
    }

    public function attachTags(array $tagIds): void
    {
        if ($this->throwOnAttachTags) {
            throw new \RuntimeException('secret-token must never escape');
        }
        if ($this->silentlyIgnoreTagAttach) {
            return;
        }
        $this->attachedTagIds = array_merge($this->attachedTagIds, array_map('intval', $tagIds));
        $this->tagIds = array_values(array_unique(array_merge($this->tagIds, array_map('intval', $tagIds))));
    }

    public function detachTags(array $tagIds): void
    {
        if ($this->throwOnDetachTags) {
            throw new \RuntimeException('private-key must never escape');
        }
        if ($this->silentlyIgnoreTagDetach) {
            return;
        }
        $this->detachedTagIds = array_merge($this->detachedTagIds, array_map('intval', $tagIds));
        $this->tagIds = array_values(array_diff($this->tagIds, $tagIds));
    }

    public function attachLists(array $listIds): void
    {
        $this->listIds = array_values(array_unique(array_merge($this->listIds, array_map('intval', $listIds))));
    }

    public function detachLists(array $listIds): void
    {
        $this->detachedListIds = array_merge($this->detachedListIds, array_map('intval', $listIds));
        $this->listIds = array_values(array_diff($this->listIds, $listIds));
    }

    /** @param array<string, string> $values */
    public function syncCustomFieldValues(array $values, bool $deleteOtherValues = true): void
    {
        if ($this->throwOnCustomFieldSync) {
            throw new \RuntimeException('custom-field-secret must never escape');
        }
        $this->lastCustomFields = $values;
        $this->lastDeleteOtherValues = $deleteOtherValues;
        foreach ($values as $key => $value) {
            if ($value === '') {
                if ($deleteOtherValues) {
                    unset($this->storedCustomFields[$key]);
                }
                continue;
            }
            $this->storedCustomFields[$key] = $value;
        }
    }

    /** @return array<string, string> */
    public function custom_fields(): array
    {
        $this->customFieldReadCalls++;
        return $this->storedCustomFields;
    }
}

final class RuntimeLikeRelationCollection
{
    /** @param list<object{id:int}> $items */
    public function __construct(private array $items)
    {
    }

    public function get(int $key): mixed
    {
        return $this->items[$key] ?? null;
    }

    /** @return list<object{id:int}> */
    public function all(): array
    {
        return $this->items;
    }
}

final class ProjectorContactsApi
{
    /** @var list<array{slug:string}> */
    public array $customFields = [
        ['slug' => 'membership_plan'],
        ['slug' => 'membership_status'],
        ['slug' => 'membership_expires'],
    ];
    public int $getContactByUserIdCalls = 0;
    public int $getContactByUserRefCalls = 0;
    public int $createOrUpdateCalls = 0;

    public function __construct(public ?ProjectorContact $contact)
    {
    }

    public function getContactByUserRef(int $userId): ?ProjectorContact
    {
        $this->getContactByUserRefCalls++;
        return $this->contact;
    }

    public function getContactByUserId(int $userId): ?ProjectorContact
    {
        $this->getContactByUserIdCalls++;
        return $this->contact;
    }

    public function createOrUpdate(array $data): ProjectorContact
    {
        $this->createOrUpdateCalls++;
        if ($this->contact === null) {
            $this->contact = new ProjectorContact(102);
        }

        return $this->contact;
    }

    public function getCustomFields(): array
    {
        return $this->customFields;
    }
}

final class ProjectorTagsApi
{
    /** @var array<string, int> */
    private array $tags = [];
    private int $nextId = 100;
    public int $importCalls = 0;
    public bool $throwOnFind = false;

    public function seed(string $title, int $id): int
    {
        $this->tags[$title] = $id;
        return $id;
    }

    public function idForTitle(string $title): int
    {
        return $this->tags[$title];
    }

    /** @param list<int> $ids @return list<string> */
    public function titlesForIds(array $ids): array
    {
        $flipped = array_flip($this->tags);
        $titles = array_map(static fn(int $id): string => (string) ($flipped[$id] ?? ''), $ids);
        sort($titles);
        return $titles;
    }

    public function getInstance(): ProjectorTagQuery
    {
        if ($this->throwOnFind) {
            throw new \RuntimeException('provider credentials must not escape');
        }
        return new ProjectorTagQuery($this);
    }

    /** @return list<object{id:int}> */
    public function importBulk(array $tags): array
    {
        $this->importCalls++;
        $created = [];
        foreach ($tags as $tag) {
            $title = (string) $tag['title'];
            $id = $this->tags[$title] ?? ++$this->nextId;
            $this->tags[$title] = $id;
            $created[] = (object) ['id' => $id];
        }
        return $created;
    }

    public function find(string $title): ?int
    {
        return $this->tags[$title] ?? null;
    }
}

final class ProjectorTagQuery
{
    private string $title = '';

    public function __construct(private ProjectorTagsApi $api)
    {
    }

    public function newQuery(): self
    {
        return $this;
    }

    public function where(string $column, string $value): self
    {
        $this->title = $value;
        return $this;
    }

    public function first(): ?object
    {
        $id = $this->api->find($this->title);
        return $id === null ? null : (object) ['id' => $id];
    }
}

<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\Domain\Transfer\SameSite;

use CartShift\Domain\Transfer\Decision\TransferDecisionSet;
use CartShift\Domain\Transfer\Identity\MappingRecord;
use CartShift\Domain\Transfer\Identity\MapState;
use CartShift\Domain\Transfer\RecordEnvelope;
use CartShift\Domain\Transfer\SameSite\GuidedCollisionDecisionBuilder;
use CartShift\Domain\Transfer\SelectionClause;
use CartShift\Domain\Transfer\SourceIdentity;
use CartShift\Domain\Transfer\TransferSelection;
use CartShift\Support\CanonicalJson;
use CartShift\Tests\Unit\PluginTestCase;

final class GuidedCollisionDecisionBuilderTest extends PluginTestCase
{
    public function testExactActiveCheckedOrderAndSubscriptionMapsContinueThroughExistingReuse(): void
    {
        $order = $this->record('order', '30');
        $subscription = $this->record('subscription', '40');
        $targetFingerprints = [
            $order->identity->canonical() => hash('sha256', 'checked-order'),
            $subscription->identity->canonical() => hash('sha256', 'checked-subscription'),
        ];
        $targets = [
            $order->identity->canonical() => 700,
            $subscription->identity->canonical() => 800,
        ];
        $builder = new GuidedCollisionDecisionBuilder(
            static fn (): iterable => [$order, $subscription],
            static fn (RecordEnvelope $record): array => [[
                'target_id' => $targets[$record->identity->canonical()],
                'target_fingerprint' => $targetFingerprints[$record->identity->canonical()],
            ]],
            static fn (RecordEnvelope $record): MappingRecord => new MappingRecord(
                $record->identity,
                $targets[$record->identity->canonical()],
                $record->sourceContentDigest,
                $targetFingerprints[$record->identity->canonical()],
                MapState::Reconciled,
            ),
        );
        $proposal = $this->proposal([$order, $subscription]);

        $enriched = $builder->enrich($proposal, $this->selection());

        self::assertSame([], $enriched['collision_questions']);
        self::assertSame($proposal['proposal_decisions'], $enriched['proposal_decisions']);
        self::assertSame($proposal['decision_set'], $enriched['decision_set']);
    }

    public function testAStaleOrLegacyMapCannotBypassACurrentCollision(): void
    {
        $order = $this->record('order', '30');
        foreach ([
            new MappingRecord(
                $order->identity,
                700,
                hash('sha256', 'stale-source'),
                hash('sha256', 'target-order-700'),
                MapState::Reconciled,
            ),
            new MappingRecord(
                $order->identity,
                700,
                $order->sourceContentDigest,
                hash('sha256', 'stale-target'),
                MapState::Reconciled,
            ),
            new MappingRecord($order->identity, 700, null, null, MapState::Legacy),
        ] as $mapping) {
            $builder = new GuidedCollisionDecisionBuilder(
                static fn (): iterable => [$order],
                static fn (): array => [[
                    'target_id' => 700,
                    'target_fingerprint' => hash('sha256', 'target-order-700'),
                ]],
                static fn (): MappingRecord => $mapping,
            );

            self::assertCount(1, $builder->questions(
                $builder->enrich($this->proposal([$order]), $this->selection()),
            ));
        }
    }

    public function testUnrelatedTargetRowsDoNotCreateAQuestionOrChangeTheProposal(): void
    {
        $order = $this->record('order', '30');
        $reads = 0;
        $proposal = $this->proposal([$order]);
        $builder = new GuidedCollisionDecisionBuilder(
            static function () use (&$reads, $order): iterable {
                ++$reads;
                yield $order;
            },
            static fn (): array => [],
        );

        $enriched = $builder->enrich($proposal, $this->selection());

        self::assertSame(1, $reads);
        self::assertSame([], $enriched['collision_questions']);
        self::assertSame($proposal['decision_set'], $enriched['decision_set']);
        self::assertSame($proposal['proposal_decisions'], $enriched['proposal_decisions']);
    }

    public function testOrderCollisionOffersOnlySkipAndExcludesItsExactDependentClosure(): void
    {
        $order = $this->record('order', '30');
        $subscription = $this->record('subscription', '40', [$order->identity]);
        $builder = new GuidedCollisionDecisionBuilder(
            static fn (): iterable => [$subscription, $order],
            static fn (RecordEnvelope $record): array => $record->identity->entityType === 'order'
                ? [['target_id' => 700, 'target_fingerprint' => hash('sha256', 'target-order-700')]]
                : [],
        );

        $base = $this->proposal([$order, $subscription]);
        $legacyFinding = [
            'identity' => $order->identity->canonical(),
            'scope' => 'target_finding',
            'finding_code' => 'source_identity_conflict',
            'action' => 'approve_mapping',
            'target_disposition' => 'create_distinct',
            'candidate_target_id' => 700,
            'target_fingerprint' => hash('sha256', 'target-order-700'),
            'source_fingerprint' => $order->sourceContentDigest,
            'operator' => 'proposal',
            'reason' => 'Legacy collision proposal.',
            'decided_at' => '2026-08-13T09:00:00Z',
        ];
        $base['proposal_decisions'][] = $legacyFinding;
        $withLegacyFinding = TransferDecisionSet::fromArray([
            ...$base['decision_set']['decisions'],
            $legacyFinding,
        ]);
        $base['decision_set'] = ['decisions' => $withLegacyFinding->rows()];
        $base['decision_set_fingerprint'] = $withLegacyFinding->fingerprint();
        $proposal = $builder->enrich($base, $this->selection());
        $question = $builder->questions($proposal)[0];

        self::assertSame('order', $question['record_kind']);
        self::assertSame(['skip'], array_column($question['choices'], 'action'));
        self::assertSame(1, $question['dependent_subscriptions']);
        self::assertSame([], $proposal['proposal_decisions']);
        self::assertSame([], $proposal['decision_set']['decisions']);

        $resolved = $builder->resolve($proposal, [[
            'review_id' => $question['review_id'],
            'choice_id' => $question['choices'][0]['choice_id'],
        ]], 'wp-user:9', '2026-08-13T10:00:00Z');

        self::assertSame([
            'site-alpha:order:30',
            'site-alpha:subscription:40',
        ], array_column($resolved['decision_set']['decisions'], 'identity'));
        self::assertSame(
            ['excluded_by_policy', 'excluded_by_policy'],
            array_column($resolved['decision_set']['decisions'], 'action'),
        );
        self::assertSame(
            [$order->sourceContentDigest, $subscription->sourceContentDigest],
            array_column($resolved['decision_set']['decisions'], 'source_fingerprint'),
        );
    }

    public function testCollisionQuestionBindsTheAllowlistedTargetStory(): void
    {
        $order = $this->record('order', '30');
        $story = [
            'kind' => 'order',
            'customer_name' => 'Ada Lovelace',
            'created_utc' => '2025-01-20 11:12:13',
            'status' => 'completed',
            'currency' => 'PLN',
            'gross_total' => 2400,
            'items' => [['name' => 'Store membership', 'quantity' => 1]],
            'item_count' => 1,
        ];
        $builder = new GuidedCollisionDecisionBuilder(
            static fn (): iterable => [$order],
            static fn (): array => [[
                'target_id' => 700,
                'target_fingerprint' => hash('sha256', 'target-order-700'),
                'target_story' => $story,
            ]],
        );

        $question = $builder->questions($builder->enrich($this->proposal([$order]), $this->selection()))[0];

        self::assertSame($story, $question['target_story']);
        self::assertStringNotContainsString('target_id', json_encode($question['target_story'], JSON_THROW_ON_ERROR));
    }

    public function testAProductCascadeSkipOwnsItsDependantsWithoutDuplicateCollisionQuestions(): void
    {
        $product = $this->record('product', '10');
        $order = $this->record('order', '30', [$product->identity]);
        $subscription = $this->record('subscription', '40', [$order->identity]);
        $targetReads = 0;
        $builder = new GuidedCollisionDecisionBuilder(
            static fn (): iterable => [$product, $order, $subscription],
            static function () use (&$targetReads): array {
                ++$targetReads;
                return [['target_id' => 700, 'target_fingerprint' => hash('sha256', 'collision')]];
            },
        );
        $proposal = $this->proposal([$order, $subscription]);
        $proposal['product_questions'] = [[
            'review_id' => 'product-0123456789ab',
            'identity' => $product->identity->canonical(),
            'closure' => array_map(static fn (RecordEnvelope $record): array => [
                'identity' => $record->identity->canonical(),
                'source_fingerprint' => $record->sourceContentDigest,
            ], [$product, $order, $subscription]),
            'choices' => [['choice_id' => 'choice-1', 'action' => 'skip']],
        ]];

        $enriched = $builder->enrich($proposal, $this->selection());

        self::assertSame([], $enriched['collision_questions']);
        self::assertSame(0, $targetReads);
    }

    public function testDeterministicOrderAndSubscriptionCollisionKeysArePassedToTargetDiscovery(): void
    {
        $order = $this->record('order', '30');
        $subscription = $this->record('subscription', '40');
        $seen = [];
        $builder = new GuidedCollisionDecisionBuilder(
            static fn (): iterable => [$order, $subscription],
            static function (RecordEnvelope $record, array $identity) use (&$seen): array {
                $seen[$record->identity->entityType] = $identity;
                return [];
            },
        );

        $builder->enrich($this->proposal([$order, $subscription]), $this->selection());

        $digest = hash('sha256', $order->identity->canonical());
        self::assertSame([
            'invoice_no' => 'CS-' . strtoupper(substr($digest, 0, 16)),
            'legacy_invoice_no' => 'WC-30',
            'uuid' => strtoupper(substr($digest, 0, 12)),
        ], $seen['order']);
        self::assertSame([
            'uuid' => md5('cartshift-v2-subscription:' . $subscription->identity->canonical()),
        ], $seen['subscription']);
    }

    public function testDuplicateCollisionCandidatesBlockInsteadOfOfferingAnUnsafeChoice(): void
    {
        $order = $this->record('order', '30');
        $builder = new GuidedCollisionDecisionBuilder(
            static fn (): iterable => [$order],
            static fn (): array => [
                ['target_id' => 700, 'target_fingerprint' => hash('sha256', 'target-order-700')],
                ['target_id' => 701, 'target_fingerprint' => hash('sha256', 'target-order-701')],
            ],
        );

        $this->expectExceptionMessage('guided_collision_target_ambiguous');
        $builder->enrich($this->proposal([$order]), $this->selection());
    }

    public function testChangedTargetEvidenceInvalidatesTheOldAnswerAndWritesNoDecisionRows(): void
    {
        $order = $this->record('order', '30');
        $fingerprint = hash('sha256', 'target-before');
        $builder = new GuidedCollisionDecisionBuilder(
            static fn (): iterable => [$order],
            static function () use (&$fingerprint): array {
                return [[
                    'target_id' => 700,
                    'target_fingerprint' => $fingerprint,
                ]];
            },
        );
        $before = $builder->enrich($this->proposal([$order]), $this->selection());
        $old = $builder->questions($before)[0];
        $fingerprint = hash('sha256', 'target-after');
        $after = $builder->enrich($this->proposal([$order]), $this->selection());
        $fresh = $builder->questions($after)[0];
        self::assertNotSame($old['review_id'], $fresh['review_id']);
        self::assertNotSame($old['choices'][0]['choice_id'], $fresh['choices'][0]['choice_id']);

        try {
            $builder->resolve($after, [[
                'review_id' => $old['review_id'],
                'choice_id' => $old['choices'][0]['choice_id'],
            ]], 'wp-user:9', '2026-08-13T10:00:00Z');
            self::fail('Stale target evidence was accepted.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('guided_collision_answers_incomplete', $exception->getMessage());
            self::assertSame([], $after['decision_set']['decisions']);
        }
    }

    public function testSkipAcceptanceRechecksTheTargetAndSealsBaselineProtectionEvidence(): void
    {
        $order = $this->record('order', '30');
        $fingerprint = hash('sha256', 'target-order-700');
        $builder = new GuidedCollisionDecisionBuilder(
            static fn (): iterable => [$order],
            static fn (): array => [[
                'target_id' => 700,
                'target_fingerprint' => hash('sha256', 'target-order-700'),
            ]],
            currentTargetFingerprint: static fn (): string => $fingerprint,
        );
        $proposal = $builder->enrich($this->proposal([$order]), $this->selection());
        $question = $builder->questions($proposal)[0];

        $resolved = $builder->resolve($proposal, [[
            'review_id' => $question['review_id'],
            'choice_id' => $question['choices'][0]['choice_id'],
        ]], 'wp-user:9', '2026-08-13T10:00:00Z');
        $decision = $resolved['decision_set']['decisions'][0];

        self::assertSame([
            'kind' => 'order',
            'target_fingerprint' => $fingerprint,
            'target_id' => 700,
        ], $decision['protected_collision_target']);
    }

    public function testSkipAcceptanceRejectsTargetDriftOrDisappearanceWithoutWritingDecisions(): void
    {
        $order = $this->record('order', '30');
        foreach ([hash('sha256', 'target-after'), null] as $current) {
            $builder = new GuidedCollisionDecisionBuilder(
                static fn (): iterable => [$order],
                static fn (): array => [[
                    'target_id' => 700,
                    'target_fingerprint' => hash('sha256', 'target-before'),
                ]],
                currentTargetFingerprint: static fn () => $current,
            );
            $proposal = $builder->enrich($this->proposal([$order]), $this->selection());
            $question = $builder->questions($proposal)[0];

            try {
                $builder->resolve($proposal, [[
                    'review_id' => $question['review_id'],
                    'choice_id' => $question['choices'][0]['choice_id'],
                ]], 'wp-user:9', '2026-08-13T10:00:00Z');
                self::fail('Changed or missing collision target evidence was accepted.');
            } catch (\RuntimeException $exception) {
                self::assertSame('guided_collision_target_changed', $exception->getMessage());
                self::assertSame([], $proposal['decision_set']['decisions']);
            }
        }
    }

    public function testChangedSourceEvidenceChangesOpaqueIds(): void
    {
        $order = $this->record('order', '30');
        $current = $order;
        $builder = new GuidedCollisionDecisionBuilder(
            static function () use (&$current): iterable {
                yield $current;
            },
            static fn (): array => [[
                'target_id' => 700,
                'target_fingerprint' => hash('sha256', 'target-order-700'),
            ]],
        );
        $before = $builder->questions($builder->enrich($this->proposal([$order]), $this->selection()))[0];
        $current = RecordEnvelope::forPayload(2, $order->identity, [
            'dependencies' => [],
            'source_status' => 'changed',
        ]);

        $after = $builder->questions($builder->enrich($this->proposal([$current]), $this->selection()))[0];

        self::assertNotSame($before['review_id'], $after['review_id']);
        self::assertNotSame($before['choices'][0]['choice_id'], $after['choices'][0]['choice_id']);
    }

    public function testTargetReadFailureBlocksBeforeAQuestionCanBeAccepted(): void
    {
        $order = $this->record('order', '30');
        $proposal = $this->proposal([$order]);
        $GLOBALS['_cartshift_test_db_error_callback'] = static fn (string $context): string =>
            str_contains($context, 'fct_orders') && str_contains($context, 'invoice_no') ? 'read failed' : '';
        $builder = new GuidedCollisionDecisionBuilder(static fn (): iterable => [$order]);

        try {
            $builder->enrich($proposal, $this->selection());
            self::fail('A failed target read produced a reviewable proposal.');
        } catch (\RuntimeException $exception) {
            self::assertSame('guided_collision_target_read_failed', $exception->getMessage());
            self::assertSame('approve_mapping', $proposal['decision_set']['decisions'][0]['action']);
        }
    }

    public function testCollisionAppearingAfterReviewRequiresANewAnswer(): void
    {
        $order = $this->record('order', '30');
        $candidates = [];
        $builder = new GuidedCollisionDecisionBuilder(
            static fn (): iterable => [$order],
            static function () use (&$candidates): array {
                return $candidates;
            },
        );
        $reviewed = $builder->enrich($this->proposal([$order]), $this->selection());
        self::assertSame([], $builder->questions($reviewed));
        $candidates = [[
            'target_id' => 700,
            'target_fingerprint' => hash('sha256', 'new-collision'),
        ]];
        $refreshed = $builder->enrich($this->proposal([$order]), $this->selection());

        try {
            $builder->resolve($refreshed, [], 'wp-user:9', '2026-08-13T10:00:00Z');
            self::fail('A newly appeared collision was accepted without review.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('guided_collision_answers_incomplete', $exception->getMessage());
            self::assertSame([], $refreshed['decision_set']['decisions']);
        }
    }

    public function testPartialAnswersAreRejected(): void
    {
        $first = $this->record('order', '30');
        $second = $this->record('subscription', '40');
        $builder = new GuidedCollisionDecisionBuilder(
            static fn (): iterable => [$first, $second],
            static fn (RecordEnvelope $record): array => [[
                'target_id' => $record->identity->entityType === 'order' ? 700 : 800,
                'target_fingerprint' => hash('sha256', $record->identity->canonical()),
            ]],
        );
        $proposal = $builder->enrich($this->proposal([$first, $second]), $this->selection());
        $question = $builder->questions($proposal)[0];

        $this->expectExceptionMessage('guided_collision_answers_incomplete');
        $builder->resolve($proposal, [[
            'review_id' => $question['review_id'],
            'choice_id' => $question['choices'][0]['choice_id'],
        ]], 'wp-user:9', '2026-08-13T10:00:00Z');
    }

    /** @param list<SourceIdentity> $dependencies */
    private function record(string $kind, string $id, array $dependencies = []): RecordEnvelope
    {
        return RecordEnvelope::forPayload(2, new SourceIdentity('site-alpha', $kind, $id), [
            'dependencies' => array_map(
                static fn (SourceIdentity $identity): string => $identity->canonical(),
                $dependencies,
            ),
        ]);
    }

    /** @param list<RecordEnvelope> $records @return array<string,mixed> */
    private function proposal(array $records): array
    {
        $rows = array_map(fn (RecordEnvelope $record): array => $this->decision($record), $records);
        $decisions = TransferDecisionSet::fromArray($rows);
        return [
            'status' => 'owner_review_required',
            'blockers' => [],
            'proposal_decisions' => $rows,
            'proposal_counts' => ['records' => count($rows), 'total' => count($rows)],
            'decision_set_fingerprint' => $decisions->fingerprint(),
            'decision_set' => ['decisions' => $decisions->rows()],
        ];
    }

    /** @return array<string,mixed> */
    private function decision(RecordEnvelope $record): array
    {
        $row = [
            'identity' => $record->identity->canonical(),
            'scope' => 'record',
            'action' => $record->identity->entityType === 'subscription'
                ? 'approve_subscription_manual'
                : 'approve_mapping',
            'source_fingerprint' => $record->sourceContentDigest,
            'operator' => 'proposal',
            'reason' => 'Test proposal.',
            'decided_at' => '2026-08-13T09:00:00Z',
        ];
        if ($record->identity->entityType === 'subscription') {
            $row += [
                'target_collection_method' => 'manual',
                'next_action_owner' => 'target_manual',
                'payment_reference_digest' => CanonicalJson::fingerprint([]),
                'source_gateway' => 'stripe',
                'source_auto_renewal_release_required' => true,
            ];
        }
        return $row;
    }

    private function selection(): TransferSelection
    {
        return new TransferSelection(
            'site-alpha',
            SelectionClause::none(),
            SelectionClause::none(),
            SelectionClause::all(),
            SelectionClause::all(),
        );
    }
}

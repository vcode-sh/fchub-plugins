<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\SameSite;

use CartShift\Domain\Migration\OrderIdentity;
use CartShift\Domain\Transfer\Decision\TransferDecisionSet;
use CartShift\Domain\Transfer\Identity\MappingRecord;
use CartShift\Domain\Transfer\Identity\MapState;
use CartShift\Domain\Transfer\Order\LoadedFluentCartOrderGateway;
use CartShift\Domain\Transfer\RecordEnvelope;
use CartShift\Domain\Transfer\RecordKind;
use CartShift\Domain\Transfer\Subscription\LoadedFluentCartSubscriptionGateway;
use CartShift\Domain\Transfer\TransferSelection;
use CartShift\Support\CanonicalJson;
use CartShift\Storage\IdMapRepository;

defined('ABSPATH') || exit;

/** Converts deterministic order and subscription collisions into exact cascade-skip decisions. */
final readonly class GuidedCollisionDecisionBuilder
{
    /** @var \Closure(TransferSelection,TransferDecisionSet):iterable<RecordEnvelope> */
    private \Closure $sourceRecords;

    /** @var \Closure(RecordEnvelope,array<string,string>):list<array<string,mixed>> */
    private \Closure $targetCandidates;

    /** @var \Closure(RecordEnvelope):?MappingRecord */
    private \Closure $checkedMapping;

    /** @var (\Closure(string,int):?string)|null */
    private ?\Closure $currentTargetFingerprint;

    /**
     * @param (callable(TransferSelection,TransferDecisionSet):iterable<RecordEnvelope>)|null $sourceRecords
     * @param (callable(RecordEnvelope,array<string,string>):list<array<string,mixed>>)|null $targetCandidates
     * @param (callable(RecordEnvelope):?MappingRecord)|null $checkedMapping
     * @param (callable(string,int):?string)|null $currentTargetFingerprint
     */
    public function __construct(
        ?callable $sourceRecords = null,
        ?callable $targetCandidates = null,
        ?callable $checkedMapping = null,
        ?callable $currentTargetFingerprint = null,
    ) {
        $this->sourceRecords = $sourceRecords === null ? self::loadedSourceRecords(...) : $sourceRecords(...);
        $this->targetCandidates = $targetCandidates === null ? self::loadedTargetCandidates(...) : $targetCandidates(...);
        $this->checkedMapping = $checkedMapping === null ? self::loadedCheckedMapping(...) : $checkedMapping(...);
        $this->currentTargetFingerprint = $currentTargetFingerprint === null
            ? ($targetCandidates === null ? self::loadedCurrentTargetFingerprint(...) : null)
            : $currentTargetFingerprint(...);
    }

    /** @param array<string,mixed> $proposal @return array<string,mixed> */
    public function enrich(array $proposal, TransferSelection $selection): array
    {
        $decisionRows = $this->rows((array) ($proposal['decision_set'] ?? []), 'decisions');
        $decisions = TransferDecisionSet::fromArray($decisionRows);
        $records = [];
        foreach (($this->sourceRecords)($selection, $decisions) as $record) {
            if (!$record instanceof RecordEnvelope || $record->identity->sourceKey !== $selection->sourceKey) {
                throw new \RuntimeException('guided_collision_source_invalid');
            }
            $records[] = $record;
        }
        $index = new GuidedSourceDependencyIndex($records);
        usort($records, static function (RecordEnvelope $left, RecordEnvelope $right): int {
            $rank = [RecordKind::Order->value => 0, RecordKind::Subscription->value => 1];
            return (($rank[$left->identity->entityType] ?? 2) <=> ($rank[$right->identity->entityType] ?? 2))
                ?: strnatcmp($left->identity->canonical(), $right->identity->canonical());
        });

        $questions = [];
        $removed = [];
        foreach (is_array($proposal['product_questions'] ?? null) ? $proposal['product_questions'] : [] as $question) {
            $choices = is_array($question['choices'] ?? null) ? $question['choices'] : [];
            if (count($choices) !== 1 || ($choices[0]['action'] ?? null) !== 'skip') {
                continue;
            }
            foreach (is_array($question['closure'] ?? null) ? $question['closure'] : [] as $evidence) {
                if (is_array($evidence) && is_string($evidence['identity'] ?? null)) {
                    $removed[$evidence['identity']] = true;
                }
            }
        }
        foreach ($records as $record) {
            if (!in_array($record->identity->kind(), [RecordKind::Order, RecordKind::Subscription], true)
                || isset($removed[$record->identity->canonical()])) {
                continue;
            }
            $candidates = ($this->targetCandidates)($record, $this->targetIdentity($record));
            $this->assertCandidates($candidates);
            if (count($candidates) > 1) {
                throw new \RuntimeException('guided_collision_target_ambiguous:' . $record->identity->canonical());
            }
            if ($candidates === []) {
                continue;
            }
            if ($this->hasExactActiveMapping($record, $candidates[0])) {
                continue;
            }

            $closure = $index->closure($record->identity);
            $closureFacts = array_map(static fn (RecordEnvelope $dependent): array => [
                'identity' => $dependent->identity->canonical(),
                'source_fingerprint' => $dependent->sourceContentDigest,
            ], $closure);
            $target = $candidates[0];
            $facts = [
                'identity' => $record->identity->canonical(),
                'record_kind' => $record->identity->entityType,
                'source_fingerprint' => $record->sourceContentDigest,
                'target_id' => $target['target_id'],
                'target_fingerprint' => $target['target_fingerprint'],
                'closure' => $closureFacts,
            ];
            if (array_key_exists('target_story', $target)) {
                $facts['target_story'] = $this->targetStory($target['target_story'], $record->identity->kind());
            }
            $reviewId = 'collision-' . substr(CanonicalJson::fingerprint($facts), 0, 12);
            $choiceFacts = ['review_id' => $reviewId, 'action' => 'skip', 'evidence' => $facts];
            $questions[] = $facts + [
                'review_id' => $reviewId,
                'dependent_orders' => $this->dependantCount($closure, RecordKind::Order, $record),
                'dependent_subscriptions' => $this->dependantCount($closure, RecordKind::Subscription, $record),
                'choices' => [[
                    'choice_id' => 'skip-' . substr(CanonicalJson::fingerprint($choiceFacts), 0, 12),
                    'action' => 'skip',
                    'label' => $record->identity->kind() === RecordKind::Order
                        ? 'Keep the existing FluentCart order and skip this WooCommerce copy'
                        : 'Keep the existing FluentCart subscription and leave renewal management in WooCommerce',
                ]],
            ];
            foreach ($closure as $dependent) {
                $removed[$dependent->identity->canonical()] = true;
            }
        }

        if ($questions === []) {
            return array_replace($proposal, ['collision_questions' => []]);
        }

        $originalProposalRows = $this->rows($proposal, 'proposal_decisions');
        $removedRecordRows = count(array_filter($originalProposalRows, static fn (array $row): bool =>
            ($row['scope'] ?? 'record') === 'record'
            && isset($removed[(string) ($row['identity'] ?? '')])));
        $proposalRows = $this->withoutClosureDecisions($originalProposalRows, $removed);
        $decisionRows = $this->withoutClosureDecisions($decisionRows, $removed);
        $remaining = TransferDecisionSet::fromArray($decisionRows);
        $counts = is_array($proposal['proposal_counts'] ?? null) ? $proposal['proposal_counts'] : [];
        $counts['records'] = max(0, (int) ($counts['records'] ?? 0) - $removedRecordRows);
        $counts['collision_choices'] = count($questions);
        $counts['total'] = count($remaining->rows());

        return array_replace($proposal, [
            'proposal_decisions' => $proposalRows,
            'proposal_counts' => $counts,
            'decision_set_fingerprint' => $remaining->fingerprint(),
            'decision_set' => ['decisions' => $remaining->rows()],
            'collision_questions' => $questions,
        ]);
    }

    /** @param array<string,mixed> $proposal @return list<array<string,mixed>> */
    public function questions(array $proposal): array
    {
        $questions = $proposal['collision_questions'] ?? [];
        if (!is_array($questions) || !array_is_list($questions)) {
            throw new \RuntimeException('guided_collision_questions_invalid');
        }
        foreach ($questions as $question) {
            if (!is_array($question)
                || !is_string($question['review_id'] ?? null)
                || !is_string($question['identity'] ?? null)
                || !is_string($question['source_fingerprint'] ?? null)
                || !is_array($question['closure'] ?? null)
                || !array_is_list($question['closure'])
                || !is_array($question['choices'] ?? null)
                || count($question['choices']) !== 1
                || ($question['choices'][0]['action'] ?? null) !== 'skip') {
                throw new \RuntimeException('guided_collision_questions_invalid');
            }
        }
        return $questions;
    }

    /**
     * @param array<string,mixed> $proposal
     * @param list<array{review_id:string,choice_id:string}> $answers
     * @return array<string,mixed>
     */
    public function resolve(array $proposal, array $answers, string $operator, string $decidedAtUtc): array
    {
        if (!array_is_list($answers)) {
            throw new \RuntimeException('guided_collision_answers_invalid');
        }
        $answerByReview = [];
        foreach ($answers as $answer) {
            $reviewId = is_array($answer) ? ($answer['review_id'] ?? null) : null;
            $choiceId = is_array($answer) ? ($answer['choice_id'] ?? null) : null;
            if (!is_string($reviewId) || !is_string($choiceId) || isset($answerByReview[$reviewId])) {
                throw new \RuntimeException('guided_collision_answers_invalid');
            }
            $answerByReview[$reviewId] = $choiceId;
        }

        $rows = [];
        foreach ($this->questions($proposal) as $question) {
            $reviewId = (string) $question['review_id'];
            if (($answerByReview[$reviewId] ?? null) !== ($question['choices'][0]['choice_id'] ?? null)) {
                throw new \RuntimeException('guided_collision_answers_incomplete');
            }
            $this->assertCurrentTarget($question);
            unset($answerByReview[$reviewId]);
            foreach ($question['closure'] as $evidence) {
                if (!is_array($evidence)
                    || !is_string($evidence['identity'] ?? null)
                    || !is_string($evidence['source_fingerprint'] ?? null)) {
                    throw new \RuntimeException('guided_collision_questions_invalid');
                }
                $identity = (string) $evidence['identity'];
                $candidate = [
                    'identity' => $identity,
                    'scope' => 'record',
                    'action' => 'excluded_by_policy',
                    'source_fingerprint' => (string) $evidence['source_fingerprint'],
                    'operator' => $operator,
                    'reason' => 'The owner kept the existing FluentCart record and skipped this exact source dependency closure.',
                    'decided_at' => $decidedAtUtc,
                ];
                if ($identity === (string) $question['identity']) {
                    $candidate['protected_collision_target'] = [
                        'kind' => (string) $question['record_kind'],
                        'target_id' => (int) $question['target_id'],
                        'target_fingerprint' => (string) $question['target_fingerprint'],
                    ];
                }
                if (isset($rows[$identity]) && $rows[$identity] !== $candidate) {
                    throw new \RuntimeException('guided_collision_closure_conflict');
                }
                $rows[$identity] = $candidate;
            }
        }
        if ($answerByReview !== []) {
            throw new \RuntimeException('guided_collision_answers_invalid');
        }

        $existing = $this->rows((array) ($proposal['decision_set'] ?? []), 'decisions');
        $resolvedRows = array_values($rows);
        $decisions = TransferDecisionSet::fromArray([...$existing, ...$resolvedRows]);
        $proposalRows = [...$this->rows($proposal, 'proposal_decisions'), ...$resolvedRows];
        $counts = is_array($proposal['proposal_counts'] ?? null) ? $proposal['proposal_counts'] : [];
        $counts['records'] = (int) ($counts['records'] ?? 0) + count($resolvedRows);
        $counts['total'] = count($decisions->rows());

        return array_replace($proposal, [
            'proposal_decisions' => $proposalRows,
            'proposal_counts' => $counts,
            'decision_set_fingerprint' => $decisions->fingerprint(),
            'decision_set' => ['decisions' => $decisions->rows()],
        ]);
    }

    /** @return array<string,string> */
    private function targetIdentity(RecordEnvelope $record): array
    {
        if ($record->identity->kind() === RecordKind::Order) {
            if (preg_match('/\A[1-9][0-9]*\z/D', $record->identity->sourceId) !== 1) {
                throw new \RuntimeException('guided_collision_source_invalid');
            }
            $digest = hash('sha256', $record->identity->canonical());
            return [
                'invoice_no' => 'CS-' . strtoupper(substr($digest, 0, 16)),
                'legacy_invoice_no' => OrderIdentity::invoiceNo((int) $record->identity->sourceId),
                'uuid' => strtoupper(substr($digest, 0, 12)),
            ];
        }
        if ($record->identity->kind() === RecordKind::Subscription) {
            return ['uuid' => md5('cartshift-v2-subscription:' . $record->identity->canonical())];
        }
        throw new \RuntimeException('guided_collision_source_invalid');
    }

    /** @param array{target_id:int,target_fingerprint:string} $candidate */
    private function hasExactActiveMapping(RecordEnvelope $record, array $candidate): bool
    {
        $mapping = ($this->checkedMapping)($record);
        return $mapping instanceof MappingRecord
            && $mapping->identity->canonical() === $record->identity->canonical()
            && $mapping->state !== MapState::Legacy
            && $mapping->isActive()
            && $mapping->targetId === $candidate['target_id']
            && is_string($mapping->sourceFingerprint)
            && hash_equals($record->sourceContentDigest, $mapping->sourceFingerprint)
            && is_string($mapping->targetFingerprint)
            && hash_equals($candidate['target_fingerprint'], $mapping->targetFingerprint);
    }

    /** @param array<string,mixed> $question */
    private function assertCurrentTarget(array $question): void
    {
        if (!$this->currentTargetFingerprint instanceof \Closure) {
            return;
        }
        try {
            $current = ($this->currentTargetFingerprint)(
                (string) ($question['record_kind'] ?? ''),
                (int) ($question['target_id'] ?? 0),
            );
        } catch (\Throwable) {
            throw new \RuntimeException('guided_collision_target_changed');
        }
        $reviewed = $question['target_fingerprint'] ?? null;
        if (!is_string($current)
            || preg_match('/\A[a-f0-9]{64}\z/D', $current) !== 1
            || !is_string($reviewed)
            || !hash_equals($reviewed, $current)) {
            throw new \RuntimeException('guided_collision_target_changed');
        }
    }

    /** @param list<array<string,mixed>> $candidates */
    private function assertCandidates(array $candidates): void
    {
        if (!array_is_list($candidates)) {
            throw new \RuntimeException('guided_collision_target_read_failed');
        }
        $ids = [];
        foreach ($candidates as $candidate) {
            if (!is_array($candidate)
                || !is_int($candidate['target_id'] ?? null)
                || $candidate['target_id'] <= 0
                || !is_string($candidate['target_fingerprint'] ?? null)
                || preg_match('/\A[a-f0-9]{64}\z/D', $candidate['target_fingerprint']) !== 1
                || isset($ids[$candidate['target_id']])) {
                throw new \RuntimeException('guided_collision_target_read_failed');
            }
            $ids[$candidate['target_id']] = true;
        }
    }

    /** @return array<string,mixed> */
    private function targetStory(mixed $story, RecordKind $kind): array
    {
        if (!is_array($story)) {
            throw new \RuntimeException('guided_collision_target_read_failed');
        }
        if ($kind === RecordKind::Order) {
            $items = is_array($story['items'] ?? null) && array_is_list($story['items']) ? $story['items'] : [];
            return [
                'kind' => 'order',
                'customer_name' => trim((string) ($story['customer_name'] ?? '')),
                'created_utc' => trim((string) ($story['created_utc'] ?? '')),
                'status' => trim((string) ($story['status'] ?? '')),
                'currency' => trim((string) ($story['currency'] ?? '')),
                'gross_total' => (int) ($story['gross_total'] ?? 0),
                'items' => array_map(static fn (mixed $item): array => is_array($item) ? [
                    'name' => trim((string) ($item['name'] ?? '')),
                    'quantity' => max(0, (int) ($item['quantity'] ?? 0)),
                ] : ['name' => '', 'quantity' => 0], $items),
                'item_count' => max(0, (int) ($story['item_count'] ?? count($items))),
            ];
        }
        if ($kind === RecordKind::Subscription) {
            return [
                'kind' => 'subscription',
                'status' => trim((string) ($story['status'] ?? '')),
                'recurring_total' => (int) ($story['recurring_total'] ?? 0),
                'next_payment_utc' => is_string($story['next_payment_utc'] ?? null)
                    ? $story['next_payment_utc']
                    : null,
                'item_name' => trim((string) ($story['item_name'] ?? '')),
                'quantity' => max(0, (int) ($story['quantity'] ?? 0)),
            ];
        }
        throw new \RuntimeException('guided_collision_source_invalid');
    }

    /** @param list<RecordEnvelope> $closure */
    private function dependantCount(array $closure, RecordKind $kind, RecordEnvelope $root): int
    {
        return count(array_filter($closure, static fn (RecordEnvelope $record): bool =>
            $record->identity->kind() === $kind
            && $record->identity->canonical() !== $root->identity->canonical()));
    }

    /** @param list<array<string,mixed>> $rows @param array<string,bool> $removed @return list<array<string,mixed>> */
    private function withoutClosureDecisions(array $rows, array $removed): array
    {
        return array_values(array_filter($rows, static function (array $row) use ($removed): bool {
            if (!isset($removed[(string) ($row['identity'] ?? '')])) {
                return true;
            }
            $scope = $row['scope'] ?? 'record';
            return $scope !== 'record'
                && !($scope === 'target_finding' && ($row['finding_code'] ?? null) === 'source_identity_conflict');
        }));
    }

    /** @param array<string,mixed> $container @return list<array<string,mixed>> */
    private function rows(array $container, string $key): array
    {
        $rows = $container[$key] ?? null;
        if (!is_array($rows) || !array_is_list($rows) || array_filter($rows, 'is_array') !== $rows) {
            throw new \RuntimeException('guided_collision_proposal_invalid');
        }
        return $rows;
    }

    /** @return iterable<RecordEnvelope> */
    private static function loadedSourceRecords(TransferSelection $selection, TransferDecisionSet $decisions): iterable
    {
        return GuidedSourceDependencyIndex::forLoadedSelection($selection, $decisions)->records();
    }

    private static function loadedCheckedMapping(RecordEnvelope $record): ?MappingRecord
    {
        return (new IdMapRepository($record->identity->sourceKey))->get($record->identity);
    }

    private static function loadedCurrentTargetFingerprint(string $kind, int $targetId): ?string
    {
        if ($targetId <= 0) {
            return null;
        }
        $snapshot = match ($kind) {
            'order' => (new LoadedFluentCartOrderGateway())->snapshot($targetId),
            'subscription' => (new LoadedFluentCartSubscriptionGateway())->snapshot($targetId),
            default => throw new \RuntimeException('guided_collision_target_kind_invalid'),
        };
        return CanonicalJson::fingerprint($snapshot);
    }

    /** @param array<string,string> $identity @return list<array{target_id:int,target_fingerprint:string}> */
    private static function loadedTargetCandidates(RecordEnvelope $record, array $identity): array
    {
        global $wpdb;
        $ids = $record->identity->kind() === RecordKind::Order
            ? $wpdb->get_col($wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}fct_orders
                 WHERE invoice_no IN (%s,%s) OR uuid = %s ORDER BY id",
                $identity['invoice_no'],
                $identity['legacy_invoice_no'],
                $identity['uuid'],
            ))
            : $wpdb->get_col($wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}fct_subscriptions WHERE uuid = %s ORDER BY id",
                $identity['uuid'],
            ));
        if (trim((string) ($wpdb->last_error ?? '')) !== '' || !is_array($ids)) {
            throw new \RuntimeException('guided_collision_target_read_failed');
        }

        $candidates = [];
        foreach ($ids as $id) {
            $targetId = (int) $id;
            if ($targetId <= 0) {
                throw new \RuntimeException('guided_collision_target_read_failed');
            }
            $snapshot = $record->identity->kind() === RecordKind::Order
                ? (new LoadedFluentCartOrderGateway())->snapshot($targetId)
                : (new LoadedFluentCartSubscriptionGateway())->snapshot($targetId);
            $candidates[] = [
                'target_id' => $targetId,
                'target_fingerprint' => CanonicalJson::fingerprint($snapshot),
                'target_story' => self::loadedTargetStory($record->identity->kind(), $snapshot),
            ];
        }
        return $candidates;
    }

    /** @param array<string,mixed> $snapshot @return array<string,mixed> */
    private static function loadedTargetStory(RecordKind $kind, array $snapshot): array
    {
        if ($kind === RecordKind::Order) {
            $order = is_array($snapshot['order'] ?? null) ? $snapshot['order'] : [];
            $addresses = is_array($snapshot['addresses'] ?? null) ? $snapshot['addresses'] : [];
            $billing = array_values(array_filter(
                $addresses,
                static fn (mixed $address): bool => is_array($address) && ($address['type'] ?? null) === 'billing',
            ))[0] ?? [];
            $rows = is_array($snapshot['items'] ?? null) && array_is_list($snapshot['items']) ? $snapshot['items'] : [];
            $items = array_map(static fn (mixed $item): array => is_array($item) ? [
                'name' => trim((string) ($item['title'] ?? $item['post_title'] ?? '')),
                'quantity' => max(0, (int) ($item['quantity'] ?? 0)),
            ] : ['name' => '', 'quantity' => 0], $rows);
            return [
                'kind' => 'order',
                'customer_name' => trim((string) ($billing['name'] ?? '')),
                'created_utc' => trim((string) ($order['created_at'] ?? '')),
                'status' => trim((string) ($order['status'] ?? '')),
                'currency' => trim((string) ($order['currency'] ?? '')),
                'gross_total' => (int) ($order['total_amount'] ?? 0),
                'items' => $items,
                'item_count' => count($items),
            ];
        }
        $subscription = is_array($snapshot['subscription'] ?? null) ? $snapshot['subscription'] : [];
        return [
            'kind' => 'subscription',
            'status' => trim((string) ($subscription['status'] ?? '')),
            'recurring_total' => (int) ($subscription['recurring_total'] ?? 0),
            'next_payment_utc' => is_string($subscription['next_billing_date'] ?? null)
                ? $subscription['next_billing_date']
                : null,
            'item_name' => trim((string) ($subscription['item_name'] ?? '')),
            'quantity' => max(0, (int) ($subscription['quantity'] ?? 0)),
        ];
    }
}

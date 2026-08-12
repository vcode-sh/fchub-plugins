<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\SameSite;

use CartShift\Domain\Mapping\ProductMatcher;
use CartShift\Domain\Mapping\VariantResolver;
use CartShift\Domain\Transfer\Decision\TransferDecisionSet;
use CartShift\Domain\Transfer\Product\LoadedFluentCartProductGateway;
use CartShift\Domain\Transfer\Product\ProductRecordFactory;
use CartShift\Domain\Transfer\Product\ProductTargetFingerprint;
use CartShift\Domain\Transfer\Product\WooProductRecordSource;
use CartShift\Domain\Transfer\RecordEnvelope;
use CartShift\Domain\Transfer\TransferSelection;

defined('ABSPATH') || exit;

/** Builds fingerprint-bound product choices when FluentCart already has a catalogue. */
final readonly class GuidedProductDecisionBuilder
{
    /** @var \Closure(TransferSelection):iterable<RecordEnvelope> */
    private \Closure $sourceRecords;

    /** @var \Closure():list<array<string,mixed>> */
    private \Closure $targetProducts;

    private GuidedProductQuestionBuilder $questionBuilder;

    /**
     * @param (callable(TransferSelection):iterable<RecordEnvelope>)|null $sourceRecords
     * @param (callable():list<array<string,mixed>>)|null $targetProducts
     * @param (callable(SourceIdentity):array{orders:int,subscriptions:int})|null $dependencyCounts
     */
    public function __construct(
        ?callable $sourceRecords = null,
        ?callable $targetProducts = null,
        ?callable $dependencyCounts = null,
        ProductMatcher $matcher = new ProductMatcher(),
        VariantResolver $variants = new VariantResolver(),
        ProductTargetFingerprint $targetFingerprint = new ProductTargetFingerprint(),
    ) {
        $this->sourceRecords = $sourceRecords === null
            ? static fn (TransferSelection $selection): iterable => (new WooProductRecordSource(
                ProductRecordFactory::forLoadedWoo(),
            ))->records($selection)
            : $sourceRecords(...);
        $this->targetProducts = $targetProducts === null
            ? self::loadedTargetProducts(...)
            : $targetProducts(...);
        $this->questionBuilder = new GuidedProductQuestionBuilder(
            $dependencyCounts,
            $matcher,
            $variants,
            $targetFingerprint,
        );
    }

    /** @param array<string,mixed> $proposal @return array<string,mixed> */
    public function enrich(array $proposal, TransferSelection $selection): array
    {
        $targets = ($this->targetProducts)();
        if ($targets === []) {
            return $proposal + ['product_questions' => []];
        }

        $records = [];
        foreach (($this->sourceRecords)($selection) as $record) {
            if (!$record instanceof RecordEnvelope || $record->identity->entityType !== 'product') {
                throw new \RuntimeException('guided_product_source_invalid');
            }
            $records[$record->identity->canonical()] = $record;
        }

        $proposalRows = $this->rows($proposal, 'proposal_decisions');
        $decisionRows = $this->rows((array) ($proposal['decision_set'] ?? []), 'decisions');
        $questions = [];
        $productBlockers = [];
        $removed = [];
        foreach ($proposalRows as $row) {
            if (($row['scope'] ?? 'record') !== 'record'
                || !in_array($row['action'] ?? null, ['activate_catalogue', 'leave_catalogue_draft'], true)) {
                continue;
            }
            $identity = (string) ($row['identity'] ?? '');
            $record = $records[$identity] ?? null;
            if (!$record instanceof RecordEnvelope
                || !hash_equals($record->sourceContentDigest, (string) ($row['source_fingerprint'] ?? ''))) {
                throw new \RuntimeException('guided_product_source_changed');
            }
            $question = $this->questionBuilder->build($record, $row, $targets);
            if ($question === null) {
                continue;
            }
            if (($question['blocked'] ?? false) === true) {
                $productBlockers[] = $question;
            } else {
                $questions[] = $question;
            }
            $removed[$identity] = true;
        }

        if ($questions === [] && $productBlockers === []) {
            return $proposal + ['product_questions' => []];
        }

        $proposalRows = array_values(array_filter(
            $proposalRows,
            static fn (array $row): bool => !isset($removed[(string) ($row['identity'] ?? '')])
                || ($row['scope'] ?? 'record') !== 'record',
        ));
        $decisionRows = array_values(array_filter(
            $decisionRows,
            static fn (array $row): bool => !isset($removed[(string) ($row['identity'] ?? '')])
                || ($row['scope'] ?? 'record') !== 'record',
        ));
        $decisions = TransferDecisionSet::fromArray($decisionRows);
        $counts = is_array($proposal['proposal_counts'] ?? null) ? $proposal['proposal_counts'] : [];
        $counts['records'] = max(
            0,
            (int) ($counts['records'] ?? 0) - count($questions) - count($productBlockers),
        );
        $counts['product_choices'] = count($questions);
        $counts['product_blockers'] = count($productBlockers);
        $counts['total'] = count($decisionRows);
        $blockers = is_array($proposal['blockers'] ?? null) ? $proposal['blockers'] : [];
        foreach ($productBlockers as $blocker) {
            $blockers[] = [
                'code' => 'product_existing_match_unresolvable',
                'product_name' => (string) $blocker['product_name'],
            ];
        }

        return array_replace($proposal, [
            'status' => $productBlockers === [] ? ($proposal['status'] ?? 'owner_review_required') : 'blocked',
            'blockers' => $blockers,
            'proposal_decisions' => $proposalRows,
            'proposal_counts' => $counts,
            'decision_set_fingerprint' => $decisions->fingerprint(),
            'decision_set' => ['decisions' => $decisions->rows()],
            'product_questions' => $questions,
        ]);
    }

    /** @param array<string,mixed> $proposal @return list<array<string,mixed>> */
    public function questions(array $proposal): array
    {
        $questions = $proposal['product_questions'] ?? [];
        if (!is_array($questions) || !array_is_list($questions)) {
            throw new \RuntimeException('guided_product_questions_invalid');
        }
        foreach ($questions as $question) {
            if (!is_array($question)
                || !is_string($question['review_id'] ?? null)
                || !is_string($question['identity'] ?? null)
                || !is_array($question['choices'] ?? null)
                || !array_is_list($question['choices'])) {
                throw new \RuntimeException('guided_product_questions_invalid');
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
            throw new \RuntimeException('guided_product_answers_invalid');
        }
        $answerByReview = [];
        foreach ($answers as $answer) {
            $reviewId = is_array($answer) ? ($answer['review_id'] ?? null) : null;
            $choiceId = is_array($answer) ? ($answer['choice_id'] ?? null) : null;
            if (!is_string($reviewId) || !is_string($choiceId) || isset($answerByReview[$reviewId])) {
                throw new \RuntimeException('guided_product_answers_invalid');
            }
            $answerByReview[$reviewId] = $choiceId;
        }

        $rows = [];
        foreach ($this->questions($proposal) as $question) {
            $reviewId = (string) $question['review_id'];
            $choiceId = $answerByReview[$reviewId] ?? null;
            $choice = null;
            foreach ($question['choices'] as $candidate) {
                if (is_array($candidate) && ($candidate['choice_id'] ?? null) === $choiceId) {
                    $choice = $candidate;
                    break;
                }
            }
            if (!is_array($choice)) {
                throw new \RuntimeException('guided_product_answers_incomplete');
            }
            unset($answerByReview[$reviewId]);
            $rows[] = $this->decisionRow($question, $choice, $operator, $decidedAtUtc);
        }
        if ($answerByReview !== []) {
            throw new \RuntimeException('guided_product_answers_invalid');
        }

        $existing = $this->rows((array) ($proposal['decision_set'] ?? []), 'decisions');
        $decisions = TransferDecisionSet::fromArray([...$existing, ...$rows]);
        $proposalRows = [...$this->rows($proposal, 'proposal_decisions'), ...$rows];
        $counts = is_array($proposal['proposal_counts'] ?? null) ? $proposal['proposal_counts'] : [];
        $counts['records'] = (int) ($counts['records'] ?? 0) + count($rows);
        $counts['total'] = count($decisions->rows());

        return array_replace($proposal, [
            'proposal_decisions' => $proposalRows,
            'proposal_counts' => $counts,
            'decision_set_fingerprint' => $decisions->fingerprint(),
            'decision_set' => ['decisions' => $decisions->rows()],
        ]);
    }

    /** @param array<string,mixed> $question @param array<string,mixed> $choice @return array<string,mixed> */
    private function decisionRow(array $question, array $choice, string $operator, string $decidedAtUtc): array
    {
        $base = [
            'identity' => (string) $question['identity'],
            'scope' => 'record',
            'source_fingerprint' => (string) $question['source_fingerprint'],
            'operator' => $operator,
            'decided_at' => $decidedAtUtc,
        ];
        return match ($choice['action'] ?? null) {
            'create' => array_replace((array) $question['original_decision'], [
                'operator' => $operator,
                'decided_at' => $decidedAtUtc,
                'reason' => 'The owner chose to create a separate FluentCart product in the guided review.',
            ]),
            'skip' => $base + [
                'action' => 'excluded_by_policy',
                'reason' => 'The owner chose to skip this product in the guided review.',
            ],
            'link' => $base + [
                'action' => 'link_existing_product',
                'target_product_id' => (int) $choice['target_product_id'],
                'target_fingerprint' => (string) $choice['target_fingerprint'],
                'variation_links' => $choice['variation_links'],
                'reason' => 'The owner chose an existing FluentCart product in the guided review.',
            ],
            default => throw new \RuntimeException('guided_product_choice_invalid'),
        };
    }

    /** @param array<string,mixed> $container @return list<array<string,mixed>> */
    private function rows(array $container, string $key): array
    {
        $rows = $container[$key] ?? null;
        if (!is_array($rows) || !array_is_list($rows) || array_filter($rows, 'is_array') !== $rows) {
            throw new \RuntimeException('guided_product_proposal_invalid');
        }
        return $rows;
    }

    /** @return list<array<string,mixed>> */
    private static function loadedTargetProducts(): array
    {
        global $wpdb;
        $ids = $wpdb->get_col(
            "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'fluent-products'
             AND post_status IN ('publish','draft','private') ORDER BY ID ASC",
        );
        if (trim((string) ($wpdb->last_error ?? '')) !== '') {
            throw new \RuntimeException('guided_product_target_read_failed');
        }
        $gateway = new LoadedFluentCartProductGateway();
        $products = [];
        foreach (array_map('intval', is_array($ids) ? $ids : []) as $id) {
            $snapshot = $gateway->snapshot($id);
            $variations = array_values(array_filter((array) ($snapshot['variations'] ?? []), 'is_array'));
            $products[] = [
                'id' => $id,
                'name' => (string) ($snapshot['product']['post_title'] ?? ''),
                'sku' => (string) ($variations[0]['sku'] ?? ''),
                'price' => (float) ($variations[0]['item_price'] ?? 0),
                'variation_count' => count($variations),
                'snapshot' => $snapshot,
            ];
        }
        return $products;
    }

}

<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\SameSite;

use CartShift\Domain\Transfer\SourceIdentity;
use CartShift\Support\CanonicalJson;

defined('ABSPATH') || exit;

/** Presents every proposed decision plainly and resolves only the exact review the owner approved. */
final readonly class GuidedDecisionReview
{
    public function __construct(
        private GuidedCustomerDecisionBuilder $customers = new GuidedCustomerDecisionBuilder(),
        private GuidedProductDecisionBuilder $products = new GuidedProductDecisionBuilder(),
        private GuidedCollisionDecisionBuilder $collisions = new GuidedCollisionDecisionBuilder(),
    ) {}

    /** @param array<string, mixed> $proposal @return array{items:list<array<string,mixed>>,blockers:list<string>} */
    public function presentation(array $proposal): array
    {
        $items = [];
        foreach ($this->proposalRows($proposal) as $row) {
            $identity = SourceIdentity::fromCanonical((string) $row['identity']);
            $items[] = [
                'review_id' => $this->decisionReviewId($row),
                'kind' => 'migration_decision',
                'group' => $identity->entityType . 's',
                'title' => $this->title($identity),
                'summary' => $this->decisionSummary($row, $this->isRenewal($proposal, $row)),
            ];
        }
        foreach ($this->products->questions($proposal) as $question) {
            $choices = array_map(fn (array $choice): array => $this->productChoice($choice), $question['choices']);
            $onlySkip = count($question['choices']) === 1
                && ($question['choices'][0]['action'] ?? null) === 'skip';
            $items[] = [
                'review_id' => (string) $question['review_id'],
                'kind' => 'product_conflict',
                'group' => 'products',
                'title' => (string) $question['product_name'],
                'summary' => $onlySkip
                    ? $this->productCascadeSkipSummary($question)
                    : (array_filter(
                    $question['choices'],
                    static fn (array $choice): bool => ($choice['action'] ?? null) === 'link',
                ) === []
                    ? 'No likely FluentCart match was found. Choose whether to create or skip this product.'
                    : 'A likely FluentCart match already exists. Choose what CartShift should do.'),
                'choices' => $choices,
            ];
        }
        foreach ($this->customers->questions($proposal) as $question) {
            $name = trim((string) ($question['name'] ?? ''));
            $email = trim((string) ($question['email'] ?? ''));
            $choices = array_map(fn (array $choice): array => $this->customerChoice($choice), $question['choices'] ?? []);
            $items[] = [
                'review_id' => (string) $question['review_id'],
                'kind' => $choices === [] ? 'customer_ownership' : 'customer_match',
                'group' => 'customers',
                'title' => $name !== '' ? $name : ($email !== '' ? $email : 'Guest customer'),
                'summary' => $choices === []
                    ? $this->customerSummary($question)
                    : 'A FluentCart customer may already represent this person. Choose whether to reuse it or create a separate customer.',
                ...($choices === [] ? [] : ['choices' => $choices]),
            ];
        }
        foreach ($this->collisions->questions($proposal) as $question) {
            $kind = (string) ($question['record_kind'] ?? '');
            $items[] = [
                'review_id' => (string) $question['review_id'],
                'kind' => 'record_collision',
                'group' => $kind === 'subscription' ? 'subscriptions' : 'orders',
                'title' => $kind === 'subscription' ? 'Existing subscription' : 'Existing order',
                'summary' => $this->collisionSummary($question),
                'choices' => [[
                    'choice_id' => (string) $question['choices'][0]['choice_id'],
                    'label' => 'Skip this WooCommerce copy',
                    'description' => $kind === 'subscription'
                        ? 'Keep the FluentCart subscription unchanged. This WooCommerce subscription will stay managed in WooCommerce.'
                        : 'Keep the FluentCart order unchanged. CartShift will not create a duplicate WooCommerce copy.',
                ]],
            ];
        }

        return ['items' => $items, 'blockers' => $this->blockingMessages($proposal)];
    }

    /**
     * @param array<string, mixed> $proposal
     * @param list<string> $approvedReviewIds
     * @param list<array{review_id:string,choice_id:string}> $reviewAnswers
     * @return array<string, mixed>
     */
    public function approve(
        array $proposal,
        array $approvedReviewIds,
        string $operator,
        string $decidedAtUtc,
        array $reviewAnswers = [],
    ): array {
        if (!array_is_list($approvedReviewIds)) {
            throw new \RuntimeException('guided_decision_review_invalid');
        }
        $presentation = $this->presentation($proposal);
        if ($presentation['blockers'] !== []) {
            throw new \RuntimeException('guided_decision_review_blocked');
        }
        $expected = array_column($presentation['items'], 'review_id');
        $actual = $approvedReviewIds;
        if (count(array_unique($actual)) !== count($actual)
            || array_filter($actual, 'is_string') !== $actual) {
            throw new \RuntimeException('guided_decision_review_invalid');
        }
        sort($expected, SORT_STRING);
        sort($actual, SORT_STRING);
        if ($actual !== $expected) {
            throw new \RuntimeException('guided_decision_review_incomplete');
        }

        $customerAnswers = [];
        foreach ($this->customers->questions($proposal) as $question) {
            $customerAnswers[] = ($question['choices'] ?? []) === []
                ? ['identity' => $question['identity'], 'action' => $question['action']]
                : $this->answerFor($reviewAnswers, (string) $question['review_id']);
        }

        $resolvedProducts = $this->products->resolve(
            $proposal,
            $this->answersFor($reviewAnswers, $this->products->questions($proposal)),
            $operator,
            $decidedAtUtc,
        );
        $resolvedCustomers = $this->customers->resolve(
            $resolvedProducts,
            $customerAnswers,
            $operator,
            $decidedAtUtc,
        );
        $resolvedCollisions = $this->collisions->resolve(
            $resolvedCustomers,
            $this->answersFor($reviewAnswers, $this->collisions->questions($proposal)),
            $operator,
            $decidedAtUtc,
        );

        $approved = $this->stampApprovedRows(
            $resolvedCollisions,
            $proposal,
            $operator,
            $decidedAtUtc,
        );

        return array_replace($approved, [
            'migration_exceptions' => $this->migrationExceptions($proposal, $reviewAnswers),
        ]);
    }

    /** @param array<string,mixed> $proposal @return list<array<string,mixed>> */
    private function proposalRows(array $proposal): array
    {
        $rows = $proposal['proposal_decisions'] ?? null;
        if (!is_array($rows) || !array_is_list($rows)) {
            throw new \RuntimeException('guided_decision_review_invalid');
        }
        foreach ($rows as $row) {
            if (!is_array($row) || !is_string($row['identity'] ?? null)) {
                throw new \RuntimeException('guided_decision_review_invalid');
            }
        }

        return $rows;
    }

    /** @param array<string,mixed> $row */
    private function decisionReviewId(array $row): string
    {
        return 'decision-' . substr(CanonicalJson::fingerprint($row), 0, 12);
    }

    private function title(SourceIdentity $identity): string
    {
        $kind = match ($identity->entityType) {
            'product' => 'Product',
            'order' => 'Order',
            'subscription' => 'Subscription',
            'customer' => 'Customer',
            default => throw new \RuntimeException('guided_decision_review_unsupported'),
        };

        if ($identity->entityType === 'order'
            && preg_match('/\A([^:]+):item:([^:]+)\z/D', $identity->sourceId, $parts) === 1) {
            return sprintf('Order %s, item %s', $parts[1], $parts[2]);
        }

        return $kind . ' ' . $identity->sourceId;
    }

    /** @param array<string,mixed> $row */
    private function decisionSummary(array $row, bool $renewal): string
    {
        $summary = match ($row['finding_code'] ?? null) {
            'historical_product_missing' => 'Keep the historical line item with a migration placeholder instead of losing the order.',
            'subscription_schedule_absence' => 'Preserve that no renewal schedule was stored on the source subscription.',
            'subscription_payment_ownership_unassessed' => 'Move future renewals to manual collection until payment ownership is verified.',
            'product_relation_loss_decision_required' => sprintf(
                'Preserve %d upsell and %d cross-sell references as migration provenance.',
                (int) ($row['upsell_count'] ?? 0),
                (int) ($row['cross_sell_count'] ?? 0),
            ),
            'product_password_protection_unsupported' => 'Migrate the product without its unsupported password protection.',
            'order_note_visibility_decision_required' => $this->orderNoteSummary($row),
            default => match ($row['action'] ?? null) {
                'activate_catalogue' => 'Migrate this published product and make it visible in FluentCart.',
                'leave_catalogue_draft' => 'Migrate this product and keep it as a draft in FluentCart.',
                'approve_mapping' => 'Migrate this record using the source-approved mapping and visibility.',
                'approve_subscription_manual' => 'Migrate this subscription with manual renewal collection.',
                default => throw new \RuntimeException('guided_decision_review_unsupported'),
            },
        };

        return $renewal ? 'Reconfirm because the source evidence changed: ' . lcfirst($summary) : $summary;
    }

    /** @param array<string,mixed> $row */
    private function orderNoteSummary(array $row): string
    {
        $notes = (int) ($row['note_count'] ?? 0);
        $visible = (int) ($row['customer_visible_note_count'] ?? 0);
        if ($visible === 0) {
            return sprintf('Keep all %d historical order notes internally and publish none to customer history.', $notes);
        }

        return sprintf(
            'Keep all %d historical order notes and use the %d customer-visible note%s in customer history.',
            $notes,
            $visible,
            $visible === 1 ? '' : 's',
        );
    }

    /** @param array<string,mixed> $question */
    private function customerSummary(array $question): string
    {
        if (($question['classification'] ?? null) === 'registered') {
            return 'Attach this customer to the exact same WordPress account on this site.';
        }
        if (($question['classification'] ?? null) === 'guest') {
            return ($question['has_downloads'] ?? false) === true
                ? 'Keep this guest purchase unlinked while preserving its downloadable access.'
                : 'Keep this guest purchase unlinked from a WordPress account.';
        }

        throw new \RuntimeException('guided_decision_review_unsupported');
    }

    /** @param array<string,mixed> $choice @return array{choice_id:string,label:string,description:string} */
    private function customerChoice(array $choice): array
    {
        $choiceId = $choice['choice_id'] ?? null;
        if (!is_string($choiceId)) {
            throw new \RuntimeException('guided_customer_questions_invalid');
        }

        return match ($choice['action'] ?? null) {
            'reuse' => [
                'choice_id' => $choiceId,
                'label' => 'Use existing customer',
                'description' => sprintf(
                    'Use %s. CartShift will not change the FluentCart customer.',
                    (string) ($choice['target_label'] ?? 'the matched customer'),
                ),
            ],
            'create' => [
                'choice_id' => $choiceId,
                'label' => 'Create a separate customer',
                'description' => 'Create a new FluentCart customer for this WooCommerce customer.',
            ],
            default => throw new \RuntimeException('guided_customer_questions_invalid'),
        };
    }

    /** @param array<string,mixed> $question */
    private function productCascadeSkipSummary(array $question): string
    {
        $orders = (int) ($question['dependent_orders'] ?? 0);
        $subscriptions = (int) ($question['dependent_subscriptions'] ?? 0);

        return sprintf(
            'A likely FluentCart product exists, but its variations cannot be linked safely. '
                . 'Skipping this product will also skip %d related order%s and %d related subscription%s.',
            $orders,
            $orders === 1 ? '' : 's',
            $subscriptions,
            $subscriptions === 1 ? '' : 's',
        );
    }

    /** @param array<string,mixed> $question */
    private function collisionSummary(array $question): string
    {
        $orders = (int) ($question['dependent_orders'] ?? 0);
        $subscriptions = (int) ($question['dependent_subscriptions'] ?? 0);
        if ($orders === 0 && $subscriptions === 0) {
            return 'FluentCart already has this record. CartShift will keep it unchanged and can skip the WooCommerce copy.';
        }

        return sprintf(
            'FluentCart already has this record. Skipping it will also skip %d related order%s and %d related subscription%s.',
            $orders,
            $orders === 1 ? '' : 's',
            $subscriptions,
            $subscriptions === 1 ? '' : 's',
        );
    }

    /**
     * @param list<array{review_id:string,choice_id:string}> $answers
     * @return array{review_id:string,choice_id:string}
     */
    private function answerFor(array $answers, string $reviewId): array
    {
        $matches = array_values(array_filter(
            $answers,
            static fn (mixed $answer): bool => is_array($answer) && ($answer['review_id'] ?? null) === $reviewId,
        ));
        if (count($matches) !== 1 || !is_string($matches[0]['choice_id'] ?? null)) {
            throw new \RuntimeException('guided_decision_review_incomplete');
        }

        return ['review_id' => $reviewId, 'choice_id' => $matches[0]['choice_id']];
    }

    /**
     * @param list<array{review_id:string,choice_id:string}> $answers
     * @param list<array<string,mixed>> $questions
     * @return list<array{review_id:string,choice_id:string}>
     */
    private function answersFor(array $answers, array $questions): array
    {
        return array_map(
            fn (array $question): array => $this->answerFor($answers, (string) $question['review_id']),
            $questions,
        );
    }

    /**
     * @param array<string,mixed> $proposal
     * @param list<array{review_id:string,choice_id:string}> $answers
     * @return list<array<string,mixed>>
     */
    private function migrationExceptions(array $proposal, array $answers): array
    {
        $exceptions = [];
        foreach ($this->products->questions($proposal) as $question) {
            $answer = $this->answerFor($answers, (string) $question['review_id']);
            $choice = array_values(array_filter(
                $question['choices'],
                static fn (array $choice): bool => ($choice['choice_id'] ?? null) === $answer['choice_id'],
            ))[0] ?? null;
            if (is_array($choice) && ($choice['action'] ?? null) === 'skip') {
                $exceptions[] = [
                    'kind' => 'skipped_product',
                    'title' => (string) ($question['product_name'] ?? 'WooCommerce product'),
                    'dependent_orders' => (int) ($question['dependent_orders'] ?? 0),
                    'dependent_subscriptions' => (int) ($question['dependent_subscriptions'] ?? 0),
                ];
            }
        }
        foreach ($this->collisions->questions($proposal) as $question) {
            $exceptions[] = [
                'kind' => ($question['record_kind'] ?? null) === 'subscription'
                    ? 'skipped_subscription'
                    : 'skipped_order',
                'dependent_orders' => (int) ($question['dependent_orders'] ?? 0),
                'dependent_subscriptions' => (int) ($question['dependent_subscriptions'] ?? 0),
            ];
        }

        return $exceptions;
    }

    /** @param array<string,mixed> $choice @return array{choice_id:string,label:string,description:string} */
    private function productChoice(array $choice): array
    {
        $choiceId = $choice['choice_id'] ?? null;
        if (!is_string($choiceId)) {
            throw new \RuntimeException('guided_product_questions_invalid');
        }
        return match ($choice['action'] ?? null) {
            'link' => [
                'choice_id' => $choiceId,
                'label' => 'Use existing product',
                'description' => sprintf(
                    'Use %s in FluentCart. CartShift will not change it.',
                    (string) ($choice['target_name'] ?? 'the matched product'),
                ),
            ],
            'create' => [
                'choice_id' => $choiceId,
                'label' => 'Create a separate product',
                'description' => 'Create a new FluentCart product because no strong duplicate was found.',
            ],
            'skip' => [
                'choice_id' => $choiceId,
                'label' => 'Skip this product',
                'description' => 'Do not migrate this WooCommerce product.',
            ],
            default => throw new \RuntimeException('guided_product_questions_invalid'),
        };
    }

    /** @param array<string,mixed> $proposal @param array<string,mixed> $row */
    private function isRenewal(array $proposal, array $row): bool
    {
        $auditKeys = $proposal['renewed_audit_decisions'] ?? [];
        $recordIdentities = $proposal['renewed_record_decisions'] ?? [];
        if (!is_array($auditKeys) || !array_is_list($auditKeys)
            || !is_array($recordIdentities) || !array_is_list($recordIdentities)) {
            throw new \RuntimeException('guided_decision_review_invalid');
        }

        return in_array((string) $row['identity'], $recordIdentities, true) || in_array(
            (string) $row['identity'] . '|' . (string) ($row['finding_code'] ?? ''),
            $auditKeys,
            true,
        );
    }

    /** @param array<string,mixed> $proposal @return list<string> */
    private function blockingMessages(array $proposal): array
    {
        $messages = [];
        foreach (is_array($proposal['blockers'] ?? null) ? $proposal['blockers'] : [] as $blocker) {
            if (!is_array($blocker) || ($blocker['code'] ?? null) === 'customer_ownership_decision_requires_owner') {
                continue;
            }
            $messages[] = ($blocker['code'] ?? null) === 'product_existing_match_unresolvable'
                ? sprintf(
                    '%s has a likely FluentCart match, but its variations do not align. '
                        . 'CartShift will stop rather than create a duplicate or change the existing product.',
                    (string) ($blocker['product_name'] ?? 'This product'),
                )
                : 'CartShift cannot safely resolve this source evidence in the guided migration yet.';
        }

        return array_values(array_unique($messages));
    }

    /**
     * The proposal time keeps a review stable; the ledger records when the owner actually approved it.
     *
     * @param array<string,mixed> $resolved
     * @param array<string,mixed> $displayed
     * @return array<string,mixed>
     */
    private function stampApprovedRows(
        array $resolved,
        array $displayed,
        string $operator,
        string $decidedAtUtc,
    ): array
    {
        $approved = [];
        foreach ($this->proposalRows($displayed) as $row) {
            $approved[$this->decisionKey($row)] = true;
        }
        foreach ($this->customers->questions($displayed) as $question) {
            $approved['record|' . (string) $question['identity'] . '|'] = true;
        }
        foreach ($this->products->questions($displayed) as $question) {
            $approved['record|' . (string) $question['identity'] . '|'] = true;
        }
        foreach ($this->collisions->questions($displayed) as $question) {
            foreach ($question['closure'] as $evidence) {
                $approved['record|' . (string) ($evidence['identity'] ?? '') . '|'] = true;
            }
        }

        $rows = $resolved['decision_set']['decisions'] ?? null;
        if (!is_array($rows) || !array_is_list($rows)) {
            throw new \RuntimeException('guided_decision_review_invalid');
        }
        foreach ($rows as &$row) {
            if (!is_array($row)) {
                throw new \RuntimeException('guided_decision_review_invalid');
            }
            if (isset($approved[$this->decisionKey($row)])) {
                $row['operator'] = $operator;
                $row['decided_at'] = $decidedAtUtc;
            }
        }
        unset($row);

        $proposalRows = $resolved['proposal_decisions'] ?? null;
        if (!is_array($proposalRows) || !array_is_list($proposalRows)) {
            throw new \RuntimeException('guided_decision_review_invalid');
        }
        foreach ($proposalRows as &$row) {
            if (is_array($row) && isset($approved[$this->decisionKey($row)])) {
                $row['operator'] = $operator;
                $row['decided_at'] = $decidedAtUtc;
            }
        }
        unset($row);

        $decisions = \CartShift\Domain\Transfer\Decision\TransferDecisionSet::fromArray($rows);

        return array_replace($resolved, [
            'proposal_decisions' => $proposalRows,
            'decision_set_fingerprint' => $decisions->fingerprint(),
            'decision_set' => ['decisions' => $decisions->rows()],
        ]);
    }

    /** @param array<string,mixed> $row */
    private function decisionKey(array $row): string
    {
        return (string) ($row['scope'] ?? '') . '|'
            . (string) ($row['identity'] ?? '') . '|'
            . (string) ($row['finding_code'] ?? '');
    }
}

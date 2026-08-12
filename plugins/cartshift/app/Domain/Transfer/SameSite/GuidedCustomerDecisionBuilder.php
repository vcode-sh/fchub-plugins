<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\SameSite;

use CartShift\Domain\Transfer\Customer\WooCustomerRecordSource;
use CartShift\Domain\Transfer\Decision\TransferDecisionSet;
use CartShift\Domain\Transfer\RecordEnvelope;
use CartShift\Domain\Transfer\SourceIdentity;
use CartShift\Domain\Transfer\TransferSelection;
use CartShift\Support\CanonicalJson;

defined('ABSPATH') || exit;

/** Turns owner-approved same-site customer questions into fingerprint-bound decision rows. */
final readonly class GuidedCustomerDecisionBuilder
{
    /** @var null|\Closure(SourceIdentity): RecordEnvelope */
    private ?\Closure $recordLoader;

    /** @var null|\Closure(int): bool */
    private ?\Closure $downloadableOrder;

    /** @var \Closure(list<RecordEnvelope>): array<string,list<array<string,mixed>>> */
    private \Closure $targetCandidates;

    /**
     * @param null|callable(SourceIdentity): RecordEnvelope $recordLoader
     * @param null|callable(int): bool $downloadableOrder
     * @param null|callable(list<RecordEnvelope>): array<string,list<array<string,mixed>>> $targetCandidates
     */
    public function __construct(
        ?callable $recordLoader = null,
        ?callable $downloadableOrder = null,
        ?callable $targetCandidates = null,
    ) {
        $this->recordLoader = $recordLoader === null ? null : $recordLoader(...);
        $this->downloadableOrder = $downloadableOrder === null ? null : $downloadableOrder(...);
        $this->targetCandidates = $targetCandidates === null
            ? self::loadedTargetCandidates(...)
            : $targetCandidates(...);
    }

    /** @param array<string,mixed> $proposal @return array<string,mixed> */
    public function enrich(array $proposal, TransferSelection $selection): array
    {
        $questions = $this->unsealedQuestions($proposal);
        $records = array_map(
            fn (array $question): RecordEnvelope => $this->record((string) $question['identity']),
            $questions,
        );
        try {
            $candidates = ($this->targetCandidates)($records);
        } catch (\Throwable) {
            return $this->targetReadFailure($proposal);
        }
        if (!is_array($candidates)) {
            return $this->targetReadFailure($proposal);
        }
        foreach ($questions as $index => $question) {
            $identity = SourceIdentity::fromCanonical((string) $question['identity']);
            if ($identity->sourceKey !== $selection->sourceKey) {
                throw new \RuntimeException('guided_customer_selection_changed');
            }
            $questions[$index]['source_fingerprint'] = $records[$index]->sourceContentDigest;
            $matches = $candidates[$identity->canonical()] ?? [];
            if (!is_array($matches) || !array_is_list($matches)) {
                throw new \RuntimeException('guided_customer_target_read_failed');
            }
            if ($matches !== []) {
                $questions[$index] = $this->candidateQuestion($question, $records[$index], $matches);
            }
        }

        return array_replace($proposal, ['customer_questions' => $questions]);
    }

    /** @param array<string,mixed> $proposal @return array<string,mixed> */
    private function targetReadFailure(array $proposal): array
    {
        $blockers = is_array($proposal['blockers'] ?? null) ? $proposal['blockers'] : [];
        $blockers[] = ['code' => 'customer_target_read_failed'];

        return array_replace($proposal, [
            'status' => 'blocked',
            'blockers' => $blockers,
            'customer_questions' => [],
        ]);
    }

    /** @param array<string, mixed> $proposal @return list<array<string, mixed>> */
    public function questions(array $proposal): array
    {
        if (array_key_exists('customer_questions', $proposal)) {
            $questions = $proposal['customer_questions'];
            if (!is_array($questions) || !array_is_list($questions)) {
                throw new \RuntimeException('guided_customer_questions_invalid');
            }
            foreach ($questions as $question) {
                if (!is_array($question)
                    || !is_string($question['review_id'] ?? null)
                    || !is_string($question['identity'] ?? null)) {
                    throw new \RuntimeException('guided_customer_questions_invalid');
                }
            }

            return $questions;
        }

        return $this->unsealedQuestions($proposal);
    }

    /** @param array<string, mixed> $proposal @return list<array<string, mixed>> */
    private function unsealedQuestions(array $proposal): array
    {
        $questions = [];
        foreach ($this->customerBlockers($proposal) as $blocker) {
            $record = $this->record((string) $blocker['identity']);
            $payload = $record->payload;
            $classification = (string) ($payload['classification'] ?? '');
            $orderId = $classification === 'guest' ? $this->guestOrderId($record) : null;
            if (!in_array($classification, ['registered', 'guest'], true)) {
                throw new \RuntimeException('guided_customer_record_invalid');
            }
            $hasDownloads = $orderId === null ? false : $this->hasDownloads($orderId);
            $reviewFacts = [
                'identity' => $record->identity->canonical(),
                'source_fingerprint' => $record->sourceContentDigest,
                'classification' => $classification,
                'action' => $classification === 'registered'
                    ? 'attach_exact_same_site_user'
                    : 'allow_unlinked_downloads',
                'has_downloads' => $hasDownloads,
            ];
            $questions[] = [
                'review_id' => 'customer-' . substr(CanonicalJson::fingerprint($reviewFacts), 0, 12),
                'identity' => $reviewFacts['identity'],
                'name' => trim((string) ($payload['first_name'] ?? '') . ' ' . (string) ($payload['last_name'] ?? '')),
                'email' => (string) ($payload['email'] ?? ''),
                'classification' => $reviewFacts['classification'],
                'action' => $reviewFacts['action'],
                'has_downloads' => $reviewFacts['has_downloads'],
            ];
        }

        return $questions;
    }

    /**
     * @param array<string,mixed> $question
     * @param list<array<string,mixed>> $candidates
     * @return array<string,mixed>
     */
    private function candidateQuestion(array $question, RecordEnvelope $record, array $candidates): array
    {
        $choices = [];
        $seen = [];
        foreach ($candidates as $candidate) {
            $targetId = is_array($candidate) ? ($candidate['target_id'] ?? null) : null;
            $snapshot = is_array($candidate) ? ($candidate['snapshot'] ?? null) : null;
            $label = is_array($candidate) ? trim((string) ($candidate['label'] ?? '')) : '';
            if (!is_int($targetId) || $targetId <= 0 || !is_array($snapshot) || $label === '') {
                throw new \RuntimeException('guided_customer_target_read_failed');
            }
            if (isset($seen[$targetId])) {
                continue;
            }
            $seen[$targetId] = true;
            $choice = [
                'action' => 'reuse',
                'target_id' => $targetId,
                'target_fingerprint' => CanonicalJson::fingerprint($snapshot),
                'target_label' => $label,
            ];
            $choices[] = ['choice_id' => 'choice-' . substr(CanonicalJson::fingerprint($choice), 0, 12)] + $choice;
        }
        usort($choices, static fn (array $left, array $right): int => $left['target_id'] <=> $right['target_id']);
        $create = ['action' => 'create'];
        $choices[] = ['choice_id' => 'choice-' . substr(CanonicalJson::fingerprint($create), 0, 12)] + $create;
        $facts = [
            'identity' => $record->identity->canonical(),
            'source_fingerprint' => $record->sourceContentDigest,
            'classification' => $question['classification'],
            'choices' => $choices,
        ];

        return array_replace($question, $facts, [
            'review_id' => 'customer-' . substr(CanonicalJson::fingerprint($facts), 0, 12),
        ]);
    }

    /**
     * @param array<string, mixed> $proposal
     * @param list<array<string, mixed>> $answers
     * @return array<string, mixed>
     */
    public function resolve(
        array $proposal,
        array $answers,
        string $operator,
        string $decidedAtUtc,
    ): array {
        if (!array_is_list($answers)) {
            throw new \RuntimeException('guided_customer_decisions_invalid');
        }
        $answerByIdentity = [];
        $answerByReview = [];
        foreach ($answers as $answer) {
            $identity = is_array($answer) ? ($answer['identity'] ?? null) : null;
            $action = is_array($answer) ? ($answer['action'] ?? null) : null;
            $reviewId = is_array($answer) ? ($answer['review_id'] ?? null) : null;
            $choiceId = is_array($answer) ? ($answer['choice_id'] ?? null) : null;
            if (is_string($reviewId) && is_string($choiceId)) {
                if (isset($answerByReview[$reviewId])) {
                    throw new \RuntimeException('guided_customer_decisions_invalid');
                }
                $answerByReview[$reviewId] = $choiceId;
                continue;
            }
            if (!is_string($identity) || !is_string($action) || isset($answerByIdentity[$identity])) {
                throw new \RuntimeException('guided_customer_decisions_invalid');
            }
            $answerByIdentity[$identity] = $action;
        }

        $rows = [];
        foreach ($this->questions($proposal) as $question) {
            $identity = (string) $question['identity'];
            if (is_array($question['choices'] ?? null) && $question['choices'] !== []) {
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
                    throw new \RuntimeException('guided_customer_decisions_incomplete');
                }
                unset($answerByReview[$reviewId]);
                $record = $this->record($identity);
                if (!hash_equals(
                    (string) ($question['source_fingerprint'] ?? ''),
                    $record->sourceContentDigest,
                )) {
                    throw new \RuntimeException('guided_customer_decision_stale');
                }
                $rows[] = match ($choice['action'] ?? null) {
                    'reuse' => $this->explicitReuseDecisionRow($record, $choice, $operator, $decidedAtUtc),
                    'create' => $this->decisionRow($record, $operator, $decidedAtUtc),
                    default => throw new \RuntimeException('guided_customer_decisions_invalid'),
                };
                continue;
            }
            if (($answerByIdentity[$identity] ?? null) !== $question['action']) {
                throw new \RuntimeException('guided_customer_decisions_incomplete');
            }
            unset($answerByIdentity[$identity]);
            $record = $this->record($identity);
            $sealedFingerprint = $question['source_fingerprint'] ?? null;
            if (is_string($sealedFingerprint)
                && !hash_equals($sealedFingerprint, $record->sourceContentDigest)) {
                throw new \RuntimeException('guided_customer_decision_stale');
            }
            $rows[] = $this->decisionRow($record, $operator, $decidedAtUtc);
        }
        if ($answerByIdentity !== [] || $answerByReview !== []) {
            throw new \RuntimeException('guided_customer_decisions_invalid');
        }

        $existingRows = $proposal['decision_set']['decisions'] ?? null;
        if (!is_array($existingRows) || !array_is_list($existingRows)) {
            throw new \RuntimeException('guided_decision_proposal_missing');
        }
        $decisions = TransferDecisionSet::fromArray([...$existingRows, ...$rows]);
        $blockers = array_values(array_filter(
            is_array($proposal['blockers'] ?? null) ? $proposal['blockers'] : [],
            static fn (mixed $blocker): bool => !is_array($blocker)
                || ($blocker['code'] ?? null) !== 'customer_ownership_decision_requires_owner',
        ));
        $counts = is_array($proposal['proposal_counts'] ?? null) ? $proposal['proposal_counts'] : [];
        $counts['manual_customers'] = count($rows);
        $counts['total'] = count($decisions->rows());

        return array_replace($proposal, [
            'decision_set_fingerprint' => $decisions->fingerprint(),
            'status' => $blockers === [] ? 'owner_review_required' : 'blocked',
            'blockers' => $blockers,
            'proposal_counts' => $counts,
            'decision_set' => ['decisions' => $decisions->rows()],
        ]);
    }

    /** @param array<string, mixed> $proposal @return list<array{code:string,identity:string}> */
    private function customerBlockers(array $proposal): array
    {
        $blockers = [];
        foreach (is_array($proposal['blockers'] ?? null) ? $proposal['blockers'] : [] as $blocker) {
            if (is_array($blocker)
                && ($blocker['code'] ?? null) === 'customer_ownership_decision_requires_owner'
                && is_string($blocker['identity'] ?? null)) {
                $blockers[$blocker['identity']] = [
                    'code' => 'customer_ownership_decision_requires_owner',
                    'identity' => $blocker['identity'],
                ];
            }
        }
        ksort($blockers, SORT_STRING);

        return array_values($blockers);
    }

    /** @return array<string, mixed> */
    private function decisionRow(RecordEnvelope $record, string $operator, string $decidedAtUtc): array
    {
        $payload = $record->payload;
        $base = [
            'identity' => $record->identity->canonical(),
            'scope' => 'record',
            'source_fingerprint' => $record->sourceContentDigest,
            'operator' => $operator,
            'reason' => 'Approved from the exact same-site customer record in the guided review.',
            'decided_at' => $decidedAtUtc,
        ];
        if (($payload['classification'] ?? null) === 'registered') {
            $userId = $payload['source_user_id'] ?? null;
            if (!is_int($userId) || $userId <= 0) {
                throw new \RuntimeException('guided_customer_record_invalid');
            }

            return $base + ['action' => 'attach_exact_same_site_user', 'user_id' => $userId];
        }

        $orderId = $this->guestOrderId($record);
        $order = $record->identity->sourceKey . ':order:' . $orderId;
        $downloadable = $this->hasDownloads($orderId) ? [$order] : [];

        return $base + [
            'action' => 'allow_unlinked_downloads',
            'affected_orders' => [$order],
            'downloadable_orders' => $downloadable,
            'downloadable_order_count' => count($downloadable),
        ];
    }

    /** @param array<string,mixed> $choice @return array<string,mixed> */
    private function explicitReuseDecisionRow(
        RecordEnvelope $record,
        array $choice,
        string $operator,
        string $decidedAtUtc,
    ): array {
        $targetId = $choice['target_id'] ?? null;
        $targetFingerprint = $choice['target_fingerprint'] ?? null;
        if (!is_int($targetId) || $targetId <= 0
            || !is_string($targetFingerprint)
            || preg_match('/\A[a-f0-9]{64}\z/D', $targetFingerprint) !== 1) {
            throw new \RuntimeException('guided_customer_decisions_invalid');
        }

        return [
            'identity' => $record->identity->canonical(),
            'scope' => 'record',
            'action' => 'reuse_explicit_target_customer',
            'target_id' => $targetId,
            'source_fingerprint' => $record->sourceContentDigest,
            'target_fingerprint' => $targetFingerprint,
            'operator' => $operator,
            'reason' => 'The owner chose an existing FluentCart customer in the guided review.',
            'decided_at' => $decidedAtUtc,
        ];
    }

    private function record(string $canonical): RecordEnvelope
    {
        $identity = SourceIdentity::fromCanonical($canonical);
        if ($identity->entityType !== 'customer') {
            throw new \RuntimeException('guided_customer_record_invalid');
        }
        $record = $this->recordLoader !== null
            ? ($this->recordLoader)($identity)
            : (new WooCustomerRecordSource())->record($identity);
        if ($record->identity->canonical() !== $identity->canonical()) {
            throw new \RuntimeException('guided_customer_record_invalid');
        }

        return $record;
    }

    private function guestOrderId(RecordEnvelope $record): int
    {
        $orderId = $record->payload['provenance']['source_order_id'] ?? null;
        if (!is_int($orderId) || $orderId <= 0 || $record->identity->sourceId !== $orderId . ':guest') {
            throw new \RuntimeException('guided_customer_record_invalid');
        }

        return $orderId;
    }

    private function hasDownloads(int $orderId): bool
    {
        if ($this->downloadableOrder !== null) {
            return ($this->downloadableOrder)($orderId);
        }
        $order = function_exists('wc_get_order') ? wc_get_order($orderId) : null;
        if (!is_object($order) || !is_callable([$order, 'get_items'])) {
            throw new \RuntimeException('guided_guest_download_contract_unavailable');
        }
        foreach ((array) $order->get_items('line_item') as $item) {
            $product = is_object($item) && is_callable([$item, 'get_product']) ? $item->get_product() : null;
            if (!is_object($product) || !is_callable([$product, 'is_downloadable'])) {
                throw new \RuntimeException('guided_guest_download_contract_unavailable');
            }
            if ((bool) $product->is_downloadable()) {
                return true;
            }
        }

        return false;
    }

    /** @param list<RecordEnvelope> $records @return array<string,list<array<string,mixed>>> */
    private static function loadedTargetCandidates(array $records): array
    {
        global $wpdb;

        $emails = [];
        $userIds = [];
        foreach ($records as $record) {
            if (!$record instanceof RecordEnvelope || $record->identity->entityType !== 'customer') {
                throw new \RuntimeException('guided_customer_record_invalid');
            }
            $email = self::normalisedEmail((string) ($record->payload['email'] ?? ''));
            if ($email !== '') {
                $emails[$email] = $email;
            }
            $userId = $record->payload['source_user_id'] ?? null;
            if (is_int($userId) && $userId > 0) {
                $userIds[$userId] = $userId;
            }
        }
        if ($emails === [] && $userIds === []) {
            return [];
        }

        $where = [];
        $arguments = [];
        if ($emails !== []) {
            $where[] = 'LOWER(TRIM(c.email)) IN (' . implode(', ', array_fill(0, count($emails), '%s')) . ')';
            array_push($arguments, ...array_values($emails));
        }
        if ($userIds !== []) {
            $where[] = 'c.user_id IN (' . implode(', ', array_fill(0, count($userIds), '%d')) . ')';
            array_push($arguments, ...array_values($userIds));
        }
        $query = $wpdb->prepare(
            "SELECT c.id AS target_id, c.user_id AS customer_user_id, c.email AS customer_email,
                    c.first_name AS customer_first_name, c.last_name AS customer_last_name,
                    c.status AS customer_status, c.uuid AS customer_uuid,
                    c.created_at AS customer_created_at, c.updated_at AS customer_updated_at,
                    a.id AS address_row_id, a.customer_id AS address_customer_id,
                    a.is_primary AS address_is_primary, a.type AS address_type,
                    a.status AS address_status, a.label AS address_label, a.name AS address_name,
                    a.address_1, a.address_2, a.city AS address_city, a.state AS address_state,
                    a.phone AS address_phone, a.email AS address_email, a.postcode AS address_postcode,
                    a.country AS address_country, a.meta AS address_meta
               FROM {$wpdb->prefix}fct_customers c
          LEFT JOIN {$wpdb->prefix}fct_customer_addresses a ON a.customer_id = c.id
              WHERE " . implode(' OR ', $where) . '
           ORDER BY c.id ASC, a.id ASC',
            ...$arguments,
        );
        $rows = $wpdb->get_results($query, ARRAY_A);
        if ($wpdb->last_error !== '' || !is_array($rows)) {
            throw new \RuntimeException('guided_customer_target_read_failed');
        }

        $targets = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                throw new \RuntimeException('guided_customer_target_read_failed');
            }
            $targetId = (int) ($row['target_id'] ?? 0);
            if ($targetId <= 0) {
                throw new \RuntimeException('guided_customer_target_read_failed');
            }
            if (!isset($targets[$targetId])) {
                $targets[$targetId] = [
                    'target_id' => $targetId,
                    'user_id' => ($row['customer_user_id'] ?? null) === null
                        ? null
                        : (int) $row['customer_user_id'],
                    'email' => (string) ($row['customer_email'] ?? ''),
                    'label' => self::targetLabel($row),
                    'snapshot' => [
                        'customer' => [
                            'user_id' => ($row['customer_user_id'] ?? null) === null
                                ? null
                                : (int) $row['customer_user_id'],
                            'email' => (string) ($row['customer_email'] ?? ''),
                            'first_name' => (string) ($row['customer_first_name'] ?? ''),
                            'last_name' => (string) ($row['customer_last_name'] ?? ''),
                            'status' => (string) ($row['customer_status'] ?? ''),
                            'uuid' => (string) ($row['customer_uuid'] ?? ''),
                            'created_at' => (string) ($row['customer_created_at'] ?? ''),
                            'updated_at' => (string) ($row['customer_updated_at'] ?? ''),
                        ],
                        'addresses' => [],
                    ],
                ];
            }
            if (($row['address_row_id'] ?? null) !== null) {
                $targets[$targetId]['snapshot']['addresses'][] = self::targetAddress($row);
            }
        }

        $result = [];
        foreach ($records as $record) {
            $email = self::normalisedEmail((string) ($record->payload['email'] ?? ''));
            $userId = $record->payload['source_user_id'] ?? null;
            foreach ($targets as $target) {
                $sameUser = is_int($userId) && $userId > 0 && $target['user_id'] === $userId;
                $sameEmail = $email !== '' && self::normalisedEmail($target['email']) === $email;
                if ($sameUser || $sameEmail) {
                    $result[$record->identity->canonical()][] = [
                        'target_id' => $target['target_id'],
                        'label' => $target['label'],
                        'snapshot' => $target['snapshot'],
                    ];
                }
            }
        }

        return $result;
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private static function targetAddress(array $row): array
    {
        $meta = $row['address_meta'] ?? null;

        return [
            'customer_id' => (int) $row['address_customer_id'],
            'is_primary' => (int) $row['address_is_primary'],
            'type' => (string) ($row['address_type'] ?? ''),
            'status' => (string) ($row['address_status'] ?? ''),
            'label' => (string) ($row['address_label'] ?? ''),
            'name' => (string) ($row['address_name'] ?? ''),
            'address_1' => (string) ($row['address_1'] ?? ''),
            'address_2' => (string) ($row['address_2'] ?? ''),
            'city' => (string) ($row['address_city'] ?? ''),
            'state' => (string) ($row['address_state'] ?? ''),
            'phone' => (string) ($row['address_phone'] ?? ''),
            'email' => (string) ($row['address_email'] ?? ''),
            'postcode' => (string) ($row['address_postcode'] ?? ''),
            'country' => (string) ($row['address_country'] ?? ''),
            'meta' => $meta === null || $meta === '' ? null : json_decode((string) $meta, true, 32, JSON_THROW_ON_ERROR),
        ];
    }

    /** @param array<string,mixed> $row */
    private static function targetLabel(array $row): string
    {
        $name = trim((string) ($row['customer_first_name'] ?? '') . ' ' . (string) ($row['customer_last_name'] ?? ''));
        $email = trim((string) ($row['customer_email'] ?? ''));
        if ($name !== '' && $email !== '') {
            return $name . ' (' . $email . ')';
        }

        return $name !== '' ? $name : ($email !== '' ? $email : 'Existing FluentCart customer');
    }

    private static function normalisedEmail(string $email): string
    {
        return strtolower(trim($email));
    }
}

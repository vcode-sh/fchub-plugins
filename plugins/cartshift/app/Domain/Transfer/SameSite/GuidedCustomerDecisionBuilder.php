<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\SameSite;

use CartShift\Domain\Transfer\Customer\WooCustomerRecordSource;
use CartShift\Domain\Transfer\Decision\TransferDecisionSet;
use CartShift\Domain\Transfer\RecordEnvelope;
use CartShift\Domain\Transfer\SourceIdentity;
use CartShift\Support\CanonicalJson;

defined('ABSPATH') || exit;

/** Turns owner-approved same-site customer questions into fingerprint-bound decision rows. */
final readonly class GuidedCustomerDecisionBuilder
{
    /** @var null|\Closure(SourceIdentity): RecordEnvelope */
    private ?\Closure $recordLoader;

    /** @var null|\Closure(int): bool */
    private ?\Closure $downloadableOrder;

    /**
     * @param null|callable(SourceIdentity): RecordEnvelope $recordLoader
     * @param null|callable(int): bool $downloadableOrder
     */
    public function __construct(?callable $recordLoader = null, ?callable $downloadableOrder = null)
    {
        $this->recordLoader = $recordLoader === null ? null : $recordLoader(...);
        $this->downloadableOrder = $downloadableOrder === null ? null : $downloadableOrder(...);
    }

    /** @param array<string, mixed> $proposal @return list<array<string, mixed>> */
    public function questions(array $proposal): array
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
        foreach ($answers as $answer) {
            $identity = is_array($answer) ? ($answer['identity'] ?? null) : null;
            $action = is_array($answer) ? ($answer['action'] ?? null) : null;
            if (!is_string($identity) || !is_string($action) || isset($answerByIdentity[$identity])) {
                throw new \RuntimeException('guided_customer_decisions_invalid');
            }
            $answerByIdentity[$identity] = $action;
        }

        $rows = [];
        foreach ($this->questions($proposal) as $question) {
            $identity = (string) $question['identity'];
            if (($answerByIdentity[$identity] ?? null) !== $question['action']) {
                throw new \RuntimeException('guided_customer_decisions_incomplete');
            }
            unset($answerByIdentity[$identity]);
            $rows[] = $this->decisionRow($this->record($identity), $operator, $decidedAtUtc);
        }
        if ($answerByIdentity !== []) {
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
}

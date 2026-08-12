<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\Legacy;

use CartShift\Domain\Subscription\Source\SubscriptionRecord as V2SubscriptionRecord;
use CartShift\Domain\Subscription\CustomerRecord as V1CustomerRecord;
use CartShift\Domain\Subscription\OrderRecord as V1OrderRecord;
use CartShift\Domain\Subscription\ProductRecord as V1ProductRecord;
use CartShift\Domain\Subscription\SubscriptionRecord as V1SubscriptionRecord;
use CartShift\Domain\Transfer\RecordEnvelope;
use CartShift\Domain\Transfer\RecordKind;
use CartShift\Domain\Transfer\SourceIdentity;
use CartShift\Support\CanonicalJson;

defined('ABSPATH') || exit;

/**
 * Converts the proven v1 subscription contract without treating old execution
 * state as permission to write through the v2 coordinator.
 *
 * Completed v1 mappings and receipts are evidence only. They are validated and
 * returned separately, never injected into a record or emitted as queued work.
 */
final class SubscriptionV1ToV2Adapter
{
    private const string BLOCKED = 'blocked_subscription_v1_conversion';

    /**
     * @param iterable<V1SubscriptionRecord> $subscriptions
     * @param array<string, array<string, mixed>> $guestProofs Indexed by v1 source reference.
     * @param list<array<string, mixed>> $completedEvidence
     * @return array{
     *   ok: bool,
     *   records: list<RecordEnvelope>,
     *   failures: list<array{code: string, identity: string, field: string, reason: string}>,
     *   external_evidence: list<array<string, mixed>>
     * }
     */
    public function convert(iterable $subscriptions, array $guestProofs = [], array $completedEvidence = []): array
    {
        $records = [];
        $failures = [];
        $v1ByIdentity = [];

        foreach ($subscriptions as $record) {
            if (!$record instanceof V1SubscriptionRecord) {
                throw new \InvalidArgumentException('subscription_v1_record_invalid');
            }

            $identity = $this->subscriptionIdentity($record);
            $v1ByIdentity[$identity] = $record;

            try {
                $customerIdentity = $this->customerIdentity($record, $guestProofs[$record->sourceRef] ?? null);
                $records[] = V2SubscriptionRecord::fromV1($record, $customerIdentity)->envelope();
            } catch (ConversionBlocked $exception) {
                $failures[] = $this->failure($identity, $exception->field, $exception->reason);
            } catch (\InvalidArgumentException $exception) {
                $failures[] = $this->failure($identity, 'record', $exception->getMessage());
            }
        }

        $validatedEvidence = [];
        foreach ($completedEvidence as $evidence) {
            $identity = is_string($evidence['source_identity'] ?? null)
                ? $evidence['source_identity']
                : 'unknown:subscription:unknown';

            try {
                $validatedEvidence[] = $this->validateExternalEvidence($evidence, $v1ByIdentity);
            } catch (ConversionBlocked $exception) {
                $failures[] = $this->failure($identity, $exception->field, $exception->reason);
            }
        }

        usort(
            $records,
            static fn (RecordEnvelope $left, RecordEnvelope $right): int => strcmp(
                $left->identity->canonical(),
                $right->identity->canonical(),
            ),
        );
        usort(
            $validatedEvidence,
            static fn (array $left, array $right): int => strcmp(
                (string) $left['source_identity'],
                (string) $right['source_identity'],
            ),
        );

        return [
            'ok' => $failures === [],
            'records' => $records,
            'failures' => $failures,
            'external_evidence' => $validatedEvidence,
        ];
    }

    /**
     * Convert a mixed v1 package without pretending its thin customer, product
     * and order records contain the full v2 contracts introduced later.
     *
     * Those three kinds may adopt bytes produced by the canonical live v2
     * source, but only with a reviewed witness bound to both fingerprints and
     * every v1 field family. Missing evidence blocks; this method never creates
     * prices, stock, schedules, relationships or payment events from defaults.
     *
     * @param iterable<V1CustomerRecord|V1ProductRecord|V1OrderRecord|V1SubscriptionRecord> $legacyRecords
     * @param array<string, RecordEnvelope> $canonicalRecords Indexed by canonical source identity.
     * @param array<string, array<string, mixed>> $equivalenceProofs Indexed by canonical source identity.
     * @param array<string, array<string, mixed>> $guestProofs
     * @param list<array<string, mixed>> $completedEvidence
     * @return array{
     *   ok: bool,
     *   records: list<RecordEnvelope>,
     *   failures: list<array{code: string, identity: string, field: string, reason: string}>,
     *   external_evidence: list<array<string, mixed>>
     * }
     */
    public function convertDataset(
        iterable $legacyRecords,
        array $canonicalRecords = [],
        array $equivalenceProofs = [],
        array $guestProofs = [],
        array $completedEvidence = [],
    ): array {
        $subscriptions = [];
        $records = [];
        $failures = [];

        foreach ($legacyRecords as $legacy) {
            if ($legacy instanceof V1SubscriptionRecord) {
                $subscriptions[] = $legacy;
                continue;
            }

            if (!$legacy instanceof V1CustomerRecord
                && !$legacy instanceof V1ProductRecord
                && !$legacy instanceof V1OrderRecord) {
                throw new \InvalidArgumentException('subscription_v1_record_invalid');
            }

            $identity = $legacy->sourceKey . ':' . $legacy->sourceRef;
            try {
                $identity = $this->thinRecordIdentity($legacy);
                $canonical = $canonicalRecords[$identity] ?? null;
                if (!$canonical instanceof RecordEnvelope) {
                    throw new ConversionBlocked('record', 'canonical_v2_record_missing');
                }
                $proof = $equivalenceProofs[$identity] ?? null;
                if (!is_array($proof)) {
                    throw new ConversionBlocked('equivalence_proof', 'canonical_v2_equivalence_unproven');
                }

                $this->validateCanonicalAdoption($legacy, $identity, $canonical, $proof);
                $records[] = $canonical;
            } catch (ConversionBlocked $exception) {
                $failures[] = $this->failure($identity, $exception->field, $exception->reason);
            }
        }

        $subscriptionsResult = $this->convert($subscriptions, $guestProofs, $completedEvidence);
        $records = [...$records, ...$subscriptionsResult['records']];
        $failures = [...$failures, ...$subscriptionsResult['failures']];
        usort(
            $records,
            static fn (RecordEnvelope $left, RecordEnvelope $right): int => strcmp(
                $left->identity->canonical(),
                $right->identity->canonical(),
            ),
        );

        return [
            'ok' => $failures === [],
            'records' => $records,
            'failures' => $failures,
            'external_evidence' => $subscriptionsResult['external_evidence'],
        ];
    }

    private function thinRecordIdentity(V1CustomerRecord|V1ProductRecord|V1OrderRecord $record): string
    {
        if ($record instanceof V1CustomerRecord) {
            if ($record->sourceUserId === null) {
                throw new ConversionBlocked('customer_identity', 'guest_customer_split_unproven');
            }
            return (new SourceIdentity($record->sourceKey, RecordKind::Customer->value, (string) $record->sourceUserId))->canonical();
        }
        if ($record instanceof V1ProductRecord) {
            return (new SourceIdentity($record->sourceKey, RecordKind::Product->value, (string) $record->sourceProductId))->canonical();
        }

        return (new SourceIdentity($record->sourceKey, RecordKind::Order->value, (string) $record->sourceOrderId))->canonical();
    }

    /** @param array<string, mixed> $proof */
    private function validateCanonicalAdoption(
        V1CustomerRecord|V1ProductRecord|V1OrderRecord $legacy,
        string $identity,
        RecordEnvelope $canonical,
        array $proof,
    ): void {
        if ($canonical->identity->canonical() !== $identity) {
            throw new ConversionBlocked('identity', 'canonical_v2_identity_changed');
        }

        $expectedKeys = [
            'decision_fingerprint',
            'field_witnesses',
            'source_identity',
            'v1_fingerprint',
            'v2_private_digest',
        ];
        $keys = array_keys($proof);
        sort($keys, SORT_STRING);
        if ($keys !== $expectedKeys || $proof['source_identity'] !== $identity) {
            throw new ConversionBlocked('equivalence_proof', 'canonical_v2_equivalence_proof_invalid');
        }

        if (!$this->isFingerprint($legacy->fingerprint)
            || !hash_equals($legacy->fingerprint, (string) $proof['v1_fingerprint'])) {
            throw new ConversionBlocked('equivalence_proof', 'legacy_v1_record_changed');
        }
        if (!hash_equals($canonical->privateContentDigest, (string) $proof['v2_private_digest'])) {
            throw new ConversionBlocked('equivalence_proof', 'canonical_v2_record_changed');
        }

        $requiredWitnesses = match (true) {
            $legacy instanceof V1CustomerRecord => ['billing_identity', 'email', 'identity'],
            $legacy instanceof V1ProductRecord => ['identity', 'name', 'sku', 'type', 'variations'],
            default => ['addresses', 'currency', 'dates', 'identity', 'items', 'status', 'totals', 'transactions'],
        };
        if (!is_array($proof['field_witnesses']) || !array_is_list($proof['field_witnesses'])) {
            throw new ConversionBlocked('field_witnesses', 'canonical_v2_field_witnesses_missing');
        }
        $witnesses = $proof['field_witnesses'];
        sort($witnesses, SORT_STRING);
        if ($witnesses !== $requiredWitnesses) {
            throw new ConversionBlocked('field_witnesses', 'canonical_v2_field_witnesses_missing');
        }

        $fingerprinted = $proof;
        unset($fingerprinted['decision_fingerprint']);
        if (!$this->isFingerprint($proof['decision_fingerprint'])
            || !hash_equals(CanonicalJson::fingerprint($fingerprinted), $proof['decision_fingerprint'])) {
            throw new ConversionBlocked('equivalence_proof', 'canonical_v2_equivalence_proof_changed');
        }

        $this->validateSharedSemantics($legacy, $canonical->payload);
    }

    /** @param array<string, mixed> $payload */
    private function validateSharedSemantics(
        V1CustomerRecord|V1ProductRecord|V1OrderRecord $legacy,
        array $payload,
    ): void {
        if ($legacy instanceof V1CustomerRecord) {
            if (($payload['source_user_id'] ?? null) !== $legacy->sourceUserId
                || strtolower(trim((string) ($payload['email'] ?? ''))) !== strtolower(trim($legacy->email))) {
                throw new ConversionBlocked('customer', 'canonical_v2_customer_semantics_changed');
            }
            return;
        }

        if ($legacy instanceof V1ProductRecord) {
            if (($payload['name'] ?? null) !== $legacy->name || ($payload['sku'] ?? null) !== $legacy->sku
                || !is_array($payload['variations'] ?? null)
                || count($payload['variations']) !== count($legacy->variations)) {
                throw new ConversionBlocked('product', 'canonical_v2_product_semantics_changed');
            }
            return;
        }

        if (($payload['source_status'] ?? null) !== $legacy->status
            || strtoupper((string) ($payload['currency'] ?? '')) !== strtoupper($legacy->currency)
            || ($payload['gross_total'] ?? null) !== ($legacy->totals['total'] ?? null)
            || !is_array($payload['product_lines'] ?? null)
            || count($payload['product_lines']) !== count($legacy->items)
            || !is_array($payload['payment_events'] ?? null)
            || count($payload['payment_events']) !== count($legacy->transactions)) {
            throw new ConversionBlocked('order', 'canonical_v2_order_semantics_changed');
        }
    }

    /** @param array<string, mixed>|null $proof */
    private function customerIdentity(V1SubscriptionRecord $record, ?array $proof): SourceIdentity
    {
        if ($record->sourceCustomerId !== null) {
            return new SourceIdentity(
                $record->sourceKey,
                RecordKind::Customer->value,
                (string) $record->sourceCustomerId,
            );
        }

        if ($proof === null) {
            throw new ConversionBlocked('customer_identity', 'guest_identity_rekey_unproven');
        }

        $expectedKeys = [
            'customer_identity',
            'decision_fingerprint',
            'dependent_fingerprints',
            'mode',
            'target_customer_id',
            'target_fingerprint',
        ];
        $keys = array_keys($proof);
        sort($keys, SORT_STRING);
        if ($keys !== $expectedKeys) {
            throw new ConversionBlocked('customer_identity', 'guest_proof_shape_invalid');
        }

        $mode = $proof['mode'];
        if (!is_string($mode) || !in_array($mode, ['exact_map', 'reviewed_merge_split'], true)) {
            throw new ConversionBlocked('customer_identity', 'guest_proof_mode_invalid');
        }

        try {
            $identity = SourceIdentity::fromCanonical((string) $proof['customer_identity']);
        } catch (\InvalidArgumentException) {
            throw new ConversionBlocked('customer_identity', 'guest_customer_identity_invalid');
        }
        $expectedSourceId = $record->parentOrderId . ':guest';
        if ($identity->sourceKey !== $record->sourceKey
            || $identity->kind() !== RecordKind::Customer
            || $identity->sourceId !== $expectedSourceId) {
            throw new ConversionBlocked('customer_identity', 'guest_customer_identity_invalid');
        }

        if (!is_int($proof['target_customer_id']) || $proof['target_customer_id'] <= 0
            || !$this->isFingerprint($proof['target_fingerprint'])) {
            throw new ConversionBlocked('customer_identity', 'guest_target_evidence_invalid');
        }

        if (!is_array($proof['dependent_fingerprints'])
            || ($proof['dependent_fingerprints'][$record->sourceRef] ?? null) !== $record->fingerprint) {
            throw new ConversionBlocked('customer_identity', 'guest_dependent_fingerprint_changed');
        }
        foreach ($proof['dependent_fingerprints'] as $dependent => $fingerprint) {
            if (!is_string($dependent) || $dependent === '' || !$this->isFingerprint($fingerprint)) {
                throw new ConversionBlocked('customer_identity', 'guest_dependent_fingerprint_invalid');
            }
        }

        if (($mode === 'exact_map' && $proof['decision_fingerprint'] !== null)
            || ($mode === 'reviewed_merge_split' && !$this->isFingerprint($proof['decision_fingerprint']))) {
            throw new ConversionBlocked('customer_identity', 'guest_decision_evidence_invalid');
        }

        return $identity;
    }

    /**
     * @param array<string, mixed> $evidence
     * @param array<string, V1SubscriptionRecord> $v1ByIdentity
     * @return array<string, mixed>
     */
    private function validateExternalEvidence(array $evidence, array $v1ByIdentity): array
    {
        $expectedKeys = [
            'action',
            'evidence_fingerprint',
            'source_fingerprint',
            'source_identity',
            'state',
            'target_fingerprint',
            'target_id',
        ];
        $keys = array_keys($evidence);
        sort($keys, SORT_STRING);
        if ($keys !== $expectedKeys) {
            throw new ConversionBlocked('external_evidence', 'external_evidence_shape_invalid');
        }

        try {
            $sourceIdentity = SourceIdentity::fromCanonical((string) $evidence['source_identity']);
        } catch (\InvalidArgumentException) {
            throw new ConversionBlocked('external_evidence', 'external_evidence_identity_invalid');
        }
        if ($sourceIdentity->kind() !== RecordKind::Subscription
            || !in_array($evidence['action'], ['created', 'reused'], true)
            || $evidence['state'] !== 'completed'
            || !is_int($evidence['target_id']) || $evidence['target_id'] <= 0
            || !$this->isFingerprint($evidence['source_fingerprint'])
            || !$this->isFingerprint($evidence['target_fingerprint'])
            || !$this->isFingerprint($evidence['evidence_fingerprint'])) {
            throw new ConversionBlocked('external_evidence', 'external_evidence_value_invalid');
        }

        $fingerprinted = $evidence;
        unset($fingerprinted['evidence_fingerprint']);
        if (!hash_equals(CanonicalJson::fingerprint($fingerprinted), $evidence['evidence_fingerprint'])) {
            throw new ConversionBlocked('external_evidence', 'external_evidence_fingerprint_changed');
        }

        $record = $v1ByIdentity[$sourceIdentity->canonical()] ?? null;
        if ($record !== null && !hash_equals($record->fingerprint, $evidence['source_fingerprint'])) {
            throw new ConversionBlocked('external_evidence', 'external_evidence_source_changed');
        }

        return $evidence;
    }

    /** @return array{code: string, identity: string, field: string, reason: string} */
    private function failure(string $identity, string $field, string $reason): array
    {
        return [
            'code' => self::BLOCKED,
            'identity' => $identity,
            'field' => $field,
            'reason' => $reason,
        ];
    }

    private function subscriptionIdentity(V1SubscriptionRecord $record): string
    {
        return (new SourceIdentity(
            $record->sourceKey,
            RecordKind::Subscription->value,
            (string) $record->sourceSubscriptionId,
        ))->canonical();
    }

    private function isFingerprint(mixed $value): bool
    {
        return is_string($value) && preg_match('/\A[a-f0-9]{64}\z/D', $value) === 1;
    }
}

final class ConversionBlocked extends \RuntimeException
{
    public function __construct(
        public readonly string $field,
        public readonly string $reason,
    ) {
        parent::__construct($reason);
    }
}

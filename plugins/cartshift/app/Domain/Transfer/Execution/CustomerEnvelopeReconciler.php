<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\Execution;

use CartShift\Domain\Transfer\Customer\CustomerReconciler;
use CartShift\Domain\Transfer\Customer\CustomerTargetGateway;
use CartShift\Domain\Transfer\Identity\CheckedMappingStore;
use CartShift\Domain\Transfer\RecordEnvelope;
use CartShift\Domain\Transfer\RecordReconciler;
use CartShift\Domain\Transfer\ReconcileContext;
use CartShift\Domain\Transfer\ReconciliationResult;
use CartShift\Support\CanonicalJson;

defined('ABSPATH') || exit;

final readonly class CustomerEnvelopeReconciler implements RecordReconciler
{
    public function __construct(
        private TargetRecordPlanFactory $plans,
        private CustomerTargetGateway $gateway,
        private CheckedMappingStore $maps,
        private CustomerReconciler $reconciler,
    ) {}

    public function reconcile(RecordEnvelope $record, ReconcileContext $context): ReconciliationResult
    {
        $projection = $this->plans->customer($record);
        $snapshot = $this->gateway->snapshot($context->targetIds['primary']);
        if ($projection['assessment']->action === 'reuse_explicit_target_customer') {
            $actualFingerprint = CanonicalJson::fingerprint($snapshot);
            $matches = ($projection['assessment']->evidence['target_id'] ?? null) === $context->targetIds['primary']
                && hash_equals(
                    (string) ($projection['assessment']->evidence['target_fingerprint'] ?? ''),
                    $actualFingerprint,
                )
                && hash_equals($context->expectedAfterFingerprint, $actualFingerprint);

            return new ReconciliationResult(
                $matches,
                $actualFingerprint,
                $matches ? [] : ['customer_target_graph_mismatch'],
            );
        }
        $addressMap = ['customer_id' => $context->targetIds['primary']];
        foreach ($projection['record']->addresses as $address) {
            $mapping = $this->maps->get($address->identity);
            if ($mapping === null || !$mapping->isActive()) {
                return new ReconciliationResult(false, hash('sha256', 'customer_address_map_missing'), ['customer_address_map_missing']);
            }
            $addressMap[$address->identity->canonical()] = $mapping->targetId;
        }
        return $this->reconciler->reconcile(
            $projection['record'],
            $projection['assessment'],
            $snapshot,
            $addressMap,
        );
    }
}

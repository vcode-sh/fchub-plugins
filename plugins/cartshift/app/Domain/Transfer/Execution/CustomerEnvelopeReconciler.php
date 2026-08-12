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
            $this->gateway->snapshot($context->targetIds['primary']),
            $addressMap,
        );
    }
}

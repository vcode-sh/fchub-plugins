<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\Execution;

use CartShift\Domain\Transfer\Order\OrderReconciler;
use CartShift\Domain\Transfer\RecordEnvelope;
use CartShift\Domain\Transfer\RecordReconciler;
use CartShift\Domain\Transfer\ReconcileContext;
use CartShift\Domain\Transfer\ReconciliationResult;

defined('ABSPATH') || exit;

final readonly class OrderEnvelopeReconciler implements RecordReconciler
{
    public function __construct(private TargetRecordPlanFactory $plans, private OrderReconciler $reconciler) {}

    public function reconcile(RecordEnvelope $record, ReconcileContext $context): ReconciliationResult
    {
        return $this->reconciler->reconcile($this->plans->order($record), $context->targetIds['primary'], $context->expectedAfterFingerprint);
    }
}

<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\Execution;

use CartShift\Domain\Transfer\Product\ProductReconciler;
use CartShift\Domain\Transfer\RecordEnvelope;
use CartShift\Domain\Transfer\RecordReconciler;
use CartShift\Domain\Transfer\ReconcileContext;
use CartShift\Domain\Transfer\ReconciliationResult;

defined('ABSPATH') || exit;

final readonly class ProductEnvelopeReconciler implements RecordReconciler
{
    public function __construct(private TargetRecordPlanFactory $plans, private ProductReconciler $reconciler) {}

    public function reconcile(RecordEnvelope $record, ReconcileContext $context): ReconciliationResult
    {
        return $this->reconciler->reconcile(
            $this->plans->product($record),
            $context->targetIds['primary'],
            $context->expectedAfterFingerprint,
            $context->approvedProductStatus,
        );
    }
}

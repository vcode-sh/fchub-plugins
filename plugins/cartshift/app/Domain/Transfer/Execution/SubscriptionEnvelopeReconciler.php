<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\Execution;

use CartShift\Domain\Transfer\RecordEnvelope;
use CartShift\Domain\Transfer\RecordReconciler;
use CartShift\Domain\Transfer\ReconcileContext;
use CartShift\Domain\Transfer\ReconciliationResult;
use CartShift\Domain\Transfer\Subscription\SubscriptionReconciler;

defined('ABSPATH') || exit;

final readonly class SubscriptionEnvelopeReconciler implements RecordReconciler
{
    public function __construct(private TargetRecordPlanFactory $plans, private SubscriptionReconciler $reconciler) {}
    public function reconcile(RecordEnvelope $record, ReconcileContext $context): ReconciliationResult
    {
        return $this->reconciler->reconcile($this->plans->subscription($record), $context->targetIds['primary'], $context->expectedAfterFingerprint);
    }
}

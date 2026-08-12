<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer;

defined('ABSPATH') || exit;

interface RecordReconciler
{
    public function reconcile(RecordEnvelope $record, ReconcileContext $context): ReconciliationResult;
}

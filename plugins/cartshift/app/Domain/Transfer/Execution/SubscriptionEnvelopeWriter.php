<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\Execution;

use CartShift\Domain\Transfer\RecordEnvelope;
use CartShift\Domain\Transfer\RecordWriter;
use CartShift\Domain\Transfer\StageContext;
use CartShift\Domain\Transfer\StageResult;
use CartShift\Domain\Transfer\Subscription\FluentCartSubscriptionWriter;

defined('ABSPATH') || exit;

final readonly class SubscriptionEnvelopeWriter implements RecordWriter
{
    public function __construct(private TargetRecordPlanFactory $plans, private FluentCartSubscriptionWriter $writer) {}
    public function stage(RecordEnvelope $record, StageContext $context): StageResult
    {
        return $this->writer->stage($this->plans->subscription($record), $context);
    }
}

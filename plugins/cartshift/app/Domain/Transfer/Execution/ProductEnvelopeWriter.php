<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\Execution;

use CartShift\Domain\Transfer\Product\FluentCartProductWriter;
use CartShift\Domain\Transfer\RecordEnvelope;
use CartShift\Domain\Transfer\RecordWriter;
use CartShift\Domain\Transfer\StageContext;
use CartShift\Domain\Transfer\StageResult;

defined('ABSPATH') || exit;

final readonly class ProductEnvelopeWriter implements RecordWriter
{
    public function __construct(private TargetRecordPlanFactory $plans, private FluentCartProductWriter $writer) {}

    public function stage(RecordEnvelope $record, StageContext $context): StageResult
    {
        return $this->writer->stage($this->plans->product($record), $context);
    }
}

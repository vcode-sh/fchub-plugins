<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\Execution;

use CartShift\Domain\Transfer\Order\FluentCartOrderWriter;
use CartShift\Domain\Transfer\RecordEnvelope;
use CartShift\Domain\Transfer\RecordWriter;
use CartShift\Domain\Transfer\StageContext;
use CartShift\Domain\Transfer\StageResult;

defined('ABSPATH') || exit;

final readonly class OrderEnvelopeWriter implements RecordWriter
{
    public function __construct(private TargetRecordPlanFactory $plans, private FluentCartOrderWriter $writer) {}

    public function stage(RecordEnvelope $record, StageContext $context): StageResult
    {
        $result = $this->writer->stage($this->plans->order($record), $context);
        $map = $result->targetMap;
        ksort($map, SORT_STRING);
        return new StageResult($result->targetId, [], [], [], $result->targetFingerprint, $result->reused, [], $map);
    }
}

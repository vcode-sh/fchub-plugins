<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\Execution;

use CartShift\Domain\Transfer\Customer\CustomerWriter;
use CartShift\Domain\Transfer\RecordEnvelope;
use CartShift\Domain\Transfer\RecordWriter;
use CartShift\Domain\Transfer\StageContext;
use CartShift\Domain\Transfer\StageResult;

defined('ABSPATH') || exit;

final readonly class CustomerEnvelopeWriter implements RecordWriter
{
    public function __construct(private TargetRecordPlanFactory $plans, private CustomerWriter $writer) {}

    public function stage(RecordEnvelope $record, StageContext $context): StageResult
    {
        $projection = $this->plans->customer($record);
        $result = $this->writer->stage($projection['record'], $projection['assessment'], $context);
        $map = [$projection['record']->identity->canonical() => $result->targetId];
        foreach ($projection['record']->addresses as $index => $address) {
            if (isset($result->addressIds[$index])) {
                $map[$address->identity->canonical()] = $result->addressIds[$index];
            }
        }
        ksort($map, SORT_STRING);
        return new StageResult($result->targetId, [], [], [], $result->targetFingerprint, $result->reused, [], $map);
    }
}

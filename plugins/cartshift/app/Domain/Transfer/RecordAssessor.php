<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer;

defined('ABSPATH') || exit;

interface RecordAssessor
{
    public function assess(RecordEnvelope $record, AssessmentContext $context): RecordAssessment;
}

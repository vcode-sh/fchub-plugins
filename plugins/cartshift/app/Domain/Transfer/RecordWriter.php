<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer;

defined('ABSPATH') || exit;

interface RecordWriter
{
    public function stage(RecordEnvelope $record, StageContext $context): StageResult;
}

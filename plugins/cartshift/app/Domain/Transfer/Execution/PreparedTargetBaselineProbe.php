<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\Execution;

use CartShift\Domain\Transfer\Decision\TransferDecisionSet;
use CartShift\Domain\Transfer\RecordEnvelope;

defined('ABSPATH') || exit;

interface PreparedTargetBaselineProbe
{
    /** @param list<RecordEnvelope> $records */
    public function capture(
        string $sourceKey,
        array $records,
        TransferDecisionSet $decisions,
        string $runId,
    ): PreparedTargetBaseline;

    public function verify(PreparedTargetBaseline $baseline, string $runId): void;
}

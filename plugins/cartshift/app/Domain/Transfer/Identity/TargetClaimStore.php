<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\Identity;

use CartShift\Domain\Transfer\SourceIdentity;

defined('ABSPATH') || exit;

interface TargetClaimStore
{
    public function claimOrThrow(
        SourceIdentity $identity,
        int $targetId,
        string $runId,
        string $sourceFingerprint,
        string $targetFingerprint,
        MapState $state,
    ): MappingRecord;
}

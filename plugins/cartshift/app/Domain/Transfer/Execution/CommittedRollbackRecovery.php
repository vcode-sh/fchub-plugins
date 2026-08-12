<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\Execution;

defined('ABSPATH') || exit;

interface CommittedRollbackRecovery
{
    public function completeCommittedRollback(RollbackPlan $plan): void;
}

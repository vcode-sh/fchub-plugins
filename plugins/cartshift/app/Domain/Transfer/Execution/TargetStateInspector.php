<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\Execution;

defined('ABSPATH') || exit;

interface TargetStateInspector
{
    public function inspect(): TargetStateFingerprint;
}

<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\Execution;

defined('ABSPATH') || exit;

interface TargetSettingsInspector
{
    public function fingerprint(): string;

    public function gatewayFingerprint(): string;
}

<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\Runtime;

defined('ABSPATH') || exit;

interface TransferRuntimeInspector
{
    public function inspect(string $role): TransferRuntimeReport;
}

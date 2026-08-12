<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\Audit;

use CartShift\Domain\Transfer\TransferSelection;

defined('ABSPATH') || exit;

interface SourceInventoryInspector
{
    public function inspect(TransferSelection $selection): SourceInventoryReport;
}

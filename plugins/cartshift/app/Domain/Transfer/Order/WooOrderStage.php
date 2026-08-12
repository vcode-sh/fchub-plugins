<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\Order;

defined('ABSPATH') || exit;

interface WooOrderStage
{
    public function stage(object $wooOrder, string $migrationId): OrderStageResult;
}

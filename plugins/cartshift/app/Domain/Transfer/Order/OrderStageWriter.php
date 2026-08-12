<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\Order;

use CartShift\Domain\Transfer\StageContext;

defined('ABSPATH') || exit;

interface OrderStageWriter
{
    public function stage(OrderStagePlan $plan, StageContext $context): OrderStageResult;
}

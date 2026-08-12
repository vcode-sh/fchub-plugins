<?php

declare(strict_types=1);

namespace CartShift\Domain\Subscription;

use CartShift\Domain\Transfer\Order\OrderStageResult;

defined('ABSPATH') || exit;

/** Canonical order staging seam used by subscription-history orchestration. */
interface SubscriptionOrderStage
{
    public function stage(
        OrderRecord $source,
        string $relationship,
        ?int $customerTargetId,
        ?int $parentTargetId,
        string $migrationId,
    ): OrderStageResult;
}

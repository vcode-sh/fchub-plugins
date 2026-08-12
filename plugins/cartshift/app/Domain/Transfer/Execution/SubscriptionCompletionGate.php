<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\Execution;

defined('ABSPATH') || exit;

interface SubscriptionCompletionGate
{
    /** @param list<TransferReceipt> $receipts */
    public function assertReady(PreparedTransfer $prepared, array $receipts): void;
}

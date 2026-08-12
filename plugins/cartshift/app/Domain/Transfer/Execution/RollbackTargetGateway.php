<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\Execution;

defined('ABSPATH') || exit;

interface RollbackTargetGateway
{
    public function fingerprint(TransferReceipt $receipt): ?string;
    public function delete(TransferReceipt $receipt): void;
}

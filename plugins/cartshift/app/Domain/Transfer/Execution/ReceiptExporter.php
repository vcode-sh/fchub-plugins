<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\Execution;

defined('ABSPATH') || exit;

interface ReceiptExporter
{
    public function export(TransferReceipt $receipt): void;
}

<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\Execution;

use CartShift\Support\CanonicalJson;

defined('ABSPATH') || exit;

final readonly class TransferReceiptRepository implements ReceiptExporter
{
    private string $directory;

    public function __construct(string $privateDirectory)
    {
        $this->directory = PrivateTransferFile::directory($privateDirectory);
    }

    public function export(TransferReceipt $receipt): void
    {
        $run = PrivateTransferFile::createDirectory($this->directory, $receipt->runId);
        $receipts = PrivateTransferFile::createDirectory($run, 'receipts');
        $name = sprintf(
            '%08d-%s-%s.json',
            $receipt->sequence,
            $receipt->recordKind,
            substr(hash('sha256', $receipt->sourceIdentity), 0, 24),
        );
        PrivateTransferFile::writeImmutable(
            $receipts,
            $name,
            CanonicalJson::encode($receipt->toArray()) . "\n",
            'transfer_receipt_immutable_conflict',
        );
    }
}

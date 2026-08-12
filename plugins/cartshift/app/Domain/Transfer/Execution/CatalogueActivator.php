<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\Execution;

defined('ABSPATH') || exit;

interface CatalogueActivator
{
    public function activate(TransferReceipt $productReceipt, string $approvedStatus): CatalogueStatusChange;
    public function fingerprint(CatalogueStatusChange $change): string;
    public function fingerprintReceipt(TransferReceipt $receipt): string;
    public function restore(CatalogueStatusChange $change): void;
    public function restoreReceipt(TransferReceipt $receipt): void;
    /** @param list<TransferReceipt> $statusReceipts */
    public function storefrontAndCartReconcile(array $statusReceipts): bool;
}

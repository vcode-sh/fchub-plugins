<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\Product;

use CartShift\Domain\Transfer\Execution\TransferReceipt;

defined('ABSPATH') || exit;

/** Confirms planned parent-stock exceptions only against receipt-owned targets. */
final readonly class ParentStockExceptionReport
{
    public function __construct(private ProductTargetGateway $gateway)
    {
    }

    /**
     * @param list<TransferReceipt> $receipts
     * @param list<array<string,mixed>> $expected
     * @return list<array<string,mixed>>
     */
    public function confirm(array $receipts, array $expected): array
    {
        $products = array_values(array_filter(
            $receipts,
            static fn (mixed $receipt): bool => $receipt instanceof TransferReceipt
                && $receipt->recordKind === 'product',
        ));
        $snapshots = [];
        $report = [];

        foreach ($expected as $item) {
            if (!is_array($item)) {
                continue;
            }
            if (($item['kind'] ?? null) !== 'shared_parent_stock') {
                $report[] = $item;
                continue;
            }
            $sourceVariation = (string) ($item['source_variation'] ?? '');
            $receipt = null;
            foreach ($products as $candidate) {
                if (isset($candidate->targetIds[$sourceVariation])) {
                    $receipt = $candidate;
                    break;
                }
            }
            if (!$receipt instanceof TransferReceipt) {
                $report[] = [...$item, 'target_verified' => null];
                continue;
            }

            $productId = $receipt->targetIds['primary'];
            $variationId = $receipt->targetIds[$sourceVariation];
            $snapshot = $snapshots[$productId] ??= $this->gateway->snapshot($productId);
            $row = null;
            foreach ((array) ($snapshot['variations'] ?? []) as $variation) {
                if (is_array($variation) && (int) ($variation['id'] ?? 0) === $variationId) {
                    $row = $variation;
                    break;
                }
            }
            $marker = is_array($row['other_info']['stock_migration_exception'] ?? null)
                ? $row['other_info']['stock_migration_exception']
                : [];
            $sourceStock = is_array($marker['source_stock'] ?? null) ? $marker['source_stock'] : [];
            $verified = is_array($row)
                && ($marker['version'] ?? null) === 1
                && ($marker['type'] ?? null) === 'shared_parent_stock'
                && ($marker['source_variation'] ?? null) === $sourceVariation
                && is_array($item['source_stock'] ?? null)
                && $sourceStock === $item['source_stock']
                && ($marker['requires_manual_resolution'] ?? null) === true
                && $this->lockedProjection($row)
                && ($marker['target_projection'] ?? null) === $this->projection();

            $report[] = [...$item, 'target_verified' => $verified];
        }

        return $report;
    }

    /** @param array<string,mixed> $row */
    private function lockedProjection(array $row): bool
    {
        foreach ($this->projection() as $field => $expected) {
            if (($row[$field] ?? null) !== $expected) {
                return false;
            }
        }

        return true;
    }

    /** @return array{manage_stock:int,total_stock:int,available:int,committed:int,on_hold:int,stock_status:string,backorders:int,item_status:string} */
    private function projection(): array
    {
        return [
            'manage_stock' => 1,
            'total_stock' => 0,
            'available' => 0,
            'committed' => 0,
            'on_hold' => 0,
            'stock_status' => 'out-of-stock',
            'backorders' => 0,
            'item_status' => 'inactive',
        ];
    }
}

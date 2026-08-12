<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\Audit;

defined('ABSPATH') || exit;

final class HposStorageIntegrityReader implements WooStorageIntegrityReader
{
    private readonly ?\Closure $query;

    public function __construct(?callable $query = null)
    {
        $this->query = $query === null ? null : \Closure::fromCallable($query);
    }

    public function inspect(string $sourceKey): array
    {
        return $this->findings($sourceKey, $this->query === null ? $this->databaseRows() : ($this->query)());
    }

    /** @return list<array<string, scalar|null>> */
    private function databaseRows(): array
    {
        global $wpdb;

        $items = $wpdb->prefix . 'woocommerce_order_items';
        $orders = $wpdb->prefix . 'wc_orders';
        $sql = "SELECT oi.order_item_id AS item_id, oi.order_id AS parent_id, oi.order_item_type AS item_type, o.type AS parent_type
            FROM `{$items}` oi
            LEFT JOIN `{$orders}` o ON o.id = oi.order_id
            WHERE o.id IS NULL OR o.type NOT IN ('shop_order', 'shop_order_refund', 'shop_subscription')
            ORDER BY oi.order_id ASC, oi.order_item_id ASC";

        return (array) $wpdb->get_results($sql, ARRAY_A);
    }

    /**
     * @param list<array<string, scalar|null>> $rows
     * @return list<array{code: string, identity: string, context: array<string, scalar|null>}>
     */
    private function findings(string $sourceKey, array $rows): array
    {
        $findings = [];

        foreach ($rows as $row) {
            $item = (int) ($row['item_id'] ?? 0);
            $parent = (int) ($row['parent_id'] ?? 0);
            $parentType = $row['parent_type'] ?? null;
            $findings[] = [
                'code' => $parentType === null ? 'order_item_parent_missing' : 'order_item_parent_type_mismatch',
                'identity' => sprintf('%s:order:%d:item:%d', $sourceKey, $parent, $item),
                'context' => [
                    'item_type' => isset($row['item_type']) ? (string) $row['item_type'] : null,
                    'parent_type' => $parentType === null ? null : (string) $parentType,
                ],
            ];
        }

        return $findings;
    }
}

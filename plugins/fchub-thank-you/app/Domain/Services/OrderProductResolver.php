<?php

declare(strict_types=1);

namespace FchubThankYou\Domain\Services;

final class OrderProductResolver
{
    /**
     * Returns product IDs (post_id) from an order identified by its transaction UUID.
     * Returns empty list when the transaction or order cannot be found.
     *
     * @return list<int>
     */
    public function fromTransactionHash(string $hash): array
    {
        $transaction = \FluentCart\App\Models\OrderTransaction::query()
            ->where('uuid', $hash)
            ->with('order.order_items')
            ->first();

        if ($transaction === null || $transaction->order === null) {
            return [];
        }

        /** @var list<int> */
        return $transaction->order->order_items
            ->pluck('post_id')
            ->unique()
            ->values()
            ->toArray();
    }
}

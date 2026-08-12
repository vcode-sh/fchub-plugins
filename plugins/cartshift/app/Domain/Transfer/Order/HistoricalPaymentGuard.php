<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\Order;

defined('ABSPATH') || exit;

final class HistoricalPaymentGuard
{
    public function register(): void
    {
        add_filter('fluent_cart/transaction/max_refundable_amount', [$this, 'maxRefundableAmount'], 1, 2);
        add_filter('fluent_cart/order_refund_manually', [$this, 'forceManualRefund'], 1, 2);
        add_filter('fluent_cart/order/view', [$this, 'redactOrderView'], 1, 1);
    }

    public function maxRefundableAmount(int|float|string $amount, mixed $transaction): int|float|string
    {
        return $this->isHistorical($transaction) ? 0 : $amount;
    }

    public function redactReference(string $reference): string
    {
        if ($reference === '') {
            return '';
        }
        return '••••' . substr($reference, -5);
    }

    /**
     * Defence in depth for FluentCart versions that consult the manual-refund
     * decision after creating a local refund row. The refundable-amount filter
     * remains the primary, pre-write boundary.
     *
     * @param array<string, mixed> $manual
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function forceManualRefund(array $manual, array $context): array
    {
        $transaction = $context['transaction'] ?? null;
        if (!$this->isHistorical($transaction)) {
            return $manual;
        }

        return [
            'status' => 'yes',
            'source' => 'cartshift_historical_provenance',
        ];
    }

    /**
     * FluentCart's order detail route already requires `orders/view`. Keep the
     * private provider reference in storage while returning only its tail from
     * that capability-gated admin surface.
     *
     * @param array<string, mixed> $order
     * @return array<string, mixed>
     */
    public function redactOrderView(array $order): array
    {
        $transactions = $order['transactions'] ?? null;
        if (!is_array($transactions)) {
            return $order;
        }

        foreach ($transactions as $index => $transaction) {
            if (!is_array($transaction) || !$this->isHistorical($transaction)) {
                continue;
            }

            $provenance = $transaction['meta']['cartshift_source_payment'] ?? null;
            if (!is_array($provenance) || !array_key_exists('provider_reference', $provenance)) {
                continue;
            }

            $provenance['provider_reference'] = $this->redactReference(
                (string) ($provenance['provider_reference'] ?? ''),
            );
            $transaction['meta']['cartshift_source_payment'] = $provenance;
            $transactions[$index] = $transaction;
        }

        $order['transactions'] = $transactions;
        return $order;
    }

    private function isHistorical(mixed $transaction): bool
    {
        return $this->field($transaction, 'payment_method') === 'wc_migrated'
            && $this->field($transaction, 'payment_method_type') === 'historical_provenance';
    }

    private function field(mixed $transaction, string $key): string
    {
        if (is_array($transaction)) {
            return (string) ($transaction[$key] ?? '');
        }
        if (is_object($transaction)) {
            return (string) ($transaction->{$key} ?? '');
        }
        return '';
    }
}

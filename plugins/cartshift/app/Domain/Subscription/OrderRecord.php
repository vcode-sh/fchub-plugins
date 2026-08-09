<?php

declare(strict_types=1);

namespace CartShift\Domain\Subscription;

defined('ABSPATH') || exit;

/**
 * A complete source order: items, transactions, totals and dates.
 *
 * This class is the plan's fourth P0 written down. The original package carried
 * subscription lines with numeric `parentOrderId` and `relatedOrders` values and
 * nothing behind them, which cannot execute the import phase at all — FluentCart
 * recomputes `bill_count` from succeeded positive charge transactions linked by
 * `subscription_id`, so an order that arrived as a bare integer contributes
 * nothing and the count resets the first time FluentCart looks at it.
 *
 * `isPaid()` reads the source's own paid date rather than guessing from status,
 * because the status vocabularies of WooCommerce, WCS and FluentCart agree less
 * than they appear to.
 */
final readonly class OrderRecord
{
    public const string KIND = 'order';

    /**
     * @param array<string, mixed>       $addresses
     * @param list<array<string, mixed>> $items
     * @param list<array<string, mixed>> $transactions
     * @param array<string, int>         $totals
     * @param array<string, string|null> $dates
     */
    public function __construct(
        public string $sourceKey,
        public string $sourceRef,
        public int $sourceOrderId,
        public string $status,
        public string $currency,
        public string $sourceCustomerRef,
        public string $billingEmail,
        public array $addresses,
        public array $items,
        public array $transactions,
        public array $totals,
        public array $dates,
        public string $fingerprint,
    ) {}

    public function kind(): string
    {
        return self::KIND;
    }

    public function isPaid(): bool
    {
        return ($this->dates['paid_utc'] ?? null) !== null;
    }

    public function total(): int
    {
        return (int) ($this->totals['total'] ?? 0);
    }

    /**
     * A billing period the subscriber received and nobody was charged for.
     *
     * Two counting systems, both right about their own question. WCS's
     * `get_payment_count()` counts PAID orders, so a parent order that settled
     * for 0.00 counts. FluentCart's `calculateBillCount()` counts succeeded
     * charge transactions with `total > 0`, so it cannot see that order at all —
     * there is no charge behind it to see. The difference is not an error in
     * either; it is a translation, and `billed_cycles_offset` is the word
     * section 10 already provides for it.
     *
     * Three facts, all read from the source and none inferred: the order
     * exists, it is paid, and it took nothing. UNPAID IS NOT THIS. A zero-total
     * order that never settled consumed no cycle, and an offset there would
     * invent one — which is the forgery the whole corrections mechanism exists
     * to avoid.
     *
     * On the Lapka source this describes 230 of the 564 subscriptions' parent
     * orders, and those 230 are exactly the 230 `history_count_mismatch`
     * failures — a 1:1 correlation, verified across the whole population.
     */
    public function isConsumedFreeCycle(): bool
    {
        return $this->isPaid() && $this->total() <= 0;
    }

    /**
     * Succeeded, positive charges — the only transactions FluentCart counts
     * towards a subscription's paid cycles.
     */
    public function succeededChargeCount(): int
    {
        $count = 0;

        foreach ($this->transactions as $transaction) {
            if (
                ($transaction['type'] ?? '') === 'charge'
                && ($transaction['status'] ?? '') === 'succeeded'
                && (int) ($transaction['total'] ?? 0) > 0
            ) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Every (product, pseudo-variation) pair this order's lines claim.
     *
     * @return list<array{source_product_id: int, pseudo_variation_key: string}>
     */
    public function productClaims(): array
    {
        $claims = [];

        foreach ($this->items as $item) {
            $claims[] = [
                'source_product_id'    => (int) ($item['source_product_id'] ?? 0),
                'pseudo_variation_key' => (string) ($item['pseudo_variation_key'] ?? ''),
            ];
        }

        return $claims;
    }

    public function withFingerprint(string $fingerprint): self
    {
        return new self(
            $this->sourceKey,
            $this->sourceRef,
            $this->sourceOrderId,
            $this->status,
            $this->currency,
            $this->sourceCustomerRef,
            $this->billingEmail,
            $this->addresses,
            $this->items,
            $this->transactions,
            $this->totals,
            $this->dates,
            $fingerprint,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function fingerprintPayload(): array
    {
        return [
            'addresses'           => $this->addresses,
            'billing_email'       => $this->billingEmail,
            'currency'            => $this->currency,
            'dates'               => $this->dates,
            'items'               => $this->items,
            'kind'                => self::KIND,
            'source_customer_ref' => $this->sourceCustomerRef,
            'source_key'          => $this->sourceKey,
            'source_order_id'     => $this->sourceOrderId,
            'source_ref'          => $this->sourceRef,
            'status'              => $this->status,
            'totals'              => $this->totals,
            'transactions'        => $this->transactions,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->fingerprintPayload() + ['fingerprint' => $this->fingerprint];
    }
}

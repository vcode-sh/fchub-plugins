<?php

declare(strict_types=1);

/**
 * The WooCommerce Subscriptions source, as much of it as the release command
 * touches — and not one method more.
 *
 * WooCommerce Subscriptions is a paid add-on and it is NOT on this machine.
 * Every method below is one the plan's context pack names verbatim, or one
 * WooCommerce core declares and this repository's WooCommerce 11.0.0 checkout
 * can be read for: `WC_Order::is_paid()`, `needs_payment()`,
 * `get_payment_method()`, `get_date_paid()`, `get_transaction_id()`,
 * `get_total()`, `get_status()`. Nothing here invents a WCS API.
 *
 * The subscription double is deliberately MUTABLE, which the read-only Lapka
 * fixture is not. The release sequence is set-save-reread-rescan, and a double
 * that could not change between the save and the re-read could not express the
 * one failure the sequence exists to catch: a queued source action creating a
 * renewal order in the window.
 */

if (!class_exists('CartShiftSourceOrderDouble')) {
    /**
     * One related order, answering exactly what the guard asks it.
     */
    final class CartShiftSourceOrderDouble
    {
        public function __construct(
            private readonly int $id,
            private readonly string $status = 'completed',
            private readonly string $total = '29.00',
            private readonly bool $paid = true,
            private readonly bool $needsPayment = false,
            private readonly string $paymentMethod = 'stripe',
            private readonly ?string $datePaid = '2024-05-11 09:15:00',
            private readonly string $transactionId = '',
        ) {
        }

        public function get_id(): int
        {
            return $this->id;
        }

        public function get_status(): string
        {
            return $this->status;
        }

        public function get_total(): string
        {
            return $this->total;
        }

        public function is_paid(): bool
        {
            return $this->paid;
        }

        public function needs_payment(): bool
        {
            return $this->needsPayment;
        }

        public function get_payment_method(): string
        {
            return $this->paymentMethod;
        }

        public function get_date_paid(): ?string
        {
            return $this->datePaid;
        }

        public function get_transaction_id(): string
        {
            return $this->transactionId;
        }

        /** An unpaid renewal WCS has not cancelled, carrying a gateway. */
        public static function openWithGateway(int $id, string $gateway = 'stripe'): self
        {
            return new self($id, 'failed', '29.00', false, true, $gateway, null, '');
        }

        /** The order `WC_Subscriptions_Manager::process_renewal()` leaves behind. */
        public static function openWithoutGateway(int $id): self
        {
            return new self($id, 'pending', '29.00', false, true, '', null, '');
        }

        public static function paid(int $id, string $gateway = 'stripe'): self
        {
            return new self($id, 'completed', '29.00', true, false, $gateway, '2024-05-11 09:15:00', 'txn-' . $id);
        }

        public static function cancelled(int $id): self
        {
            return new self($id, 'cancelled', '29.00', false, false, 'stripe', null, '');
        }

        public static function zeroTotal(int $id): self
        {
            return new self($id, 'pending', '0.00', false, true, 'stripe', null, '');
        }
    }
}

if (!class_exists('CartShiftSourceSubscriptionDouble')) {
    /**
     * A `WC_Subscription`, for the four calls the release sequence makes.
     */
    final class CartShiftSourceSubscriptionDouble
    {
        /** @var list<string> Every mutating call, in order. */
        public array $calls = [];

        /** @var array<string, list<object>> */
        private array $related;

        /** @var (\Closure(self): void)|null Runs inside save(), before the re-read. */
        private $onSave;

        /**
         * @param array<string, list<object>> $related    Keyed by relationship type.
         * @param callable(self): void|null   $onSave     What the source does while saving.
         */
        public function __construct(
            private readonly int $id = 910_001,
            private bool $manual = false,
            private string $paymentRetry = '',
            array $related = [],
            ?callable $onSave = null,
            private readonly bool $saveSucceeds = true,
            private readonly ?bool $effectiveManual = null,
        ) {
            $this->related = $related + ['parent' => [], 'renewal' => [], 'switch' => [], 'resubscribe' => []];
            $this->onSave  = $onSave === null ? null : $onSave(...);
        }

        public function get_id(): int
        {
            return $this->id;
        }

        public function get_status(): string
        {
            return 'active';
        }

        public function is_manual(): bool
        {
            return $this->effectiveManual ?? $this->manual;
        }

        public function get_requires_manual_renewal(): bool
        {
            return $this->manual;
        }

        public function set_requires_manual_renewal(bool $manual): void
        {
            $this->calls[] = 'set_requires_manual_renewal:' . ($manual ? 'true' : 'false');

            // Deliberately staged rather than applied here: WCS writes the flag
            // to the object and persists it on save(), and a double that
            // persisted on the setter could never express a save that failed.
            $this->pending = $manual;
        }

        public function save(): int
        {
            $this->calls[] = 'save';

            if ($this->saveSucceeds && $this->pending !== null) {
                $this->manual  = $this->pending;
                $this->pending = null;
            }

            if ($this->onSave !== null) {
                ($this->onSave)($this);
            }

            return $this->id;
        }

        /**
         * `0` for an unset date, as WooCommerce Subscriptions does — see the
         * note on `CartShiftLapkaSubscription::get_date()`. `SourceRenewalGuard`
         * already treats `'0'` as "no retry scheduled"; this makes the double
         * prove it rather than the reader having to trust it.
         */
        public function get_date(string $type): string|int
        {
            $value = $type === 'payment_retry' ? trim($this->paymentRetry) : '';

            return $value === '' ? 0 : $value;
        }

        /**
         * @return list<object>
         */
        public function get_related_orders(string $returnFields = 'ids', string $relationshipType = 'any'): array
        {
            $this->calls[] = sprintf('get_related_orders:%s:%s', $returnFields, $relationshipType);

            $orders = $relationshipType === 'any'
                ? array_merge(...array_values($this->related))
                : ($this->related[$relationshipType] ?? []);

            if ($returnFields === 'ids') {
                return array_values(array_map(
                    static fn (object $order): int => $order->get_id(),
                    $orders,
                ));
            }

            return array_values($orders);
        }

        /** What the source does between the save and the re-read. */
        public function addRenewalOrder(object $order): void
        {
            $this->related['renewal'][] = $order;
        }

        /**
         * Run this once, inside the next `save()`.
         *
         * The restoration's ordering test needs to observe the world at the
         * instant the flag goes back, which is the only moment that
         * distinguishes intent-before-act from act-before-record.
         */
        public function onNextSave(callable $callback): void
        {
            $this->onSave = $callback(...);
        }

        /** The operator settling that invoice before the next run. */
        public function removeRenewalOrder(int $orderId): void
        {
            $this->related['renewal'] = array_values(array_filter(
                $this->related['renewal'],
                static fn (object $order): bool => $order->get_id() !== $orderId,
            ));
        }

        public function scheduleRetry(string $date): void
        {
            $this->paymentRetry = $date;
        }

        private ?bool $pending = null;
    }
}

if (!class_exists('CartShiftWcsRenewalMechanics')) {
    /**
     * WooCommerce Subscriptions 8.7.1's three renewal guards, as the plan's
     * context pack documents them — and as a place to prove why one of them is
     * not enough.
     *
     * This is CHARACTERISATION. It asserts nothing about CartShift; it records
     * what the plan says the source does, so the release command's own tests
     * can point at it and say "which is why we also scan the orders".
     */
    final class CartShiftWcsRenewalMechanics
    {
        /**
         * `WC_Subscriptions_Manager::process_renewal()` creates a renewal order
         * and does NOT set a gateway on it.
         */
        public static function processRenewal(int $orderId): CartShiftSourceOrderDouble
        {
            return CartShiftSourceOrderDouble::openWithoutGateway($orderId);
        }

        /**
         * `WC_Subscriptions_Payment_Gateways::gateway_scheduled_subscription_payment()`
         * checks `!$subscription->is_manual()` before reaching the helper.
         */
        public static function scheduledPaymentWrapperWouldCharge(
            CartShiftSourceSubscriptionDouble $subscription,
            ?CartShiftSourceOrderDouble $order,
        ): bool {
            if ($subscription->is_manual()) {
                return false;
            }

            return self::triggerGatewayRenewalPaymentHook($order);
        }

        /**
         * `trigger_gateway_renewal_payment_hook()` checks order presence, a
         * positive total and a non-empty payment method. It does NOT check
         * `is_manual()`, which is why retry, admin and early-renewal paths can
         * reach a gateway hook on a manual subscription.
         */
        public static function triggerGatewayRenewalPaymentHook(?CartShiftSourceOrderDouble $order): bool
        {
            if ($order === null) {
                return false;
            }

            return (float) $order->get_total() > 0 && $order->get_payment_method() !== '';
        }
    }
}

<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\Order;

defined('ABSPATH') || exit;

final readonly class PaymentProjection
{
    /** @param array<string, scalar|null> $provenance */
    public function __construct(
        public string $paymentMethod,
        public string $paymentMethodType,
        public string $paymentMode,
        public string $transactionType,
        public string $status,
        public string $vendorChargeId,
        public int $amount,
        public string $currency,
        public string $createdUtc,
        public array $provenance,
    ) {
        if ($paymentMethod !== 'wc_migrated' || $paymentMethodType !== 'historical_provenance'
            || $vendorChargeId !== '' || !in_array($paymentMode, ['live', 'test'], true)) {
            throw new \InvalidArgumentException('Historical payment projection is not inert.');
        }
    }

    public function toTargetTransaction(): array
    {
        return [
            'payment_method' => $this->paymentMethod, 'payment_method_type' => $this->paymentMethodType,
            'payment_mode' => $this->paymentMode, 'transaction_type' => $this->transactionType,
            'status' => $this->status, 'vendor_charge_id' => '', 'total' => $this->amount,
            'currency' => $this->currency, 'created_at' => $this->createdUtc,
            'meta' => ['cartshift_source_payment' => $this->provenance],
        ];
    }
}

<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\Order;

use CartShift\Domain\Transfer\SourceIdentity;

defined('ABSPATH') || exit;

final readonly class PaymentEventRecord
{
    /** @param array<string, scalar|null> $provenance */
    public function __construct(
        public SourceIdentity $identity,
        public string $type,
        public int $amount,
        public string $currency,
        public string $status,
        public PaymentEvidenceKind $evidenceKind,
        public string $paymentMethod,
        public string $paymentMethodTitle,
        public ?string $providerReference,
        public ?SourceIdentity $parentEvent,
        public ?string $occurredUtc,
        public array $provenance,
    ) {
        if (!in_array($type, ['charge', 'refund'], true) || $amount < 0 || $currency === '') {
            throw new \InvalidArgumentException('Payment event values are invalid.');
        }
    }

    public function toArray(): array
    {
        return ['identity' => $this->identity->canonical(), 'type' => $this->type, 'amount' => $this->amount,
            'currency' => $this->currency, 'status' => $this->status, 'evidence_kind' => $this->evidenceKind->value,
            'payment_method' => $this->paymentMethod, 'payment_method_title' => $this->paymentMethodTitle,
            'provider_reference' => $this->providerReference, 'parent_event' => $this->parentEvent?->canonical(),
            'occurred_utc' => $this->occurredUtc, 'provenance' => $this->provenance];
    }
}

<?php

declare(strict_types=1);

namespace CartShift\Domain\Subscription;

defined('ABSPATH') || exit;

/**
 * A source subscription that is structurally capable of being migrated.
 *
 * Read the constructor types as the specification they are. `int $parentOrderId`
 * is not `?int`; `array $items` is never allowed to arrive empty; the customer
 * reference is a non-empty string. A source row that cannot satisfy those does
 * not become a SubscriptionRecord with holes in it — it becomes an
 * `InvalidSourceRecord`, is counted, and blocks. That is the difference between
 * this design and the one the plan lists as a P0, where an unresolved reference
 * was demoted to "paused" and then written to a NOT NULL column anyway.
 *
 * `sourceCustomerId` stays nullable because a guest genuinely has no user ID.
 * It is evidence, not an address: only `sourceCustomerRef` identifies anybody
 * across a site boundary.
 *
 * `paymentReferences` is carried because the migration needs it and is kept out
 * of the canonical field set because a token is not a business fact. A digest of
 * it is fingerprinted instead, so a rotated token still reads as a source
 * change without the token itself joining the hashed material.
 */
final readonly class SubscriptionRecord
{
    public const string KIND = 'subscription';

    /**
     * @param array<string, mixed>             $billingIdentity
     * @param list<array<string, mixed>>       $items
     * @param array<string, string>            $paymentReferences
     * @param list<SubscriptionOrderReference> $relatedOrders
     */
    public function __construct(
        public string $sourceKey,
        public string $sourceRef,
        public int $sourceSubscriptionId,
        public string $status,
        public string $currency,
        public string $sourceCustomerRef,
        public int|null $sourceCustomerId,
        public string $billingEmail,
        public array $billingIdentity,
        public int $parentOrderId,
        public array $items,
        public SubscriptionContract $contract,
        public string $gateway,
        public bool $requiresManualRenewal,
        public array $paymentReferences,
        public SubscriptionDates $dates,
        public array $relatedOrders,
        public int $sourcePaymentCount,
        public string $fingerprint,
    ) {}

    public function kind(): string
    {
        return self::KIND;
    }

    /**
     * @return list<int>
     */
    public function relatedOrderIds(string $relationship): array
    {
        $ids = [];

        foreach ($this->relatedOrders as $reference) {
            if ($reference->relationship === $relationship) {
                $ids[] = $reference->sourceOrderId;
            }
        }

        return $ids;
    }

    public function withFingerprint(string $fingerprint): self
    {
        return new self(
            $this->sourceKey,
            $this->sourceRef,
            $this->sourceSubscriptionId,
            $this->status,
            $this->currency,
            $this->sourceCustomerRef,
            $this->sourceCustomerId,
            $this->billingEmail,
            $this->billingIdentity,
            $this->parentOrderId,
            $this->items,
            $this->contract,
            $this->gateway,
            $this->requiresManualRenewal,
            $this->paymentReferences,
            $this->dates,
            $this->relatedOrders,
            $this->sourcePaymentCount,
            $fingerprint,
        );
    }

    /**
     * The canonical, non-secret field set. See the class docblock for why the
     * payment references appear here only as a digest.
     *
     * @return array<string, mixed>
     */
    public function fingerprintPayload(): array
    {
        return [
            'billing_email'             => $this->billingEmail,
            'billing_identity'          => $this->billingIdentity,
            'contract'                  => $this->contract->toArray(),
            'currency'                  => $this->currency,
            'dates'                     => $this->dates->toArray(),
            'gateway'                   => $this->gateway,
            'items'                     => $this->items,
            'kind'                      => self::KIND,
            'parent_order_id'           => $this->parentOrderId,
            'payment_reference_digest'  => SubscriptionRecordFactory::digest($this->paymentReferences),
            'related_orders'            => array_map(
                static fn (SubscriptionOrderReference $reference): array => $reference->toArray(),
                $this->relatedOrders,
            ),
            'requires_manual_renewal'   => $this->requiresManualRenewal,
            'source_customer_id'        => $this->sourceCustomerId,
            'source_customer_ref'       => $this->sourceCustomerRef,
            'source_key'                => $this->sourceKey,
            'source_payment_count'      => $this->sourcePaymentCount,
            'source_ref'                => $this->sourceRef,
            'source_subscription_id'    => $this->sourceSubscriptionId,
            'status'                    => $this->status,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $payload = $this->fingerprintPayload();

        unset($payload['payment_reference_digest']);

        return $payload + [
            'fingerprint'        => $this->fingerprint,
            'payment_references' => $this->paymentReferences,
        ];
    }
}

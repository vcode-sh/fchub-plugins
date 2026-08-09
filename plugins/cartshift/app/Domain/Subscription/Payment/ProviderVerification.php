<?php

declare(strict_types=1);

namespace CartShift\Domain\Subscription\Payment;

defined('ABSPATH') || exit;

/**
 * What a provider confirmed, in three separate fields, because they are three
 * separate things.
 *
 * A customer/payer reference, a reusable payment mandate, and a remote
 * recurring schedule are not interchangeable, however similar their prefixes
 * look. `SubscriptionMapper.php:223-228` assigned a PayPal subscription ID as
 * the customer ID, which is why they are modelled apart here and why nothing
 * copies one into another's slot.
 *
 * Every field is null until proven. A null is "not verified", never "verified
 * absent" — the difference is the whole reason an empty PayPal reference set
 * must reach `provider_method_missing` rather than being read as "no vault
 * exists, therefore manual is proven safe".
 */
final readonly class ProviderVerification
{
    /** @var list<string> Sorted and de-duplicated. */
    public array $reasonCodes;

    /**
     * @param array<string, mixed> $methodMetadata Non-secret display data: brand, last four, expiry.
     * @param list<string>         $reasonCodes
     * @param string|null          $sourceMetadataAdapter The exact source contract the references were
     *                                                    read under, or null when none could be identified.
     */
    public function __construct(
        public ?string $customerId,
        public ?string $methodId,
        public ?string $subscriptionId,
        public array $methodMetadata,
        array $reasonCodes,
        public ?string $sourceMetadataAdapter = null,
    ) {
        $codes = array_values(array_unique($reasonCodes));
        sort($codes);

        $this->reasonCodes = $codes;
    }

    /**
     * Nothing was proven, and here is why.
     *
     * @param list<string> $reasonCodes
     */
    public static function nothing(array $reasonCodes, ?string $sourceMetadataAdapter = null): self
    {
        return new self(null, null, null, [], $reasonCodes, $sourceMetadataAdapter);
    }

    public function hasCustomer(): bool
    {
        return ($this->customerId ?? '') !== '';
    }

    public function hasMethod(): bool
    {
        return ($this->methodId ?? '') !== '';
    }

    public function hasSchedule(): bool
    {
        return ($this->subscriptionId ?? '') !== '';
    }

    public function isClean(): bool
    {
        return $this->reasonCodes === [];
    }

    /**
     * Something usable was proven and nothing contradicted it.
     */
    public function verified(): bool
    {
        return $this->isClean() && ($this->hasMethod() || $this->hasSchedule());
    }

    /**
     * The subscription meta FluentCart's charge path reads at fire time.
     *
     * @see fluent-cart/app/Modules/PaymentMethods/StripeGateway/Stripe.php:215-216
     * @see fluent-cart/app/Modules/PaymentMethods/PayPalGateway/Processor.php:817-818
     *
     * @return array<string, mixed>
     */
    public function activePaymentMethod(): array
    {
        if (!$this->hasMethod()) {
            return [];
        }

        $active = [PaymentMigrationDecision::ACTIVE_METHOD_ID => $this->methodId];

        if ($this->methodMetadata !== []) {
            $active['details'] = $this->methodMetadata;
        }

        return $active;
    }
}

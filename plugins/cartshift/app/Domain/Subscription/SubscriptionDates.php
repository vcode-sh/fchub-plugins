<?php

declare(strict_types=1);

namespace CartShift\Domain\Subscription;

defined('ABSPATH') || exit;

/**
 * The five source dates, in UTC, as explicit strings — or null.
 *
 * Strings rather than DateTimeImmutable because these values are fingerprinted
 * and written to a package file. A date object's canonical form depends on the
 * ambient timezone and on how whoever formats it feels that day; `Y-m-d H:i:s`
 * in UTC does not.
 *
 * Null means the source has no date. 360 of the 564 Lapka subscriptions have no
 * next-payment date, and the whole point of keeping the null is that nothing
 * downstream can quietly replace it with something plausible.
 */
final readonly class SubscriptionDates
{
    public function __construct(
        public string|null $startUtc,
        public string|null $trialEndUtc,
        public string|null $nextPaymentUtc,
        public string|null $cancelledUtc,
        public string|null $endUtc,
    ) {}

    /**
     * @return array<string, string|null>
     */
    public function toArray(): array
    {
        return [
            'cancelled_utc'    => $this->cancelledUtc,
            'end_utc'          => $this->endUtc,
            'next_payment_utc' => $this->nextPaymentUtc,
            'start_utc'        => $this->startUtc,
            'trial_end_utc'    => $this->trialEndUtc,
        ];
    }
}

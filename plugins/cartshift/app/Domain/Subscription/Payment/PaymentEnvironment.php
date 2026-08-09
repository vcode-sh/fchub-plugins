<?php

declare(strict_types=1);

namespace CartShift\Domain\Subscription\Payment;

defined('ABSPATH') || exit;

/**
 * Everything a strategy needs that is not in the subscription itself.
 *
 * Two of these fields carry most of the weight.
 *
 * `settingsFingerprint` is the SHA-256 Task 0's runtime report takes over the
 * reviewed store-wide subscription settings and the target census.
 * `approvedSettingsFingerprint` is what an operator supplied to
 * `--approve-system-settings=<sha256>` after reading that report. A `system`
 * decision is only available when the two match exactly, because approval is
 * bound to the configuration it was given for — a store whose settings moved
 * since the review has not been approved, it has merely been approved once, for
 * something else. CartShift never changes either FluentCart setting; the
 * operator applies the change outside CartShift and re-runs the audit.
 *
 * `verifiers` is keyed by strategy name rather than being two named properties,
 * so a fourth gateway needs a strategy class and a registry entry and nothing
 * here. The absence of a verifier is a legitimate state — a target with no
 * provider credentials configured — and produces deliberate manual rather than
 * an unverified mandate.
 */
final readonly class PaymentEnvironment
{
    /** The one WCS status that means "this will bill again on its own". */
    public const string STATUS_RENEWING = 'active';

    /** UTC `Y-m-d H:i:s`, matching `SubscriptionDates`. */
    public string $nowUtc;

    /**
     * @param array<string, ProviderReferenceVerifier> $verifiers            Keyed by strategy name.
     * @param list<string>                             $verifiedWebhookOwners Strategies whose target webhook
     *                                                                        routing resolves the vendor ID.
     */
    public function __construct(
        public PaymentCapabilityProbe $capabilities,
        public string $settingsFingerprint,
        public ?string $approvedSettingsFingerprint = null,
        public array $verifiers = [],
        public array $verifiedWebhookOwners = [],
        public bool $manualFallbackConfirmed = false,
        public bool $legacySourceChargePathProven = false,
        ?string $nowUtc = null,
    ) {
        $this->nowUtc = $nowUtc ?? gmdate('Y-m-d H:i:s');
    }

    public function verifierFor(string $strategy): ?ProviderReferenceVerifier
    {
        return $this->verifiers[$strategy] ?? null;
    }

    /**
     * Whether the operator approved *these* settings, not merely some settings.
     *
     * `hash_equals` because this is an approval token and a timing-safe
     * comparison costs nothing. The empty-string guards matter more: an unset
     * fingerprint on both sides would otherwise compare equal and approve
     * every store by construction.
     */
    public function systemSettingsApproved(): bool
    {
        if ($this->settingsFingerprint === '' || ($this->approvedSettingsFingerprint ?? '') === '') {
            return false;
        }

        return hash_equals($this->settingsFingerprint, (string) $this->approvedSettingsFingerprint);
    }

    public function webhookOwnershipVerified(string $strategy): bool
    {
        return in_array($strategy, $this->verifiedWebhookOwners, true);
    }

    /**
     * Whether a source date is in the future relative to this assessment.
     *
     * Null in, null out. 360 of the 564 Lapka subscriptions have no
     * next-payment date at all, and the whole point of carrying the null
     * through `SubscriptionDates` is that nothing downstream replaces it with
     * something plausible.
     */
    public function isFutureUtc(?string $utc): ?bool
    {
        if ($utc === null || $utc === '') {
            return null;
        }

        return $utc > $this->nowUtc;
    }

    /**
     * Whether this record's schedule disqualifies it from a live mandate.
     *
     * Plan sections 8.2 and 8.3 both require a future, reconciled next billing
     * date before a subscription may be handed to `system` or to a remote
     * schedule, and section 9.3 blocks such a record outright. The codes are
     * section 9.4's existing lifecycle codes, not new ones.
     *
     * The gate applies only to records that are actually renewing. An on-hold
     * subscription legitimately has no next date — 125 Lapka records are on
     * hold and 360 have no next-payment date — and inventing one, or blocking
     * over its absence, would both be wrong.
     *
     * @return list<string>|null Null when there is nothing to object to.
     */
    public function liveScheduleFault(string $status, ?string $nextPaymentUtc): ?array
    {
        if ($status !== self::STATUS_RENEWING) {
            return null;
        }

        return match ($this->isFutureUtc($nextPaymentUtc)) {
            null    => ['active_next_date_missing'],
            false   => ['active_next_date_past'],
            default => null,
        };
    }
}

<?php

declare(strict_types=1);

namespace CartShift\Domain\Subscription\Payment;

use CartShift\Domain\Subscription\SubscriptionRecord;

defined('ABSPATH') || exit;

/**
 * Read-only PayPal ownership checks. Retrieval only, exactly as for Stripe.
 *
 * The same single-argument closure seam: a resource path in, a decoded object
 * or null out. No method parameter, no body, so no vault setup, no order
 * creation, no capture, and no subscription can originate here.
 *
 * Two entirely different things may be proven, and they are kept apart because
 * they lead to different destinations:
 *
 * A **vault ID** is a reusable mandate the target merchant can charge
 * off-session. `PayPal::chargeRenewal()` delegates to
 * `Processor::chargeVaultedRenewal()` (PayPal.php:266), which reads that ID
 * from `active_payment_method.vendor_method_id` at fire time and creates an
 * Orders v2 charge against `payment_source.paypal.vault_id`
 * (Processor.php:817-838). That is a FluentCart `system` subscription.
 *
 * A **remote subscription ID** is a schedule PayPal is already running. That is
 * a FluentCart `automatic` subscription, and it must never be written into
 * `vendor_customer_id` or into the vault metadata — three identifiers, three
 * fields, however much their prefixes may wish to participate in performance
 * art.
 *
 * Which references exist at all comes from `PayPalSourceMetadataAdapter`, which
 * for the Lapka restore resolves nothing, because the PPCP plugin source is
 * absent and a guessed meta key is worse than an admitted gap.
 */
final class PayPalReferenceVerifier implements ProviderReferenceVerifier
{
    /** The only HTTP verb this class is permitted to cause. */
    public const string HTTP_METHOD = 'GET';

    /** @var list<string> A remote schedule in one of these states would keep charging. */
    private const array LIVE_REMOTE_STATUSES = ['ACTIVE', 'APPROVED', 'SUSPENDED'];

    /**
     * @param \Closure(string): (array<string, mixed>|null) $retrieve Resource path in, object or null out.
     */
    public function __construct(
        private readonly \Closure $retrieve,
        private readonly PayPalSourceMetadataAdapter $metadata,
        private readonly string $expectedMerchantId = '',
        private readonly string $expectedMode = 'live',
    ) {
    }

    public function verify(SubscriptionRecord $record, PaymentEnvironment $environment): ProviderVerification
    {
        $references = $this->metadata->extract($record);

        if ($references['resolved'] !== true) {
            // The extraction never happened. That is not evidence that no vault
            // exists; it is evidence that nobody looked, and the two must not
            // be confused into "manual is proven safe". The null adapter
            // travels with the reason so a receipt can say which it was.
            return ProviderVerification::nothing($references['reason_codes'], $references['adapter']);
        }

        $reasons = $references['reason_codes'];

        $verifiedVault  = null;
        $verifiedPayer  = $references['payer_id'];
        $verifiedRemote = null;
        $methodMetadata = [];

        if ($references['vault_id'] !== null) {
            $verifiedVault = $this->verifyVault(
                $references['vault_id'],
                $reasons,
                $verifiedPayer,
                $methodMetadata,
            );
        }

        if ($references['subscription_id'] !== null) {
            $verifiedRemote = $this->verifyRemoteSchedule($references['subscription_id'], $reasons);
        }

        // A payer ID nobody could corroborate is a source note, not a verified
        // provider customer. Better null than an identifier the writer would
        // treat as proven.
        if ($verifiedVault === null && $verifiedRemote === null) {
            $verifiedPayer = null;
        }

        return new ProviderVerification(
            $verifiedPayer,
            $verifiedVault,
            $verifiedRemote,
            $methodMetadata,
            $reasons,
            $references['adapter'],
        );
    }

    /**
     * @param list<string>         $reasons
     * @param array<string, mixed> $methodMetadata
     */
    private function verifyVault(
        string $vaultId,
        array &$reasons,
        ?string &$payerId,
        array &$methodMetadata,
    ): ?string {
        $token = $this->get('v3/vault/payment-tokens/' . $vaultId);

        if ($token === null) {
            $reasons[] = 'provider_method_missing';

            return null;
        }

        if (!$this->guardMerchantAndMode($token, $reasons)) {
            return null;
        }

        // A vault token that is not active cannot be charged off-session, and
        // discovering that at the first renewal is discovering it in front of a
        // customer.
        if (strtoupper((string) ($token['status'] ?? '')) !== 'ACTIVE') {
            $reasons[] = 'provider_method_unsupported';

            return null;
        }

        $customer = (array) ($token['customer'] ?? []);
        $declared = trim((string) ($customer['id'] ?? ''));

        if ($declared !== '') {
            $payerId = $declared;
        }

        // FluentCart's display shape only, and nothing beyond it: a PayPal
        // vault has no card to describe and an email address is customer data.
        $methodMetadata = ['brand' => 'paypal', 'type' => 'paypal'];

        return $vaultId;
    }

    /**
     * @param list<string> $reasons
     */
    private function verifyRemoteSchedule(string $remoteId, array &$reasons): ?string
    {
        $remote = $this->get('v1/billing/subscriptions/' . $remoteId);

        if ($remote === null) {
            $reasons[] = 'provider_subscription_missing';

            return null;
        }

        if (!$this->guardMerchantAndMode($remote, $reasons)) {
            return null;
        }

        if (!in_array(strtoupper((string) ($remote['status'] ?? '')), self::LIVE_REMOTE_STATUSES, true)) {
            // Not running. It cannot be the authoritative owner of the next
            // charge, whatever the source row remembers.
            $reasons[] = 'provider_schedule_mismatch';

            return null;
        }

        return $remoteId;
    }

    /**
     * @param array<string, mixed> $object
     * @param list<string>         $reasons
     */
    private function guardMerchantAndMode(array $object, array &$reasons): bool
    {
        $sound = true;

        $merchant = trim((string) ($object['merchant_id'] ?? ''));

        if ($merchant !== '' && $this->expectedMerchantId !== '' && $merchant !== $this->expectedMerchantId) {
            $reasons[] = 'provider_account_mismatch';
            $sound = false;
        }

        $mode = strtolower(trim((string) ($object['mode'] ?? '')));

        if ($mode !== '' && $mode !== strtolower($this->expectedMode)) {
            $reasons[] = 'provider_mode_mismatch';
            $sound = false;
        }

        return $sound;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function get(string $resource): ?array
    {
        return ($this->retrieve)($resource);
    }
}

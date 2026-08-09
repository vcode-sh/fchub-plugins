<?php

declare(strict_types=1);

namespace CartShift\Domain\Subscription\Payment;

use CartShift\Domain\Subscription\SubscriptionRecord;

defined('ABSPATH') || exit;

/**
 * Read-only Stripe ownership checks. No charge, no intent, no subscription.
 *
 * The seam is a single-argument closure: given a resource path, hand back the
 * decoded object or null. That is the whole outbound surface. There is no
 * method parameter to set to POST, no body to send, and therefore no shape of
 * call this class can make that creates a PaymentIntent, a SetupIntent, a
 * charge, or a subscription. Assessment must never move money and this design
 * makes it structurally unable to.
 *
 * What it establishes, per plan section 8.2: the customer exists under the
 * target credentials, the payment method exists and belongs to that customer,
 * both are in the expected mode and account, and no remote Stripe subscription
 * is still charging the same contract.
 *
 * The `src_` cohort — 246 of the 367 Lapka Stripe records — is deliberately
 * unsupported until a sandbox proves FluentCart's charge path accepts it.
 * `Stripe::chargeRenewal()` posts the stored value straight into a
 * PaymentIntent's `payment_method` field (Stripe.php:216-238); a legacy source
 * ID passed there is not "probably fine", it is an experiment conducted on
 * somebody's card. Until proven, `provider_method_unsupported`, and the
 * expected resolution is a customer payment-method update.
 */
final class StripeReferenceVerifier implements ProviderReferenceVerifier
{
    /** The only HTTP verb this class is permitted to cause. */
    public const string HTTP_METHOD = 'GET';

    public const string REF_CUSTOMER = 'stripe_customer_id';
    public const string REF_METHOD = 'stripe_source_id';
    public const string REF_SUBSCRIPTION = 'stripe_subscription_id';

    /** Modern payment methods. The only prefix FluentCart's charge path is proven against. */
    private const string MODERN_PREFIX = 'pm_';

    /** @var list<string> Legacy sources and card tokens: recognised, not accepted. */
    private const array LEGACY_PREFIXES = ['src_', 'card_'];

    /** @var list<string> A remote schedule in one of these states would keep charging. */
    private const array LIVE_REMOTE_STATUSES = ['active', 'trialing', 'past_due', 'unpaid'];

    /**
     * @param \Closure(string): (array<string, mixed>|null) $retrieve      Resource path in, object or null out.
     * @param string                                        $expectedAccountId Compared only when the object declares one.
     * @param string                                        $expectedMode  `live` or `test`.
     */
    public function __construct(
        private readonly \Closure $retrieve,
        private readonly string $expectedAccountId = '',
        private readonly string $expectedMode = 'live',
    ) {
    }

    public function verify(SubscriptionRecord $record, PaymentEnvironment $environment): ProviderVerification
    {
        $customerId = trim($record->paymentReferences[self::REF_CUSTOMER] ?? '');
        $token      = trim($record->paymentReferences[self::REF_METHOD] ?? '');
        $remoteId   = trim($record->paymentReferences[self::REF_SUBSCRIPTION] ?? '');

        $reasons  = [];
        $metadata = [];

        if ($customerId === '') {
            $reasons[] = 'provider_customer_missing';
        }

        if ($token === '') {
            $reasons[] = 'provider_method_missing';
        }

        $usableToken = $this->tokenIsUsable($token, $environment);

        if ($token !== '' && !$usableToken) {
            $reasons[] = 'provider_method_unsupported';
        }

        $verifiedCustomer = $customerId === ''
            ? null
            : $this->verifyCustomer($customerId, $reasons);

        $verifiedMethod = $usableToken && $verifiedCustomer !== null
            ? $this->verifyMethod($token, $verifiedCustomer, $reasons, $metadata)
            : null;

        $verifiedRemote = $remoteId === ''
            ? null
            : $this->verifyRemoteSchedule($remoteId, $reasons);

        return new ProviderVerification(
            $verifiedCustomer,
            $verifiedMethod,
            $verifiedRemote,
            $metadata,
            $reasons,
        );
    }

    /**
     * Whether this token is one FluentCart's installed charge path can use.
     */
    private function tokenIsUsable(string $token, PaymentEnvironment $environment): bool
    {
        if (str_starts_with($token, self::MODERN_PREFIX)) {
            return true;
        }

        foreach (self::LEGACY_PREFIXES as $prefix) {
            if (str_starts_with($token, $prefix)) {
                // Only after a sandbox has proven the exact charge path accepts
                // it. The flag is the proof's receipt, not an optimism setting.
                return $environment->legacySourceChargePathProven;
            }
        }

        return false;
    }

    /**
     * @param list<string> $reasons
     */
    private function verifyCustomer(string $customerId, array &$reasons): ?string
    {
        $customer = $this->get('customers/' . $customerId);

        if ($customer === null || ($customer['deleted'] ?? false) === true) {
            $reasons[] = 'provider_customer_missing';

            return null;
        }

        $sound = $this->guardModeAndAccount($customer, $reasons);

        return $sound ? $customerId : null;
    }

    /**
     * @param list<string>         $reasons
     * @param array<string, mixed> $metadata
     */
    private function verifyMethod(string $token, string $customerId, array &$reasons, array &$metadata): ?string
    {
        $method = $this->get($this->methodResource($token, $customerId));

        if ($method === null) {
            $reasons[] = 'provider_method_missing';

            return null;
        }

        // Ownership. A method that exists but hangs off a different customer is
        // somebody else's card, and charging it would be the single worst
        // outcome this whole plan exists to prevent.
        if ((string) ($method['customer'] ?? '') !== $customerId) {
            $reasons[] = 'provider_method_missing';

            return null;
        }

        if (!$this->guardModeAndAccount($method, $reasons)) {
            return null;
        }

        $card = (array) ($method['card'] ?? []);

        // FluentCart's own display shape (Subscription::getPaymentMethodText()
        // reads details.brand and details.last_4), and nothing beyond it. A PAN,
        // a fingerprint, or a full token has no business in a migration receipt.
        $metadata = array_filter([
            'brand'  => (string) ($card['brand'] ?? ''),
            'last_4' => (string) ($card['last4'] ?? ''),
            'type'   => (string) ($method['type'] ?? ''),
        ], static fn (string $value): bool => $value !== '');

        return $token;
    }

    /**
     * A remote Stripe subscription that would keep charging the same contract.
     *
     * None of the 367 Lapka Stripe records has one — Woo Stripe charges WCS
     * renewals locally — but if one turns up, adopting the record as a
     * FluentCart `system` subscription would mean two components billing the
     * same customer.
     *
     * The return contract matches `PayPalReferenceVerifier`'s deliberately:
     * `ProviderVerification::hasSchedule()` means "a remote schedule is
     * actually running", in both. So a cancelled remote returns null — it is
     * not an obstacle to anything and reporting it as a schedule would be a
     * trap for the next caller — while a running one is returned *and*
     * disqualifies system adoption here.
     *
     * @param list<string> $reasons
     */
    private function verifyRemoteSchedule(string $remoteId, array &$reasons): ?string
    {
        $remote = $this->get('subscriptions/' . $remoteId);

        if ($remote === null) {
            $reasons[] = 'provider_subscription_missing';

            return null;
        }

        if (!in_array((string) ($remote['status'] ?? ''), self::LIVE_REMOTE_STATUSES, true)) {
            return null;
        }

        $reasons[] = 'provider_schedule_mismatch';

        return $remoteId;
    }

    /**
     * A modern method is a top-level object; a legacy source hangs off the
     * customer. Both are retrievals.
     */
    private function methodResource(string $token, string $customerId): string
    {
        return str_starts_with($token, self::MODERN_PREFIX)
            ? 'payment_methods/' . $token
            : 'customers/' . $customerId . '/sources/' . $token;
    }

    /**
     * @param array<string, mixed> $object
     * @param list<string>         $reasons
     */
    private function guardModeAndAccount(array $object, array &$reasons): bool
    {
        $sound = true;

        if (array_key_exists('livemode', $object)) {
            $expected = $this->expectedMode === 'live';

            if ((bool) $object['livemode'] !== $expected) {
                $reasons[] = 'provider_mode_mismatch';
                $sound = false;
            }
        }

        // Retrieving under the target's credentials already implies the target
        // account. This only fires when the object explicitly names a different
        // one, which is the Connect case.
        $declared = (string) ($object['account'] ?? '');

        if ($declared !== '' && $this->expectedAccountId !== '' && $declared !== $this->expectedAccountId) {
            $reasons[] = 'provider_account_mismatch';
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

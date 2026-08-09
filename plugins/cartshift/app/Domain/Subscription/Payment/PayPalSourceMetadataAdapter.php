<?php

declare(strict_types=1);

namespace CartShift\Domain\Subscription\Payment;

use CartShift\Domain\Subscription\SubscriptionRecord;

defined('ABSPATH') || exit;

/**
 * Which source PayPal meta key holds which kind of identifier — for one exact
 * plugin version, or for none at all.
 *
 * This class ships with an empty contract table, and that is the finding rather
 * than an omission. The WooCommerce PayPal Payments plugin is absent from the
 * Lapka restore (plan section 4.3), the restored WooCommerce payment-token
 * table contains Stripe rows only, and Task 0's runtime probe reports
 * `source_paypal_adapter_unknown` accordingly. Nothing here guesses a meta key
 * from a plausible-looking name.
 *
 * The reason that matters is the shape of the failure. A guessed vault key that
 * happened to hold a payer ID would produce a `system` PayPal subscription
 * whose first renewal charges nothing, or worse, charges against a mandate
 * belonging to a different contract. Four kinds of identifier live in that
 * plugin's metadata and they are routinely confused:
 *
 * - a **Woo token row ID**, a local `woocommerce_payment_tokens` primary key,
 *   which means nothing to PayPal at all;
 * - a **PayPal vault ID**, the reusable mandate
 *   `Processor::chargeVaultedRenewal()` reads from
 *   `active_payment_method.vendor_method_id` at fire time
 *   (Processor.php:817-825);
 * - a **payer/customer ID**, which identifies a person, not a mandate;
 * - a **remote subscription or billing-agreement ID**, which identifies a
 *   schedule PayPal is already running.
 *
 * Until a real plugin version is registered here with its verified key map,
 * `extract()` resolves nothing and the PayPal cohort takes plan section 8.3 C's
 * deliberate manual route. Registering one is a contract entry and its tests;
 * it is not a change to any strategy.
 */
final class PayPalSourceMetadataAdapter
{
    public const string ADAPTER_PPCP = 'woocommerce-paypal-payments';

    /**
     * The contract could not be identified, so no lookup was performed.
     *
     * Deliberately not `provider_method_missing`, which means a lookup ran and
     * came back empty. Those are different operator actions — supply the source
     * plugin version and re-audit, versus accept deliberate manual per §8.3 C —
     * and collapsing them into one code makes 71 PPCP records read exactly like
     * a Stripe record whose customer genuinely has no saved card.
     */
    public const string REASON_CONTRACT_UNKNOWN = 'provider_metadata_contract_unknown';

    public const KEY_VAULT = 'vault';
    public const KEY_PAYER = 'payer';
    public const KEY_SUBSCRIPTION = 'subscription';
    public const KEY_TOKEN_ROW = 'token_row';

    /** @var list<string> The four identifier kinds, kept apart on purpose. */
    private const array KINDS = [
        self::KEY_VAULT,
        self::KEY_PAYER,
        self::KEY_SUBSCRIPTION,
        self::KEY_TOKEN_ROW,
    ];

    /**
     * Contracts by exact `adapter:version`.
     *
     * @var array<string, array<string, list<string>>>
     */
    private array $contracts = [];

    /**
     * @param string|null $adapterVersion Exact `adapter:version` from the source
     *                                    runtime, or null when unknown — which
     *                                    is the Lapka case.
     */
    public function __construct(
        private readonly ?string $adapterVersion = null,
    ) {
    }

    /**
     * Register the verified key map for one exact plugin version.
     *
     * Exact, not a range: PPCP has moved its subscription metadata between
     * minor releases, and "close enough" is how a payer ID ends up in a vault
     * field.
     *
     * @param array<string, list<string>> $contract Kind => ordered source reference keys.
     */
    public function register(string $adapterVersion, array $contract): self
    {
        foreach (array_keys($contract) as $kind) {
            if (!in_array($kind, self::KINDS, true)) {
                throw new \InvalidArgumentException(sprintf(
                    'Unknown PayPal identifier kind "%s".',
                    (string) $kind,
                ));
            }
        }

        $this->contracts[$adapterVersion] = $contract;

        return $this;
    }

    public function knows(?string $adapterVersion): bool
    {
        return $adapterVersion !== null && isset($this->contracts[$adapterVersion]);
    }

    /**
     * The four identifiers, each in its own slot, or nothing.
     *
     * An unknown adapter resolves nothing even when the record carries payment
     * references, because an unlabelled identifier is not evidence of anything.
     * It reports `provider_metadata_contract_unknown`, not
     * `provider_method_missing`: the extraction was never performed, which is a
     * different fact from a lookup that ran and found nothing, and it is never
     * "no vault exists, therefore manual is proven safe".
     *
     * @return array{
     *     adapter: string|null,
     *     resolved: bool,
     *     vault_id: string|null,
     *     payer_id: string|null,
     *     subscription_id: string|null,
     *     token_row_id: string|null,
     *     reason_codes: list<string>,
     * }
     */
    public function extract(SubscriptionRecord $record): array
    {
        $empty = [
            'adapter'         => $this->adapterVersion,
            'resolved'        => false,
            'vault_id'        => null,
            'payer_id'        => null,
            'subscription_id' => null,
            'token_row_id'    => null,
            'reason_codes'    => [self::REASON_CONTRACT_UNKNOWN],
        ];

        if (!$this->knows($this->adapterVersion)) {
            return $empty;
        }

        $contract = $this->contracts[(string) $this->adapterVersion];

        $found = [];

        foreach (self::KINDS as $kind) {
            $found[$kind] = $this->firstReference($record, $contract[$kind] ?? []);
        }

        $reasons = [];

        if ($found[self::KEY_VAULT] === null && $found[self::KEY_SUBSCRIPTION] === null) {
            // Neither a reusable mandate nor a remote schedule. A payer ID or a
            // local token row on its own cannot bill anybody.
            $reasons[] = 'provider_method_missing';
        }

        return [
            'adapter'         => $this->adapterVersion,
            'resolved'        => true,
            'vault_id'        => $found[self::KEY_VAULT],
            'payer_id'        => $found[self::KEY_PAYER],
            'subscription_id' => $found[self::KEY_SUBSCRIPTION],
            'token_row_id'    => $found[self::KEY_TOKEN_ROW],
            'reason_codes'    => $reasons,
        ];
    }

    /**
     * @param list<string> $keys
     */
    private function firstReference(SubscriptionRecord $record, array $keys): ?string
    {
        foreach ($keys as $key) {
            $value = trim((string) ($record->paymentReferences[$key] ?? ''));

            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }
}

<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\Domain\Subscription\Payment;

use CartShift\Domain\Subscription\InvalidSourceRecord;
use CartShift\Domain\Subscription\Payment\PaymentCapabilityProbe;
use CartShift\Domain\Subscription\Payment\PaymentEnvironment;
use CartShift\Domain\Subscription\Payment\PayPalReferenceVerifier;
use CartShift\Domain\Subscription\Payment\PayPalSourceMetadataAdapter;
use CartShift\Domain\Subscription\Payment\StripeReferenceVerifier;
use CartShift\Domain\Subscription\SubscriptionRecord;
use CartShift\Domain\Subscription\SubscriptionRecordFactory;
use CartShift\Tests\Unit\PluginTestCase;

/**
 * Shared scaffolding for the payment-strategy tests.
 *
 * Records come from the Lapka fixtures through the real
 * `SubscriptionRecordFactory`, not from hand-built doubles. That matters more
 * than convenience: the PayPal cohort's payment references are empty precisely
 * because Task 2 refused to guess a PPCP meta key, and a hand-built record
 * would have quietly supplied one and hidden the gap these tests exist to pin.
 *
 * Provider reads are closures with one parameter and no body — there is no
 * shape of call this seam can make that is not a retrieval. The recording fake
 * keeps every resource it was asked for so a test can assert what was read, and
 * more importantly what was not.
 */
abstract class PaymentStrategyTestCase extends PluginTestCase
{
    /** @var array<string, callable> */
    protected array $shapes;

    protected SubscriptionRecordFactory $factory;

    /** @var list<string> Every provider resource any recording verifier was asked for. */
    protected array $reads = [];

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->shapes  = require dirname(__DIR__, 4) . '/fixtures/lapka-subscription-shapes.php';
        $this->factory = new SubscriptionRecordFactory();
        $this->reads   = [];

        $this->seedGateways();
        $this->seedStoreSettings(mode: 'store_managed', systemCharge: 'yes');
    }

    // ── records ────────────────────────────────────────────

    /**
     * @param array<string, mixed> $overrides
     */
    protected function record(string $shape, array $overrides = []): SubscriptionRecord
    {
        $record = $this->factory->subscriptionFromWoo('lapka', $this->shapes[$shape]($overrides));

        $this->assertNotInstanceOf(
            InvalidSourceRecord::class,
            $record,
            sprintf('Fixture "%s" did not decode into a valid record.', $shape),
        );

        /** @var SubscriptionRecord $record */
        return $record;
    }

    // ── environments ───────────────────────────────────────

    /**
     * A target that is system-capable, approved, and can verify both providers.
     *
     * @param array<string, mixed> $overrides
     */
    protected function environment(array $overrides = []): PaymentEnvironment
    {
        $capabilities = $overrides['capabilities'] ?? new PaymentCapabilityProbe();
        $fingerprint  = $overrides['settingsFingerprint'] ?? 'fingerprint-of-the-reviewed-target';

        return new PaymentEnvironment(
            capabilities: $capabilities,
            settingsFingerprint: $fingerprint,
            approvedSettingsFingerprint: array_key_exists('approvedSettingsFingerprint', $overrides)
                ? $overrides['approvedSettingsFingerprint']
                : $fingerprint,
            verifiers: $overrides['verifiers'] ?? [
                'stripe' => $this->stripeVerifier(),
                'paypal' => $this->paypalVerifier(),
            ],
            verifiedWebhookOwners: $overrides['verifiedWebhookOwners'] ?? [],
            manualFallbackConfirmed: $overrides['manualFallbackConfirmed'] ?? false,
            legacySourceChargePathProven: $overrides['legacySourceChargePathProven'] ?? false,
            nowUtc: $overrides['nowUtc'] ?? '2026-08-09 00:00:00',
        );
    }

    // ── provider doubles ───────────────────────────────────

    /**
     * A Stripe account that owns the fixture customer and its `pm_` method.
     *
     * @param array<string, array<string, mixed>> $overrides
     */
    protected function stripeVerifier(array $overrides = []): StripeReferenceVerifier
    {
        $objects = array_merge([
            'customers/cus_synthetic_fixture_0001'        => ['id' => 'cus_synthetic_fixture_0001', 'livemode' => true],
            'customers/cus_synthetic_fixture_0002'        => ['id' => 'cus_synthetic_fixture_0002', 'livemode' => true],
            'payment_methods/pm_synthetic_fixture_0001'   => [
                'id'       => 'pm_synthetic_fixture_0001',
                'customer' => 'cus_synthetic_fixture_0001',
                'livemode' => true,
                'type'     => 'card',
                'card'     => ['brand' => 'visa', 'last4' => '4242', 'exp_month' => 12, 'exp_year' => 2030],
            ],
            'customers/cus_synthetic_fixture_0002/sources/src_synthetic_fixture_0002' => [
                'id'       => 'src_synthetic_fixture_0002',
                'customer' => 'cus_synthetic_fixture_0002',
                'livemode' => true,
            ],
        ], $overrides);

        return new StripeReferenceVerifier(
            $this->recordingRetrieve($objects),
            expectedMode: 'live',
        );
    }

    /**
     * A PayPal merchant, and the source metadata adapter that feeds it.
     *
     * The adapter ships with no registered PPCP contract, so unless a test
     * registers one this verifier answers "nothing is resolvable" — which is
     * the Lapka reality rather than a testing convenience.
     *
     * @param array<string, array<string, mixed>> $objects
     */
    protected function paypalVerifier(
        array $objects = [],
        ?PayPalSourceMetadataAdapter $adapter = null,
    ): PayPalReferenceVerifier {
        return new PayPalReferenceVerifier(
            $this->recordingRetrieve($objects),
            $adapter ?? new PayPalSourceMetadataAdapter(null),
            expectedMerchantId: 'MERCHANT-SYNTHETIC-TARGET',
            expectedMode: 'live',
        );
    }

    /**
     * A retrieval seam that records what it was asked for.
     *
     * One parameter, no body, no method argument: there is no call shape here
     * that could create, confirm, or charge anything. That is the point — the
     * verifiers are provably read-only by construction, not by inspection.
     *
     * @param array<string, array<string, mixed>> $objects
     * @return \Closure(string): (array<string, mixed>|null)
     */
    protected function recordingRetrieve(array $objects): \Closure
    {
        return function (string $resource) use ($objects): ?array {
            $this->reads[] = $resource;

            return $objects[$resource] ?? null;
        };
    }

    // ── target store ───────────────────────────────────────

    protected function seedGateways(string ...$slugs): void
    {
        $available = [
            'stripe' => \CartShiftFakeGateway::stripe(),
            'paypal' => \CartShiftFakeGateway::paypal(),
        ];

        $slugs = $slugs === [] ? ['stripe', 'paypal'] : $slugs;

        $GLOBALS['_cartshift_test_fc_gateways'] = array_intersect_key($available, array_flip($slugs));
    }

    protected function seedStoreSettings(string $mode, string $systemCharge): void
    {
        $GLOBALS['_cartshift_test_options']['fluent_cart_store_settings'] = [
            'subscription_management_mode' => $mode,
            'subscription_system_charge'   => $systemCharge,
        ];
    }
}

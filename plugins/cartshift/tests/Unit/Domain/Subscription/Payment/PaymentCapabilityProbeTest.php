<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\Domain\Subscription\Payment;

use CartShift\Domain\Subscription\Payment\PaymentCapabilityProbe;
use CartShift\Tests\Unit\Domain\Subscription\FakeRuntimeSymbols;
use CartShift\Tests\Unit\PluginTestCase;

/**
 * The one place CartShift is allowed to claim system collection.
 *
 * FluentCart answers `manual` for two entirely unrelated situations: a store
 * that has not opted into store-managed billing with system charging on, and a
 * gateway that genuinely cannot charge off-session. The first is global policy
 * an operator may review and change outside CartShift; the second is a fact
 * about the gateway that no setting fixes. Reporting one as the other sends
 * somebody hunting a defect that does not exist — so the six cases below exist
 * to keep them apart.
 *
 * What this probe must never do is reimplement FluentCart's conjunction of the
 * two. `systemCollectionMethod()` returns exactly what
 * `SubscriptionManagementMode::resolveCollectionMethodFor()` returned, and the
 * attribution lives in a separate diagnostic that explains the answer rather
 * than second-guessing it.
 */
final class PaymentCapabilityProbeTest extends PluginTestCase
{
    // ── the canonical answer ───────────────────────────────

    public function testAFullyEnabledStoreReportsStripeSystemCapable(): void
    {
        $this->seedGateways();
        $this->seedStoreSettings(mode: 'store_managed', systemCharge: 'yes');

        $probe = new PaymentCapabilityProbe();

        $this->assertSame(
            PaymentCapabilityProbe::METHOD_SYSTEM,
            $probe->systemCollectionMethod(PaymentCapabilityProbe::GATEWAY_STRIPE),
        );
        $this->assertTrue($probe->isSystemCapable(PaymentCapabilityProbe::GATEWAY_STRIPE));
        $this->assertSame([], $probe->diagnose(PaymentCapabilityProbe::GATEWAY_STRIPE)['reason_codes']);
    }

    /**
     * PayPal 1.6.0 advertises `system_subscription` (PayPal.php:28) and its
     * `chargeRenewal()` reaches `Processor::chargeVaultedRenewal()`
     * (PayPal.php:266, Processor.php:806), which charges the saved vault ID
     * off-session. So PayPal is system-capable in exactly the same way Stripe
     * is, and modelling it as remote-schedule-only would be wrong.
     */
    public function testAFullyEnabledStoreReportsPayPalSystemCapable(): void
    {
        $this->seedGateways();
        $this->seedStoreSettings(mode: 'store_managed', systemCharge: 'yes');

        $probe = new PaymentCapabilityProbe();

        $this->assertSame(
            PaymentCapabilityProbe::METHOD_SYSTEM,
            $probe->systemCollectionMethod(PaymentCapabilityProbe::GATEWAY_PAYPAL),
        );
        $this->assertSame([], $probe->diagnose(PaymentCapabilityProbe::GATEWAY_PAYPAL)['reason_codes']);
    }

    // ── policy failure one: the system-charge switch is off ─

    public function testStoreManagedWithSystemChargeDisabledIsAPolicyFailure(): void
    {
        $this->seedGateways();
        $this->seedStoreSettings(mode: 'store_managed', systemCharge: 'no');

        $diagnosis = (new PaymentCapabilityProbe())->diagnose(PaymentCapabilityProbe::GATEWAY_STRIPE);

        $this->assertSame(PaymentCapabilityProbe::METHOD_MANUAL, $diagnosis['collection_method']);
        $this->assertSame(
            [PaymentCapabilityProbe::REASON_STORE_MODE_NOT_APPROVED],
            $diagnosis['reason_codes'],
            'A store setting is not a gateway defect.',
        );
        $this->assertTrue($diagnosis['store_managed']);
        $this->assertFalse($diagnosis['system_charge_enabled']);
        $this->assertTrue(
            $diagnosis['system_subscription'],
            'Stripe still advertises the capability; only the store said no.',
        );
    }

    // ── policy failure two: gateway-managed mode ───────────

    public function testGatewayManagedModeIsAPolicyFailureNotAGatewayDefect(): void
    {
        $this->seedGateways();
        $this->seedStoreSettings(mode: 'gateway_managed', systemCharge: 'yes');

        $diagnosis = (new PaymentCapabilityProbe())->diagnose(PaymentCapabilityProbe::GATEWAY_STRIPE);

        $this->assertSame(PaymentCapabilityProbe::METHOD_MANUAL, $diagnosis['collection_method']);
        $this->assertSame(
            [PaymentCapabilityProbe::REASON_STORE_MODE_NOT_APPROVED],
            $diagnosis['reason_codes'],
        );
        $this->assertFalse($diagnosis['store_managed']);
        $this->assertFalse($diagnosis['system_charge_enabled']);
        $this->assertTrue($diagnosis['system_subscription']);
    }

    /**
     * The two policy failures are different inputs and the report says which,
     * even though FluentCart collapses both into `manual` and both carry the
     * same reason code.
     */
    public function testTheTwoPolicyFailuresRemainTellableApart(): void
    {
        $this->seedGateways();

        $this->seedStoreSettings(mode: 'gateway_managed', systemCharge: 'yes');
        $modeOff = (new PaymentCapabilityProbe())->diagnose(PaymentCapabilityProbe::GATEWAY_STRIPE);

        $this->seedStoreSettings(mode: 'store_managed', systemCharge: 'no');
        $chargeOff = (new PaymentCapabilityProbe())->diagnose(PaymentCapabilityProbe::GATEWAY_STRIPE);

        $this->assertSame($modeOff['reason_codes'], $chargeOff['reason_codes']);
        $this->assertNotSame(
            $modeOff['store_managed'],
            $chargeOff['store_managed'],
            'Same code, different input — the diagnosis has to say which one.',
        );
    }

    // ── an absent gateway feature is a different thing ──────

    public function testAGatewayWithoutTheSystemFeatureIsBlamedForItself(): void
    {
        $GLOBALS['_cartshift_test_fc_gateways'] = [
            'stripe' => \CartShiftFakeGateway::stripe()->without('system_subscription'),
            'paypal' => \CartShiftFakeGateway::paypal(),
        ];
        $this->seedStoreSettings(mode: 'store_managed', systemCharge: 'yes');

        $diagnosis = (new PaymentCapabilityProbe())->diagnose(PaymentCapabilityProbe::GATEWAY_STRIPE);

        $this->assertSame(PaymentCapabilityProbe::METHOD_MANUAL, $diagnosis['collection_method']);
        $this->assertSame(
            [PaymentCapabilityProbe::REASON_GATEWAY_LACKS_CAPABILITY],
            $diagnosis['reason_codes'],
        );
        $this->assertNotContains(
            PaymentCapabilityProbe::REASON_STORE_MODE_NOT_APPROVED,
            $diagnosis['reason_codes'],
            'The store policy is fine here. Blaming it would send the operator to the wrong screen.',
        );
    }

    public function testBothPolicyAndCapabilityFailuresAreReportedTogether(): void
    {
        $GLOBALS['_cartshift_test_fc_gateways'] = [
            'stripe' => \CartShiftFakeGateway::stripe()->without('system_subscription'),
        ];
        $this->seedStoreSettings(mode: 'gateway_managed', systemCharge: 'no');

        $diagnosis = (new PaymentCapabilityProbe())->diagnose(PaymentCapabilityProbe::GATEWAY_STRIPE);

        $this->assertSame([
            PaymentCapabilityProbe::REASON_GATEWAY_LACKS_CAPABILITY,
            PaymentCapabilityProbe::REASON_STORE_MODE_NOT_APPROVED,
        ], $diagnosis['reason_codes']);
    }

    // ── an unregistered gateway cannot be asked at all ─────

    public function testAnUnregisteredGatewayIsUnavailableRatherThanManual(): void
    {
        $GLOBALS['_cartshift_test_fc_gateways'] = [];
        $this->seedStoreSettings(mode: 'store_managed', systemCharge: 'yes');

        $probe = new PaymentCapabilityProbe();
        $diagnosis = $probe->diagnose(PaymentCapabilityProbe::GATEWAY_STRIPE);

        $this->assertSame(
            PaymentCapabilityProbe::METHOD_UNAVAILABLE,
            $probe->systemCollectionMethod(PaymentCapabilityProbe::GATEWAY_STRIPE),
            'FluentCart was never asked, so no answer of FluentCart\'s may be reported.',
        );
        $this->assertFalse($probe->isRegistered(PaymentCapabilityProbe::GATEWAY_STRIPE));
        $this->assertSame(
            [PaymentCapabilityProbe::REASON_UNAVAILABLE],
            $diagnosis['reason_codes'],
        );
        $this->assertNotContains(
            PaymentCapabilityProbe::REASON_GATEWAY_LACKS_CAPABILITY,
            $diagnosis['reason_codes'],
            'An absent gateway has no features to lack.',
        );
    }

    public function testAnAbsentCanonicalProbeIsUnavailableRatherThanGuessed(): void
    {
        $this->seedGateways();
        $this->seedStoreSettings(mode: 'store_managed', systemCharge: 'yes');

        $symbols = (new FakeRuntimeSymbols())
            ->withoutClass('FluentCart\App\Modules\Subscriptions\Services\SubscriptionManagementMode');

        $probe = new PaymentCapabilityProbe($symbols);

        $this->assertSame(
            PaymentCapabilityProbe::METHOD_UNAVAILABLE,
            $probe->systemCollectionMethod(PaymentCapabilityProbe::GATEWAY_STRIPE),
        );
        $this->assertSame(
            [PaymentCapabilityProbe::REASON_UNAVAILABLE],
            $probe->diagnose(PaymentCapabilityProbe::GATEWAY_STRIPE)['reason_codes'],
        );
    }

    /**
     * The whole point of the seam: CartShift asks FluentCart and reports the
     * answer. A store whose filter forces store-managed billing on is
     * system-capable even though the stored option says otherwise, because
     * `getMode()` applies that filter and this probe does not second-guess it.
     */
    public function testTheAnswerIsFluentCartsIncludingItsFilters(): void
    {
        $this->seedGateways();
        $this->seedStoreSettings(mode: 'gateway_managed', systemCharge: 'yes');

        add_filter(
            'fluent_cart/subscription/management_mode',
            static fn (): string => 'store_managed',
        );

        $this->assertSame(
            PaymentCapabilityProbe::METHOD_SYSTEM,
            (new PaymentCapabilityProbe())->systemCollectionMethod(PaymentCapabilityProbe::GATEWAY_STRIPE),
        );
    }

    // ── helpers ────────────────────────────────────────────

    private function seedGateways(): void
    {
        $GLOBALS['_cartshift_test_fc_gateways'] = [
            'stripe' => \CartShiftFakeGateway::stripe(),
            'paypal' => \CartShiftFakeGateway::paypal(),
        ];
    }

    private function seedStoreSettings(string $mode, string $systemCharge): void
    {
        $GLOBALS['_cartshift_test_options']['fluent_cart_store_settings'] = [
            'subscription_management_mode' => $mode,
            'subscription_system_charge'   => $systemCharge,
        ];
    }
}

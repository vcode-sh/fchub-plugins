<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\Domain\Subscription;

use CartShift\Domain\Subscription\NormalizedSubscriptionContract;
use CartShift\Support\Enums\FcBillingInterval;
use CartShift\Tests\Unit\PluginTestCase;

/**
 * The comparable form of "what does this thing bill, how often, for how long".
 *
 * Both ends of a mapping decision reduce to one of these — a WooCommerce
 * source variation through the exact cadence table, a FluentCart target
 * variation through its stored `repeat_interval` — so the question "may this
 * source claim that target" is an equality test rather than six ad-hoc reads.
 */
final class NormalizedSubscriptionContractTest extends PluginTestCase
{
    public function testAWooMonthlyContractNormalisesToMonthly(): void
    {
        $contract = NormalizedSubscriptionContract::fromWooCommerce('month', 1);

        $this->assertTrue($contract->isRepresentable());
        $this->assertSame(FcBillingInterval::Monthly, $contract->interval());
        $this->assertSame(0, $contract->trialDays());
        $this->assertSame(0, $contract->finiteCycles());
    }

    public function testAWooYearlyContractNormalisesToYearly(): void
    {
        $this->assertSame(
            FcBillingInterval::Yearly,
            NormalizedSubscriptionContract::fromWooCommerce('year', 1)->interval(),
        );
    }

    /**
     * The whole reason this class exists rather than a bare enum call: an
     * unsupported cadence is a first-class state that carries its own blocking
     * reason code, not a null somebody downstream has to remember to check.
     */
    public function testAnUnsupportedCadenceIsNotRepresentableAndCarriesTheBlockingCode(): void
    {
        $contract = NormalizedSubscriptionContract::fromWooCommerce('week', 2);

        $this->assertFalse($contract->isRepresentable());
        $this->assertNull($contract->interval());
        $this->assertSame(
            [NormalizedSubscriptionContract::ERROR_UNSUPPORTED_CADENCE],
            $contract->reasonCodes(),
        );
        $this->assertSame('unsupported_billing_cadence', NormalizedSubscriptionContract::ERROR_UNSUPPORTED_CADENCE);
    }

    public function testARepresentableContractHasNoReasonCodes(): void
    {
        $this->assertSame([], NormalizedSubscriptionContract::fromWooCommerce('month', 6)->reasonCodes());
    }

    public function testAFluentCartTargetNormalisesFromItsStoredInterval(): void
    {
        $contract = NormalizedSubscriptionContract::fromFluentCart('half_yearly', trialDays: 14, finiteCycles: 4);

        $this->assertSame(FcBillingInterval::HalfYearly, $contract->interval());
        $this->assertSame(14, $contract->trialDays());
        $this->assertSame(4, $contract->finiteCycles());
    }

    /**
     * A FluentCart variation storing something outside the six is a variation
     * CartShift cannot reason about, so it is unrepresentable rather than
     * quietly rounded to the nearest cadence.
     */
    public function testAFluentCartTargetWithAnUnknownIntervalIsNotRepresentable(): void
    {
        $this->assertFalse(NormalizedSubscriptionContract::fromFluentCart('fortnightly')->isRepresentable());
    }

    // ──────────────────────────────────────────────
    // Cadence gate vs sharing key
    // ──────────────────────────────────────────────

    /**
     * The per-row hard gate is cadence alone (plan section 7.2, rule 2). Trial
     * and term describe the target's own plan and are shown to the operator;
     * they do not disqualify a target whose billing rhythm matches.
     */
    public function testCadenceMatchesIgnoresTrialAndTerm(): void
    {
        $source = NormalizedSubscriptionContract::fromWooCommerce('month', 1);
        $target = NormalizedSubscriptionContract::fromFluentCart('monthly', trialDays: 30, finiteCycles: 12);

        $this->assertTrue($source->cadenceMatches($target));
    }

    public function testCadenceDoesNotMatchAcrossDifferentIntervals(): void
    {
        $this->assertFalse(
            NormalizedSubscriptionContract::fromWooCommerce('month', 1)
                ->cadenceMatches(NormalizedSubscriptionContract::fromFluentCart('yearly')),
        );
    }

    /**
     * An unrepresentable contract matches nothing at all, including another
     * unrepresentable one. Two rows CartShift cannot express are not thereby
     * equivalent.
     */
    public function testAnUnrepresentableContractMatchesNothing(): void
    {
        $unrepresentable = NormalizedSubscriptionContract::fromWooCommerce('week', 2);

        $this->assertFalse($unrepresentable->cadenceMatches(NormalizedSubscriptionContract::fromFluentCart('weekly')));
        $this->assertFalse($unrepresentable->cadenceMatches($unrepresentable));
    }

    /**
     * The sharing key is stricter than the cadence gate: two sources may share
     * one target variation only when their whole normalised contract agrees.
     */
    public function testTheSharingKeyCoversCadenceTrialAndTerm(): void
    {
        $a = NormalizedSubscriptionContract::fromWooCommerce('month', 1);
        $b = NormalizedSubscriptionContract::fromWooCommerce('month', 1);

        $this->assertSame($a->key(), $b->key());

        $withTrial = NormalizedSubscriptionContract::fromWooCommerce('month', 1, trialDays: 7);
        $withTerm  = NormalizedSubscriptionContract::fromWooCommerce('month', 1, finiteCycles: 12);

        $this->assertNotSame($a->key(), $withTrial->key());
        $this->assertNotSame($a->key(), $withTerm->key());
        $this->assertNotSame($withTrial->key(), $withTerm->key());
    }

    public function testTheLapkaMonthlyAndYearlyKeysDiffer(): void
    {
        $this->assertNotSame(
            NormalizedSubscriptionContract::fromWooCommerce('month', 1)->key(),
            NormalizedSubscriptionContract::fromWooCommerce('year', 1)->key(),
            'Monthly and yearly are the two Lapka contracts. If their keys agreed they could share a variation.',
        );
    }

    /**
     * Two unrepresentable contracts must not collapse to one shareable key
     * either, so the key names the exact source cadence rather than a generic
     * "unsupported".
     */
    public function testUnrepresentableContractsDoNotShareAKey(): void
    {
        $this->assertNotSame(
            NormalizedSubscriptionContract::fromWooCommerce('week', 2)->key(),
            NormalizedSubscriptionContract::fromWooCommerce('year', 2)->key(),
        );
    }

    /**
     * Price is nowhere in this class, and that is the point: PLN 24 and PLN 29
     * are the same monthly contract as far as variation choice goes. The
     * subscription row keeps the amount.
     */
    public function testTwoPriceCohortsOfOneCadenceShareAKey(): void
    {
        $this->assertSame(
            NormalizedSubscriptionContract::fromWooCommerce('month', 1)->key(),
            NormalizedSubscriptionContract::fromWooCommerce('month', 1)->key(),
        );
    }

    public function testTheDescriptionIsStableAndReadable(): void
    {
        $contract = NormalizedSubscriptionContract::fromFluentCart('monthly', trialDays: 7, finiteCycles: 12);

        $this->assertSame([
            'interval'      => 'monthly',
            'trial_days'    => 7,
            'finite_cycles' => 12,
            'representable' => true,
            'key'           => $contract->key(),
        ], $contract->toArray());
    }
}

<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\Domain\Mapping;

use CartShift\Domain\Mapping\SubscriptionVariantMatcher;
use CartShift\Domain\Mapping\VariantResolver;
use CartShift\Tests\Unit\PluginTestCase;

/**
 * VariantResolver's third pass pairs the nth Woo variation with the nth
 * FluentCart variant. For a T-shirt that is a defensible last resort. For a
 * subscription it is a yearly subscriber silently rebilled monthly, because the
 * monthly variation is usually the one FluentCart lists first.
 *
 * So subscription source variations never reach that pass. They are matched on
 * an exact cadence gate, or they are not matched at all and the mapping is
 * blocked until the operator picks a compatible target by hand.
 */
final class SubscriptionVariantMatcherTest extends PluginTestCase
{
    private function matcher(): SubscriptionVariantMatcher
    {
        return new SubscriptionVariantMatcher(new VariantResolver());
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function sub(int $id, string $period, int $multiplier, array $overrides = []): array
    {
        return array_merge([
            'id'           => $id,
            'sku'          => '',
            'name'         => 'Default',
            'price'        => 2900,
            'payment_type' => 'subscription',
            'period'       => $period,
            'multiplier'   => $multiplier,
            'trial_days'   => 0,
            'times'        => 0,
        ], $overrides);
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function oneTime(int $id, array $overrides = []): array
    {
        return array_merge([
            'id'           => $id,
            'sku'          => '',
            'name'         => 'Default',
            'price'        => 2900,
            'payment_type' => 'onetime',
            'period'       => '',
            'multiplier'   => 0,
            'trial_days'   => 0,
            'times'        => 0,
        ], $overrides);
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function target(int $id, string $repeatInterval, array $overrides = []): array
    {
        return array_merge([
            'id'              => $id,
            'sku'             => '',
            'name'            => ucfirst($repeatInterval),
            'price'           => 29.0,
            'payment_type'    => $repeatInterval === '' ? 'onetime' : 'subscription',
            'repeat_interval' => $repeatInterval,
            'trial_days'      => 0,
            'times'           => 0,
        ], $overrides);
    }

    // ──────────────────────────────────────────────
    // The headline defect
    // ──────────────────────────────────────────────

    /**
     * The Lapka yearly product against a target product whose first variation
     * is monthly. Position would answer "monthly"; cadence answers "yearly".
     */
    public function testAYearlySourceNeverLandsOnTheMonthlyVariationBecauseItIsFirst(): void
    {
        $result = $this->matcher()->match(
            [$this->sub(770_002, 'year', 1)],
            [$this->target(4101, 'monthly'), $this->target(4102, 'yearly')],
        );

        $this->assertSame([770_002 => 4102], $result['map']);
        $this->assertSame([], $result['errors']);
    }

    public function testAMonthlySourceLandsOnTheMonthlyVariation(): void
    {
        $result = $this->matcher()->match(
            [$this->sub(770_001, 'month', 1)],
            [$this->target(4101, 'monthly'), $this->target(4102, 'yearly')],
        );

        $this->assertSame([770_001 => 4101], $result['map']);
    }

    /**
     * No compatible cadence at all: an orphan and a block, never the nearest
     * variation. Section 7.4 — automatic subscription-orphan creation is out of
     * scope, and creating one as `onetime` is exactly the defect being removed.
     */
    public function testASourceWithNoCadenceMatchIsAnOrphanRatherThanAPositionalGuess(): void
    {
        $result = $this->matcher()->match(
            [$this->sub(770_002, 'year', 1)],
            [$this->target(4101, 'monthly')],
        );

        $this->assertSame([], $result['map']);
        $this->assertSame([770_002], $result['orphans']);
        $this->assertSame(
            ['target_variation_missing'],
            array_column($result['errors'], 'code'),
        );
    }

    /**
     * A one-time target variation is never a subscription's home, whatever it
     * is called. Hard gate 1 of section 7.2.
     */
    public function testAOneTimeTargetVariationIsNeverOfferedToASubscriptionSource(): void
    {
        $result = $this->matcher()->match(
            [$this->sub(770_001, 'month', 1, ['sku' => 'CLUB-M'])],
            [$this->target(4101, '', ['sku' => 'CLUB-M', 'name' => 'Default'])],
        );

        $this->assertSame([], $result['map']);
        $this->assertSame(['target_variation_missing'], array_column($result['errors'], 'code'));
    }

    /**
     * Blocked before matching begins, with its own code: the operator's problem
     * is not "no target fits", it is "FluentCart cannot express week/2 at all".
     */
    public function testAnUnsupportedSourceCadenceBlocksBeforeAnyTargetIsConsidered(): void
    {
        $result = $this->matcher()->match(
            [$this->sub(770_003, 'week', 2)],
            [$this->target(4101, 'weekly')],
        );

        $this->assertSame([], $result['map']);
        $this->assertSame([770_003], $result['orphans']);
        $this->assertSame(['unsupported_billing_cadence'], array_column($result['errors'], 'code'));
    }

    // ──────────────────────────────────────────────
    // Ranking within the compatible set
    // ──────────────────────────────────────────────

    public function testAnExactSkuBeatsEverythingElseWithinTheSameCadence(): void
    {
        $result = $this->matcher()->match(
            [$this->sub(770_001, 'month', 1, ['sku' => 'CLUB-M', 'name' => 'Monthly'])],
            [
                $this->target(4101, 'monthly', ['sku' => '', 'name' => 'Monthly']),
                $this->target(4102, 'monthly', ['sku' => 'CLUB-M', 'name' => 'Something else']),
            ],
        );

        $this->assertSame([770_001 => 4102], $result['map']);
    }

    public function testAnExactNameBeatsPriceWithinTheSameCadence(): void
    {
        $result = $this->matcher()->match(
            [$this->sub(770_001, 'month', 1, ['name' => 'Monthly', 'price' => 2400])],
            [
                $this->target(4101, 'monthly', ['name' => 'Cheap', 'price' => 24.0]),
                $this->target(4102, 'monthly', ['name' => 'Monthly', 'price' => 29.0]),
            ],
        );

        $this->assertSame([770_001 => 4102], $result['map']);
    }

    public function testPriceIsTheLastSignalWithinTheSameCadence(): void
    {
        $result = $this->matcher()->match(
            [$this->sub(770_001, 'month', 1, ['name' => 'Default', 'price' => 2900])],
            [
                $this->target(4101, 'monthly', ['name' => 'A', 'price' => 12.0]),
                $this->target(4102, 'monthly', ['name' => 'B', 'price' => 29.0]),
            ],
        );

        $this->assertSame([770_001 => 4102], $result['map']);
    }

    /**
     * A cadence-compatible target whose list price differs is still the answer.
     * The difference is a warning that says the source contract is preserved —
     * PLN 24 stays PLN 24 — not a reason to refuse the mapping.
     */
    public function testAPriceDifferenceIsAWarningAndNeverAGate(): void
    {
        $result = $this->matcher()->match(
            [$this->sub(770_001, 'month', 1, ['price' => 2400])],
            [$this->target(4101, 'monthly', ['price' => 29.0])],
        );

        $this->assertSame([770_001 => 4101], $result['map']);
        $this->assertSame([], $result['errors']);
        $this->assertSame(['target_price_differs_from_source'], array_column($result['warnings'], 'code'));
        $this->assertStringContainsString('preserved', $result['warnings'][0]['message']);
    }

    public function testNoPriceWarningWhenTheListPriceAgrees(): void
    {
        $result = $this->matcher()->match(
            [$this->sub(770_001, 'month', 1, ['price' => 2900])],
            [$this->target(4101, 'monthly', ['price' => 29.0])],
        );

        $this->assertSame([], $result['warnings']);
    }

    // ──────────────────────────────────────────────
    // Claiming
    // ──────────────────────────────────────────────

    public function testATargetVariationIsClaimedOnceWithinOneProduct(): void
    {
        $result = $this->matcher()->match(
            [$this->sub(11, 'month', 1), $this->sub(12, 'month', 1)],
            [$this->target(4101, 'monthly')],
        );

        $this->assertSame([11 => 4101], $result['map']);
        $this->assertSame([12], $result['orphans']);
    }

    /**
     * Mixed products still work, and the one-time half keeps every pass it has
     * today — including position, which is right for a size and wrong only for
     * a cadence.
     */
    public function testOneTimeVariationsKeepTheirExistingThreePassBehaviour(): void
    {
        $result = $this->matcher()->match(
            [$this->oneTime(11, ['name' => 'Alpha']), $this->oneTime(12, ['name' => 'Beta'])],
            [$this->target(4101, '', ['name' => 'One']), $this->target(4102, '', ['name' => 'Two'])],
        );

        $this->assertSame([11 => 4101, 12 => 4102], $result['map']);
        $this->assertSame([], $result['errors']);
    }

    /**
     * A one-time source must never fall positionally onto a subscription
     * variation either — that would sell a membership as a single purchase.
     */
    public function testAOneTimeSourceNeverPositionallyClaimsASubscriptionVariation(): void
    {
        $result = $this->matcher()->match(
            [$this->oneTime(11, ['name' => 'Alpha'])],
            [$this->target(4101, 'monthly', ['name' => 'Monthly'])],
        );

        $this->assertSame([], $result['map']);
        $this->assertSame([11], $result['orphans']);
    }

    /**
     * The subscription half runs first and reserves what it takes, so the
     * one-time half cannot walk off with a variation the cadence gate already
     * assigned.
     */
    public function testTheSubscriptionHalfReservesItsTargetsBeforeTheOneTimeHalfRuns(): void
    {
        $result = $this->matcher()->match(
            [
                $this->oneTime(11, ['name' => 'Anything']),
                $this->sub(12, 'month', 1, ['name' => 'Monthly']),
            ],
            [
                $this->target(4101, 'monthly', ['name' => 'Monthly']),
                $this->target(4102, '', ['name' => 'Anything']),
            ],
        );

        $this->assertSame([11 => 4102, 12 => 4101], $result['map']);
    }

    // ──────────────────────────────────────────────
    // What the operator is shown
    // ──────────────────────────────────────────────

    /**
     * Section 7.3: once a product is selected, every target variation is listed
     * with its payment type, repeat interval, list price, trial and finite-cycle
     * details, and its compatibility with this source variation.
     */
    public function testEveryTargetVariationIsDescribedForEachSubscriptionSource(): void
    {
        $result = $this->matcher()->match(
            [$this->sub(770_002, 'year', 1)],
            [
                $this->target(4101, 'monthly', ['price' => 29.0, 'trial_days' => 7, 'times' => 12]),
                $this->target(4102, 'yearly', ['price' => 290.0]),
                $this->target(4103, '', ['name' => 'Lifetime', 'price' => 999.0]),
            ],
        );

        $this->assertCount(1, $result['sources']);

        $source = $result['sources'][0];

        $this->assertSame(770_002, $source['id']);
        $this->assertTrue($source['subscription']);
        $this->assertSame('yearly', $source['interval']);
        $this->assertSame(4102, $source['selected']);

        $options = [];

        foreach ($source['options'] as $option) {
            $options[$option['id']] = $option;
        }

        foreach (['payment_type', 'repeat_interval', 'price', 'trial_days', 'times'] as $field) {
            $this->assertArrayHasKey($field, $options[4101], "The operator cannot judge a target without its {$field}.");
        }

        $this->assertSame('monthly', $options[4101]['repeat_interval']);
        $this->assertSame(7, $options[4101]['trial_days']);
        $this->assertSame(12, $options[4101]['times']);
        $this->assertFalse($options[4101]['compatible']);
        $this->assertSame(['target_variation_contract_mismatch'], $options[4101]['errors']);

        $this->assertTrue($options[4102]['compatible']);
        $this->assertSame([], $options[4102]['errors']);

        $this->assertSame('onetime', $options[4103]['payment_type']);
        $this->assertFalse($options[4103]['compatible']);
    }

    /**
     * An incompatible target is still listed. The operator has to be able to
     * see why it is refused; hiding it is how a support ticket says "CartShift
     * lost my yearly plan".
     */
    public function testIncompatibleTargetsAreListedRatherThanHidden(): void
    {
        $result = $this->matcher()->match(
            [$this->sub(770_002, 'year', 1)],
            [$this->target(4101, 'monthly')],
        );

        $this->assertCount(1, $result['sources'][0]['options']);
        $this->assertNull($result['sources'][0]['selected']);
    }

    // ──────────────────────────────────────────────
    // Operator override
    // ──────────────────────────────────────────────

    /**
     * The operator's own choice wins over the suggestion, and is honoured only
     * when it is compatible. This is what the mapping screen posts back.
     */
    public function testAnExplicitCompatibleChoiceOverridesTheSuggestion(): void
    {
        $result = $this->matcher()->match(
            [$this->sub(770_001, 'month', 1, ['sku' => 'CLUB-M'])],
            [
                $this->target(4101, 'monthly', ['sku' => 'CLUB-M']),
                $this->target(4102, 'monthly', ['sku' => 'OTHER']),
            ],
            [770_001 => 4102],
        );

        $this->assertSame([770_001 => 4102], $result['map']);
    }

    public function testAnExplicitIncompatibleChoiceIsRefusedRatherThanSilentlyCorrected(): void
    {
        $result = $this->matcher()->match(
            [$this->sub(770_002, 'year', 1)],
            [$this->target(4101, 'monthly'), $this->target(4102, 'yearly')],
            [770_002 => 4101],
        );

        $this->assertSame([], $result['map']);
        $this->assertSame(
            ['target_variation_contract_mismatch'],
            array_column($result['errors'], 'code'),
            'Quietly moving the operator to 4102 would hide the fact that they asked for the wrong one.',
        );
    }

    public function testAnExplicitChoiceOutsideTheProductIsRefused(): void
    {
        $result = $this->matcher()->match(
            [$this->sub(770_001, 'month', 1)],
            [$this->target(4101, 'monthly')],
            [770_001 => 9999],
        );

        $this->assertSame([], $result['map']);
        $this->assertSame(['target_variation_missing'], array_column($result['errors'], 'code'));
    }

    /**
     * The mirror of testAOneTimeSourceNeverPositionallyClaimsASubscriptionVariation,
     * through the door that gate could not see.
     *
     * `$chosen` used to be consulted only inside the subscription branch, so a
     * one-time source that *named* a subscription variation walked straight
     * past every check and was persisted verbatim — and a single claim on that
     * target then passes MappingSetValidator too. It is reachable without a
     * hostile client: save the decision while the FluentCart variation is
     * one-time, let the owner convert it to a subscription, re-save through
     * `bulk`, and a single purchase is now a recurring contract.
     */
    public function testAOneTimeSourceMayNotExplicitlyClaimASubscriptionVariation(): void
    {
        $result = $this->matcher()->match(
            [$this->oneTime(11, ['name' => 'Alpha'])],
            [$this->target(4101, 'monthly', ['name' => 'Monthly']), $this->target(4102, '', ['name' => 'Alpha'])],
            [11 => 4101],
        );

        $this->assertSame([], $result['map']);
        $this->assertSame([11], $result['orphans']);
        $this->assertSame(['target_variation_contract_mismatch'], array_column($result['errors'], 'code'));
    }

    /**
     * The check is a refusal gate, not a selection mechanism — and this is the
     * asymmetry, stated out loud so nobody later mistakes it for a bug.
     *
     * Section 7.3 removes positional fallback *for subscription products* and
     * requires an explicit variation for every subscription source. One-time
     * sources deliberately keep all three of VariantResolver's passes,
     * position included, because a size is not a billing contract. So naming a
     * one-time target does not refuse the decision — and it does not select
     * anything either: the resolver still decides.
     *
     * Proven by naming 4103 and watching the map come back 4102, which is what
     * the name pass picks. The earlier version of this test named the target
     * the resolver would have chosen anyway, so it would have passed just as
     * happily with the explicit choice ignored — which is precisely what it was
     * meant to be checking.
     */
    public function testAOneTimeSourcesNamedTargetIsNeitherRefusedNorSelected(): void
    {
        $result = $this->matcher()->match(
            [$this->oneTime(11, ['name' => 'Alpha'])],
            [$this->target(4102, '', ['name' => 'Alpha']), $this->target(4103, '', ['name' => 'Beta'])],
            [11 => 4103],
        );

        $this->assertSame([], $result['errors'], 'A one-time target is never a refusal.');
        $this->assertSame(
            [11 => 4102],
            $result['map'],
            'And never a selection: the resolver paired Alpha with Alpha, ignoring the named 4103.',
        );
    }

    /**
     * A target the operator names that is not on this product at all is left
     * to the resolver rather than refused. One-time mapping has never asserted
     * that a stale variant map still resolves, and starting here would refuse
     * decisions on catalogues this task has no business touching.
     */
    public function testAOneTimeSourceNamingAnAbsentTargetFallsBackToTheResolver(): void
    {
        $result = $this->matcher()->match(
            [$this->oneTime(11, ['name' => 'Alpha'])],
            [$this->target(4102, '', ['name' => 'Alpha'])],
            [11 => 9999],
        );

        $this->assertSame([11 => 4102], $result['map']);
        $this->assertSame([], $result['errors']);
    }

    // ──────────────────────────────────────────────
    // Reserved is its own answer
    // ──────────────────────────────────────────────

    /**
     * Two same-cadence source variations of one product, one target.
     *
     * The second is refused because the first took it, which is nothing to do
     * with its billing contract — reporting "this product bills monthly and the
     * chosen variation bills monthly" is the kind of message that makes an
     * owner distrust the whole screen.
     */
    public function testAReservedTargetSaysSoRatherThanClaimingACadenceMismatch(): void
    {
        $result = $this->matcher()->match(
            [$this->sub(11, 'month', 1), $this->sub(12, 'month', 1)],
            [$this->target(4101, 'monthly')],
        );

        $second = $result['sources'][1];

        $this->assertNull($second['selected']);
        $this->assertFalse($second['options'][0]['compatible']);
        $this->assertSame(
            ['target_variation_contract_collision'],
            $second['options'][0]['errors'],
            'A dimmed option with no reason on it is the one case the screen explains nothing.',
        );
    }

    public function testAnExplicitlyChosenReservedTargetIsRefusedForBeingTakenNotForItsCadence(): void
    {
        $result = $this->matcher()->match(
            [$this->sub(11, 'month', 1), $this->sub(12, 'month', 1)],
            [$this->target(4101, 'monthly'), $this->target(4102, 'monthly')],
            [11 => 4101, 12 => 4101],
        );

        $this->assertSame([11 => 4101], $result['map']);
        $this->assertSame(['target_variation_contract_collision'], array_column($result['errors'], 'code'));
        $this->assertStringContainsString('already', $result['errors'][0]['message']);
    }

    public function testAnEmptyTargetCatalogueOrphansEverySubscriptionSource(): void
    {
        $result = $this->matcher()->match([$this->sub(770_001, 'month', 1)], []);

        $this->assertSame([], $result['map']);
        $this->assertSame([770_001], $result['orphans']);
    }
}

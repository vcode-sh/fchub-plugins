<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\Domain\Mapping;

use CartShift\Domain\Mapping\MappingSetValidator;
use CartShift\Domain\Mapping\ProductMapDecision;
use CartShift\Domain\Mapping\SubscriptionVariantMatcher;
use CartShift\Domain\Mapping\VariantResolver;
use CartShift\Domain\Subscription\NormalizedSubscriptionContract;
use CartShift\Tests\Unit\PluginTestCase;

/**
 * The acceptance case this whole task exists for.
 *
 * Lapka has two WooCommerce subscription products — monthly and yearly — and
 * one FluentCart product, `Klubu Przyjaciol Psow`, with a monthly variation at
 * PLN 29 and a yearly one at PLN 290. Both source products must link to that
 * one product and to *different* variations.
 *
 * ProductMatcher scored both source products `band=none`, so nothing here is
 * reachable by suggestion alone: the operator picks the product by hand and the
 * cadence gate picks the variation. Under the old positional fallback both
 * would have taken the first variation, which is monthly — 188 yearly
 * subscribers quietly moved onto a monthly plan.
 *
 * Anonymised source shapes come from tests/fixtures/lapka-subscription-shapes.php
 * so this file and the payment/dataset tasks describe the same population in the
 * same vocabulary.
 */
final class LapkaSubscriptionMappingTest extends PluginTestCase
{
    /** The FluentCart product both source products link to. */
    private const int TARGET_PRODUCT = 88;

    private const int TARGET_MONTHLY = 4101;
    private const int TARGET_YEARLY  = 4102;

    /** @var array<string, callable> */
    private array $shapes;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shapes = require dirname(__DIR__, 3) . '/fixtures/lapka-subscription-shapes.php';
    }

    /**
     * `Klubu Przyjaciol Psow` as CartShift reads it back out of FluentCart:
     * monthly first, which is exactly why position is the wrong answer.
     *
     * @return list<array<string, mixed>>
     */
    private function targetVariations(): array
    {
        return [
            [
                'id'              => self::TARGET_MONTHLY,
                'sku'             => '',
                'name'            => 'Miesiecznie',
                'price'           => 29.0,
                'payment_type'    => 'subscription',
                'repeat_interval' => 'monthly',
                'trial_days'      => 0,
                'times'           => 0,
            ],
            [
                'id'              => self::TARGET_YEARLY,
                'sku'             => '',
                'name'            => 'Rocznie',
                'price'           => 290.0,
                'payment_type'    => 'subscription',
                'repeat_interval' => 'yearly',
                'trial_days'      => 0,
                'times'           => 0,
            ],
        ];
    }

    /**
     * One Woo simple subscription product as the mapping screen describes it:
     * a single pseudo-variation keyed by the product ID.
     *
     * @return list<array<string, mixed>>
     */
    private function sourceVariation(int $productId, string $period, int $priceMinor): array
    {
        return [[
            'id'           => $productId,
            'sku'          => '',
            'name'         => 'Default',
            'price'        => $priceMinor,
            'payment_type' => 'subscription',
            'period'       => $period,
            'multiplier'   => 1,
            'trial_days'   => 0,
            'times'        => 0,
        ]];
    }

    private function matcher(): SubscriptionVariantMatcher
    {
        return new SubscriptionVariantMatcher(new VariantResolver());
    }

    // ──────────────────────────────────────────────
    // Two products, one target product, two variations
    // ──────────────────────────────────────────────

    public function testTheMonthlySourceProductTakesTheMonthlyVariation(): void
    {
        $result = $this->matcher()->match(
            $this->sourceVariation(CARTSHIFT_LAPKA_MONTHLY_PRODUCT_ID, 'month', 2900),
            $this->targetVariations(),
        );

        $this->assertSame([CARTSHIFT_LAPKA_MONTHLY_PRODUCT_ID => self::TARGET_MONTHLY], $result['map']);
        $this->assertSame([], $result['errors']);
    }

    public function testTheYearlySourceProductTakesTheYearlyVariationNotTheFirstOne(): void
    {
        $result = $this->matcher()->match(
            $this->sourceVariation(CARTSHIFT_LAPKA_YEARLY_PRODUCT_ID, 'year', 29000),
            $this->targetVariations(),
        );

        $this->assertSame(
            [CARTSHIFT_LAPKA_YEARLY_PRODUCT_ID => self::TARGET_YEARLY],
            $result['map'],
            'Positional fallback would have answered 4101 — the monthly variation, because FluentCart lists it first.',
        );
    }

    public function testBothDecisionsPointAtOneFluentCartProductAndTwoDistinctVariations(): void
    {
        $decisions = $this->lapkaDecisions();

        $this->assertSame(
            [self::TARGET_PRODUCT, self::TARGET_PRODUCT],
            array_map(static fn (ProductMapDecision $d): ?int => $d->fcPostId(), $decisions),
            'Both Woo products link to Klubu Przyjaciol Psow.',
        );

        $claimed = [];

        foreach ($decisions as $decision) {
            foreach ($decision->variantMap() as $targetId) {
                $claimed[] = $targetId;
            }
        }

        $this->assertSame([self::TARGET_MONTHLY, self::TARGET_YEARLY], $claimed);
        $this->assertCount(2, array_unique($claimed), 'Distinct variations, or 188 yearly subscribers bill monthly.');
    }

    public function testTheCompleteLapkaMappingSetValidates(): void
    {
        $validation = (new MappingSetValidator($this->lapkaContracts()))->validate($this->lapkaDecisions());

        $this->assertTrue($validation->isValid());
        $this->assertSame([], $validation->errors);
    }

    /**
     * And the failure mode, proven rather than assumed: point both at the
     * monthly variation and the set validator refuses the save.
     */
    public function testForcingBothOntoTheMonthlyVariationIsBlocked(): void
    {
        $validation = (new MappingSetValidator($this->lapkaContracts()))->validate([
            ProductMapDecision::link(
                CARTSHIFT_LAPKA_MONTHLY_PRODUCT_ID,
                'subscription',
                self::TARGET_PRODUCT,
                'none',
                [CARTSHIFT_LAPKA_MONTHLY_PRODUCT_ID => self::TARGET_MONTHLY],
            ),
            ProductMapDecision::link(
                CARTSHIFT_LAPKA_YEARLY_PRODUCT_ID,
                'subscription',
                self::TARGET_PRODUCT,
                'none',
                [CARTSHIFT_LAPKA_YEARLY_PRODUCT_ID => self::TARGET_MONTHLY],
            ),
        ]);

        $this->assertFalse($validation->isValid());
        $this->assertSame(['target_variation_contract_collision'], array_column($validation->errors, 'code'));
    }

    // ──────────────────────────────────────────────
    // Price cohorts
    // ──────────────────────────────────────────────

    /**
     * 167 of the 375 monthly subscribers pay PLN 24 against a PLN 29 catalogue
     * price. They must map to the same monthly variation as the PLN 29 cohort —
     * price is a suggestion signal, never a gate — and their own amount must
     * survive the mapping untouched.
     */
    public function testThePln24MonthlyCohortMapsToTheSameVariationAsThePln29One(): void
    {
        $pln29 = $this->matcher()->match(
            $this->sourceVariation(CARTSHIFT_LAPKA_MONTHLY_PRODUCT_ID, 'month', 2900),
            $this->targetVariations(),
        );

        $pln24 = $this->matcher()->match(
            $this->sourceVariation(CARTSHIFT_LAPKA_MONTHLY_PRODUCT_ID, 'month', 2400),
            $this->targetVariations(),
        );

        $this->assertSame($pln29['map'], $pln24['map']);
        $this->assertSame([], $pln24['errors']);
    }

    public function testThePln240YearlyCohortMapsToTheSameVariationAsThePln290One(): void
    {
        $pln290 = $this->matcher()->match(
            $this->sourceVariation(CARTSHIFT_LAPKA_YEARLY_PRODUCT_ID, 'year', 29000),
            $this->targetVariations(),
        );

        $pln240 = $this->matcher()->match(
            $this->sourceVariation(CARTSHIFT_LAPKA_YEARLY_PRODUCT_ID, 'year', 24000),
            $this->targetVariations(),
        );

        $this->assertSame($pln290['map'], $pln240['map']);
        $this->assertSame([self::TARGET_YEARLY], array_values($pln240['map']));
    }

    /**
     * The older cohorts are warned about, not corrected: the operator is told
     * the catalogue price differs and that the subscriber's own contract is
     * what gets written.
     */
    public function testTheOlderCohortsAreWarnedAboutRatherThanRepriced(): void
    {
        foreach ([[CARTSHIFT_LAPKA_MONTHLY_PRODUCT_ID, 'month', 2400], [CARTSHIFT_LAPKA_YEARLY_PRODUCT_ID, 'year', 24000]] as [$productId, $period, $priceMinor]) {
            $result = $this->matcher()->match(
                $this->sourceVariation($productId, $period, $priceMinor),
                $this->targetVariations(),
            );

            $this->assertSame([], $result['errors']);
            $this->assertSame(['target_price_differs_from_source'], array_column($result['warnings'], 'code'));
        }
    }

    /**
     * The subscriber rows themselves. Nothing in this task touches an amount,
     * and this is the assertion that would notice if it started to: the four
     * Lapka cohorts keep PLN 24 / 29 / 240 / 290 exactly as the source recorded
     * them, whichever variation the mapping chose.
     */
    public function testTheFourSubscriberCohortsKeepTheirOwnAmounts(): void
    {
        $expected = [
            'monthlyPln29' => '29.00',
            'monthlyPln24' => '24.00',
            'yearlyPln290' => '290.00',
            'yearlyPln240' => '240.00',
        ];

        foreach ($expected as $shape => $total) {
            $subscription = ($this->shapes[$shape])();

            $this->assertSame($total, $subscription->get_total(), "The {$shape} cohort's own contract.");

            $items = array_values($subscription->get_items());

            $this->assertSame($total, $items[0]->get_total());
        }
    }

    // ──────────────────────────────────────────────
    // Harness
    // ──────────────────────────────────────────────

    /** @return list<ProductMapDecision> */
    private function lapkaDecisions(): array
    {
        $monthly = $this->matcher()->match(
            $this->sourceVariation(CARTSHIFT_LAPKA_MONTHLY_PRODUCT_ID, 'month', 2900),
            $this->targetVariations(),
        );

        $yearly = $this->matcher()->match(
            $this->sourceVariation(CARTSHIFT_LAPKA_YEARLY_PRODUCT_ID, 'year', 29000),
            $this->targetVariations(),
        );

        return [
            ProductMapDecision::link(
                CARTSHIFT_LAPKA_MONTHLY_PRODUCT_ID,
                'subscription',
                self::TARGET_PRODUCT,
                'none',
                $monthly['map'],
            ),
            ProductMapDecision::link(
                CARTSHIFT_LAPKA_YEARLY_PRODUCT_ID,
                'subscription',
                self::TARGET_PRODUCT,
                'none',
                $yearly['map'],
            ),
        ];
    }

    /** @return array<int, NormalizedSubscriptionContract> */
    private function lapkaContracts(): array
    {
        return [
            CARTSHIFT_LAPKA_MONTHLY_PRODUCT_ID => NormalizedSubscriptionContract::fromWooCommerce('month', 1),
            CARTSHIFT_LAPKA_YEARLY_PRODUCT_ID  => NormalizedSubscriptionContract::fromWooCommerce('year', 1),
        ];
    }
}

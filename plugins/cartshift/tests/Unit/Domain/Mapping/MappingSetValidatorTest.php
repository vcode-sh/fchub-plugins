<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\Domain\Mapping;

use CartShift\Domain\Mapping\MappingSetValidator;
use CartShift\Domain\Mapping\ProductMapDecision;
use CartShift\Domain\Subscription\NormalizedSubscriptionContract;
use CartShift\Tests\Unit\PluginTestCase;

/**
 * VariantResolver's `$claimed` array protects one product decision. Two Woo
 * products decided one after the other each get their own, so both can claim
 * the same FluentCart variation and neither call ever notices.
 *
 * On Lapka that is the monthly and the yearly source product both landing on
 * the monthly variation of `Klubu Przyjaciol Psow` — 188 yearly subscribers
 * rebilled every month, and nothing in the per-row validation says a word.
 */
final class MappingSetValidatorTest extends PluginTestCase
{
    private const int MONTHLY_SOURCE = 770_001;
    private const int YEARLY_SOURCE  = 770_002;

    private const int MONTHLY_TARGET = 4101;
    private const int YEARLY_TARGET  = 4102;

    /** @return array<int, NormalizedSubscriptionContract> */
    private function lapkaContracts(): array
    {
        return [
            self::MONTHLY_SOURCE => NormalizedSubscriptionContract::fromWooCommerce('month', 1),
            self::YEARLY_SOURCE  => NormalizedSubscriptionContract::fromWooCommerce('year', 1),
        ];
    }

    /** @param array<int, int> $variantMap */
    private function link(int $wcId, int $fcPostId, array $variantMap, bool $shared = false): ProductMapDecision
    {
        return ProductMapDecision::link($wcId, 'subscription', $fcPostId, 'none', $variantMap, [], $shared);
    }

    // ──────────────────────────────────────────────
    // The collision
    // ──────────────────────────────────────────────

    public function testTwoSourcesWithDifferentCadencesMayNotClaimOneTargetVariation(): void
    {
        $validation = (new MappingSetValidator($this->lapkaContracts()))->validate([
            $this->link(self::MONTHLY_SOURCE, 88, [self::MONTHLY_SOURCE => self::MONTHLY_TARGET]),
            $this->link(self::YEARLY_SOURCE, 88, [self::YEARLY_SOURCE => self::MONTHLY_TARGET]),
        ]);

        $this->assertFalse($validation->isValid());
        $this->assertSame(['target_variation_contract_collision'], array_column($validation->errors, 'code'));
        $this->assertSame(self::MONTHLY_TARGET, $validation->errors[0]['target_variation_id']);
    }

    /**
     * The error names every source involved, because "one of your products
     * collides with another" is not something an operator can act on.
     */
    public function testTheCollisionListsEverySourceProductAndVariationInvolved(): void
    {
        $validation = (new MappingSetValidator($this->lapkaContracts()))->validate([
            $this->link(self::YEARLY_SOURCE, 88, [self::YEARLY_SOURCE => self::MONTHLY_TARGET]),
            $this->link(self::MONTHLY_SOURCE, 88, [self::MONTHLY_SOURCE => self::MONTHLY_TARGET]),
        ]);

        $this->assertSame(
            [
                ['wc_id' => self::MONTHLY_SOURCE, 'source_variation_id' => self::MONTHLY_SOURCE],
                ['wc_id' => self::YEARLY_SOURCE, 'source_variation_id' => self::YEARLY_SOURCE],
            ],
            $validation->errors[0]['sources'],
            'Sorted, so the same collision reads identically whichever order the decisions arrived in.',
        );
    }

    /**
     * The Lapka acceptance shape: same FluentCart product, different variations.
     */
    public function testTwoSourcesMayShareOneTargetProductWhenTheyTakeDifferentVariations(): void
    {
        $validation = (new MappingSetValidator($this->lapkaContracts()))->validate([
            $this->link(self::MONTHLY_SOURCE, 88, [self::MONTHLY_SOURCE => self::MONTHLY_TARGET]),
            $this->link(self::YEARLY_SOURCE, 88, [self::YEARLY_SOURCE => self::YEARLY_TARGET]),
        ]);

        $this->assertTrue($validation->isValid());
        $this->assertSame([], $validation->errors);
    }

    // ──────────────────────────────────────────────
    // Deliberate sharing
    // ──────────────────────────────────────────────

    /**
     * Two legacy monthly products converging on one variation is a real and
     * reasonable thing to want. It requires equal contracts AND both decisions
     * saying so.
     */
    public function testEquivalentContractsMayShareATargetWhenEveryDecisionOptsIn(): void
    {
        $contracts = [
            901 => NormalizedSubscriptionContract::fromWooCommerce('month', 1),
            902 => NormalizedSubscriptionContract::fromWooCommerce('month', 1),
        ];

        $validation = (new MappingSetValidator($contracts))->validate([
            $this->link(901, 88, [901 => self::MONTHLY_TARGET], shared: true),
            $this->link(902, 88, [902 => self::MONTHLY_TARGET], shared: true),
        ]);

        $this->assertTrue($validation->isValid());
    }

    public function testEquivalentContractsStillCollideWhenOnlyOneDecisionOptsIn(): void
    {
        $contracts = [
            901 => NormalizedSubscriptionContract::fromWooCommerce('month', 1),
            902 => NormalizedSubscriptionContract::fromWooCommerce('month', 1),
        ];

        $validation = (new MappingSetValidator($contracts))->validate([
            $this->link(901, 88, [901 => self::MONTHLY_TARGET], shared: true),
            $this->link(902, 88, [902 => self::MONTHLY_TARGET]),
        ]);

        $this->assertFalse($validation->isValid());
        $this->assertSame(['target_variation_contract_collision'], array_column($validation->errors, 'code'));
    }

    /**
     * Opting in is not a licence to merge two different contracts. Price may
     * differ; cadence, trial and term may not.
     */
    public function testOptingInDoesNotLetDifferentCadencesShareATarget(): void
    {
        $validation = (new MappingSetValidator($this->lapkaContracts()))->validate([
            $this->link(self::MONTHLY_SOURCE, 88, [self::MONTHLY_SOURCE => self::MONTHLY_TARGET], shared: true),
            $this->link(self::YEARLY_SOURCE, 88, [self::YEARLY_SOURCE => self::MONTHLY_TARGET], shared: true),
        ]);

        $this->assertFalse($validation->isValid());
    }

    public function testOptingInDoesNotLetDifferentTrialsShareATarget(): void
    {
        $contracts = [
            901 => NormalizedSubscriptionContract::fromWooCommerce('month', 1),
            902 => NormalizedSubscriptionContract::fromWooCommerce('month', 1, trialDays: 14),
        ];

        $validation = (new MappingSetValidator($contracts))->validate([
            $this->link(901, 88, [901 => self::MONTHLY_TARGET], shared: true),
            $this->link(902, 88, [902 => self::MONTHLY_TARGET], shared: true),
        ]);

        $this->assertFalse($validation->isValid());
    }

    public function testOptingInDoesNotLetDifferentFiniteTermsShareATarget(): void
    {
        $contracts = [
            901 => NormalizedSubscriptionContract::fromWooCommerce('month', 1),
            902 => NormalizedSubscriptionContract::fromWooCommerce('month', 1, finiteCycles: 12),
        ];

        $validation = (new MappingSetValidator($contracts))->validate([
            $this->link(901, 88, [901 => self::MONTHLY_TARGET], shared: true),
            $this->link(902, 88, [902 => self::MONTHLY_TARGET], shared: true),
        ]);

        $this->assertFalse($validation->isValid());
    }

    // Price is not part of the sharing key, and there is deliberately no test
    // for that here: neither ProductMapDecision nor this validator has any
    // notion of price, so a test at this level could only restate the opt-in
    // case above under a misleading name. Price non-gating is proven where
    // price actually exists — SubscriptionVariantMatcherTest
    // ::testAPriceDifferenceIsAWarningAndNeverAGate, and the two cohort tests
    // in LapkaSubscriptionMappingTest.

    // ──────────────────────────────────────────────
    // One-time products keep their behaviour
    // ──────────────────────────────────────────────

    /**
     * No contract on either side means neither is a subscription, and two
     * one-time products landing on one variation is what CartShift 1.4.x has
     * always done. Blocking it here would regress ordinary product mapping to
     * fix a subscription defect.
     */
    public function testTwoOneTimeSourcesSharingATargetIsNotACollision(): void
    {
        $validation = (new MappingSetValidator())->validate([
            ProductMapDecision::link(11, 'simple', 88, 'strong', [11 => 501]),
            ProductMapDecision::link(12, 'simple', 88, 'strong', [12 => 501]),
        ]);

        $this->assertTrue($validation->isValid());
    }

    /**
     * Mixing them is a collision, though: a one-time product and a subscription
     * cannot be the same entitlement.
     */
    public function testAOneTimeSourceCollidesWithASubscriptionSourceOnOneTarget(): void
    {
        $validation = (new MappingSetValidator([
            self::MONTHLY_SOURCE => NormalizedSubscriptionContract::fromWooCommerce('month', 1),
        ]))->validate([
            $this->link(self::MONTHLY_SOURCE, 88, [self::MONTHLY_SOURCE => self::MONTHLY_TARGET]),
            ProductMapDecision::link(12, 'simple', 88, 'strong', [12 => self::MONTHLY_TARGET]),
        ]);

        $this->assertFalse($validation->isValid());
    }

    // ──────────────────────────────────────────────
    // A source nobody can read
    // ──────────────────────────────────────────────
    //
    // `wc_get_product()` returning nothing used to produce no contract, which
    // this validator read as the one-time key — so two subscription decisions
    // whose products had been deleted keyed identically as `onetime`, hit the
    // all-one-time pass, and a monthly/yearly collision validated clean. The
    // plan's inference policy is explicit: an unresolved load-bearing fact gets
    // a named branch, never a cheerful default.

    public function testTwoUnreadableSourcesMayNotQuietlyShareATarget(): void
    {
        $validation = (new MappingSetValidator([], [self::MONTHLY_SOURCE, self::YEARLY_SOURCE]))->validate([
            $this->link(self::MONTHLY_SOURCE, 88, [self::MONTHLY_SOURCE => self::MONTHLY_TARGET]),
            $this->link(self::YEARLY_SOURCE, 88, [self::YEARLY_SOURCE => self::MONTHLY_TARGET]),
        ]);

        $this->assertFalse($validation->isValid());
        $this->assertSame(['target_variation_contract_collision'], array_column($validation->errors, 'code'));
    }

    /**
     * Nor may an unreadable source pass itself off as a one-time product, which
     * is exactly the substitution the old null did.
     */
    public function testAnUnreadableSourceNeverKeysAsOneTime(): void
    {
        $validation = (new MappingSetValidator([], [self::MONTHLY_SOURCE]))->validate([
            $this->link(self::MONTHLY_SOURCE, 88, [self::MONTHLY_SOURCE => self::MONTHLY_TARGET]),
            ProductMapDecision::link(12, 'simple', 88, 'strong', [12 => self::MONTHLY_TARGET]),
        ]);

        $this->assertFalse($validation->isValid());
    }

    /**
     * And opting in cannot approve it either. `allow_shared_target` is the
     * operator saying two contracts are equivalent; nobody can say that about
     * two contracts nobody can read.
     */
    public function testOptingInCannotApproveContractsNobodyCanRead(): void
    {
        $validation = (new MappingSetValidator([], [901, 902]))->validate([
            $this->link(901, 88, [901 => self::MONTHLY_TARGET], shared: true),
            $this->link(902, 88, [902 => self::MONTHLY_TARGET], shared: true),
        ]);

        $this->assertFalse($validation->isValid());
    }

    /**
     * A single claim by an unreadable source is still fine. It is not a
     * contract anyone can compare, and there is nothing to compare it with —
     * the per-row gate is where an unreadable source is refused on save.
     */
    public function testASingleClaimByAnUnreadableSourceStillPasses(): void
    {
        $validation = (new MappingSetValidator([], [self::MONTHLY_SOURCE]))->validate([
            $this->link(self::MONTHLY_SOURCE, 88, [self::MONTHLY_SOURCE => self::MONTHLY_TARGET]),
        ]);

        $this->assertTrue($validation->isValid());
    }

    public function testCreateAndSkipDecisionsClaimNothing(): void
    {
        $validation = (new MappingSetValidator($this->lapkaContracts()))->validate([
            ProductMapDecision::create(self::MONTHLY_SOURCE, 'subscription', 'none'),
            ProductMapDecision::skip(self::YEARLY_SOURCE, 'subscription', 'none'),
        ]);

        $this->assertTrue($validation->isValid());
    }

    public function testAnEmptySetIsValid(): void
    {
        $this->assertTrue((new MappingSetValidator())->validate([])->isValid());
    }

    // ──────────────────────────────────────────────
    // Fingerprint
    // ──────────────────────────────────────────────

    public function testTheFingerprintIsASha256Hex(): void
    {
        $validation = (new MappingSetValidator($this->lapkaContracts()))->validate([
            $this->link(self::MONTHLY_SOURCE, 88, [self::MONTHLY_SOURCE => self::MONTHLY_TARGET]),
        ]);

        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $validation->fingerprint());
    }

    /**
     * Tasks 10 and 11 persist this into stage and cutover receipts, and a
     * receipt transition revalidates against it. A hash that depended on the
     * order the decisions happened to be read in would invalidate a perfectly
     * good approval every time the table was re-sorted.
     */
    public function testTheFingerprintIsIndependentOfDecisionOrder(): void
    {
        $a = $this->link(self::MONTHLY_SOURCE, 88, [self::MONTHLY_SOURCE => self::MONTHLY_TARGET]);
        $b = $this->link(self::YEARLY_SOURCE, 88, [self::YEARLY_SOURCE => self::YEARLY_TARGET]);

        $validator = new MappingSetValidator($this->lapkaContracts());

        $this->assertSame(
            $validator->validate([$a, $b])->fingerprint(),
            $validator->validate([$b, $a])->fingerprint(),
        );
    }

    public function testTheFingerprintChangesWhenAVariationChoiceChanges(): void
    {
        $validator = new MappingSetValidator($this->lapkaContracts());

        $before = $validator->validate([
            $this->link(self::YEARLY_SOURCE, 88, [self::YEARLY_SOURCE => self::YEARLY_TARGET]),
        ])->fingerprint();

        $after = $validator->validate([
            $this->link(self::YEARLY_SOURCE, 88, [self::YEARLY_SOURCE => self::MONTHLY_TARGET]),
        ])->fingerprint();

        $this->assertNotSame($before, $after);
    }

    public function testTheFingerprintChangesWhenSharingIsToggled(): void
    {
        $validator = new MappingSetValidator($this->lapkaContracts());

        $this->assertNotSame(
            $validator->validate([
                $this->link(self::MONTHLY_SOURCE, 88, [self::MONTHLY_SOURCE => self::MONTHLY_TARGET]),
            ])->fingerprint(),
            $validator->validate([
                $this->link(self::MONTHLY_SOURCE, 88, [self::MONTHLY_SOURCE => self::MONTHLY_TARGET], shared: true),
            ])->fingerprint(),
        );
    }

    /**
     * Band is a suggestion score recomputed from the catalogue every time the
     * screen loads. A re-scored catalogue must not invalidate an operator's
     * approved mapping — the same reasoning RuntimeCompatibilityReport applies
     * to plugin versions.
     */
    public function testTheFingerprintIgnoresTheSuggestionBand(): void
    {
        $validator = new MappingSetValidator($this->lapkaContracts());

        $this->assertSame(
            $validator->validate([
                ProductMapDecision::link(self::MONTHLY_SOURCE, 'subscription', 88, 'none', [self::MONTHLY_SOURCE => self::MONTHLY_TARGET]),
            ])->fingerprint(),
            $validator->validate([
                ProductMapDecision::link(self::MONTHLY_SOURCE, 'subscription', 88, 'strong', [self::MONTHLY_SOURCE => self::MONTHLY_TARGET]),
            ])->fingerprint(),
        );
    }

    public function testTheValidationSerialisesToAStableShape(): void
    {
        $validation = (new MappingSetValidator($this->lapkaContracts()))->validate([
            $this->link(self::MONTHLY_SOURCE, 88, [self::MONTHLY_SOURCE => self::MONTHLY_TARGET]),
        ]);

        $this->assertSame(
            ['errors', 'fingerprint', 'valid'],
            array_keys($validation->toArray()),
            'Sorted keys, so two identical validations serialise byte for byte.',
        );
    }
}

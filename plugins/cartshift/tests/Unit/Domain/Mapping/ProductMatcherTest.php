<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\Domain\Mapping;

use CartShift\Domain\Mapping\ProductMatcher;
use CartShift\Tests\Unit\PluginTestCase;

/**
 * SKU-first matching was rejected in design because most WooCommerce shops
 * leave SKUs blank, which turns a SKU-keyed matcher into a screen of 300 rows
 * saying "no candidate". Title similarity therefore carries the score and SKU
 * is a bonus. testABlankSkuCatalogueStillMatches is the test that pins that
 * decision — if it ever goes green by accident, the design has drifted back.
 */
final class ProductMatcherTest extends PluginTestCase
{
    private function woo(string $name, string $sku = '', float $price = 10.0, int $variations = 1): array
    {
        return ['name' => $name, 'sku' => $sku, 'price' => $price, 'variation_count' => $variations];
    }

    private function candidate(int $id, string $name, string $sku = '', float $price = 10.0, int $variations = 1): array
    {
        return ['id' => $id, 'name' => $name, 'sku' => $sku, 'price' => $price, 'variation_count' => $variations];
    }

    public function testAnIdenticalSkuIsStrong(): void
    {
        $result = (new ProductMatcher())->match(
            $this->woo('Pro Licence Annual', 'PRO-1'),
            [$this->candidate(900, 'Pro Licence', 'PRO-1')],
        );

        $this->assertSame(ProductMatcher::BAND_STRONG, $result['band']);
        $this->assertSame(900, $result['candidate_id']);
    }

    public function testAnIdenticalTitleAndPriceIsStrongWithoutAnySku(): void
    {
        $result = (new ProductMatcher())->match(
            $this->woo('Blue Hoodie', '', 49.0),
            [$this->candidate(901, 'Blue Hoodie', '', 49.0)],
        );

        $this->assertSame(ProductMatcher::BAND_STRONG, $result['band']);
        $this->assertSame(901, $result['candidate_id']);
    }

    public function testABlankSkuCatalogueStillMatches(): void
    {
        $result = (new ProductMatcher())->match(
            $this->woo('Classic T-Shirt', '', 19.0),
            [$this->candidate(902, 'Classic T Shirt', '', 25.0)],
        );

        $this->assertNotSame(
            ProductMatcher::BAND_NONE,
            $result['band'],
            'A blank-SKU shop is the common case; it must not degrade to no candidate.',
        );
        $this->assertSame(902, $result['candidate_id']);
    }

    public function testAnUnrelatedNameIsNoCandidate(): void
    {
        $result = (new ProductMatcher())->match(
            $this->woo('Gift Card'),
            [$this->candidate(903, 'Enterprise Support Retainer')],
        );

        $this->assertSame(ProductMatcher::BAND_NONE, $result['band']);
        $this->assertNull($result['candidate_id']);
    }

    public function testAnEmptyCandidateListIsNoCandidate(): void
    {
        $result = (new ProductMatcher())->match($this->woo('Anything'), []);

        $this->assertSame(ProductMatcher::BAND_NONE, $result['band']);
        $this->assertNull($result['candidate_id']);
        $this->assertSame([], $result['ranked']);
    }

    /**
     * Renamed from testTheBestCandidateWinsAndRankingIsDescending: raw score
     * descending is no longer the contract. Ranking (and therefore selection)
     * is ordered by band precedence first and score only as a tiebreak within
     * the same band — see testASkuVerifiedStrongCandidateOutranksAHigherScoringLikelyOne
     * for why score alone is not safe to sort by.
     */
    public function testTheBestCandidateWinsAndRankingIsOrderedByBandThenScore(): void
    {
        $result = (new ProductMatcher())->match(
            $this->woo('Blue Hoodie', '', 49.0),
            [
                $this->candidate(910, 'Red Hoodie', '', 49.0),
                $this->candidate(911, 'Blue Hoodie', '', 49.0),
                $this->candidate(912, 'Hoodie', '', 49.0),
            ],
        );

        $this->assertSame(911, $result['candidate_id']);
        $this->assertCount(3, $result['ranked']);
        $this->assertSame(
            $result['candidate_id'],
            $result['ranked'][0]['id'],
            'ranked[0] must always be the same candidate the top-level band/candidate_id describe.',
        );
        $this->assertRankedIsOrderedByBandThenScore($result['ranked']);
    }

    /**
     * The finding this fix round exists for: 601 is SKU-verified and STRONG on
     * its own signals (title alone clears the strong-floor once paired with the
     * SKU match). 602 has no SKU but a closer title and matching price and
     * variation count, so its raw additive score is higher than 601's. A
     * selector that picks by raw score alone hands the owner the unverified,
     * lower-trust candidate labelled "likely" while the verified STRONG match
     * sits unlabelled in ranked[1] — exactly backwards for a trust tier that
     * drives the mapping screen's bulk "link all N in this band" actions.
     *
     * The names moved when TITLE_STRONG_FLOOR rose from 0.50 to 0.55: the
     * original fixture's 601 title scored 0.5455, which cleared the old floor
     * and no longer clears the new one, so it stopped testing band precedence
     * and started testing the floor. Same shape, numbers picked to sit clear of
     * the boundary — 601 at 0.69 (STRONG via SKU, raw 1.04), 602 at 0.87
     * (LIKELY, raw 1.07).
     */
    public function testASkuVerifiedStrongCandidateOutranksAHigherScoringLikelyOne(): void
    {
        $result = (new ProductMatcher())->match(
            $this->woo('Bar Stool Oak', 'BS-OAK-01', 89.0, 2),
            [
                $this->candidate(601, 'Oak Bar Stool', 'BS-OAK-01', 129.0, 1),
                $this->candidate(602, 'Bar Stool Oak Set', '', 89.0, 2),
            ],
        );

        $this->assertSame(
            ProductMatcher::BAND_STRONG,
            $result['band'],
            'The SKU-verified candidate is STRONG on its own signals and must not be demoted by losing a raw-score contest.',
        );
        $this->assertSame(
            601,
            $result['candidate_id'],
            'A STRONG, SKU-verified candidate must outrank a candidate that only scores higher because its title happens to be closer.',
        );
    }

    public function testAnEmptySkuOnBothSidesIsNotTreatedAsAMatch(): void
    {
        $blank = (new ProductMatcher())->match(
            $this->woo('Widget A', ''),
            [$this->candidate(920, 'Widget B', '')],
        );

        $matched = (new ProductMatcher())->match(
            $this->woo('Widget A', 'W-A'),
            [$this->candidate(921, 'Widget B', 'W-A')],
        );

        $this->assertLessThan(
            $matched['score'],
            $blank['score'],
            'Two blank SKUs are an absence of evidence, not evidence of identity.',
        );
    }

    public function testCaseAndPunctuationDoNotBlockAMatch(): void
    {
        $result = (new ProductMatcher())->match(
            $this->woo('PRO LICENCE — ANNUAL!', '', 99.0),
            [$this->candidate(930, 'pro licence annual', '', 99.0)],
        );

        $this->assertSame(ProductMatcher::BAND_STRONG, $result['band']);
    }

    // ──────────────────────────────────────────────
    // Polish, which is the catalogue this matcher actually has to work on
    // ──────────────────────────────────────────────

    /**
     * The measurement that started this: similar_text() compares bytes, so
     * before folding, `Żółć gęślą jaźń` against `Zolc gesla jazn` scored 0.00
     * through normalizeTitle() and 0.308 raw. Folded, it is the same string.
     *
     * Price and variation count are deliberately mismatched so the score is the
     * title similarity and nothing else — 1.0 here is a statement about the
     * fold, not about the bonuses.
     */
    public function testAccentsAreTheOnlyDifferenceAndScoreOne(): void
    {
        foreach (
            [
                ['Żółć gęślą jaźń', 'Zolc gesla jazn'],
                ['Żółty kabel USB', 'Zolty kabel USB'],
                ['Stacja dokująca USB-C', 'Stacja dokujaca USB-C'],
                ['Ręcznik plażowy duży', 'Recznik plazowy duzy'],
                ['Zestaw śrubokrętów precyzyjnych', 'Zestaw srubokretow precyzyjnych'],
            ] as [$wooName, $fcName]
        ) {
            $result = (new ProductMatcher())->match(
                $this->woo($wooName, '', 10.0, 1),
                [$this->candidate(940, $fcName, '', 99.0, 3)],
            );

            $this->assertSame(
                1.0,
                $result['score'],
                "'{$wooName}' and '{$fcName}' differ only in accents and must fold to the same string.",
            );
        }
    }

    /**
     * The whole point, stated as an outcome rather than a number: an accented
     * Woo name and its unaccented FluentCart twin at the same price is the
     * strongest evidence the matcher has, and before folding it was not even a
     * candidate.
     */
    public function testAnAccentedNameMatchesItsUnaccentedTwinStrongly(): void
    {
        $result = (new ProductMatcher())->match(
            $this->woo('Stacja dokująca USB-C 12w1', '', 249.0),
            [$this->candidate(941, 'Stacja dokujaca USB-C 12w1', '', 249.0)],
        );

        $this->assertSame(ProductMatcher::BAND_STRONG, $result['band']);
        $this->assertSame(941, $result['candidate_id']);
    }

    /**
     * The two guesses the owner actually saw on the mapping screen, both real
     * pairs from the reference install's 160-pair sweep, both scoring above the
     * old 0.40 floor and below the measured 0.55 one. Neither product exists in
     * both catalogues, so `weak` was never a hedge — it was wrong.
     */
    public function testTheTwoMeasuredFalsePositivesAreNoLongerCandidates(): void
    {
        foreach (
            [
                ['Kabel USB-C Premium', 'name your price demo'],          // 0.46, the sweep's ceiling
                ['Koszulka WordPress Developer', 'name your price demo'], // 0.42
                ['Ręcznik plażowy duży', 'payment plan product'],         // 0.45, folded corpus
                ['Żółć gęślą jaźń', 'Fleece Jacket'],                     // 0.43, folded corpus
            ] as [$wooName, $fcName]
        ) {
            $result = (new ProductMatcher())->match(
                $this->woo($wooName, '', 19.0),
                [$this->candidate(950, $fcName, '', 25.0)],
            );

            $this->assertSame(
                ProductMatcher::BAND_NONE,
                $result['band'],
                "'{$wooName}' -> '{$fcName}' is a measured false positive and must not be offered.",
            );
            $this->assertNull($result['candidate_id']);
        }
    }

    /**
     * The gap has two sides, and a floor that only refuses things is easy to
     * get right by refusing everything. The measured true-pair floor was 0.81,
     * so a genuine pair whose wording differs as well as its accents still has
     * to survive.
     */
    public function testAGenuinePairWithDifferentWordingStillSurvivesTheHigherFloor(): void
    {
        $result = (new ProductMatcher())->match(
            $this->woo('Hosting WordPress Premium (WKRÓTCE)', '', 99.0),
            [$this->candidate(951, 'Hosting WordPress Premium WKROTCE', '', 149.0)],
        );

        $this->assertNotSame(
            ProductMatcher::BAND_NONE,
            $result['band'],
            'A real pair differing in punctuation and accents must clear the 0.55 floor comfortably.',
        );
        $this->assertSame(951, $result['candidate_id']);
    }

    /**
     * Asserts the real ranking contract: non-increasing by (band precedence,
     * score), band strictly dominant. A run of `weak` entries may never sit
     * ahead of a `strong` one regardless of score, and score only decides
     * order between two entries that share a band.
     *
     * @param list<array{id: int, band: string, score: float}> $ranked
     */
    private function assertRankedIsOrderedByBandThenScore(array $ranked): void
    {
        $precedence = [
            ProductMatcher::BAND_STRONG => 0,
            ProductMatcher::BAND_LIKELY => 1,
            ProductMatcher::BAND_WEAK   => 2,
            ProductMatcher::BAND_NONE   => 3,
        ];

        for ($i = 1; $i < count($ranked); $i++) {
            $previous = $ranked[$i - 1];
            $current  = $ranked[$i];

            $this->assertGreaterThanOrEqual(
                $precedence[$previous['band']],
                $precedence[$current['band']],
                "ranked[{$i}] (band={$current['band']}) must not outrank ranked[" . ($i - 1) . "] (band={$previous['band']}).",
            );

            if ($precedence[$previous['band']] === $precedence[$current['band']]) {
                $this->assertGreaterThanOrEqual(
                    $current['score'],
                    $previous['score'],
                    "Within the same band, ranked[" . ($i - 1) . "]['score'] must not be less than ranked[{$i}]['score'].",
                );
            }
        }
    }
}

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
     * The finding this fix round exists for, pinned with the reviewer's exact
     * fixture: 601 is SKU-verified and STRONG on its own signals (title alone
     * clears the strong-floor once paired with the SKU match). 602 has no SKU
     * but a closer title, so its raw additive score is higher than 601's. A
     * selector that picks by raw score alone hands the owner the unverified,
     * lower-trust candidate labelled "likely" while the verified STRONG match
     * sits unlabelled in ranked[1] — exactly backwards for a trust tier that
     * drives the mapping screen's bulk "link all N in this band" actions.
     */
    public function testASkuVerifiedStrongCandidateOutranksAHigherScoringLikelyOne(): void
    {
        $result = (new ProductMatcher())->match(
            $this->woo('Bar Stool Oak Finish', 'BS-OAK-01', 89.0, 2),
            [
                $this->candidate(601, 'Oak Bar Stool', 'BS-OAK-01', 129.0, 1),
                $this->candidate(602, 'Bar Stool Oak Finish Set', '', 129.0, 1),
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

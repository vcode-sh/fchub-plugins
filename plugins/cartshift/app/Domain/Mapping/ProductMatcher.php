<?php

declare(strict_types=1);

namespace CartShift\Domain\Mapping;

defined('ABSPATH') || exit;

/**
 * Ranks FluentCart candidates for one WooCommerce product.
 *
 * A suggestion engine, never a decision engine. Nothing here commits a link —
 * the owner does, on the mapping screen, which is why there is no "auto-accept
 * above N" threshold anywhere in this class.
 *
 * Title similarity carries the score and SKU is a bonus, not a gate. That is
 * the opposite of the obvious design and it is deliberate: real WooCommerce
 * shops mostly leave SKUs blank, so a SKU-keyed matcher reports "no candidate"
 * for an entire catalogue and hands the owner 300 manual searches.
 *
 * Selection is band-first, score-second — never raw score alone. SKU_BONUS is
 * smaller than plausible title-similarity gaps, so a pure-score contest can
 * let a blank-SKU candidate with a slightly closer title outscore a candidate
 * that is SKU-verified and STRONG on its own. Bands are trust tiers that
 * drive the mapping screen's bulk "link all N in this band" actions, so the
 * headline pick must never understate confidence: the best-band candidate
 * always wins, and raw score only breaks ties within the same band.
 */
final class ProductMatcher
{
    public const string BAND_STRONG = 'strong';
    public const string BAND_LIKELY = 'likely';
    public const string BAND_WEAK   = 'weak';
    public const string BAND_NONE   = 'none';

    private const float SKU_BONUS       = 0.35;
    private const float PRICE_BONUS     = 0.15;
    private const float VARIATION_BONUS = 0.05;

    private const float TITLE_NEAR_IDENTICAL = 0.95;
    private const float TITLE_STRONG_FLOOR   = 0.50;
    private const float TITLE_LIKELY         = 0.70;
    private const float TITLE_WEAK           = 0.40;

    /**
     * @param array{name: string, sku: string, price: float, variation_count: int}               $woo
     * @param list<array{id: int, name: string, sku: string, price: float, variation_count: int}> $candidates
     *
     * @return array{band: string, candidate_id: int|null, score: float, ranked: list<array{id: int, band: string, score: float}>}
     */
    public function match(array $woo, array $candidates): array
    {
        if ($candidates === []) {
            return ['band' => self::BAND_NONE, 'candidate_id' => null, 'score' => 0.0, 'ranked' => []];
        }

        $ranked = [];

        foreach ($candidates as $candidate) {
            $titleSimilarity = self::titleSimilarity($woo['name'], $candidate['name']);
            $skuEqual        = self::skuEqual($woo['sku'], $candidate['sku']);
            $priceEqual      = self::priceEqual($woo['price'], $candidate['price']);
            $variationEqual  = $woo['variation_count'] === $candidate['variation_count'];

            $score = $titleSimilarity
                + ($skuEqual ? self::SKU_BONUS : 0.0)
                + ($priceEqual ? self::PRICE_BONUS : 0.0)
                + ($variationEqual ? self::VARIATION_BONUS : 0.0);

            // Signals computed once per candidate and fed straight into band() —
            // selection needs every candidate's own band, not just the eventual
            // winner's, so this can no longer be deferred to a single post-hoc call.
            $ranked[] = [
                'id'    => (int) $candidate['id'],
                'band'  => self::band($titleSimilarity, $skuEqual, $priceEqual),
                'score' => round($score, 4),
            ];
        }

        // Band first, score only as a tiebreak within the same band. This is the
        // whole fix: sorting by score alone can seat a blank-SKU LIKELY candidate
        // ahead of a SKU-verified STRONG one. See the class docblock.
        usort(
            $ranked,
            static fn (array $a, array $b): int => self::bandPrecedence($a['band']) <=> self::bandPrecedence($b['band'])
                ?: $b['score'] <=> $a['score'],
        );

        $winner = $ranked[0];

        return [
            'band'         => $winner['band'],
            'candidate_id' => $winner['band'] === self::BAND_NONE ? null : $winner['id'],
            'score'        => $winner['score'],
            'ranked'       => $ranked,
        ];
    }

    /**
     * Sort key for a band, strongest first. Internal only — never surfaced.
     */
    private static function bandPrecedence(string $band): int
    {
        return match ($band) {
            self::BAND_STRONG => 0,
            self::BAND_LIKELY => 1,
            self::BAND_WEAK   => 2,
            self::BAND_NONE   => 3,
            default           => 3,
        };
    }

    /**
     * The band for one candidate's own signals.
     *
     * Callable per candidate, not only on the eventual winner — selection has
     * to know every candidate's band before it can tell which one should win.
     */
    private static function band(float $titleSimilarity, bool $skuEqual, bool $priceEqual): string
    {
        // A matching SKU is a deliberate act by whoever set it, but it is not on
        // its own enough — two products can share a lazily copied SKU. Pairing it
        // with a title floor costs nothing and rules that out.
        if ($skuEqual && $titleSimilarity >= self::TITLE_STRONG_FLOOR) {
            return self::BAND_STRONG;
        }

        if ($titleSimilarity >= self::TITLE_NEAR_IDENTICAL && $priceEqual) {
            return self::BAND_STRONG;
        }

        if ($titleSimilarity >= self::TITLE_LIKELY) {
            return self::BAND_LIKELY;
        }

        if ($titleSimilarity >= self::TITLE_WEAK) {
            return self::BAND_WEAK;
        }

        return self::BAND_NONE;
    }

    /**
     * Similarity of two product names, 0.0 to 1.0.
     *
     * Normalised first, because "PRO LICENCE — ANNUAL!" and "pro licence annual"
     * are the same product typed by two different people on two different days.
     */
    private static function titleSimilarity(string $a, string $b): float
    {
        $a = self::normalizeTitle($a);
        $b = self::normalizeTitle($b);

        if ($a === '' || $b === '') {
            return 0.0;
        }

        if ($a === $b) {
            return 1.0;
        }

        similar_text($a, $b, $percent);

        return round($percent / 100, 4);
    }

    /**
     * Lower-case, strip punctuation, collapse whitespace.
     *
     * Unicode-aware: an em dash and a hyphen are both separators, and a shop
     * whose product names carry accents must not be reduced to noise.
     */
    private static function normalizeTitle(string $title): string
    {
        $title = function_exists('mb_strtolower') ? mb_strtolower($title, 'UTF-8') : strtolower($title);

        $title = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $title) ?? '';

        return trim(preg_replace('/\s+/', ' ', $title) ?? '');
    }

    /**
     * Two SKUs match only when both are present.
     *
     * Blank equals blank is an absence of evidence, and treating it as identity
     * would hand every unSKU'd product in the shop a spurious strong match
     * against every other one.
     */
    private static function skuEqual(string $a, string $b): bool
    {
        $a = trim($a);
        $b = trim($b);

        return $a !== '' && $b !== '' && strcasecmp($a, $b) === 0;
    }

    private static function priceEqual(float $a, float $b): bool
    {
        return abs($a - $b) < 0.005;
    }
}

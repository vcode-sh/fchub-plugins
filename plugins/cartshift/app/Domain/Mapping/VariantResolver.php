<?php

declare(strict_types=1);

namespace CartShift\Domain\Mapping;

defined('ABSPATH') || exit;

/**
 * Pairs a Woo product's variations with a linked FluentCart product's variants.
 *
 * Three passes, strongest signal first, each FC variant claimable once:
 * SKU, then normalised name, then position. Anything left over is an orphan,
 * which the caller turns into a variant it adds to the FC product — visibly,
 * and flagged created_by_migration so rollback takes it back out.
 *
 * A Woo simple product arrives here as a single pseudo-variation keyed by the
 * product ID, because that is exactly how ProductMigrator stores its
 * ENTITY_VARIATION row for simple products.
 */
final class VariantResolver
{
    /**
     * @param list<array{id: int, sku: string, name: string}> $wooVariations Display order.
     * @param list<array{id: int, sku: string, name: string}> $fcVariants    Display order.
     *
     * @return array{map: array<int, int>, orphans: list<int>}
     */
    public function resolve(array $wooVariations, array $fcVariants): array
    {
        $map     = [];
        $claimed = [];

        $remaining = $wooVariations;

        $remaining = $this->pass(
            $remaining,
            $fcVariants,
            $map,
            $claimed,
            function (array $woo, array $fc): bool {
                $a = trim($woo['sku']);
                $b = trim($fc['sku']);

                // Both must be present. Blank equals blank is an absence of
                // evidence, and would pair the first two unSKU'd variants of
                // unrelated sizes.
                return $a !== '' && $b !== '' && strcasecmp($a, $b) === 0;
            },
        );

        $remaining = $this->pass(
            $remaining,
            $fcVariants,
            $map,
            $claimed,
            fn (array $woo, array $fc): bool
                => self::normalizeName($woo['name']) !== ''
                && self::normalizeName($woo['name']) === self::normalizeName($fc['name']),
        );

        // Position: the nth unclaimed FC variant for the nth unmatched Woo
        // variation, in the order the shop displays them.
        foreach ($remaining as $index => $woo) {
            $free = null;

            foreach ($fcVariants as $fc) {
                if (!isset($claimed[$fc['id']])) {
                    $free = $fc;
                    break;
                }
            }

            if ($free === null) {
                continue;
            }

            $map[$woo['id']]       = (int) $free['id'];
            $claimed[$free['id']]  = true;
            unset($remaining[$index]);
        }

        $orphans = array_values(array_map(
            static fn (array $woo): int => (int) $woo['id'],
            $remaining,
        ));

        ksort($map);

        return ['map' => $map, 'orphans' => $orphans];
    }

    /**
     * Run one matching pass, mutating $map and $claimed, returning what is left.
     *
     * @param list<array{id: int, sku: string, name: string}> $remaining
     * @param list<array{id: int, sku: string, name: string}> $fcVariants
     * @param array<int, int>                                 $map
     * @param array<int, bool>                                $claimed
     * @param callable(array, array): bool                    $matches
     *
     * @return array<int, array{id: int, sku: string, name: string}>
     */
    private function pass(
        array $remaining,
        array $fcVariants,
        array &$map,
        array &$claimed,
        callable $matches,
    ): array {
        foreach ($remaining as $index => $woo) {
            foreach ($fcVariants as $fc) {
                if (isset($claimed[$fc['id']]) || !$matches($woo, $fc)) {
                    continue;
                }

                $map[(int) $woo['id']] = (int) $fc['id'];
                $claimed[$fc['id']]    = true;
                unset($remaining[$index]);

                break;
            }
        }

        return $remaining;
    }

    /**
     * Fold accents, lower-case, strip punctuation, collapse whitespace.
     *
     * The same fix ProductMatcher::normalizeTitle() carries, and it matters
     * more here. Variant names are where a Polish shop's diacritics actually
     * live — `Biały`, `Żółty`, `Zielony` — and this comparison is exact, not
     * fuzzy: without folding, `Biały` simply is not `Bialy`, the name pass
     * matches nothing, and every variant silently falls through to the
     * position pass. Position-pairing an unordered pair of variants is the
     * "XL revenue on the L row" failure this class exists to prevent, and it
     * would have happened quietly, on the catalogues most likely to need it.
     *
     * remove_accents() is WordPress's own and transliterates rather than
     * drops. It also ends this class's purity, deliberately — see the note in
     * ProductMatcher::normalizeTitle().
     */
    private static function normalizeName(string $name): string
    {
        $name = remove_accents($name);

        $name = function_exists('mb_strtolower') ? mb_strtolower($name, 'UTF-8') : strtolower($name);

        $name = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $name) ?? '';

        return trim(preg_replace('/\s+/', ' ', $name) ?? '');
    }
}

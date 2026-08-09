<?php

declare(strict_types=1);

namespace CartShift\Domain\Mapping;

defined('ABSPATH') || exit;

use CartShift\Domain\Subscription\NormalizedSubscriptionContract;

/**
 * Validates every selected mapping decision together, not one at a time.
 *
 * `VariantResolver::$claimed` is per-`resolve()` call, and one call is one Woo
 * product. Two Woo products decided one after the other therefore each get a
 * fresh claim set, so both can name the same FluentCart variation and neither
 * call ever sees the other. Every per-row check passes; the mapping screen
 * reports two tidy links.
 *
 * On Lapka that is the monthly source product and the yearly one both landing
 * on the monthly variation of `Klubu Przyjaciol Psow` — 188 yearly subscribers
 * moved onto a monthly plan, discovered by the customers.
 *
 * So: a claim index by target variation ID, built across the whole set.
 *
 *   - one claim passes; the per-row contract gate already ran at match time;
 *   - several claims pass only when every claiming source has the same
 *     normalised cadence/trial/term key AND every decision involved explicitly
 *     stores `allow_shared_target=true`;
 *   - anything else is `target_variation_contract_collision`, naming every
 *     source product and variation involved.
 *
 * Historical price differences are not part of the key. Lapka's monthly cohort
 * is split PLN 29 / PLN 24 against one catalogue price, and both are the same
 * contract as far as choosing a variation goes.
 */
final class MappingSetValidator
{
    public const string ERROR_COLLISION = 'target_variation_contract_collision';

    /**
     * The key a claim with no subscription contract carries.
     *
     * Two one-time products converging on one FluentCart variation is what
     * CartShift 1.4.x has always done, and blocking it to fix a subscription
     * defect would break ordinary product mapping on every catalogue that has
     * no subscriptions at all. So claims that are all one-time pass; a mixed
     * pile does not, because a one-time purchase and a recurring contract are
     * not the same entitlement.
     */
    private const string ONE_TIME_KEY = 'onetime';

    /** @var array<int, true> Source variation IDs whose product could not be read. */
    private readonly array $unreadable;

    /**
     * @param array<int, NormalizedSubscriptionContract> $sourceContracts
     *        Source variation ID => its normalised contract. Only subscription
     *        source variations need an entry; anything absent and readable is
     *        one-time.
     * @param list<int> $unreadableSourceVariationIds
     *        Source variations whose WooCommerce product could not be loaded.
     *        These key uniquely and can never equal anything, including each
     *        other — see keyFor().
     */
    public function __construct(
        private readonly array $sourceContracts = [],
        array $unreadableSourceVariationIds = [],
    ) {
        $unreadable = [];

        foreach ($unreadableSourceVariationIds as $id) {
            $unreadable[(int) $id] = true;
        }

        $this->unreadable = $unreadable;
    }

    /**
     * The sharing key for one source variation.
     *
     * An unreadable source keys on its own ID, so it equals nothing — not the
     * one-time key, and not another unreadable source. That is deliberate and
     * it is the plan's inference policy rather than caution for its own sake:
     * a missing `wc_get_product()` used to produce a null contract, which this
     * validator read as `onetime`, so two subscription decisions whose products
     * had been deleted keyed identically, hit the all-one-time pass below, and
     * a monthly/yearly collision validated clean. `allow_shared_target` cannot
     * rescue them either — that flag is the operator asserting two contracts
     * are equivalent, and nobody can assert that about two contracts nobody can
     * read.
     */
    private function keyFor(int $sourceVariationId): string
    {
        if (isset($this->unreadable[$sourceVariationId])) {
            return 'unreadable:' . $sourceVariationId;
        }

        return isset($this->sourceContracts[$sourceVariationId])
            ? $this->sourceContracts[$sourceVariationId]->key()
            : self::ONE_TIME_KEY;
    }

    /**
     * @param list<ProductMapDecision> $decisions
     */
    public function validate(array $decisions): MappingSetValidation
    {
        $links = array_values(array_filter(
            $decisions,
            static fn (ProductMapDecision $decision): bool => $decision->isLink(),
        ));

        /** @var array<int, list<array{wc_id: int, source_variation_id: int, key: string, shared: bool}>> $claims */
        $claims = [];

        foreach ($links as $decision) {
            foreach ($decision->variantMap() as $sourceVariationId => $targetVariationId) {
                $claims[$targetVariationId][] = [
                    'wc_id'               => $decision->wcId(),
                    'source_variation_id' => (int) $sourceVariationId,
                    'key'                 => $this->keyFor((int) $sourceVariationId),
                    'shared'              => $decision->allowSharedTarget(),
                ];
            }
        }

        ksort($claims);

        $errors = [];

        foreach ($claims as $targetVariationId => $claimants) {
            if (count($claimants) < 2) {
                continue;
            }

            $keys = array_unique(array_column($claimants, 'key'));

            // All one-time: the pre-existing behaviour, deliberately kept.
            if ($keys === [self::ONE_TIME_KEY]) {
                continue;
            }

            // A single unreadable key is one key, but it is not evidence of
            // equivalence — it is the absence of evidence. Sharing needs a
            // contract somebody can read on every claimant.
            $equivalent = count($keys) === 1 && !str_starts_with((string) reset($keys), 'unreadable:');
            $allOptedIn = !in_array(false, array_column($claimants, 'shared'), true);

            if ($equivalent && $allOptedIn) {
                continue;
            }

            $errors[] = [
                'code'                => self::ERROR_COLLISION,
                'target_variation_id' => $targetVariationId,
                'sources'             => self::sortSources($claimants),
                'message'             => $equivalent
                    ? sprintf(
                        'Several products claim FluentCart variation %d. Their contracts match, so this is '
                        . 'allowed — but every one of them has to say so explicitly.',
                        $targetVariationId,
                    )
                    : sprintf(
                        'Several products claim FluentCart variation %d with different billing contracts. '
                        . 'Give each one its own variation.',
                        $targetVariationId,
                    ),
            ];
        }

        return new MappingSetValidation($errors, self::canonical($decisions));
    }

    /**
     * @param list<array{wc_id: int, source_variation_id: int, key: string, shared: bool}> $claimants
     * @return list<array{wc_id: int, source_variation_id: int}>
     */
    private static function sortSources(array $claimants): array
    {
        $sources = array_map(
            static fn (array $claim): array => [
                'wc_id'               => $claim['wc_id'],
                'source_variation_id' => $claim['source_variation_id'],
            ],
            $claimants,
        );

        usort(
            $sources,
            static fn (array $a, array $b): int
                => [$a['wc_id'], $a['source_variation_id']] <=> [$b['wc_id'], $b['source_variation_id']],
        );

        return array_values($sources);
    }

    /**
     * The decision set reduced to what actually decides the outcome, sorted by
     * product so two identical sets in different orders hash identically.
     *
     * `band` is excluded on purpose. It is a suggestion score recomputed from
     * the target catalogue on every page load, so including it would let an
     * unrelated FluentCart edit invalidate an operator's approved mapping —
     * the same reasoning RuntimeCompatibilityReport applies to plugin versions.
     * Orphan IDs are included, because creating a variant is part of what this
     * mapping will write; their descriptors are not, being source facts rather
     * than operator decisions.
     *
     * @param list<ProductMapDecision> $decisions
     * @return list<array<string, mixed>>
     */
    private static function canonical(array $decisions): array
    {
        $canonical = [];

        foreach ($decisions as $decision) {
            $orphanIds = array_map(
                static fn (array $orphan): int => (int) $orphan['id'],
                $decision->orphans(),
            );

            sort($orphanIds);

            $variantMap = $decision->variantMap();
            ksort($variantMap);

            $canonical[] = [
                'wc_id'               => $decision->wcId(),
                'decision'            => $decision->decision(),
                'fc_post_id'          => $decision->fcPostId(),
                'variant_map'         => $variantMap,
                'orphan_ids'          => $orphanIds,
                'allow_shared_target' => $decision->allowSharedTarget(),
            ];
        }

        usort($canonical, static fn (array $a, array $b): int => $a['wc_id'] <=> $b['wc_id']);

        return array_values($canonical);
    }
}

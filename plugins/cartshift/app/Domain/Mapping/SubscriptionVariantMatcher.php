<?php

declare(strict_types=1);

namespace CartShift\Domain\Mapping;

defined('ABSPATH') || exit;

use CartShift\Domain\Subscription\NormalizedSubscriptionContract;

/**
 * Pairs one Woo product's variations with one FluentCart product's variants,
 * with the subscriptions among them matched on their billing contract rather
 * than on their position in a list.
 *
 * VariantResolver's third pass takes the nth Woo variation and the nth FC
 * variant. For a T-shirt size that is a defensible last resort — somebody
 * eyeballs the row and fixes it. For a subscription it is invisible: the
 * mapping screen says "1/1 variants matched", the migration reports success,
 * and a yearly subscriber is billed every month for ever. FluentCart lists
 * monthly first on almost every membership product, so the wrong answer is also
 * the likely one.
 *
 * So subscription source variations never reach that pass. Section 7.2's order,
 * applied within one already-chosen target product:
 *
 *   1. hard gate — the target variation's payment type is `subscription`;
 *   2. hard gate — the source cadence passes the exact table and equals the
 *      target's normalised interval;
 *   3. strong signal — the target variation's SKU or title resembles the source;
 *   4. informational — the target's list price resembles the source's;
 *   5. warning only — a differing list price, which never gates anything.
 *
 * Nothing left over is guessed at. A source variation with no compatible target
 * is an orphan and a block, because creating one automatically cannot yet
 * reproduce cadence, trial, length, setup fee and payment type exactly — and
 * creating it as `onetime`, which is what CartShift does today, sells a
 * membership as a single purchase.
 *
 * The one-time variations of the same product keep every pass they have now,
 * including position: the defect is cadence-shaped, and fixing it by breaking
 * ordinary product mapping would be a poor trade.
 */
final class SubscriptionVariantMatcher
{
    /** No target variation in this product can carry the source's contract. */
    public const string ERROR_TARGET_MISSING = 'target_variation_missing';

    /** A specific target was named — by suggestion or by hand — and does not fit. */
    public const string ERROR_CONTRACT_MISMATCH = 'target_variation_contract_mismatch';

    /** The source cadence has no exact FluentCart equivalent at all. */
    public const string ERROR_UNSUPPORTED_CADENCE = NormalizedSubscriptionContract::ERROR_UNSUPPORTED_CADENCE;

    /**
     * The target is already spoken for — by another variation of this same
     * product, or by the cadence-gated half of this same call.
     *
     * §9.4's collision code rather than a new one: "two sources, one target
     * variation" is the same fact whether the two sources sit in one decision
     * or in two, and the code list stays closed. It is emitted separately from
     * the cadence codes because it is not a contract mismatch — reporting
     * "this product bills monthly and the chosen variation bills monthly" is
     * how a screen loses an owner's trust in one sentence.
     */
    public const string ERROR_TARGET_RESERVED = 'target_variation_contract_collision';

    /** The target's list price differs from the source's. Never a gate. */
    public const string WARNING_PRICE_DIFFERS = 'target_price_differs_from_source';

    public function __construct(
        private readonly VariantResolver $resolver = new VariantResolver(),
    ) {
    }

    /**
     * @param list<array{id: int, sku: string, name: string, price?: int, payment_type?: string, period?: string, multiplier?: int, trial_days?: int, times?: int}> $wooVariations
     *        Display order. `price` is in FluentCart's integer minor units.
     * @param list<array{id: int, sku: string, name: string, price?: float, payment_type?: string, repeat_interval?: string, trial_days?: int, times?: int}> $fcVariants
     *        Display order. `price` is decimal, as fct_product_variations is read.
     * @param array<int, int> $chosen Operator overrides: source variation ID => target variation ID.
     *
     * @return array{
     *     map: array<int, int>,
     *     orphans: list<int>,
     *     errors: list<array{code: string, source_variation_id: int, target_variation_id: int|null, message: string}>,
     *     warnings: list<array{code: string, source_variation_id: int, target_variation_id: int|null, message: string}>,
     *     sources: list<array<string, mixed>>,
     * }
     */
    public function match(array $wooVariations, array $fcVariants, array $chosen = []): array
    {
        $map      = [];
        $orphans  = [];
        $errors   = [];
        $warnings = [];
        $sources  = [];

        $reserved = [];

        // The subscription half first, so it reserves what the cadence gate
        // assigns before the one-time half is allowed to pair anything.
        $oneTime = [];

        foreach ($wooVariations as $woo) {
            if (!self::isSubscription($woo)) {
                $oneTime[] = $woo;

                continue;
            }

            $outcome = $this->matchOne($woo, $fcVariants, $reserved, $chosen[(int) $woo['id']] ?? null);

            $sources[] = $outcome['source'];
            $errors    = [...$errors, ...$outcome['errors']];
            $warnings  = [...$warnings, ...$outcome['warnings']];

            if ($outcome['target'] === null) {
                $orphans[] = (int) $woo['id'];

                continue;
            }

            $map[(int) $woo['id']] = $outcome['target'];
            $reserved[]            = $outcome['target'];
        }

        // A one-time source may not take a subscription variation either — that
        // sells a membership as a single purchase, which is the same defect
        // pointing the other way. Removing them from the pool denies the
        // position pass the chance...
        $oneTimeTargets = array_values(array_filter(
            $fcVariants,
            static fn (array $fc): bool => !self::targetIsSubscription($fc),
        ));

        // ...and this closes the door the pool could not. A one-time source
        // that *names* a subscription target never goes through the resolver at
        // all, so filtering the pool never sees it. It is reachable without a
        // hostile client: save the decision while the FluentCart variation is
        // one-time, let the owner convert it to a subscription, re-save through
        // `bulk`, and a single purchase becomes a recurring contract with every
        // per-row check passing.
        //
        // An explicit target that is not on this product is left alone rather
        // than refused. One-time mapping has never asserted that a stale
        // variant map still resolves, and starting here would refuse decisions
        // on catalogues this task has no business touching.
        $targetsById = [];

        foreach ($fcVariants as $fc) {
            $targetsById[(int) $fc['id']] = $fc;
        }

        $resolvable = [];

        foreach ($oneTime as $woo) {
            $explicit = $chosen[(int) $woo['id']] ?? null;
            $target   = $explicit !== null ? ($targetsById[$explicit] ?? null) : null;

            if ($target === null || !self::targetIsSubscription($target)) {
                $resolvable[] = $woo;

                continue;
            }

            $errors[]  = self::error(
                self::ERROR_CONTRACT_MISMATCH,
                (int) $woo['id'],
                $explicit,
                sprintf(
                    'FluentCart variation %d is a subscription, and "%s" is a one-time purchase.',
                    (int) $explicit,
                    (string) ($woo['name'] ?? ''),
                ),
            );
            $orphans[] = (int) $woo['id'];
        }

        if ($resolvable !== []) {
            $resolved = $this->resolver->resolve($resolvable, $oneTimeTargets, $reserved);

            $map     = $map + $resolved['map'];
            $orphans = [...$orphans, ...$resolved['orphans']];
        }

        ksort($map);
        sort($orphans);

        return [
            'map'      => $map,
            'orphans'  => array_values($orphans),
            'errors'   => $errors,
            'warnings' => $warnings,
            'sources'  => $sources,
        ];
    }

    /**
     * One subscription source variation against the whole target list.
     *
     * @param array<string, mixed>       $woo
     * @param list<array<string, mixed>> $fcVariants
     * @param list<int>                  $reserved
     *
     * @return array{target: int|null, errors: list<array<string, mixed>>, warnings: list<array<string, mixed>>, source: array<string, mixed>}
     */
    private function matchOne(array $woo, array $fcVariants, array $reserved, ?int $explicit): array
    {
        $sourceId = (int) $woo['id'];
        $contract = self::sourceContract($woo);

        $errors   = [];
        $warnings = [];
        $options  = [];

        $best      = null;
        $bestScore = -1.0;

        foreach ($fcVariants as $fc) {
            $targetId       = (int) $fc['id'];
            $targetContract = self::targetContract($fc);

            $optionErrors = [];

            if (!self::targetIsSubscription($fc)) {
                $optionErrors[] = self::ERROR_CONTRACT_MISMATCH;
            } elseif (!$contract->isRepresentable()) {
                $optionErrors[] = self::ERROR_UNSUPPORTED_CADENCE;
            } elseif (!$contract->cadenceMatches($targetContract)) {
                $optionErrors[] = self::ERROR_CONTRACT_MISMATCH;
            }

            $optionWarnings = [];

            if ($optionErrors === [] && !self::pricesAgree($woo, $fc)) {
                $optionWarnings[] = self::WARNING_PRICE_DIFFERS;
            }

            if ($optionErrors === [] && in_array($targetId, $reserved, true)) {
                $optionErrors[] = self::ERROR_TARGET_RESERVED;
            }

            $compatible = $optionErrors === [];

            $options[] = [
                'id'              => $targetId,
                'sku'             => (string) ($fc['sku'] ?? ''),
                'name'            => (string) ($fc['name'] ?? ''),
                'payment_type'    => self::targetIsSubscription($fc) ? 'subscription' : 'onetime',
                'repeat_interval' => (string) ($fc['repeat_interval'] ?? ''),
                'price'           => (float) ($fc['price'] ?? 0.0),
                'trial_days'      => (int) ($fc['trial_days'] ?? 0),
                'times'           => (int) ($fc['times'] ?? 0),
                'compatible'      => $compatible,
                'errors'          => $optionErrors,
                'warnings'        => $optionWarnings,
            ];

            if (!$compatible) {
                continue;
            }

            $score = self::score($woo, $fc);

            if ($score > $bestScore) {
                $bestScore = $score;
                $best      = $targetId;
            }
        }

        $byId = [];

        foreach ($options as $option) {
            $byId[$option['id']] = $option;
        }

        $target = $best;

        // An explicit choice replaces the suggestion, and is refused rather
        // than corrected when it does not fit. Quietly moving the operator to
        // the variation CartShift preferred hides the fact that they asked for
        // a different one — and on a membership catalogue the two answers are
        // "billed monthly" and "billed yearly".
        if ($explicit !== null) {
            $target = null;

            if (!isset($byId[$explicit])) {
                $errors[] = self::error(
                    self::ERROR_TARGET_MISSING,
                    $sourceId,
                    $explicit,
                    'The chosen variation does not belong to this FluentCart product.',
                );
            } elseif (!$byId[$explicit]['compatible']) {
                // The option already worked out why it is refused; saying it
                // again in different words here is how "already taken" came to
                // be reported as "wrong billing interval".
                $errors[] = self::error(
                    $byId[$explicit]['errors'][0],
                    $sourceId,
                    $explicit,
                    self::refusalMessage($byId[$explicit]['errors'][0], $contract, $byId[$explicit]),
                );
            } else {
                $target = $explicit;
            }
        } elseif ($target === null) {
            $errors[] = $contract->isRepresentable()
                ? self::error(
                    self::ERROR_TARGET_MISSING,
                    $sourceId,
                    null,
                    sprintf(
                        'No %s subscription variation on this product. Add one, or pick a different product.',
                        $contract->interval()?->value ?? '',
                    ),
                )
                : self::error(
                    self::ERROR_UNSUPPORTED_CADENCE,
                    $sourceId,
                    null,
                    'FluentCart cannot express this billing interval exactly, so nothing may be mapped to it.',
                );
        }

        if ($target !== null) {
            $warnings = array_map(
                static fn (string $code): array => self::warning(
                    $code,
                    $sourceId,
                    $target,
                    'The FluentCart list price differs from this product. Existing subscribers keep their own '
                    . 'amount — the source contract is preserved.',
                ),
                $byId[$target]['warnings'],
            );
        }

        return [
            'target'   => $target,
            'errors'   => $errors,
            'warnings' => $warnings,
            'source'   => [
                'id'           => $sourceId,
                'sku'          => (string) ($woo['sku'] ?? ''),
                'name'         => (string) ($woo['name'] ?? ''),
                'subscription' => true,
                'interval'     => $contract->interval()?->value,
                'trial_days'   => $contract->trialDays(),
                'times'        => $contract->finiteCycles(),
                'selected'     => $target,
                'options'      => $options,
            ],
        ];
    }

    /**
     * Operator-facing copy for one refusal, in that refusal's own terms.
     *
     * @param array<string, mixed> $option
     */
    private static function refusalMessage(
        string $code,
        NormalizedSubscriptionContract $contract,
        array $option,
    ): string {
        return match ($code) {
            self::ERROR_TARGET_RESERVED => sprintf(
                'FluentCart variation %d is already taken by another variation of this product.',
                (int) $option['id'],
            ),
            self::ERROR_UNSUPPORTED_CADENCE
                => 'FluentCart cannot express this billing interval exactly, so nothing may be mapped to it.',
            default => sprintf(
                'This product bills %s and the chosen variation bills %s.',
                $contract->interval()?->value ?? 'on an interval FluentCart cannot express',
                ((string) $option['repeat_interval']) !== ''
                    ? (string) $option['repeat_interval']
                    : 'nothing — it is a one-time variation',
            ),
        };
    }

    /**
     * Rank within the compatible set: SKU, then title, then price.
     *
     * Weighted so no combination of the weaker signals can outrank a stronger
     * one — the same reason ProductMatcher selects band-first rather than by
     * raw score.
     *
     * @param array<string, mixed> $woo
     * @param array<string, mixed> $fc
     */
    private static function score(array $woo, array $fc): float
    {
        $score = 0.0;

        $wooSku = trim((string) ($woo['sku'] ?? ''));
        $fcSku  = trim((string) ($fc['sku'] ?? ''));

        // Both present, as everywhere else in CartShift: blank equals blank is
        // an absence of evidence, not identity.
        if ($wooSku !== '' && $fcSku !== '' && strcasecmp($wooSku, $fcSku) === 0) {
            $score += 4.0;
        }

        $wooName = self::normalize((string) ($woo['name'] ?? ''));
        $fcName  = self::normalize((string) ($fc['name'] ?? ''));

        if ($wooName !== '' && $wooName === $fcName) {
            $score += 2.0;
        }

        if (self::pricesAgree($woo, $fc)) {
            $score += 1.0;
        }

        return $score;
    }

    /**
     * @param array<string, mixed> $woo
     * @param array<string, mixed> $fc
     */
    private static function pricesAgree(array $woo, array $fc): bool
    {
        // The source side is already in FluentCart's integer minor units
        // (MoneyHelper::toCents); the target side is read back out of
        // fct_product_variations as a decimal. Compared in minor units so
        // nothing rests on float equality.
        $source = (int) ($woo['price'] ?? 0);
        $target = (int) round(((float) ($fc['price'] ?? 0.0)) * 100);

        return $source === $target;
    }

    /**
     * @param array<string, mixed> $woo
     */
    private static function isSubscription(array $woo): bool
    {
        return ($woo['payment_type'] ?? '') === 'subscription';
    }

    /**
     * @param array<string, mixed> $fc
     */
    private static function targetIsSubscription(array $fc): bool
    {
        return ($fc['payment_type'] ?? '') === 'subscription';
    }

    /**
     * @param array<string, mixed> $woo
     */
    private static function sourceContract(array $woo): NormalizedSubscriptionContract
    {
        return NormalizedSubscriptionContract::fromWooCommerce(
            (string) ($woo['period'] ?? ''),
            (int) ($woo['multiplier'] ?? 0),
            (int) ($woo['trial_days'] ?? 0),
            (int) ($woo['times'] ?? 0),
        );
    }

    /**
     * @param array<string, mixed> $fc
     */
    private static function targetContract(array $fc): NormalizedSubscriptionContract
    {
        return NormalizedSubscriptionContract::fromFluentCart(
            (string) ($fc['repeat_interval'] ?? ''),
            (int) ($fc['trial_days'] ?? 0),
            (int) ($fc['times'] ?? 0),
        );
    }

    /**
     * @return array{code: string, source_variation_id: int, target_variation_id: int|null, message: string}
     */
    private static function error(string $code, int $sourceId, ?int $targetId, string $message): array
    {
        return [
            'code'                => $code,
            'source_variation_id' => $sourceId,
            'target_variation_id' => $targetId,
            'message'             => $message,
        ];
    }

    /**
     * @return array{code: string, source_variation_id: int, target_variation_id: int|null, message: string}
     */
    private static function warning(string $code, int $sourceId, ?int $targetId, string $message): array
    {
        return self::error($code, $sourceId, $targetId, $message);
    }

    /**
     * Fold accents, lower-case, strip punctuation, collapse whitespace — the
     * same normalisation VariantResolver and ProductMatcher already apply, and
     * for the same reason: a Polish catalogue's `Miesięcznie` is not a different
     * variation from its unaccented twin.
     */
    private static function normalize(string $value): string
    {
        $value = remove_accents($value);

        $value = function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);

        $value = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $value) ?? '';

        return trim(preg_replace('/\s+/', ' ', $value) ?? '');
    }
}

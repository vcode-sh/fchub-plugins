<?php

declare(strict_types=1);

namespace CartShift\Support;

defined('ABSPATH') || exit;

/**
 * One deterministic, total, injective serialisation for everything CartShift
 * fingerprints.
 *
 * Several things in this plan are hashed and then *bound to* — an operator
 * approves a target-settings fingerprint with `--approve-system-settings=<sha256>`,
 * a stage receipt records the mapping-set fingerprint, and every receipt
 * transition revalidates against both. Three properties follow, and each was
 * broken in at least one hand-rolled copy before this class existed.
 *
 * **Deterministic.** Key order may never depend on the order the producer
 * happened to assemble things in. `sortDeep()` sorts every associative array by
 * key, all the way down, and leaves lists alone — a list's order is either
 * meaningful or is sorted by its producer, and reordering one here would
 * silently change what a caller meant.
 *
 * **Total.** `(string) json_encode(...)` is a trapdoor: `json_encode()` returns
 * `false` on malformed UTF-8, the cast turns `false` into `''`, and the payload
 * then fingerprints as SHA-256 of the empty string. That is a constant, and a
 * constant is exactly what an approval token must never be able to become — a
 * stale or wrong `--approve-system-settings` hash would match by construction.
 * A restored Polish WooCommerce database full of Latin-1 mangled billing names
 * is precisely the input that produces it.
 *
 * **Injective.** So malformed strings are not dropped, replaced by a shared
 * placeholder, or hashed away into one bucket: `textOrMarker()` substitutes a
 * `sha256:` marker over the raw bytes, which is deterministic, always
 * encodable, and different for different byte sequences. Two differently
 * mangled payloads therefore keep two different fingerprints. The marker hashes
 * rather than shows the bytes because a malformed value may be a mangled
 * payment reference, and the plan's Global Constraints keep those out of
 * fingerprint inputs, logs and reports.
 *
 * `JSON_THROW_ON_ERROR` covers what is left — INF, NAN, recursion, depth. Those
 * are programmer errors rather than source data and must be loud rather than
 * quietly hashing to a constant.
 *
 * This is deliberately the same contract `SubscriptionRecordFactory` arrived at
 * for package records, expressed once for the callers outside that file. For
 * well-formed input it is byte-identical to the `(string) json_encode(sortDeep(…))`
 * every call site used before, so no existing fingerprint moves.
 */
final class CanonicalJson
{
    /**
     * The canonical string a fingerprint is taken over.
     *
     * The flags are part of the contract, not a preference: a UTF-8 product
     * title must hash the same however PHP feels about escaping it, and
     * JSON_PRESERVE_ZERO_FRACTION keeps `29.0` from collapsing into `29` and
     * changing a hash that nothing else changed.
     *
     * @param array<array-key, mixed> $value
     *
     * @throws \JsonException When the value cannot be encoded even after
     *                        malformed text has been substituted — INF, NAN,
     *                        recursion or depth. Never silently empty.
     */
    public static function encode(array $value): string
    {
        return json_encode(
            self::canonicalise($value),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR,
        );
    }

    /**
     * SHA-256 over encode().
     *
     * @param array<array-key, mixed> $value
     *
     * @throws \JsonException
     */
    public static function fingerprint(array $value): string
    {
        return hash('sha256', self::encode($value));
    }

    /**
     * Sorted, and with every unencodable string replaced by a stable marker.
     *
     * @param array<array-key, mixed> $value
     * @return array<array-key, mixed>
     */
    public static function canonicalise(array $value): array
    {
        /** @var array<array-key, mixed> $escaped */
        $escaped = self::escapeMalformedText($value);

        return self::sortDeep($escaped);
    }

    /**
     * Sort every associative array by key, all the way down. Lists untouched.
     *
     * @param array<array-key, mixed> $value
     * @return array<array-key, mixed>
     */
    public static function sortDeep(array $value): array
    {
        $isList = array_is_list($value);

        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = self::sortDeep($item);
            }
        }

        if (!$isList) {
            ksort($value);
        }

        return $value;
    }

    /**
     * Whether a string is valid UTF-8 and can therefore survive JSON encoding.
     *
     * `preg_match('//u', ...)` is the fallback rather than an afterthought: this
     * plugin already has a test harness for running without mbstring
     * (tests/stubs/MbstringAbsence.php), and a canonicalisation that silently
     * changed answer depending on an installed extension would be worse than
     * the bug it replaces.
     */
    public static function isText(string $value): bool
    {
        return function_exists('mb_check_encoding')
            ? mb_check_encoding($value, 'UTF-8')
            : preg_match('//u', $value) === 1;
    }

    /**
     * A string as itself, or a stable injective marker when it is not text.
     */
    public static function textOrMarker(string $value): string
    {
        return self::isText($value) ? $value : 'sha256:' . hash('sha256', $value);
    }

    private static function escapeMalformedText(mixed $value): mixed
    {
        if (is_string($value)) {
            return self::textOrMarker($value);
        }

        // AN OBJECT IS A CONTAINER TOO, and one whose properties this used to
        // hand straight back — so a mangled byte sequence one level inside a
        // `stdClass` walked past the marker substitution and took
        // `json_encode()` down with it. That was unreachable only because no
        // current caller passes an object, and three `digest()`/`canonicalJson()`
        // call sites take one from outside this class. Reachability is a poor
        // thing for a fingerprint's totality to rest on.
        //
        // Rebuilt as a `stdClass` rather than as an array: an empty object must
        // stay `{}` and not become `[]`. Properties are sorted here because
        // `sortDeep()` cannot see inside an object, and an order-dependent
        // fingerprint is the same class of bug one step along.
        if (is_object($value)) {
            $properties = [];

            foreach (get_object_vars($value) as $key => $item) {
                $properties[self::textOrMarker((string) $key)] = self::escapeMalformedText($item);
            }

            ksort($properties);

            $escaped = new \stdClass();

            foreach ($properties as $key => $item) {
                $escaped->{$key} = $item;
            }

            return $escaped;
        }

        if (!is_array($value)) {
            return $value;
        }

        $escaped = [];

        // Keys too. A malformed array key is just as unencodable as a value,
        // and json_encode() fails on the whole document either way.
        foreach ($value as $key => $item) {
            $escaped[is_string($key) ? self::textOrMarker($key) : $key] = self::escapeMalformedText($item);
        }

        return $escaped;
    }
}

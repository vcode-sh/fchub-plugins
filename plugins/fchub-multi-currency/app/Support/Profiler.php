<?php

declare(strict_types=1);

namespace FChubMultiCurrency\Support;

defined('ABSPATH') || exit;

/**
 * Temporary diagnostic instrumentation for issue #72's non-base-currency
 * latency investigation. The site owner reports a consistent, repeatable
 * ~3-4s gap between switching to the base currency (fast) and switching to
 * any other currency (~6.5s), on a site with a persistent Redis object cache
 * confirmed active — which should mean only the first request for a given
 * currency pair pays a database cost, not every one. This exists to find out,
 * with real measurements, whether RatesCacheStore is actually being hit on
 * repeat requests, how long the cache read and the database fallback each
 * take, and whether the plugin's own cache group has somehow ended up
 * non-persistent — rather than assuming any of that from reading the code.
 *
 * Not meant to ship long-term. Remove this class and its call sites once the
 * real bottleneck is identified and fixed. Gated behind ?_debug_timing=1 —
 * see ContextController::get() and ContextModule's debug output hook — so it
 * never runs, or costs anything beyond a few hrtime() calls, for an ordinary
 * visitor.
 *
 * A static collector is deliberately the whole mechanism: PHP tears down all
 * state between requests, so there's no cross-request leakage to worry about,
 * and it avoids threading a profiler instance through constructors in code
 * that has no other reason to know about it.
 */
final class Profiler
{
    /** @var array<int, array{label: string, time: float}> */
    private static array $marks = [];

    /** @var array<int, array{label: string, data: array<string, mixed>}> */
    private static array $notes = [];

    public static function mark(string $label): void
    {
        self::$marks[] = ['label' => $label, 'time' => hrtime(true)];
    }

    /**
     * Records a non-timing observation alongside the timeline — e.g. a cache
     * hit/miss result, or a diagnostic fact that isn't itself a duration.
     *
     * @param array<string, mixed> $data
     */
    public static function note(string $label, array $data = []): void
    {
        self::$notes[] = ['label' => $label, 'data' => $data];
    }

    public static function reset(): void
    {
        self::$marks = [];
        self::$notes = [];
    }

    /**
     * True only when the request explicitly opted into debug timing via
     * ?_debug_timing=1 — checked directly from $_GET rather than threading a
     * WP_REST_Request through every call site, since this needs to gate a
     * plain front-end page load (ContextModule's wp_footer hook) as well as
     * the REST controller.
     */
    public static function isRequested(): bool
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- read-only diagnostic gate, no state changes, value itself unused beyond truthiness
        return isset($_GET['_debug_timing']) && $_GET['_debug_timing'] !== '0';
    }

    /**
     * @return array<int, array{label: string, sinceStartMs: float, sincePreviousMs: float}>
     */
    public static function report(): array
    {
        if (self::$marks === []) {
            return [];
        }

        $start = self::$marks[0]['time'];
        $previous = $start;
        $report = [];

        foreach (self::$marks as $mark) {
            $report[] = [
                'label'           => $mark['label'],
                'sinceStartMs'    => round(($mark['time'] - $start) / 1e6, 2),
                'sincePreviousMs' => round(($mark['time'] - $previous) / 1e6, 2),
            ];
            $previous = $mark['time'];
        }

        return $report;
    }

    /**
     * @return array<int, array{label: string, data: array<string, mixed>}>
     */
    public static function noteReport(): array
    {
        return self::$notes;
    }
}

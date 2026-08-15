<?php

declare(strict_types=1);

namespace FChubMultiCurrency\Support;

defined('ABSPATH') || exit;

/**
 * Temporary diagnostic instrumentation for issue #72's reconciliation-latency
 * investigation. GET /context was measured at 2.5–5.5s+ end to end on one
 * host, and the site owner has ruled out generic PHP/host slowness as the
 * explanation — nothing else on that site takes anywhere near that long. This
 * exists to find out where inside the request the time actually goes
 * (resolver chain, rate cache, DB fallback, live provider — see
 * ExchangeRateService::getRate() and ContextController::get()), rather than
 * guessing.
 *
 * Not meant to ship long-term. Remove this class and its call sites once the
 * real bottleneck is identified and fixed.
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

    public static function mark(string $label): void
    {
        self::$marks[] = ['label' => $label, 'time' => hrtime(true)];
    }

    public static function reset(): void
    {
        self::$marks = [];
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
}

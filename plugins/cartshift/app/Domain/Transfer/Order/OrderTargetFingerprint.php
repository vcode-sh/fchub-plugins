<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\Order;

use CartShift\Support\CanonicalJson;

defined('ABSPATH') || exit;

final class OrderTargetFingerprint
{
    /** @param array<string, mixed> $snapshot @param array<string, int> $targetMap */
    public function fingerprint(array $snapshot, array $targetMap): string
    {
        ksort($targetMap, SORT_STRING);
        return CanonicalJson::fingerprint([
            'order_graph' => self::normaliseSnapshot($snapshot),
            'target_map' => $targetMap,
        ]);
    }

    /** @param array<string, mixed> $snapshot @return array<string, mixed> */
    public static function normaliseSnapshot(array $snapshot): array
    {
        $normalised = [
            'order' => is_array($snapshot['order'] ?? null) ? $snapshot['order'] : null,
            'items' => self::rows($snapshot['items'] ?? []),
            'addresses' => self::rows($snapshot['addresses'] ?? []),
            'coupons' => self::rows($snapshot['coupons'] ?? []),
            'tax_rates' => self::rows($snapshot['tax_rates'] ?? []),
            'transactions' => self::rows($snapshot['transactions'] ?? []),
            'meta' => self::rows($snapshot['meta'] ?? []),
        ];
        return CanonicalJson::canonicalise($normalised);
    }

    /** @return list<array<string, mixed>> */
    private static function rows(mixed $rows): array
    {
        if (!is_array($rows)) {
            return [];
        }
        $rows = array_values(array_filter($rows, 'is_array'));
        // The subscription graph owns this late-bound foreign key. Orders are
        // necessarily written before the subscription ID exists; including it
        // here would make a correct history link look like order drift while
        // the order reconciler deliberately treats the field as external.
        foreach ($rows as &$row) {
            unset($row['subscription_id']);
        }
        unset($row);
        usort($rows, static fn (array $left, array $right): int => [
            (int) ($left['id'] ?? 0),
            (string) ($left['meta_key'] ?? ''),
        ] <=> [
            (int) ($right['id'] ?? 0),
            (string) ($right['meta_key'] ?? ''),
        ]);
        return $rows;
    }
}

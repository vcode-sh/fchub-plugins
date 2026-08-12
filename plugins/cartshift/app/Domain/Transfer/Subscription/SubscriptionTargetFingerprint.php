<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\Subscription;

use CartShift\Support\CanonicalJson;

defined('ABSPATH') || exit;

final class SubscriptionTargetFingerprint
{
    /** @param array<string,mixed> $snapshot @param array<string,int> $targetMap */
    public function fingerprint(array $snapshot, array $targetMap): string
    {
        ksort($targetMap, SORT_STRING);
        return CanonicalJson::fingerprint([
            'subscription_graph' => self::normalise($snapshot),
            'target_map' => $targetMap,
        ]);
    }

    /** @param array<string,mixed> $snapshot @return array<string,mixed> */
    public static function normalise(array $snapshot): array
    {
        $row = is_array($snapshot['subscription'] ?? null) ? $snapshot['subscription'] : null;
        if ($row !== null) {
            unset($row['id'], $row['updated_at']);
        }
        $links = is_array($snapshot['transaction_links'] ?? null) ? array_values($snapshot['transaction_links']) : [];
        usort($links, static fn (array $a, array $b): int => (int) ($a['id'] ?? 0) <=> (int) ($b['id'] ?? 0));
        $meta = is_array($snapshot['meta'] ?? null) ? $snapshot['meta'] : [];
        ksort($meta, SORT_STRING);
        return CanonicalJson::canonicalise(['subscription' => $row, 'transaction_links' => $links, 'meta' => $meta]);
    }
}

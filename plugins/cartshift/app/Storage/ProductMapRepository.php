<?php

declare(strict_types=1);

namespace CartShift\Storage;

use CartShift\Domain\Mapping\ProductMapDecision;
use CartShift\Support\Constants;

defined('ABSPATH') || exit;

/**
 * The owner's mapping decisions, kept apart from the ID map on purpose.
 *
 * See Migrations::v6() for why. In short: this table holds intentions, the ID
 * map holds facts, and `reset` is allowed to destroy the latter.
 */
final class ProductMapRepository
{
    private readonly string $table;

    /**
     * Which source's decisions this repository speaks for. See schema v7.
     *
     * Defaults to `local`, so the existing mapping screen is unaffected. It
     * matters for the cross-site route, where both Lapka sites number their
     * products from one and a decision about the club's product 42 must not
     * surface as a decision about the shop's.
     */
    private readonly string $sourceKey;

    public function __construct(string $sourceKey = Constants::DEFAULT_SOURCE_KEY)
    {
        global $wpdb;

        $this->table     = $wpdb->prefix . 'cartshift_product_map';
        $this->sourceKey = $sourceKey;
    }

    /**
     * Which source's decisions this repository speaks for.
     *
     * Exposed because a caller that was handed a repository rather than
     * constructing one has to be able to ask. The container binds exactly one,
     * pinned to `local` (`MigrationModule`), and a cross-site audit reading
     * `local`'s decisions would report a different namespace's readiness under
     * this cohort's name.
     */
    public function sourceKey(): string
    {
        return $this->sourceKey;
    }

    /**
     * Upsert one decision.
     *
     * REPLACE rather than INSERT ... ON DUPLICATE KEY UPDATE because the table
     * has exactly one unique key and no foreign keys pointing at its surrogate
     * id, so losing the row's id on rewrite costs nothing.
     */
    public function save(ProductMapDecision $decision): void
    {
        global $wpdb;

        $wpdb->replace(
            $this->table,
            [
                'source_key'  => $this->sourceKey,
                'wc_id'       => $decision->wcId(),
                'wc_type'     => $decision->wcType(),
                'decision'    => $decision->decision(),
                'fc_post_id'  => $decision->fcPostId(),
                'band'        => $decision->band(),
                'variant_map' => $decision->isLink()
                    ? (string) wp_json_encode($decision->variantEnvelope())
                    : null,
                'decided_at'  => gmdate('Y-m-d H:i:s'),
            ],
            ['%s', '%d', '%s', '%s', '%d', '%s', '%s', '%s'],
        );
    }

    /**
     * @param list<ProductMapDecision> $decisions
     */
    public function saveMany(array $decisions): void
    {
        foreach ($decisions as $decision) {
            $this->save($decision);
        }
    }

    public function get(int $wcId): ?ProductMapDecision
    {
        global $wpdb;

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->table} WHERE source_key = %s AND wc_id = %d LIMIT 1",
            $this->sourceKey,
            $wcId,
        ));

        if (empty($rows)) {
            return null;
        }

        return ProductMapDecision::fromRow($rows[0]);
    }

    /** @return list<ProductMapDecision> */
    public function all(): array
    {
        global $wpdb;

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->table} WHERE source_key = %s ORDER BY wc_id ASC",
            $this->sourceKey,
        ));

        return array_map(ProductMapDecision::fromRow(...), $rows ?: []);
    }

    /** @return list<ProductMapDecision> */
    public function linked(): array
    {
        global $wpdb;

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->table}
             WHERE source_key = %s AND decision = 'link'
             ORDER BY wc_id ASC",
            $this->sourceKey,
        ));

        // fromRow() downgrades a link with no target to create, so filter after
        // mapping rather than trusting the column.
        return array_values(array_filter(
            array_map(ProductMapDecision::fromRow(...), $rows ?: []),
            static fn (ProductMapDecision $d): bool => $d->isLink(),
        ));
    }

    /** @return list<int> */
    public function skippedProductIds(): array
    {
        global $wpdb;

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->table}
             WHERE source_key = %s AND decision = 'skip'
             ORDER BY wc_id ASC",
            $this->sourceKey,
        ));

        $ids = array_map(
            static fn (object $row): int => (int) $row->wc_id,
            $rows ?: [],
        );

        sort($ids);

        return $ids;
    }

    public function count(): int
    {
        global $wpdb;

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->table} WHERE source_key = %s",
            $this->sourceKey,
        ));
    }

    /**
     * Discard this source's decisions, and only this source's.
     *
     * A DELETE rather than the TRUNCATE it used to be: once the table is shared
     * between source namespaces, truncating it to clear one mapping session
     * would throw away every other source's decisions too.
     */
    public function clear(): void
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$this->table} WHERE source_key = %s",
            $this->sourceKey,
        ));
    }
}

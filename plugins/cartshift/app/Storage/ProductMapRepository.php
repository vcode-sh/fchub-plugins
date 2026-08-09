<?php

declare(strict_types=1);

namespace CartShift\Storage;

use CartShift\Domain\Mapping\ProductMapDecision;

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

    public function __construct()
    {
        global $wpdb;

        $this->table = $wpdb->prefix . 'cartshift_product_map';
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
            ['%d', '%s', '%s', '%d', '%s', '%s', '%s'],
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
            "SELECT * FROM {$this->table} WHERE wc_id = %d LIMIT 1",
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

        $rows = $wpdb->get_results("SELECT * FROM {$this->table} ORDER BY wc_id ASC");

        return array_map(ProductMapDecision::fromRow(...), $rows ?: []);
    }

    /** @return list<ProductMapDecision> */
    public function linked(): array
    {
        global $wpdb;

        $rows = $wpdb->get_results(
            "SELECT * FROM {$this->table} WHERE decision = 'link' ORDER BY wc_id ASC",
        );

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

        $rows = $wpdb->get_results(
            "SELECT * FROM {$this->table} WHERE decision = 'skip' ORDER BY wc_id ASC",
        );

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

        return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$this->table}");
    }

    public function clear(): void
    {
        global $wpdb;

        $wpdb->query("TRUNCATE TABLE {$this->table}");
    }
}

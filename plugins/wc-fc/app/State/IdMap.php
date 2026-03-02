<?php

namespace WcFc\State;

defined('ABSPATH') or die;

class IdMap
{
    /**
     * Get the fully-qualified table name.
     */
    private function table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'wc_fc_id_map';
    }

    /**
     * Store a WC-to-FC ID mapping.
     *
     * @param string $entityType e.g. product, variation, customer, order, subscription, coupon
     * @param int    $wcId       WooCommerce ID
     * @param int    $fcId       FluentCart ID
     */
    public function store(string $entityType, int $wcId, int $fcId): void
    {
        global $wpdb;

        $wpdb->replace(
            $this->table(),
            [
                'entity_type' => $entityType,
                'wc_id'       => $wcId,
                'fc_id'       => $fcId,
                'created_at'  => gmdate('Y-m-d H:i:s'),
            ],
            ['%s', '%d', '%d', '%s']
        );
    }

    /**
     * Get the FluentCart ID for a given WC entity.
     *
     * @return int|null
     */
    public function getFcId(string $entityType, int $wcId): ?int
    {
        global $wpdb;

        $result = $wpdb->get_var($wpdb->prepare(
            "SELECT fc_id FROM {$this->table()} WHERE entity_type = %s AND wc_id = %d",
            $entityType,
            $wcId
        ));

        return $result !== null ? (int) $result : null;
    }

    /**
     * Get the WooCommerce ID for a given FC entity.
     *
     * @return int|null
     */
    public function getWcId(string $entityType, int $fcId): ?int
    {
        global $wpdb;

        $result = $wpdb->get_var($wpdb->prepare(
            "SELECT wc_id FROM {$this->table()} WHERE entity_type = %s AND fc_id = %d",
            $entityType,
            $fcId
        ));

        return $result !== null ? (int) $result : null;
    }

    /**
     * Get all mappings for a given entity type.
     *
     * @return array Array of objects with wc_id, fc_id
     */
    public function getAllByEntityType(string $entityType): array
    {
        global $wpdb;

        return $wpdb->get_results($wpdb->prepare(
            "SELECT wc_id, fc_id FROM {$this->table()} WHERE entity_type = %s",
            $entityType
        ));
    }

    /**
     * Delete all mappings for a specific entity type.
     */
    public function deleteByEntityType(string $entityType): void
    {
        global $wpdb;

        $wpdb->delete(
            $this->table(),
            ['entity_type' => $entityType],
            ['%s']
        );
    }

    /**
     * Delete all mappings.
     */
    public function deleteAll(): void
    {
        global $wpdb;
        $wpdb->query("TRUNCATE TABLE {$this->table()}");
    }
}

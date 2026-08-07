<?php

declare(strict_types=1);

/**
 * WordPress and WooCommerce function stubs needed by the ProductMigrator tests.
 *
 * Everything is guarded so this file can be required from any test file without
 * clashing with the shared bootstrap or another suite.
 */

if (!class_exists('WP_Term')) {
    class WP_Term
    {
        public int $term_id = 0;
        public string $name = '';
        public string $slug = '';
        public string $description = '';
        public int $parent = 0;
        public string $taxonomy = '';

        /**
         * @param array<string, mixed> $props
         */
        public function __construct(array $props = [])
        {
            foreach ($props as $key => $value) {
                if (property_exists($this, $key)) {
                    $this->{$key} = $value;
                }
            }
        }
    }
}

if (!function_exists('get_woocommerce_currency')) {
    function get_woocommerce_currency(): string
    {
        return $GLOBALS['_cartshift_test_wc_currency'] ?? 'USD';
    }
}

if (!function_exists('wc_attribute_taxonomy_name')) {
    function wc_attribute_taxonomy_name(string $attributeName): string
    {
        return 'pa_' . $attributeName;
    }
}

if (!function_exists('wc_get_attribute_taxonomies')) {
    function wc_get_attribute_taxonomies(): array
    {
        return $GLOBALS['_cartshift_test_wc_attribute_taxonomies'] ?? [];
    }
}

if (!function_exists('get_terms')) {
    /**
     * Returns whatever the test registered for the requested taxonomy.
     */
    function get_terms(array $args = [], mixed $deprecated = ''): array|WP_Error
    {
        $taxonomy = $args['taxonomy'] ?? '';

        return $GLOBALS['_cartshift_test_taxonomy_terms'][$taxonomy] ?? [];
    }
}

if (!function_exists('taxonomy_exists')) {
    function taxonomy_exists(string $taxonomy): bool
    {
        return isset($GLOBALS['_cartshift_test_taxonomy_terms'][$taxonomy]);
    }
}

if (!function_exists('wp_insert_term')) {
    function wp_insert_term(string $term, string $taxonomy, array $args = []): array|WP_Error
    {
        $GLOBALS['_cartshift_test_inserted_terms'][] = [$term, $taxonomy, $args];

        return ['term_id' => 9000 + count($GLOBALS['_cartshift_test_inserted_terms'] ?? [])];
    }
}

if (!function_exists('wp_get_post_terms')) {
    function wp_get_post_terms(int $postId, string $taxonomy = '', array $args = []): array|WP_Error
    {
        return $GLOBALS['_cartshift_test_post_terms'][$postId][$taxonomy] ?? [];
    }
}

if (!function_exists('wp_get_object_terms')) {
    function wp_get_object_terms(int|array $objectIds, string|array $taxonomies, array $args = []): array|WP_Error
    {
        return [];
    }
}

if (!function_exists('wp_set_object_terms')) {
    function wp_set_object_terms(
        int $objectId,
        array|string|int $terms,
        string $taxonomy,
        bool $append = false,
    ): array|WP_Error {
        $GLOBALS['_cartshift_test_object_terms'][$objectId][$taxonomy] = $terms;

        return is_array($terms) ? $terms : [$terms];
    }
}

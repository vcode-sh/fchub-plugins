<?php

namespace FChubMemberships\Adapters;

defined('ABSPATH') || exit;

use FChubMemberships\Adapters\Contracts\AccessAdapterInterface;
use FChubMemberships\Adapters\Contracts\BatchResourceLabelAdapterInterface;

class WordPressContentAdapter implements AccessAdapterInterface, BatchResourceLabelAdapterInterface
{
    public function supports(string $resourceType): bool
    {
        // Built-in aliases
        if (in_array($resourceType, ['post', 'page', 'category', 'tag'], true)) {
            return true;
        }

        // Direct post type name (from ResourceTypeRegistry)
        if (post_type_exists($resourceType)) {
            return true;
        }

        // Direct taxonomy name (from ResourceTypeRegistry)
        if (taxonomy_exists($resourceType)) {
            return true;
        }

        // Legacy prefix formats
        if (strpos($resourceType, 'custom_post_type:') === 0) {
            $cpt = substr($resourceType, strlen('custom_post_type:'));
            return post_type_exists($cpt);
        }

        if (strpos($resourceType, 'taxonomy:') === 0) {
            $taxonomy = substr($resourceType, strlen('taxonomy:'));
            return taxonomy_exists($taxonomy);
        }

        return false;
    }

    public function grant(int $userId, string $resourceType, string $resourceId, array $context = []): array
    {
        return [
            'success' => true,
            'message' => __('Access granted. Content restriction handled by membership grants.', 'fchub-memberships'),
        ];
    }

    public function revoke(int $userId, string $resourceType, string $resourceId, array $context = []): array
    {
        return [
            'success' => true,
            'message' => __('Access revoked. Content restriction handled by membership grants.', 'fchub-memberships'),
        ];
    }

    public function check(int $userId, string $resourceType, string $resourceId): bool
    {
        return true;
    }

    public function getResourceLabel(string $resourceType, string $resourceId): string
    {
        if ($this->isTaxonomyType($resourceType)) {
            $term = get_term((int) $resourceId);
            if ($term && !is_wp_error($term)) {
                return $term->name;
            }
            return sprintf(__('Term #%s', 'fchub-memberships'), $resourceId);
        }

        $title = get_the_title((int) $resourceId);
        if ($title) {
            return $title;
        }

        return sprintf(__('Post #%s', 'fchub-memberships'), $resourceId);
    }

    public function getResourceLabels(string $resourceType, array $resourceIds): array
    {
        $resourceIds = array_values(array_unique(array_map('strval', $resourceIds)));
        if ($resourceIds === []) {
            return [];
        }

        if ($this->isTaxonomyType($resourceType) || taxonomy_exists($resourceType)) {
            $labels = array_fill_keys($resourceIds, '');
            $taxonomy = $this->resolveTaxonomy($resourceType);
            $termIds = array_values(array_filter(array_map('intval', $resourceIds)));
            $terms = get_terms([
                'taxonomy' => $taxonomy,
                'hide_empty' => false,
                'include' => $termIds,
                'number' => count($termIds),
            ]);
            if (is_wp_error($terms)) {
                throw new \RuntimeException('Taxonomy resource labels could not be loaded.');
            }
            foreach ($terms as $term) {
                $id = (string) $term->term_id;
                if (array_key_exists($id, $labels)) {
                    $labels[$id] = (string) $term->name;
                }
            }
            foreach ($labels as $id => $label) {
                if ($label === '') {
                    $labels[$id] = sprintf(__('Term #%s', 'fchub-memberships'), $id);
                }
            }

            return $labels;
        }

        $labels = array_fill_keys($resourceIds, '');
        $postIds = array_values(array_filter(array_map('intval', $resourceIds)));
        $posts = get_posts([
            'post_type' => $this->resolvePostType($resourceType),
            'post_status' => 'any',
            'posts_per_page' => -1,
            'post__in' => $postIds,
            'orderby' => 'post__in',
        ]);
        foreach ($posts as $post) {
            $id = (string) $post->ID;
            if (array_key_exists($id, $labels)) {
                $title = (string) $post->post_title;
                if ($resourceType === 'menu_item') {
                    $menuItem = wp_setup_nav_menu_item($post);
                    $title = (string) ($menuItem->title ?? $title);
                }
                $labels[$id] = $title;
            }
        }
        foreach ($labels as $id => $label) {
            if ($label === '') {
                $labels[$id] = sprintf(__('Post #%s', 'fchub-memberships'), $id);
            }
        }

        return $labels;
    }

    public function searchResources(string $query, string $resourceType, int $limit = 20): array
    {
        // Check if the resource type is directly a taxonomy
        if ($this->isTaxonomyType($resourceType)) {
            return $this->searchTerms($query, $resourceType, $limit);
        }

        // Check if it is a registered taxonomy (e.g. custom taxonomies registered via ResourceTypeRegistry)
        if (taxonomy_exists($resourceType)) {
            return $this->searchTermsDirect($query, $resourceType, $limit);
        }

        // For all post types (built-in and custom), search posts
        return $this->searchPosts($query, $resourceType, $limit);
    }

    public function getResourceTypes(): array
    {
        $types = [];

        $postTypes = get_post_types(['public' => true], 'objects');
        foreach ($postTypes as $postType) {
            if ($postType->name === 'attachment') {
                continue;
            }

            if (in_array($postType->name, ['post', 'page'], true)) {
                $types[$postType->name] = $postType->label;
            } else {
                $types['custom_post_type:' . $postType->name] = $postType->label;
            }
        }

        $taxonomies = get_taxonomies(['public' => true], 'objects');
        foreach ($taxonomies as $taxonomy) {
            if (in_array($taxonomy->name, ['category', 'post_tag'], true)) {
                $key = $taxonomy->name === 'post_tag' ? 'tag' : 'category';
                $types[$key] = $taxonomy->label;
            } else {
                $types['taxonomy:' . $taxonomy->name] = $taxonomy->label;
            }
        }

        return $types;
    }

    private function isTaxonomyType(string $resourceType): bool
    {
        return in_array($resourceType, ['category', 'tag'], true)
            || strpos($resourceType, 'taxonomy:') === 0;
    }

    private function resolveTaxonomy(string $resourceType): string
    {
        if ($resourceType === 'category') {
            return 'category';
        }

        if ($resourceType === 'tag') {
            return 'post_tag';
        }

        if (taxonomy_exists($resourceType)) {
            return $resourceType;
        }

        return substr($resourceType, strlen('taxonomy:'));
    }

    private function searchTerms(string $query, string $resourceType, int $limit): array
    {
        $taxonomy = $this->resolveTaxonomy($resourceType);
        return $this->searchTermsDirect($query, $taxonomy, $limit);
    }

    /**
     * Search terms directly by taxonomy name.
     */
    private function searchTermsDirect(string $query, string $taxonomy, int $limit): array
    {
        $args = [
            'taxonomy'   => $taxonomy,
            'hide_empty' => false,
            'number'     => $limit,
        ];

        if ($query !== '') {
            $args['search'] = $query;
        }

        $terms = get_terms($args);
        if (is_wp_error($terms)) {
            return [];
        }

        $taxonomyObj = get_taxonomy($taxonomy);
        $typeLabel = $taxonomyObj ? $taxonomyObj->label : $taxonomy;

        $results = [];
        foreach ($terms as $term) {
            $results[] = [
                'id'         => (string) $term->term_id,
                'label'      => $term->name,
                'type_label' => $typeLabel,
            ];
        }

        return $results;
    }

    private function searchPosts(string $query, string $resourceType, int $limit): array
    {
        $postType = $this->resolvePostType($resourceType);

        $args = [
            'post_type'      => $postType,
            'post_status'    => 'publish',
            'posts_per_page' => $limit,
            'orderby'        => $query === '' ? 'date' : 'relevance',
            'order'          => 'DESC',
        ];

        if ($query !== '') {
            $args['s'] = $query;
        }

        $wpQuery = new \WP_Query($args);

        $postTypeObj = get_post_type_object($postType);
        $typeLabel = $postTypeObj ? $postTypeObj->label : $postType;

        $results = [];
        foreach ($wpQuery->posts as $post) {
            $results[] = [
                'id'         => (string) $post->ID,
                'label'      => $post->post_title,
                'type_label' => $typeLabel,
            ];
        }

        return $results;
    }

    /**
     * Resolve a resource type to a post type, handling both direct names and prefixed keys.
     * Overrides parent to also check if the type is already a valid post type directly.
     */
    private function resolvePostType(string $resourceType): string
    {
        if (in_array($resourceType, ['post', 'page'], true)) {
            return $resourceType;
        }

        // If it's a registered post type directly (from ResourceTypeRegistry), return as-is
        if (post_type_exists($resourceType)) {
            return $resourceType;
        }

        // Legacy prefix format
        if (strpos($resourceType, 'custom_post_type:') === 0) {
            return substr($resourceType, strlen('custom_post_type:'));
        }

        return $resourceType;
    }
}

<?php

namespace FChubPortalExtender\Storage;

defined('ABSPATH') || exit;

class EndpointRepository
{
    private const OPTION_KEY = 'fchub_portal_endpoints';

    private const RESERVED_SLUGS = [
        'dashboard',
        'purchase-history',
        'subscriptions',
        'licenses',
        'downloads',
        'profile',
    ];

    public function getAll(): array
    {
        $endpoints = get_option(self::OPTION_KEY, []);

        if (!is_array($endpoints)) {
            return [];
        }

        usort($endpoints, fn($a, $b) => ($a['position'] ?? 0) <=> ($b['position'] ?? 0));

        return $endpoints;
    }

    public function getActive(): array
    {
        return array_values(array_filter(
            $this->getAll(),
            fn($ep) => ($ep['status'] ?? 'inactive') === 'active'
        ));
    }

    public function find(string $id): ?array
    {
        $endpoints = $this->getAll();

        foreach ($endpoints as $endpoint) {
            if ($endpoint['id'] === $id) {
                return $endpoint;
            }
        }

        return null;
    }

    public function create(array $data): array
    {
        $endpoints = $this->getAll();

        $this->validateSlug($data['slug'] ?? '', null);

        $now = current_time('mysql');
        $maxPosition = 0;

        foreach ($endpoints as $ep) {
            if (($ep['position'] ?? 0) > $maxPosition) {
                $maxPosition = $ep['position'];
            }
        }

        $endpoint = [
            'id'             => wp_generate_uuid4(),
            'slug'           => sanitize_title($data['slug']),
            'title'          => sanitize_text_field($data['title'] ?? ''),
            'type'           => in_array($data['type'] ?? '', ['page_id', 'shortcode']) ? $data['type'] : 'page_id',
            'page_id'        => absint($data['page_id'] ?? 0),
            'shortcode'      => sanitize_text_field($data['shortcode'] ?? ''),
            'icon_type'      => in_array($data['icon_type'] ?? '', ['svg', 'dashicon', 'url']) ? $data['icon_type'] : 'svg',
            'icon_value'     => $this->sanitizeIconValue($data['icon_type'] ?? 'svg', $data['icon_value'] ?? ''),
            'position'       => $maxPosition + 1,
            'status'         => in_array($data['status'] ?? '', ['active', 'inactive']) ? $data['status'] : 'active',
            'scroll_enabled' => !empty($data['scroll_enabled']),
            'scroll_mode'    => in_array($data['scroll_mode'] ?? '', ['auto', 'fixed']) ? $data['scroll_mode'] : 'auto',
            'scroll_height'  => absint($data['scroll_height'] ?? 600),
            'created_at'     => $now,
            'updated_at'     => $now,
        ];

        $endpoints[] = $endpoint;
        $this->save($endpoints);

        return $endpoint;
    }

    public function update(string $id, array $data): ?array
    {
        $endpoints = $this->getAll();
        $index = null;

        foreach ($endpoints as $i => $ep) {
            if ($ep['id'] === $id) {
                $index = $i;
                break;
            }
        }

        if ($index === null) {
            return null;
        }

        if (isset($data['slug'])) {
            $this->validateSlug($data['slug'], $id);
            $endpoints[$index]['slug'] = sanitize_title($data['slug']);
        }

        if (isset($data['title'])) {
            $endpoints[$index]['title'] = sanitize_text_field($data['title']);
        }

        if (isset($data['type']) && in_array($data['type'], ['page_id', 'shortcode'])) {
            $endpoints[$index]['type'] = $data['type'];
        }

        if (isset($data['page_id'])) {
            $endpoints[$index]['page_id'] = absint($data['page_id']);
        }

        if (isset($data['shortcode'])) {
            $endpoints[$index]['shortcode'] = sanitize_text_field($data['shortcode']);
        }

        if (isset($data['icon_type']) && in_array($data['icon_type'], ['svg', 'dashicon', 'url'])) {
            $endpoints[$index]['icon_type'] = $data['icon_type'];
        }

        if (isset($data['icon_value'])) {
            $endpoints[$index]['icon_value'] = $this->sanitizeIconValue(
                $endpoints[$index]['icon_type'],
                $data['icon_value']
            );
        }

        if (isset($data['status']) && in_array($data['status'], ['active', 'inactive'])) {
            $endpoints[$index]['status'] = $data['status'];
        }

        if (array_key_exists('scroll_enabled', $data)) {
            $endpoints[$index]['scroll_enabled'] = !empty($data['scroll_enabled']);
        }

        if (isset($data['scroll_mode']) && in_array($data['scroll_mode'], ['auto', 'fixed'])) {
            $endpoints[$index]['scroll_mode'] = $data['scroll_mode'];
        }

        if (isset($data['scroll_height'])) {
            $endpoints[$index]['scroll_height'] = absint($data['scroll_height']);
        }

        $endpoints[$index]['updated_at'] = current_time('mysql');

        $this->save($endpoints);

        return $endpoints[$index];
    }

    public function delete(string $id): bool
    {
        $endpoints = $this->getAll();
        $filtered = array_values(array_filter($endpoints, fn($ep) => $ep['id'] !== $id));

        if (count($filtered) === count($endpoints)) {
            return false;
        }

        $this->save($filtered);

        return true;
    }

    public function reorder(array $ids): array
    {
        $endpoints = $this->getAll();
        $indexed = [];

        foreach ($endpoints as $ep) {
            $indexed[$ep['id']] = $ep;
        }

        $reordered = [];
        $position = 0;

        foreach ($ids as $id) {
            if (isset($indexed[$id])) {
                $indexed[$id]['position'] = $position++;
                $reordered[] = $indexed[$id];
                unset($indexed[$id]);
            }
        }

        // Append any endpoints not in the reorder list
        foreach ($indexed as $ep) {
            $ep['position'] = $position++;
            $reordered[] = $ep;
        }

        $this->save($reordered);

        return $reordered;
    }

    public static function getReservedSlugs(): array
    {
        return self::RESERVED_SLUGS;
    }

    private function validateSlug(string $slug, ?string $excludeId): void
    {
        $slug = sanitize_title($slug);

        if (empty($slug)) {
            throw new \InvalidArgumentException(
                __('The endpoint slug cannot be empty.', 'fchub-portal-extender')
            );
        }

        if (in_array($slug, self::RESERVED_SLUGS, true)) {
            throw new \InvalidArgumentException(
                sprintf(
                    __('The slug "%s" is reserved by FluentCart and cannot be used.', 'fchub-portal-extender'),
                    $slug
                )
            );
        }

        $endpoints = $this->getAll();

        foreach ($endpoints as $ep) {
            if ($ep['slug'] === $slug && $ep['id'] !== $excludeId) {
                throw new \InvalidArgumentException(
                    sprintf(
                        __('The slug "%s" is already in use by another endpoint.', 'fchub-portal-extender'),
                        $slug
                    )
                );
            }
        }
    }

    private function sanitizeIconValue(string $type, string $value): string
    {
        if ($type === 'url') {
            return esc_url_raw($value);
        }

        if ($type === 'dashicon') {
            return sanitize_text_field($value);
        }

        // SVG — allow through with basic sanitisation (strip script tags)
        $value = preg_replace('/<script\b[^>]*>.*?<\/script>/is', '', $value);

        return $value;
    }

    private function save(array $endpoints): void
    {
        update_option(self::OPTION_KEY, $endpoints, false);
    }
}

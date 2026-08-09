<?php

namespace FChubMemberships\Domain\Plan;

defined('ABSPATH') || exit;

use FChubMemberships\Adapters\Contracts\AccessAdapterInterface;
use FChubMemberships\Support\Constants;
use FChubMemberships\Support\ResourceTypeRegistry;

/**
 * Turns raw plan_rules rows into items a human can actually read.
 *
 * A plan rule is a provider/resource_type/resource_id triple and nothing else —
 * no title, no link, no date. Anything that shows rules to a member (the
 * notification emails, chiefly) has to resolve those three columns into a real
 * title and, where one exists, a real permalink. Labels come from the same
 * adapter contract the admin UI uses, so provider-backed rules stay readable.
 *
 * Rules that point at nothing a member can open — navigation and protection
 * plumbing, CRM segmentation, content that has since been deleted — are dropped
 * rather than rendered as an empty bullet with a link to nowhere.
 */
final class PlanRulePresenter
{
    /** Registry groups that describe protection plumbing, not member-facing content. */
    private const NON_CONTENT_GROUPS = ['navigation', 'advanced'];

    /** Providers whose rules are side effects rather than resources a member can visit. */
    private const NON_CONTENT_PROVIDERS = [Constants::PROVIDER_FLUENTCRM];

    /** Resource IDs that mean "every resource of this type". */
    private const WILDCARD_IDS = ['', '0', '*'];

    /** Post statuses nobody should be pointed at, however valid the rule is. */
    private const UNREACHABLE_POST_STATUSES = ['trash', 'auto-draft', 'draft', 'pending'];

    private ?ResourceTypeRegistry $registry;

    public function __construct(?ResourceTypeRegistry $registry = null)
    {
        // Resolved lazily: this class is constructed on every notification
        // service, and the registry boots the whole resource type catalogue.
        $this->registry = $registry;
    }

    /**
     * Resources the member can open the moment the grant lands.
     *
     * @param array<int, mixed> $rules Raw plan_rules rows.
     * @return list<array{title: string, url: string}>
     */
    public function immediateResources(array $rules): array
    {
        $items = [];

        foreach ($rules as $rule) {
            if (!is_array($rule)) {
                continue;
            }
            if (($rule['drip_type'] ?? Constants::DRIP_TYPE_IMMEDIATE) !== Constants::DRIP_TYPE_IMMEDIATE) {
                continue;
            }

            $resolved = $this->resolve($rule);
            if ($resolved !== null) {
                $items[] = $resolved;
            }
        }

        return $items;
    }

    /**
     * Resources that unlock later, with an honest description of when.
     *
     * No URL here on purpose — the content is still locked.
     *
     * @param array<int, mixed> $rules Raw plan_rules rows.
     * @return list<array{title: string, available_date: string}>
     */
    public function dripItems(array $rules): array
    {
        $items = [];

        foreach ($rules as $rule) {
            if (!is_array($rule)) {
                continue;
            }
            if (($rule['drip_type'] ?? Constants::DRIP_TYPE_IMMEDIATE) === Constants::DRIP_TYPE_IMMEDIATE) {
                continue;
            }

            $resolved = $this->resolve($rule);
            if ($resolved === null) {
                continue;
            }

            $items[] = [
                'title'          => $resolved['title'],
                'available_date' => $this->describeAvailability($rule),
            ];
        }

        return $items;
    }

    /**
     * @param array<string, mixed> $rule
     * @return array{title: string, url: string}|null Null when the rule has nothing to show.
     */
    private function resolve(array $rule): ?array
    {
        $provider = (string) ($rule['provider'] ?? Constants::PROVIDER_WORDPRESS_CORE);
        $type     = trim((string) ($rule['resource_type'] ?? ''));
        $id       = trim((string) ($rule['resource_id'] ?? ''));

        if ($type === '' || in_array($provider, self::NON_CONTENT_PROVIDERS, true)) {
            return null;
        }

        $config = $this->registry()->getForRead($type);
        $group  = is_array($config) ? (string) ($config['group'] ?? 'advanced') : 'advanced';
        if (in_array($group, self::NON_CONTENT_GROUPS, true)) {
            return null;
        }

        if (in_array($id, self::WILDCARD_IDS, true)) {
            return [
                /* translators: %s is a resource type label, for example "Posts". */
                'title' => sprintf(__('All %s', 'fchub-memberships'), $this->typeLabel($type, $config)),
                'url'   => '',
            ];
        }

        if ($provider !== Constants::PROVIDER_WORDPRESS_CORE) {
            $label = $this->providerLabel($type, $id, $config);

            return $label === null ? null : ['title' => $label, 'url' => ''];
        }

        return $this->resolveWordPressContent($type, $id, $config);
    }

    /**
     * @param array<string, mixed>|null $config
     * @return array{title: string, url: string}|null
     */
    private function resolveWordPressContent(string $type, string $id, ?array $config): ?array
    {
        $readType = $this->registry()->resolveReadType($type);

        if ($this->isTaxonomy($readType, $config)) {
            $taxonomy = $this->taxonomyName($readType);
            $term     = get_term((int) $id, $taxonomy);
            if (!$term || is_wp_error($term) || empty($term->name)) {
                return null;
            }

            $link = get_term_link((int) $id, $taxonomy);

            return [
                'title' => (string) $term->name,
                'url'   => is_string($link) ? $link : '',
            ];
        }

        $post = get_post((int) $id);
        if (!$post) {
            return null;
        }

        if (in_array((string) ($post->post_status ?? ''), self::UNREACHABLE_POST_STATUSES, true)) {
            return null;
        }

        $title = trim((string) ($post->post_title ?? ''));
        if ($title === '') {
            /* translators: 1: resource type label, 2: resource ID. */
            $title = sprintf(__('%1$s #%2$s', 'fchub-memberships'), $this->typeLabel($type, $config), $id);
        }

        $permalink = get_permalink((int) $id);

        return [
            'title' => $title,
            'url'   => is_string($permalink) ? $permalink : '',
        ];
    }

    /**
     * Ask the provider's adapter what this resource is called.
     *
     * @param array<string, mixed>|null $config
     */
    private function providerLabel(string $type, string $id, ?array $config): ?string
    {
        $adapterClass = is_array($config) ? ($config['adapter'] ?? null) : null;
        if (!is_string($adapterClass)
            || !class_exists($adapterClass)
            || !is_a($adapterClass, AccessAdapterInterface::class, true)
        ) {
            return null;
        }

        try {
            $adapter = new $adapterClass();
            $label   = trim((string) $adapter->getResourceLabel($this->registry()->resolveReadType($type), $id));
        } catch (\Throwable) {
            // Provider inactive or unhappy. A missing bullet beats a broken one.
            return null;
        }

        return $label !== '' ? $label : null;
    }

    /**
     * @param array<string, mixed> $rule
     */
    private function describeAvailability(array $rule): string
    {
        $dripType = (string) ($rule['drip_type'] ?? '');

        if ($dripType === Constants::DRIP_TYPE_DELAYED) {
            $days = (int) ($rule['drip_delay_days'] ?? 0);
            if ($days <= 0) {
                return __('As soon as your membership starts', 'fchub-memberships');
            }

            return sprintf(
                /* translators: %d is a number of days. */
                _n('%d day after joining', '%d days after joining', $days, 'fchub-memberships'),
                $days
            );
        }

        if ($dripType === Constants::DRIP_TYPE_FIXED_DATE) {
            $timestamp = strtotime((string) ($rule['drip_date'] ?? ''));
            if (!$timestamp) {
                return '';
            }

            $format = (string) get_option('date_format');

            return wp_date($format !== '' ? $format : 'F j, Y', $timestamp);
        }

        return '';
    }

    /** @param array<string, mixed>|null $config */
    private function isTaxonomy(string $readType, ?array $config): bool
    {
        if (is_array($config) && ($config['group'] ?? '') === 'taxonomy') {
            return true;
        }

        return in_array($readType, ['category', 'tag', 'post_tag'], true)
            || str_starts_with($readType, 'taxonomy:')
            || taxonomy_exists($readType);
    }

    private function taxonomyName(string $readType): string
    {
        if ($readType === 'tag') {
            return 'post_tag';
        }

        if (str_starts_with($readType, 'taxonomy:')) {
            return substr($readType, strlen('taxonomy:'));
        }

        return $readType;
    }

    /** @param array<string, mixed>|null $config */
    private function typeLabel(string $type, ?array $config): string
    {
        $label = is_array($config) ? trim((string) ($config['label'] ?? '')) : '';

        return $label !== '' ? $label : $type;
    }

    private function registry(): ResourceTypeRegistry
    {
        return $this->registry ??= ResourceTypeRegistry::getInstance();
    }
}

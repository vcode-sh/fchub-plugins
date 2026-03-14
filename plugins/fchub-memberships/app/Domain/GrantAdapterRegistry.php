<?php

namespace FChubMemberships\Domain;

defined('ABSPATH') || exit;

final class GrantAdapterRegistry
{
    /** @var array<string, class-string> */
    private array $map;

    /**
     * @param array<string, class-string> $map
     */
    public function __construct(array $map = [])
    {
        $this->map = $map + [
            'wordpress_core'   => \FChubMemberships\Adapters\WordPressContentAdapter::class,
            'learndash'        => \FChubMemberships\Adapters\LearnDashAdapter::class,
            'fluentcrm'        => \FChubMemberships\Adapters\FluentCrmAdapter::class,
            'fluent_community' => \FChubMemberships\Adapters\FluentCommunityAdapter::class,
        ];
    }

    public function resolve(string $provider): ?object
    {
        $class = $this->map[$provider] ?? null;

        if ($class && class_exists($class)) {
            return new $class();
        }

        return null;
    }
}

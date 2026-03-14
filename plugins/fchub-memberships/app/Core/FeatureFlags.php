<?php

namespace FChubMemberships\Core;

defined('ABSPATH') || exit;

final class FeatureFlags
{
    /** @var array<string, bool> */
    private array $flags;

    /**
     * @param array<string, bool> $flags
     */
    public function __construct(array $flags = [])
    {
        $this->flags = $flags;
    }

    public static function fromWordPress(): self
    {
        $flags = get_option('fchub_memberships_feature_flags', []);
        if (!is_array($flags)) {
            $flags = [];
        }

        $filtered = apply_filters('fchub_memberships/feature_flags', $flags);
        if (!is_array($filtered)) {
            $filtered = [];
        }

        $normalized = [];
        foreach ($filtered as $key => $value) {
            $normalized[(string) $key] = (bool) $value;
        }

        return new self($normalized);
    }

    public function isEnabled(string $flag): bool
    {
        return $this->flags[$flag] ?? true;
    }

    /**
     * @return array<string, bool>
     */
    public function all(): array
    {
        return $this->flags;
    }
}

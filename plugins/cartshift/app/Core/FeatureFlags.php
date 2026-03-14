<?php

declare(strict_types=1);

namespace CartShift\Core;

defined('ABSPATH') || exit();

final class FeatureFlags
{
    // Free features — always enabled.
    public const string SUBSCRIPTIONS = 'subscriptions';
    public const string BACKGROUND_PROCESSING = 'background_processing';
    public const string WP_CLI = 'wp_cli';

    // Pro features — gated by wp_options.
    public const string DETAILED_REPORTING = 'detailed_reporting';
    public const string MULTI_SOURCE = 'multi_source';
    public const string DOWNLOAD_FILES = 'download_files';
    public const string ATTRIBUTE_MAPPING = 'attribute_mapping';

    // Core module keys — always enabled.
    public const string INFRASTRUCTURE = 'infrastructure';
    public const string ADMIN = 'admin';
    public const string MIGRATION = 'migration';

    private const array FREE_FEATURES = [
        self::INFRASTRUCTURE,
        self::ADMIN,
        self::MIGRATION,
        self::SUBSCRIPTIONS,
        self::BACKGROUND_PROCESSING,
        self::WP_CLI,
    ];

    /** @param array<string, bool> $flags */
    public function __construct(
        private readonly array $flags = [],
    ) {}

    public static function fromWordPress(): self
    {
        $stored = get_option('cartshift_feature_flags', []);

        if (! is_array($stored)) {
            $stored = [];
        }

        /** @var array<string, bool> $filtered */
        $filtered = apply_filters('cartshift/feature_flags', $stored);

        if (! is_array($filtered)) {
            $filtered = [];
        }

        $normalised = [];
        foreach ($filtered as $key => $value) {
            $normalised[(string) $key] = (bool) $value;
        }

        return new self($normalised);
    }

    public function isEnabled(string $flag): bool
    {
        if (in_array($flag, self::FREE_FEATURES, true)) {
            return true;
        }

        return $this->flags[$flag] ?? false;
    }

    /** @return array<string, bool> */
    public function all(): array
    {
        return $this->flags;
    }
}

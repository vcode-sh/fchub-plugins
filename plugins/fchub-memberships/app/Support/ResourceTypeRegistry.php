<?php

namespace FChubMemberships\Support;

use FChubMemberships\Integration\Community\CommunityCapabilityRegistry;

defined('ABSPATH') || exit;

class ResourceTypeRegistry
{
    /** @var array<string, string> */
    private const READ_ONLY_LEGACY_ALIASES = [
        'sfwd-courses' => 'ld_course',
    ];

    /** @var string[] */
    private const EXCLUDED_LEGACY_LEARNDASH_TYPES = [
        'sfwd-courses',
        'sfwd-lessons',
    ];

    private static ?self $instance = null;

    /** @var array<string, array> */
    private array $types = [];

    private bool $initialized = false;

    public function __construct(private ?CommunityCapabilityRegistry $communityCapabilities = null)
    {
        $this->communityCapabilities ??= new CommunityCapabilityRegistry();
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Get all registered resource types.
     *
     * @return array<string, array>
     */
    public function getAll(): array
    {
        $this->ensureInitialized();
        return $this->types;
    }

    /**
     * Get resource types filtered by group.
     *
     * @return array<string, array>
     */
    public function getByGroup(string $group): array
    {
        $this->ensureInitialized();
        return array_filter($this->types, fn(array $type) => $type['group'] === $group);
    }

    /**
     * Get a single resource type by key.
     */
    public function get(string $key): ?array
    {
        $this->ensureInitialized();
        return $this->types[$key] ?? null;
    }

    /**
     * Resolve persisted legacy resource types for display without accepting them for new writes.
     */
    public function resolveReadType(string $key): string
    {
        return self::READ_ONLY_LEGACY_ALIASES[$key] ?? $key;
    }

    /**
     * Get a resource type configuration for display of persisted rules.
     */
    public function getForRead(string $key): ?array
    {
        $readKey = $this->resolveReadType($key);
        $type = $this->get($readKey);
        if ($type !== null) {
            return $type;
        }

        if ($key === 'sfwd-lessons') {
            return [
                'key'           => 'sfwd-lessons',
                'label'         => __('LearnDash Lesson', 'fchub-memberships'),
                'group'         => 'content',
                'icon'          => 'welcome-learn-more',
                'searchable'    => false,
                'supports_bulk' => false,
                'allow_all'     => true,
                'provider'      => Constants::PROVIDER_WORDPRESS_CORE,
                'adapter'       => \FChubMemberships\Adapters\WordPressContentAdapter::class,
                'source'        => 'LearnDash (legacy read-only)',
                'read_only'     => true,
            ];
        }

        return match ($readKey) {
            'ld_course' => [
                'key'           => $readKey,
                'label'         => __('LearnDash Course', 'fchub-memberships'),
                'group'         => 'content',
                'icon'          => 'welcome-learn-more',
                'searchable'    => false,
                'supports_bulk' => false,
                'allow_all'     => false,
                'provider'      => Constants::PROVIDER_LEARNDASH,
                'adapter'       => \FChubMemberships\Adapters\LearnDashAdapter::class,
                'source'        => 'LearnDash (inactive, read-only)',
                'read_only'     => true,
            ],
            'ld_group' => [
                'key'           => $readKey,
                'label'         => __('LearnDash Group', 'fchub-memberships'),
                'group'         => 'content',
                'icon'          => 'welcome-learn-more',
                'searchable'    => false,
                'supports_bulk' => false,
                'allow_all'     => false,
                'provider'      => Constants::PROVIDER_LEARNDASH,
                'adapter'       => \FChubMemberships\Adapters\LearnDashAdapter::class,
                'source'        => 'LearnDash (inactive, read-only)',
                'read_only'     => true,
            ],
            'fluentcrm_tag' => [
                'key'           => $readKey,
                'label'         => __('FluentCRM Tag', 'fchub-memberships'),
                'group'         => 'content',
                'icon'          => 'tag',
                'searchable'    => false,
                'supports_bulk' => false,
                'allow_all'     => false,
                'provider'      => Constants::PROVIDER_FLUENTCRM,
                'adapter'       => \FChubMemberships\Adapters\FluentCrmAdapter::class,
                'source'        => 'FluentCRM (inactive, read-only)',
                'read_only'     => true,
            ],
            'fluentcrm_list' => [
                'key'           => $readKey,
                'label'         => __('FluentCRM List', 'fchub-memberships'),
                'group'         => 'content',
                'icon'          => 'list-view',
                'searchable'    => false,
                'supports_bulk' => false,
                'allow_all'     => false,
                'provider'      => Constants::PROVIDER_FLUENTCRM,
                'adapter'       => \FChubMemberships\Adapters\FluentCrmAdapter::class,
                'source'        => 'FluentCRM (inactive, read-only)',
                'read_only'     => true,
            ],
            'fc_space' => [
                'key'           => $readKey,
                'label'         => __('Spaces', 'fchub-memberships'),
                'group'         => 'content',
                'icon'          => 'groups',
                'searchable'    => false,
                'supports_bulk' => false,
                'allow_all'     => false,
                'identifier'    => 'positive_int',
                'provider'      => Constants::PROVIDER_FLUENT_COMMUNITY,
                'adapter'       => \FChubMemberships\Adapters\FluentCommunityAdapter::class,
                'source'        => 'FluentCommunity (inactive, read-only)',
                'read_only'     => true,
            ],
            'fc_course' => [
                'key'           => $readKey,
                'label'         => __('Courses', 'fchub-memberships'),
                'group'         => 'content',
                'icon'          => 'welcome-learn-more',
                'searchable'    => false,
                'supports_bulk' => false,
                'allow_all'     => false,
                'identifier'    => 'positive_int',
                'provider'      => Constants::PROVIDER_FLUENT_COMMUNITY,
                'adapter'       => \FChubMemberships\Adapters\FluentCommunityAdapter::class,
                'source'        => 'FluentCommunity (inactive, read-only)',
                'read_only'     => true,
            ],
            'fc_badge' => [
                'key'           => $readKey,
                'label'         => __('Community Badge', 'fchub-memberships'),
                'group'         => 'content',
                'icon'          => 'awards',
                'searchable'    => false,
                'supports_bulk' => false,
                'allow_all'     => false,
                'identifier'    => 'slug',
                'provider'      => Constants::PROVIDER_FLUENT_COMMUNITY,
                'adapter'       => \FChubMemberships\Adapters\FluentCommunityAdapter::class,
                'source'        => 'FluentCommunity Pro (inactive, read-only)',
                'read_only'     => true,
            ],
            default => null,
        };
    }

    /**
     * Check if a resource type key is valid/registered.
     */
    public function isValid(string $key): bool
    {
        $this->ensureInitialized();
        return isset($this->types[$key]);
    }

    /**
     * Register a new resource type (or override an existing one).
     */
    public function register(string $key, array $config): void
    {
        $this->types[$key] = array_merge([
            'key'            => $key,
            'label'          => $key,
            'group'          => 'content',
            'icon'           => 'file',
            'searchable'     => false,
            'supports_bulk'  => false,
            'allow_all'      => true,
            'provider'       => Constants::PROVIDER_WORDPRESS_CORE,
            'adapter'        => \FChubMemberships\Adapters\WordPressContentAdapter::class,
            'source'         => '',
        ], $config, ['key' => $key]);
    }

    /**
     * Get only resource types that support search.
     *
     * @return array<string, array>
     */
    public function getSearchableTypes(): array
    {
        $this->ensureInitialized();
        return array_filter($this->types, fn(array $type) => $type['searchable']);
    }

    /**
     * Format resource types as select options for frontend dropdowns.
     *
     * @return array<int, array{value: string, label: string, group: string, source: string}>
     */
    public function toSelectOptions(): array
    {
        $this->ensureInitialized();

        $options = [];
        foreach ($this->types as $key => $type) {
            $options[] = [
                'value'  => $key,
                'label'  => $type['label'],
                'group'  => $type['group'],
                'source' => $type['source'] ?? '',
            ];
        }

        return $options;
    }

    /**
     * Get all unique group names.
     *
     * @return string[]
     */
    public function getGroups(): array
    {
        $this->ensureInitialized();
        $groups = array_unique(array_column($this->types, 'group'));
        return array_values($groups);
    }

    /**
     * Get group labels for UI display.
     *
     * @return array<string, string>
     */
    public function getGroupLabels(): array
    {
        return [
            'content'    => __('Content', 'fchub-memberships'),
            'taxonomy'   => __('Taxonomy', 'fchub-memberships'),
            'navigation' => __('Navigation', 'fchub-memberships'),
            'advanced'   => __('Advanced', 'fchub-memberships'),
        ];
    }

    /**
     * Reset the registry (useful for testing).
     */
    public static function reset(): void
    {
        self::$instance = null;
    }

    private function ensureInitialized(): void
    {
        if ($this->initialized) {
            return;
        }

        $this->initialized = true;
        $this->registerDefaults();

        /**
         * Allow plugins to register custom resource types.
         *
         * @param ResourceTypeRegistry $registry The registry instance.
         */
        do_action('fchub_memberships/resource_types', $this);
    }

    /**
     * Taxonomy slugs that are irrelevant for membership access rules.
     */
    private const BLACKLISTED_TAXONOMIES = [
        'product_shipping_class',
        'product_visibility',
        'product_type',
        'nav_menu',
        'link_category',
        'post_format',
        'wp_theme',
        'wp_template_part_area',
    ];

    /**
     * Post types that are irrelevant for membership access rules.
     */
    private const BLACKLISTED_POST_TYPES = [
        'attachment',
        'revision',
        'custom_css',
        'customize_changeset',
        'oembed_cache',
        'user_request',
        'wp_block',
        'wp_template',
        'wp_template_part',
        'wp_global_styles',
        'wp_navigation',
        'wp_font_family',
        'wp_font_face',
        'shop_order',
        'shop_order_refund',
        'shop_coupon',
    ];

    /**
     * Detect the source plugin for a custom post type.
     */
    private function detectPostTypeSource(object $cpt): string
    {
        $name = $cpt->name;

        // FluentCart (check before WooCommerce since both may be active)
        if (
            str_starts_with($name, 'fluent-')
            || str_starts_with($name, 'fct_')
            || str_starts_with($name, 'fct-')
        ) {
            return 'FluentCart';
        }

        // WooCommerce
        if (
            $name === 'product'
            || str_starts_with($name, 'shop_')
        ) {
            if (class_exists('WooCommerce') || defined('WC_ABSPATH')) {
                return 'WooCommerce';
            }
        }

        // LearnDash
        if (str_starts_with($name, 'sfwd-') || str_starts_with($name, 'ld-')) {
            return 'LearnDash';
        }

        // WPForms, GravityForms, etc.
        if (str_starts_with($name, 'wpforms') || str_starts_with($name, 'gf_')) {
            return '';
        }

        return '';
    }

    /**
     * Detect the source plugin for a custom taxonomy.
     */
    private function detectTaxonomySource(object $tax): string
    {
        $name = $tax->name;

        // FluentCart taxonomies (check before WooCommerce — hyphenated slugs)
        if (
            str_starts_with($name, 'product-')
            || str_starts_with($name, 'fct_')
            || str_starts_with($name, 'fct-')
        ) {
            return 'FluentCart';
        }

        // WooCommerce taxonomies (underscored slugs)
        if (
            str_starts_with($name, 'product_')
            || str_starts_with($name, 'pa_')
        ) {
            if (class_exists('WooCommerce') || defined('WC_ABSPATH')) {
                return 'WooCommerce';
            }
        }

        // LearnDash
        if (str_starts_with($name, 'ld_') || str_starts_with($name, 'ld-')) {
            return 'LearnDash';
        }

        return '';
    }

    private function registerDefaults(): void
    {
        // --- Content group: core post types ---
        $this->register('post', [
            'label'          => __('Posts', 'fchub-memberships'),
            'group'          => 'content',
            'icon'           => 'admin-post',
            'searchable'     => true,
            'supports_bulk'  => true,
            'provider'       => Constants::PROVIDER_WORDPRESS_CORE,
            'source'         => 'WordPress',
        ]);

        $this->register('page', [
            'label'          => __('Pages', 'fchub-memberships'),
            'group'          => 'content',
            'icon'           => 'admin-page',
            'searchable'     => true,
            'supports_bulk'  => true,
            'provider'       => Constants::PROVIDER_WORDPRESS_CORE,
            'source'         => 'WordPress',
        ]);

        // Dynamic: register all public custom post types
        $customPostTypes = get_post_types(['public' => true, '_builtin' => false], 'objects');
        foreach ($customPostTypes as $cpt) {
            if (in_array($cpt->name, self::BLACKLISTED_POST_TYPES, true)) {
                continue;
            }
            if (defined('LEARNDASH_VERSION') && in_array($cpt->name, self::EXCLUDED_LEGACY_LEARNDASH_TYPES, true)) {
                continue;
            }
            $source = $this->detectPostTypeSource($cpt);
            $this->register($cpt->name, [
                'label'          => $this->resolveObjectLabel($cpt),
                'group'          => 'content',
                'icon'           => !empty($cpt->menu_icon) ? (string) $cpt->menu_icon : 'admin-post',
                'searchable'     => true,
                'supports_bulk'  => true,
                'provider'       => Constants::PROVIDER_WORDPRESS_CORE,
                'source'         => $source,
            ]);
        }

        // --- Taxonomy group ---
        $this->register('category', [
            'label'          => __('Categories', 'fchub-memberships'),
            'group'          => 'taxonomy',
            'icon'           => 'category',
            'searchable'     => true,
            'supports_bulk'  => true,
            'provider'       => Constants::PROVIDER_WORDPRESS_CORE,
            'source'         => 'WordPress',
        ]);

        $this->register('post_tag', [
            'label'          => __('Tags', 'fchub-memberships'),
            'group'          => 'taxonomy',
            'icon'           => 'tag',
            'searchable'     => true,
            'supports_bulk'  => true,
            'provider'       => Constants::PROVIDER_WORDPRESS_CORE,
            'source'         => 'WordPress',
        ]);

        // Dynamic: register all public custom taxonomies
        $customTaxonomies = get_taxonomies(['public' => true, '_builtin' => false], 'objects');
        foreach ($customTaxonomies as $tax) {
            if (in_array($tax->name, self::BLACKLISTED_TAXONOMIES, true)) {
                continue;
            }
            // Skip WooCommerce product attributes (pa_*) — too granular for membership rules
            if (str_starts_with($tax->name, 'pa_')) {
                continue;
            }
            $source = $this->detectTaxonomySource($tax);
            $this->register($tax->name, [
                'label'          => $this->resolveObjectLabel($tax),
                'group'          => 'taxonomy',
                'icon'           => 'tag',
                'searchable'     => true,
                'supports_bulk'  => true,
                'provider'       => Constants::PROVIDER_WORDPRESS_CORE,
                'source'         => $source,
            ]);
        }

        // --- Navigation group ---
        $this->register('menu_item', [
            'label'          => __('Menu Items', 'fchub-memberships'),
            'group'          => 'navigation',
            'icon'           => 'menu',
            'searchable'     => false,
            'supports_bulk'  => false,
            'provider'       => Constants::PROVIDER_WORDPRESS_CORE,
            'source'         => 'WordPress',
        ]);

        // --- Advanced group ---
        $this->register('comment', [
            'label'          => __('Comments', 'fchub-memberships'),
            'group'          => 'advanced',
            'icon'           => 'admin-comments',
            'searchable'     => false,
            'supports_bulk'  => false,
            'provider'       => Constants::PROVIDER_WORDPRESS_CORE,
            'source'         => 'WordPress',
        ]);

        $this->register('url_pattern', [
            'label'          => __('URL Patterns', 'fchub-memberships'),
            'group'          => 'advanced',
            'icon'           => 'admin-links',
            'searchable'     => false,
            'supports_bulk'  => false,
            'provider'       => Constants::PROVIDER_WORDPRESS_CORE,
        ]);

        $this->register('special_page', [
            'label'          => __('Special Pages', 'fchub-memberships'),
            'group'          => 'advanced',
            'icon'           => 'admin-home',
            'searchable'     => false,
            'supports_bulk'  => false,
            'provider'       => Constants::PROVIDER_WORDPRESS_CORE,
        ]);

        $this->register('more_tag', [
            'label'          => __('More Tag Content', 'fchub-memberships'),
            'group'          => 'advanced',
            'icon'           => 'editor-insertmore',
            'searchable'     => true,
            'supports_bulk'  => true,
            'provider'       => Constants::PROVIDER_WORDPRESS_CORE,
        ]);

        // --- Third-party providers (conditional) ---
        if (defined('LEARNDASH_VERSION')) {
            $this->register('ld_course', [
                'label'          => __('LearnDash Course', 'fchub-memberships'),
                'group'          => 'content',
                'icon'           => 'welcome-learn-more',
                'searchable'     => true,
                'supports_bulk'  => false,
                'allow_all'      => false,
                'provider'       => Constants::PROVIDER_LEARNDASH,
                'adapter'        => \FChubMemberships\Adapters\LearnDashAdapter::class,
                'source'         => 'LearnDash',
            ]);

            $this->register('ld_group', [
                'label'          => __('LearnDash Group', 'fchub-memberships'),
                'group'          => 'content',
                'icon'           => 'welcome-learn-more',
                'searchable'     => true,
                'supports_bulk'  => false,
                'allow_all'      => false,
                'provider'       => Constants::PROVIDER_LEARNDASH,
                'adapter'        => \FChubMemberships\Adapters\LearnDashAdapter::class,
                'source'         => 'LearnDash',
            ]);
        }

        if (defined('FLUENTCRM')) {
            $this->register('fluentcrm_tag', [
                'label'          => __('FluentCRM Tag', 'fchub-memberships'),
                'group'          => 'content',
                'icon'           => 'tag',
                'searchable'     => true,
                'supports_bulk'  => false,
                'allow_all'      => false,
                'provider'       => Constants::PROVIDER_FLUENTCRM,
                'adapter'        => \FChubMemberships\Adapters\FluentCrmAdapter::class,
                'source'         => 'FluentCRM',
            ]);

            $this->register('fluentcrm_list', [
                'label'          => __('FluentCRM List', 'fchub-memberships'),
                'group'          => 'content',
                'icon'           => 'list-view',
                'searchable'     => true,
                'supports_bulk'  => false,
                'allow_all'      => false,
                'provider'       => Constants::PROVIDER_FLUENTCRM,
                'adapter'        => \FChubMemberships\Adapters\FluentCrmAdapter::class,
                'source'         => 'FluentCRM',
            ]);
        }

        if ($this->communityCapabilities->supports('spaces')) {
            $this->register('fc_space', [
                'label'          => __('Spaces', 'fchub-memberships'),
                'group'          => 'content',
                'icon'           => 'groups',
                'searchable'     => true,
                'supports_bulk'  => false,
                'allow_all'      => false,
                'identifier'     => 'positive_int',
                'provider'       => Constants::PROVIDER_FLUENT_COMMUNITY,
                'adapter'        => \FChubMemberships\Adapters\FluentCommunityAdapter::class,
                'source'         => 'FluentCommunity',
            ]);
        }

        if ($this->communityCapabilities->supports('courses')) {
            $this->register('fc_course', [
                'label'          => __('Courses', 'fchub-memberships'),
                'group'          => 'content',
                'icon'           => 'welcome-learn-more',
                'searchable'     => true,
                'supports_bulk'  => false,
                'allow_all'      => false,
                'identifier'     => 'positive_int',
                'provider'       => Constants::PROVIDER_FLUENT_COMMUNITY,
                'adapter'        => \FChubMemberships\Adapters\FluentCommunityAdapter::class,
                'source'         => 'FluentCommunity',
            ]);
        }

        if ($this->communityCapabilities->supports('badges')) {
            $this->register('fc_badge', [
                'label'          => __('Community Badge', 'fchub-memberships'),
                'group'          => 'content',
                'icon'           => 'awards',
                'searchable'     => true,
                'supports_bulk'  => false,
                'allow_all'      => false,
                'identifier'     => 'slug',
                'provider'       => Constants::PROVIDER_FLUENT_COMMUNITY,
                'adapter'        => \FChubMemberships\Adapters\FluentCommunityAdapter::class,
                'source'         => 'FluentCommunity Pro',
            ]);
        }
    }

    private function resolveObjectLabel(object $object): string
    {
        $singular = '';
        if (isset($object->labels) && is_object($object->labels) && isset($object->labels->singular_name)) {
            $singular = (string) $object->labels->singular_name;
        }

        if ($singular !== '') {
            return $singular;
        }

        if (isset($object->label) && $object->label !== '') {
            return (string) $object->label;
        }

        return isset($object->name) ? (string) $object->name : '';
    }
}

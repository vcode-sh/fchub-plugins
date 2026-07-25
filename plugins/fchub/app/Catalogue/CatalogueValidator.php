<?php

namespace FChubHub\Catalogue;

use UnexpectedValueException;

defined('ABSPATH') || exit;

/**
 * The trust boundary. Everything downstream — the state resolver, the REST
 * reads, the package downloader — acts on whatever this class hands back, so
 * this class assumes the payload is hostile until proven otherwise.
 *
 * Nothing is repaired, coerced, or waved through: a catalogue is either
 * entirely valid or it is rejected, and a rejected catalogue never replaces
 * the copy already on disk.
 */
final class CatalogueValidator
{
    public const SCHEMA_VERSION = 1;

    /** The only slugs FCHub 1.0.0 will ever install, in catalogue order. */
    public const SLUGS = [
        'fchub-p24',
        'fchub-fakturownia',
        'fchub-memberships',
        'fchub-portal-extender',
        'fchub-wishlist',
        'fchub-multi-currency',
    ];

    public const HUB_PLUGIN_FILE = 'fchub/fchub.php';

    /**
     * A relative wp-admin destination and nothing else: no scheme, no host, no
     * traversal, no whitespace. Shared with DescriptorRegistry so a product's
     * own descriptor is held to exactly the same rule as the catalogue.
     *
     * The D modifier matters: without it PHP's `$` also matches immediately
     * before a trailing newline, so "admin.php?page=x\n" would sail through a
     * pattern written to forbid exactly that sort of thing.
     */
    public const ADMIN_PATH_PATTERN = '~^[a-z0-9_-]+\.php(\?[A-Za-z0-9_\-=&%.\[\]]*)?(#[A-Za-z0-9_\-/?=&%.]*)?$~D';

    private const DOCS_HOSTS = ['fchub.co'];

    private const PACKAGE_HOSTS = [
        'github.com',
        'objects.githubusercontent.com',
        'release-assets.githubusercontent.com',
    ];

    private const TOP_LEVEL_KEYS = ['schema_version', 'hub', 'products'];

    private const HUB_KEYS = ['version', 'plugin_file', 'release_url', 'package_url', 'checksum_url'];

    /** The normalised product shape, in the order the design lists it. */
    private const PRODUCT_KEYS = [
        'name',
        'description',
        'version',
        'plugin_file',
        'requires_wp',
        'requires_php',
        'dependencies',
        'docs_url',
        'release_url',
        'package_url',
        'checksum_url',
        'admin_path',
        'status',
    ];

    /**
     * Accepted on the way in and dropped on the way out. The generator emits
     * docs_path for the website; the absolute docs_url already says everything
     * the plugin needs, and one source of truth beats two.
     */
    private const DROPPED_PRODUCT_KEYS = ['docs_path'];

    private const STATUSES = ['stable'];

    /** Anchored with D so a trailing newline cannot ride along. */
    private const VERSION_PATTERN = '/^[0-9]+(\.[0-9]+)*([-+][0-9A-Za-z.-]+)?$/D';

    private const DEPENDENCY_PATTERN = '/^[a-z0-9-]+$/D';

    /**
     * Caps for the only two free-form fields in the catalogue. Current real
     * values top out at 15 and 196 characters, so there is room to write a
     * proper description without room to ship a novel — or a payload.
     */
    private const MAX_NAME_LENGTH = 120;

    private const MAX_DESCRIPTION_LENGTH = 400;

    /**
     * @param array<string, mixed> $catalogue
     * @return array{schema_version: int, hub: array<string, string>, products: array<string, array<string, mixed>>}
     *
     * @throws UnexpectedValueException with a stable internal code. Task 4 maps
     *         these to friendly public messages; they are never shown as-is.
     */
    public function validate(array $catalogue): array
    {
        $this->assertKeys($catalogue, self::TOP_LEVEL_KEYS, [], 'catalogue_schema_invalid', 'catalogue');

        if (($catalogue['schema_version'] ?? null) !== self::SCHEMA_VERSION) {
            $this->fail('catalogue_schema_invalid', 'schema_version');
        }

        if (!is_array($catalogue['products']) || $catalogue['products'] === []) {
            $this->fail('catalogue_products_invalid', 'products');
        }

        $packageHosts = $this->allowedPackageHosts();

        $products = [];

        foreach ($catalogue['products'] as $slug => $product) {
            if (!is_string($slug) || !in_array($slug, self::SLUGS, true)) {
                $this->fail('catalogue_slug_unknown', (string) $slug);
            }

            if (!is_array($product)) {
                $this->fail('catalogue_product_invalid', $slug);
            }

            $products[$slug] = $this->normaliseProduct($slug, $product, $packageHosts);
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'hub' => $this->normaliseHub($catalogue['hub'], $packageHosts),
            'products' => $products,
        ];
    }

    /**
     * The hub record is update metadata for FCHub itself. It is held to the
     * same HTTPS and release-host rules as a product, and it never appears in
     * the product map the interface renders.
     *
     * @param mixed $hub
     * @param list<string> $packageHosts
     * @return array<string, string>
     */
    private function normaliseHub($hub, array $packageHosts): array
    {
        if (!is_array($hub)) {
            $this->fail('catalogue_hub_invalid', 'hub');
        }

        $this->assertKeys($hub, self::HUB_KEYS, [], 'catalogue_hub_invalid', 'hub');

        if ($hub['plugin_file'] !== self::HUB_PLUGIN_FILE) {
            $this->fail('catalogue_hub_invalid', 'hub.plugin_file');
        }

        $version = $this->assertVersion($hub['version'], 'hub.version');

        $normalised = [
            'version' => $version,
            'plugin_file' => self::HUB_PLUGIN_FILE,
        ];

        foreach (['release_url', 'package_url', 'checksum_url'] as $key) {
            $normalised[$key] = $this->assertUrl(
                $hub[$key],
                $packageHosts,
                'catalogue_package_host_invalid',
                'hub.' . $key
            );
        }

        return $normalised;
    }

    /**
     * @param array<string, mixed> $product
     * @param list<string> $packageHosts
     * @return array<string, mixed>
     */
    private function normaliseProduct(string $slug, array $product, array $packageHosts): array
    {
        $this->assertKeys(
            $product,
            self::PRODUCT_KEYS,
            self::DROPPED_PRODUCT_KEYS,
            'catalogue_product_invalid',
            $slug
        );

        if ($product['plugin_file'] !== $slug . '/' . $slug . '.php') {
            $this->fail('catalogue_plugin_file_invalid', $slug . '.plugin_file');
        }

        if (!in_array($product['status'], self::STATUSES, true)) {
            $this->fail('catalogue_status_invalid', $slug . '.status');
        }

        if (!is_string($product['admin_path']) || preg_match(self::ADMIN_PATH_PATTERN, $product['admin_path']) !== 1) {
            $this->fail('catalogue_admin_path_invalid', $slug . '.admin_path');
        }

        return [
            'name' => $this->assertText($product['name'], self::MAX_NAME_LENGTH, $slug . '.name'),
            'description' => $this->assertText(
                $product['description'],
                self::MAX_DESCRIPTION_LENGTH,
                $slug . '.description'
            ),
            'version' => $this->assertVersion($product['version'], $slug . '.version'),
            'plugin_file' => $product['plugin_file'],
            'requires_wp' => $this->assertVersion($product['requires_wp'], $slug . '.requires_wp'),
            'requires_php' => $this->assertVersion($product['requires_php'], $slug . '.requires_php'),
            'dependencies' => $this->assertDependencies($product['dependencies'], $slug),
            'docs_url' => $this->assertUrl(
                $product['docs_url'],
                self::DOCS_HOSTS,
                'catalogue_docs_host_invalid',
                $slug . '.docs_url'
            ),
            'release_url' => $this->assertUrl(
                $product['release_url'],
                $packageHosts,
                'catalogue_package_host_invalid',
                $slug . '.release_url'
            ),
            'package_url' => $this->assertUrl(
                $product['package_url'],
                $packageHosts,
                'catalogue_package_host_invalid',
                $slug . '.package_url'
            ),
            'checksum_url' => $this->assertUrl(
                $product['checksum_url'],
                $packageHosts,
                'catalogue_package_host_invalid',
                $slug . '.checksum_url'
            ),
            'admin_path' => $product['admin_path'],
            'status' => $product['status'],
        ];
    }

    /**
     * @param array<string, mixed> $subject
     * @param list<string> $required
     * @param list<string> $optional
     */
    private function assertKeys(array $subject, array $required, array $optional, string $code, string $context): void
    {
        $missing = array_diff($required, array_keys($subject));

        if ($missing !== []) {
            $this->fail($code, $context . ' missing ' . implode(',', $missing));
        }

        $unexpected = array_diff(array_keys($subject), $required, $optional);

        if ($unexpected !== []) {
            $this->fail($code, $context . ' unexpected ' . implode(',', $unexpected));
        }
    }

    /**
     * The catalogue's only free-form fields, and the only two that get rendered
     * — so they leave here stripped of markup and bounded in length rather than
     * exactly as some endpoint sent them. Escaping at output is still Task 5's
     * job; this is the belt to that pair of braces.
     *
     * @param mixed $value
     */
    private function assertText($value, int $maxLength, string $context): string
    {
        if (!is_string($value)) {
            $this->fail('catalogue_product_invalid', $context);
        }

        $clean = sanitize_text_field($value);

        if ($clean === '') {
            $this->fail('catalogue_product_invalid', $context);
        }

        // WordPress does not assume mbstring exists, so neither does this.
        // Without it the cap is measured in bytes, which for UTF-8 is strictly
        // stricter than characters — it fails closed, never open.
        $length = function_exists('mb_strlen') ? mb_strlen($clean) : strlen($clean);

        if ($length > $maxLength) {
            $this->fail('catalogue_text_too_long', $context);
        }

        return $clean;
    }

    /**
     * @param mixed $value
     */
    private function assertVersion($value, string $context): string
    {
        if (
            !is_string($value)
            || preg_match(self::VERSION_PATTERN, $value) !== 1
            || !version_compare($value, '0', '>')
        ) {
            $this->fail('catalogue_version_invalid', $context);
        }

        return $value;
    }

    /**
     * @param mixed $value
     * @return list<string>
     */
    private function assertDependencies($value, string $slug): array
    {
        if (!is_array($value) || array_is_list($value) === false) {
            $this->fail('catalogue_dependencies_invalid', $slug . '.dependencies');
        }

        foreach ($value as $dependency) {
            if (!is_string($dependency) || preg_match(self::DEPENDENCY_PATTERN, $dependency) !== 1) {
                $this->fail('catalogue_dependencies_invalid', $slug . '.dependencies');
            }
        }

        return array_values($value);
    }

    /**
     * @param mixed $value
     * @param list<string> $allowedHosts
     */
    private function assertUrl($value, array $allowedHosts, string $hostCode, string $context): string
    {
        if (!is_string($value) || $value === '') {
            $this->fail('catalogue_url_invalid', $context);
        }

        $scheme = wp_parse_url($value, PHP_URL_SCHEME);
        $host = wp_parse_url($value, PHP_URL_HOST);

        if (!is_string($scheme) || !is_string($host) || $host === '') {
            $this->fail('catalogue_url_invalid', $context);
        }

        // The harness in tests/e2e serves its fixtures over plain HTTP from a
        // container. Production ships no such filter, so production stays
        // HTTPS-only whatever a catalogue claims.
        $allowHttp = (bool) apply_filters('fchub/catalogue/allow_http', false, $value);

        if ($scheme !== 'https' && !($scheme === 'http' && $allowHttp)) {
            $this->fail('catalogue_url_insecure', $context);
        }

        if (!in_array(strtolower($host), $allowedHosts, true)) {
            $this->fail($hostCode, $context);
        }

        return $value;
    }

    /**
     * @return list<string>
     */
    private function allowedPackageHosts(): array
    {
        $hosts = apply_filters('fchub/catalogue/allowed_package_hosts', self::PACKAGE_HOSTS);

        if (!is_array($hosts)) {
            return self::PACKAGE_HOSTS;
        }

        $clean = [];

        foreach ($hosts as $host) {
            if (is_string($host) && $host !== '') {
                $clean[] = strtolower($host);
            }
        }

        // A filter that returns nothing usable gets the shipped list back
        // rather than an empty allow-list, which would fail open on hosts.
        return $clean === [] ? self::PACKAGE_HOSTS : $clean;
    }

    private function fail(string $code, string $context): never
    {
        throw new UnexpectedValueException(sprintf('%s: %s', $code, $context));
    }
}

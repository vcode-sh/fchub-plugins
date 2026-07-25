<?php

namespace FChubHub\Catalogue;

defined('ABSPATH') || exit;

/**
 * Works out where each catalogue product stands on this site, across four
 * dimensions that are deliberately kept apart:
 *
 *   lifecycle     — not_installed | inactive | active
 *   update        — current | available | unknown
 *   compatibility — compatible | blocked | unknown
 *   health        — healthy | attention | unknown
 *
 * Collapsing those into one status is how interfaces end up insisting a plugin
 * is either "active" or "outdated", as though it could not manage both.
 *
 * This class resolves and nothing else: it installs nothing, activates
 * nothing, and makes no HTTP request. It is handed the catalogue, WordPress's
 * plugin inventory, the active list, and the accepted descriptors, and it
 * hands back state.
 */
final class ProductStateResolver
{
    /** Dependencies FCHub knows how to look for. Anything else is unknown. */
    private const DEPENDENCIES = [
        'fluentcart' => ['constant' => 'FLUENTCART_VERSION', 'plugin_file' => 'fluent-cart/fluent-cart.php'],
    ];

    /**
     * A deliberate second copy of ProductOperationService::CAPABILITIES.
     *
     * That one decides what an operation permits; this one decides which
     * buttons the screen is offered, and app/Catalogue does not get to depend
     * on app/Operations to find out. The two must agree — drift either way and
     * the interface offers a button the operation refuses, or hides one it
     * would have allowed — so ProductStateResolverTest pins them together.
     */
    private const ACTION_CAPABILITIES = [
        'install' => ['install_plugins'],
        'install-and-activate' => ['install_plugins', 'activate_plugins'],
        'activate' => ['activate_plugins'],
        'update' => ['update_plugins'],
        'deactivate' => ['activate_plugins'],
    ];

    /**
     * Deliberately a separate copy of CatalogueValidator's pattern rather than
     * a shared constant. That one governs what a first-party catalogue may
     * declare; this one governs whatever string a third-party plugin header
     * happens to contain. They agree today and are free to stop agreeing —
     * loosening what WordPress will hand us must not loosen the trust boundary.
     */
    private const VERSION_PATTERN = '/^[0-9]+(\.[0-9]+)*([-+][0-9A-Za-z.-]+)?$/D';

    public function __construct(
        private readonly string $phpVersion,
        private readonly string $wpVersion
    ) {
    }

    public static function forSite(): self
    {
        return new self(PHP_VERSION, (string) get_bloginfo('version'));
    }

    /**
     * @param array<string, mixed> $catalogue **Must** be CatalogueValidator output.
     *        Every product key is read unguarded because the validator has
     *        already guaranteed all of them; hand it anything else and you get
     *        a cascade of undefined-key warnings instead of one clear failure.
     * @param array<string, array<string, mixed>> $installed WordPress's get_plugins() inventory.
     * @param list<string> $active WordPress's active plugin files.
     * @param array<string, array<string, mixed>> $descriptors Accepted descriptors, keyed by slug.
     * @return array<string, array<string, mixed>>
     */
    public function resolve(array $catalogue, array $installed, array $active, array $descriptors): array
    {
        $products = $catalogue['products'] ?? [];

        if (!is_array($products)) {
            return [];
        }

        $states = [];

        foreach ($products as $slug => $product) {
            $states[$slug] = $this->resolveProduct(
                (string) $slug,
                $product,
                $installed,
                $active,
                $descriptors[$slug] ?? null
            );
        }

        return $states;
    }

    /**
     * @param array<string, mixed> $product
     * @param array<string, array<string, mixed>> $installed
     * @param list<string> $active
     * @param array<string, mixed>|null $descriptor
     * @return array<string, mixed>
     */
    private function resolveProduct(
        string $slug,
        array $product,
        array $installed,
        array $active,
        ?array $descriptor
    ): array {
        $pluginFile = (string) $product['plugin_file'];

        $lifecycle = $this->lifecycle($pluginFile, $installed, $active);
        $installedVersion = $this->installedVersion($pluginFile, $installed);
        $update = $this->update($installedVersion, (string) $product['version']);

        $compatibility = $this->compatibility($product, $active);

        // Only a running plugin can publish its own descriptor. Anything
        // claiming to speak for a product that is not active is speaking for
        // someone else, so its health and settings link are ignored.
        $trusted = $lifecycle === 'active' ? $descriptor : null;

        $health = $trusted['health'] ?? null;

        return [
            'slug' => $slug,
            'lifecycle' => $lifecycle,
            'update' => $update,
            'compatibility' => $compatibility['status'],
            'compatibility_reason' => $compatibility['reason'],
            'health' => is_array($health) ? (string) $health['status'] : 'unknown',
            'health_message' => is_array($health) ? $health['message'] : null,
            'installed_version' => $installedVersion,
            'admin_url' => $this->adminUrl($lifecycle, $product, $trusted),
            'actions' => $this->actions($lifecycle, $update, $compatibility['status']),
        ];
    }

    /**
     * What this site actually is, for the one screen that has to say so out
     * loud: the two version floors every product is measured against, and the
     * platform most of them sit on.
     *
     * `fluentcart` is null when FluentCart is not running here. Installed but
     * switched off counts as absent, because a product that needs it will not
     * load either — and the pathological case of an active plugin missing from
     * WordPress's own inventory reads as absent too, which is the honest answer
     * for an installation in that state.
     *
     * @param array<string, array<string, mixed>> $installed WordPress's get_plugins() inventory.
     * @param list<string> $active WordPress's active plugin files.
     * @return array{wp: string, php: string, fluentcart: string|null}
     */
    public function site(array $installed, array $active): array
    {
        return [
            'wp' => $this->wpVersion,
            'php' => $this->phpVersion,
            'fluentcart' => $this->dependencyVersion('fluentcart', $installed, $active),
        ];
    }

    /**
     * @param array<string, array<string, mixed>> $installed
     * @param list<string> $active
     */
    private function dependencyVersion(string $dependency, array $installed, array $active): ?string
    {
        if (!isset(self::DEPENDENCIES[$dependency]) || !$this->hasDependency($dependency, $active)) {
            return null;
        }

        $signature = self::DEPENDENCIES[$dependency];

        // The constant is the platform speaking for itself and is preferred;
        // the plugin header is what WordPress read off disk, and covers a
        // platform that is active but has not defined its constant yet.
        if (defined($signature['constant'])) {
            $version = constant($signature['constant']);

            if (is_string($version) && $version !== '') {
                return $version;
            }
        }

        $header = $installed[$signature['plugin_file']]['Version'] ?? null;

        return is_string($header) && $header !== '' ? $header : null;
    }

    /**
     * @param array<string, array<string, mixed>> $installed
     * @param list<string> $active
     */
    private function lifecycle(string $pluginFile, array $installed, array $active): string
    {
        if (!array_key_exists($pluginFile, $installed)) {
            return 'not_installed';
        }

        return in_array($pluginFile, $active, true) ? 'active' : 'inactive';
    }

    /**
     * @param array<string, array<string, mixed>> $installed
     */
    private function installedVersion(string $pluginFile, array $installed): ?string
    {
        $version = $installed[$pluginFile]['Version'] ?? null;

        return is_string($version) && $version !== '' ? $version : null;
    }

    /**
     * A product that is not installed is neither current nor outdated, so it
     * gets the honest answer rather than a flattering one.
     */
    private function update(?string $installedVersion, string $catalogueVersion): string
    {
        if ($installedVersion === null || preg_match(self::VERSION_PATTERN, $installedVersion) !== 1) {
            return 'unknown';
        }

        return version_compare($catalogueVersion, $installedVersion, '>') ? 'available' : 'current';
    }

    /**
     * @param array<string, mixed> $product
     * @param list<string> $active
     * @return array{status: string, reason: array<string, string|null>|null}
     */
    private function compatibility(array $product, array $active): array
    {
        $requiresPhp = (string) $product['requires_php'];
        $requiresWp = (string) $product['requires_wp'];

        if (version_compare($this->phpVersion, $requiresPhp, '<')) {
            return $this->incompatible('blocked', 'php', $requiresPhp, $this->phpVersion);
        }

        if ($this->wpVersion === '') {
            return $this->incompatible('unknown', 'wp', $requiresWp, null);
        }

        if (version_compare($this->wpVersion, $requiresWp, '<')) {
            return $this->incompatible('blocked', 'wp', $requiresWp, $this->wpVersion);
        }

        foreach ((array) $product['dependencies'] as $dependency) {
            $dependency = (string) $dependency;

            if (!isset(self::DEPENDENCIES[$dependency])) {
                return $this->incompatible('unknown', 'dependency', $dependency, null);
            }

            if (!$this->hasDependency($dependency, $active)) {
                return $this->incompatible('blocked', 'dependency', $dependency, null);
            }
        }

        return ['status' => 'compatible', 'reason' => null];
    }

    /**
     * @return array{status: string, reason: array<string, string|null>}
     */
    private function incompatible(string $status, string $requirement, string $required, ?string $current): array
    {
        return [
            'status' => $status,
            'reason' => [
                'requirement' => $requirement,
                'required' => $required,
                'current' => $current,
            ],
        ];
    }

    /**
     * @param list<string> $active
     */
    private function hasDependency(string $dependency, array $active): bool
    {
        $signature = self::DEPENDENCIES[$dependency];

        return defined($signature['constant']) || in_array($signature['plugin_file'], $active, true);
    }

    /**
     * @param array<string, mixed> $product
     * @param array<string, mixed>|null $descriptor
     */
    private function adminUrl(string $lifecycle, array $product, ?array $descriptor): ?string
    {
        if ($lifecycle !== 'active') {
            return null;
        }

        $adminPath = $descriptor['admin_path'] ?? null;

        if (!is_string($adminPath) || $adminPath === '') {
            $adminPath = (string) $product['admin_path'];
        }

        return $adminPath === '' ? null : admin_url(ltrim($adminPath, '/'));
    }

    /**
     * Actions the current user may genuinely run right now. Anything blocked,
     * unverifiable, or beyond their capabilities is simply not offered — the
     * interface explains why separately rather than dangling a button that
     * would be refused.
     *
     * @return list<string>
     */
    private function actions(string $lifecycle, string $update, string $compatibility): array
    {
        $safe = $compatibility === 'compatible';
        $candidates = [];

        if ($lifecycle === 'not_installed' && $safe) {
            $candidates = ['install', 'install-and-activate'];
        }

        if ($lifecycle === 'inactive' && $safe) {
            $candidates[] = 'activate';
        }

        if ($lifecycle !== 'not_installed' && $update === 'available' && $safe) {
            $candidates[] = 'update';
        }

        // Switching a product off is always available to anyone who could have
        // switched it on, incompatible or not. Trapping an administrator with
        // a plugin they cannot disable would be a bold interpretation of calm.
        if ($lifecycle === 'active') {
            $candidates[] = 'deactivate';
        }

        return array_values(array_filter($candidates, fn (string $action): bool => $this->isPermitted($action)));
    }

    private function isPermitted(string $action): bool
    {
        // An action this map has never heard of is refused, not fatal. This
        // runs inside a read-only REST route, and a resolver that crashes over
        // a name it does not recognise takes the whole screen with it.
        foreach (self::ACTION_CAPABILITIES[$action] ?? ['do_not_allow'] as $capability) {
            if (!current_user_can($capability)) {
                return false;
            }
        }

        return true;
    }
}

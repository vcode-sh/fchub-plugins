<?php

namespace FChubHub\Operations;

use Closure;
use FChubHub\Catalogue\CatalogueRepository;
use FChubHub\Catalogue\CatalogueValidator;
use FChubHub\Catalogue\DescriptorRegistry;
use FChubHub\Catalogue\ProductStateResolver;

defined('ABSPATH') || exit;

/**
 * Everything FCHub is willing to do to a plugin, and the order it insists on
 * doing it in:
 *
 *     capability -> catalogue -> lifecycle -> compatibility -> verified package
 *     -> WordPress core -> confirmation -> clean up
 *
 * Nothing in here installs an arbitrary archive, deletes product files, or
 * touches a product's own options and tables. The only inputs it will act on
 * are slugs the trusted catalogue already knows about, and the only tools it
 * uses are WordPress's own — injected as callables so the guard order can be
 * proved without an upgrader, a filesystem, or a plausible excuse to download
 * anything.
 */
final class ProductOperationService
{
    /**
     * The capability every action needs, and the map both this service and the
     * REST permission callbacks read. One map means a route cannot quietly ask
     * for less than the operation behind it.
     */
    public const CAPABILITIES = [
        'install' => ['install_plugins'],
        'install-and-activate' => ['install_plugins', 'activate_plugins'],
        'activate' => ['activate_plugins'],
        'update' => ['update_plugins'],
        'deactivate' => ['activate_plugins'],
    ];

    /** Dependency ids as a human would write them. Anything unmapped is shown as-is. */
    private const DEPENDENCY_LABELS = ['fluentcart' => 'FluentCart'];

    /**
     * @param Closure(): array<string, array<string, mixed>> $installedPlugins
     * @param Closure(): list<string> $activePlugins
     * @param Closure(string, bool, string): mixed $installer Hands a local ZIP to WordPress, told
     *        whether this is an update and which plugin file it is replacing. True means installed.
     * @param Closure(string): mixed $activator Null on success, WP_Error otherwise — activate_plugin()'s contract.
     * @param Closure(string): void $deactivator
     * @param Closure(): void $refreshInventory
     * @param Closure(string): void $logger Internal diagnostics sink. Never reaches a response.
     */
    public function __construct(
        private readonly CatalogueRepository $repository,
        private readonly ProductStateResolver $resolver,
        private readonly VerifiedPackageDownloader $downloader,
        private readonly Closure $installedPlugins,
        private readonly Closure $activePlugins,
        private readonly Closure $installer,
        private readonly Closure $activator,
        private readonly Closure $deactivator,
        private readonly Closure $refreshInventory,
        private readonly Closure $logger
    ) {
    }

    public static function forSite(): self
    {
        return new self(
            repository: CatalogueRepository::forSiteShared(),
            resolver: ProductStateResolver::forSite(),
            downloader: VerifiedPackageDownloader::forSite(),
            installedPlugins: static function (): array {
                require_once ABSPATH . 'wp-admin/includes/plugin.php';

                return get_plugins();
            },
            activePlugins: static fn (): array => array_values(
                array_filter((array) get_option('active_plugins', []), 'is_string')
            ),
            installer: static function (string $zip, bool $isUpdate, string $pluginFile = '') {
                require_once ABSPATH . 'wp-admin/includes/file.php';
                require_once ABSPATH . 'wp-admin/includes/plugin.php';
                require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

                // Added for exactly one call and taken off again, so nothing
                // else on the site ever runs through FCHub's filter.
                $packageOptions = self::packageOptions($isUpdate, $pluginFile);

                add_filter('upgrader_package_options', $packageOptions);

                try {
                    // Automatic_Upgrader_Skin collects its commentary instead of
                    // echoing it into the middle of a JSON response.
                    $upgrader = new \Plugin_Upgrader(new \Automatic_Upgrader_Skin());

                    return $upgrader->install($zip, [
                        'overwrite_package' => $isUpdate,
                        'clear_update_cache' => true,
                    ]);
                } finally {
                    remove_filter('upgrader_package_options', $packageOptions);
                }
            },
            activator: static function (string $pluginFile) {
                require_once ABSPATH . 'wp-admin/includes/plugin.php';

                return activate_plugin($pluginFile);
            },
            deactivator: static function (string $pluginFile): void {
                require_once ABSPATH . 'wp-admin/includes/plugin.php';

                deactivate_plugins([$pluginFile]);
            },
            refreshInventory: static function (): void {
                require_once ABSPATH . 'wp-admin/includes/plugin.php';

                wp_clean_plugins_cache(true);
            },
            logger: self::debugLogger()
        );
    }

    /**
     * What FCHub adds to an update before WordPress unpacks anything.
     *
     * `Plugin_Upgrader::install()` hard-codes `action => 'install'` and passes
     * no `temp_backup`, while `Plugin_Upgrader::upgrade()` — the Plugins screen's
     * path — passes both. That difference is not cosmetic: with
     * `overwrite_package` set, core deletes the product's directory *before*
     * copying the new files in, and its restore branch only runs when a
     * `temp_backup` exists. A disk filling up, a permissions flip or a timeout
     * mid-copy would therefore leave the product deleted where the Plugins
     * screen would have put it back. `WP_Upgrader::run()` applies
     * `upgrader_package_options` before any of that happens, which is where the
     * missing pieces go in.
     *
     * Supplying `plugin` and `action` alongside it also means
     * `upgrader_process_complete` fires as the update it actually is, so a
     * product that keys a schema migration off an update sees the same event
     * whether it was updated through FCHub or through the Plugins screen.
     *
     * Public because this is the guarantee worth proving, and proving it
     * through a real `Plugin_Upgrader` would mean standing up a filesystem.
     *
     * @return Closure(array<string, mixed>): array<string, mixed>
     */
    public static function packageOptions(bool $isUpdate, string $pluginFile): Closure
    {
        return static function (array $options) use ($isUpdate, $pluginFile): array {
            // A fresh install has nothing to back up and is not an update, so
            // it is handed back exactly as core built it.
            if (!$isUpdate || $pluginFile === '') {
                return $options;
            }

            $hookExtra = is_array($options['hook_extra'] ?? null) ? $options['hook_extra'] : [];

            $hookExtra['plugin'] = $pluginFile;
            $hookExtra['action'] = 'update';
            $hookExtra['temp_backup'] = [
                'slug' => dirname($pluginFile),
                'src' => WP_PLUGIN_DIR,
                'dir' => 'plugins',
            ];

            $options['hook_extra'] = $hookExtra;

            return $options;
        };
    }

    /**
     * The default diagnostics sink: silent unless the site has asked for noise.
     *
     * It lives here rather than in the HTTP layer because operations are what
     * generate diagnostics, and a controller is not something app/Operations
     * should have to know exists.
     */
    public static function debugLogger(): Closure
    {
        return static function (string $message): void {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('FCHub: ' . $message);
            }
        };
    }

    /**
     * Whether the current user may run an action at all. Shared with the REST
     * permission callbacks; an unrecognised action is never permitted.
     */
    public static function userCan(string $action): bool
    {
        foreach (self::CAPABILITIES[$action] ?? ['do_not_allow'] as $capability) {
            if (!current_user_can($capability)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Where every product on this site stands right now. Deliberately not
     * memoised: it is read before an operation and again afterwards, and the
     * second read is the entire point.
     *
     * @return array{
     *     source: string,
     *     last_refresh: string|null,
     *     catalogue: array<string, mixed>,
     *     site: array{wp: string, php: string, fluentcart: string|null},
     *     states: array<string, array<string, mixed>>
     * }
     */
    public function snapshot(bool $forceRefresh = false): array
    {
        $envelope = $this->repository->get($forceRefresh);
        $catalogue = $envelope['catalogue'];

        // Read once and handed to both, so the versions the interface prints
        // and the versions the compatibility check used cannot come from two
        // different reads of the same site.
        $installed = ($this->installedPlugins)();
        $active = ($this->activePlugins)();

        return [
            'source' => $envelope['source'],
            'last_refresh' => $envelope['last_refresh'],
            'catalogue' => $catalogue,
            'site' => $this->resolver->site($installed, $active),
            'states' => $this->resolver->resolve(
                $catalogue,
                $installed,
                $active,
                (new DescriptorRegistry())->collect($catalogue)
            ),
        ];
    }

    /**
     * @return array{slug: string, action: string, notice: string}
     */
    public function install(string $slug): array
    {
        [$product, $state] = $this->prepare($slug, 'install');

        $this->assertNotInstalled($product, $state, $slug);
        $this->assertCompatible($product, $state, $slug);
        $this->installPackage($product, $slug, isUpdate: false);

        return $this->outcome($slug, 'install', $product);
    }

    /**
     * @return array{slug: string, action: string, notice: string}
     */
    public function installAndActivate(string $slug): array
    {
        [$product, $state] = $this->prepare($slug, 'install-and-activate');

        $this->assertNotInstalled($product, $state, $slug);
        $this->assertCompatible($product, $state, $slug);
        $this->installPackage($product, $slug, isUpdate: false);
        $this->switchOn($product, $slug, afterInstall: true);

        return $this->outcome($slug, 'install-and-activate', $product);
    }

    /**
     * @return array{slug: string, action: string, notice: string}
     */
    public function activate(string $slug): array
    {
        [$product, $state] = $this->prepare($slug, 'activate');

        if ($state['lifecycle'] === 'not_installed') {
            throw OperationError::create('product_not_installed', [$product['name']], $slug);
        }

        if ($state['lifecycle'] === 'active') {
            throw OperationError::create('product_already_active', [$product['name']], $slug);
        }

        $this->assertCompatible($product, $state, $slug);
        $this->switchOn($product, $slug);

        return $this->outcome($slug, 'activate', $product);
    }

    /**
     * @return array{slug: string, action: string, notice: string}
     */
    public function update(string $slug): array
    {
        [$product, $state] = $this->prepare($slug, 'update');

        if ($state['lifecycle'] === 'not_installed') {
            throw OperationError::create('product_not_installed', [$product['name']], $slug);
        }

        // Anything other than a confirmed newer release — including a version
        // header FCHub cannot read — is not an update, and pretending otherwise
        // would overwrite files on a guess.
        if ($state['update'] !== 'available') {
            throw OperationError::create('update_unavailable', [$product['name']], $slug . ' update: ' . $state['update']);
        }

        $this->assertCompatible($product, $state, $slug);
        $this->installPackage($product, $slug, isUpdate: true);

        return $this->outcome($slug, 'update', $product);
    }

    /**
     * @return array{slug: string, action: string, notice: string}
     */
    public function deactivate(string $slug): array
    {
        [$product, $state] = $this->prepare($slug, 'deactivate');

        if ($state['lifecycle'] === 'not_installed') {
            throw OperationError::create('product_not_installed', [$product['name']], $slug);
        }

        if ($state['lifecycle'] !== 'active') {
            throw OperationError::create('product_not_active', [$product['name']], $slug);
        }

        // Compatibility is deliberately not checked: anyone who could switch a
        // product on can switch it off, working or not.
        ($this->deactivator)($this->pluginFile($product, $slug));

        return $this->outcome($slug, 'deactivate', $product);
    }

    /**
     * Capability first, then existence. An account without the right to act
     * learns nothing about what is or is not in the catalogue.
     *
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function prepare(string $slug, string $action): array
    {
        if (!self::userCan($action)) {
            throw OperationError::create('insufficient_capability', [], $action . ' refused for ' . $slug);
        }

        $snapshot = $this->snapshot();
        $product = $snapshot['catalogue']['products'][$slug] ?? null;
        $state = $snapshot['states'][$slug] ?? null;

        if (!is_array($product) || !is_array($state)) {
            throw OperationError::create('product_unknown', [], $action . ': ' . $slug);
        }

        return [$product, $state];
    }

    /**
     * The plugin file FCHub is about to act on, and the one thing it will never
     * be. A validated catalogue cannot name FCHub as a product, so this can
     * only fire if something upstream got creative — at which point FCHub
     * declines to switch itself off halfway through answering a request.
     *
     * @param array<string, mixed> $product
     */
    private function pluginFile(array $product, string $slug): string
    {
        $pluginFile = (string) $product['plugin_file'];

        if ($pluginFile === CatalogueValidator::HUB_PLUGIN_FILE) {
            throw OperationError::create('product_unknown', [], $slug . ' claims the hub plugin file');
        }

        return $pluginFile;
    }

    /**
     * @param array<string, mixed> $product
     * @param array<string, mixed> $state
     */
    private function assertNotInstalled(array $product, array $state, string $slug): void
    {
        if ($state['lifecycle'] !== 'not_installed') {
            throw OperationError::create('product_already_installed', [$product['name']], $slug);
        }
    }

    /**
     * @param array<string, mixed> $product
     * @param array<string, mixed> $state
     */
    private function assertCompatible(array $product, array $state, string $slug): void
    {
        if ($state['compatibility'] === 'compatible') {
            return;
        }

        $name = (string) $product['name'];
        $reason = is_array($state['compatibility_reason']) ? $state['compatibility_reason'] : [];
        $requirement = (string) ($reason['requirement'] ?? '');
        $required = (string) ($reason['required'] ?? '');

        // `blocked` means FCHub knows exactly what is missing and can say so.
        // `unknown` means it cannot verify the requirement, and inventing a
        // specific sentence about it would be a confident sort of lie.
        if ($state['compatibility'] !== 'blocked' || !in_array($requirement, ['php', 'wp', 'dependency'], true)) {
            throw OperationError::create('product_incompatible.unknown', [$name], $slug . ': ' . $requirement);
        }

        throw OperationError::create(
            'product_incompatible.' . $requirement,
            [$name, self::label($required)],
            $slug . ': needs ' . $requirement . ' ' . $required
        );
    }

    /**
     * @param array<string, mixed> $product
     */
    private function installPackage(array $product, string $slug, bool $isUpdate): void
    {
        $pluginFile = $this->pluginFile($product, $slug);
        $zip = $this->downloader->download($product);

        // The one path where FCHub knowingly hands unverified bytes to the
        // upgrader. It is allowed — the archive still came over HTTPS from a
        // trusted release host — but it is not allowed to happen silently.
        //
        // Logged here, before the install, because the event worth recording is
        // the decision to proceed unverified. Whether the upgrader then accepts
        // the archive is a separate question with its own outcome.
        $note = $this->downloader->lastNote();

        if ($note !== null) {
            ($this->logger)(sprintf('%s: no checksum for %s %s, installing anyway', $note, $slug, $product['version']));
        }

        try {
            $result = ($this->installer)($zip, $isUpdate, $pluginFile);
        } finally {
            // The archive is ours from the moment it lands, and stays ours
            // through every way this can end.
            VerifiedPackageDownloader::discard($zip);
        }

        if ($result !== true) {
            throw OperationError::create(
                'installation_failed',
                [],
                'upgrader refused ' . $slug . ': ' . self::describe($result)
            );
        }

        ($this->refreshInventory)();

        $installed = ($this->installedPlugins)();

        if (!array_key_exists($pluginFile, $installed)) {
            throw OperationError::create('installation_failed', [], $pluginFile . ' missing after install');
        }

        $version = trim((string) ($installed[$pluginFile]['Version'] ?? ''));

        if ($version !== (string) $product['version']) {
            throw OperationError::create(
                'version_mismatch',
                [(string) $product['name'], (string) $product['version']],
                $slug . ': expected ' . $product['version'] . ', found ' . ($version === '' ? 'nothing' : $version)
            );
        }
    }

    /**
     * @param array<string, mixed> $product
     * @param bool $afterInstall Changes only the sentence: an activation that
     *        failed after an install did leave something behind, and saying
     *        otherwise is a lie the Plugins screen exposes on the next click.
     */
    private function switchOn(array $product, string $slug, bool $afterInstall = false): void
    {
        $result = ($this->activator)($this->pluginFile($product, $slug));

        if (is_wp_error($result)) {
            throw OperationError::create(
                $afterInstall ? 'activation_failed.after_install' : 'activation_failed.plain',
                [(string) $product['name']],
                $slug . ': ' . $result->get_error_code()
            );
        }
    }

    /**
     * @param array<string, mixed> $product
     * @return array{slug: string, action: string, notice: string}
     */
    private function outcome(string $slug, string $action, array $product): array
    {
        $name = (string) $product['name'];

        $notice = match ($action) {
            'install' => sprintf(__('%s is installed and ready when you are.', 'fchub'), $name),
            'install-and-activate' => sprintf(__('%s is installed and switched on.', 'fchub'), $name),
            'activate' => sprintf(__('%s is switched on.', 'fchub'), $name),
            'update' => sprintf(__('%1$s is now on %2$s.', 'fchub'), $name, (string) $product['version']),
            'deactivate' => sprintf(
                __('%s is switched off. Its data is exactly where you left it.', 'fchub'),
                $name
            ),
            default => sprintf(__('%s is done.', 'fchub'), $name),
        };

        return ['slug' => $slug, 'action' => $action, 'notice' => $notice];
    }

    private static function label(string $dependency): string
    {
        return self::DEPENDENCY_LABELS[$dependency] ?? $dependency;
    }

    /**
     * @param mixed $result
     */
    private static function describe($result): string
    {
        if (is_wp_error($result)) {
            return (string) $result->get_error_code();
        }

        return get_debug_type($result);
    }
}

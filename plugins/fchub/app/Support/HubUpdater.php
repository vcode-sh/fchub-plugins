<?php

namespace FChubHub\Support;

use FChubHub\Catalogue\CatalogueRepository;
use UnexpectedValueException;

defined('ABSPATH') || exit;

/**
 * Keeps FCHub itself up to date, and nothing else.
 *
 * WordPress fires update_plugins_{hostname} for any plugin whose header
 * declares a matching Update URI. FCHub answers on fchub.co for exactly one
 * plugin file — its own — using the catalogue's top-level hub record. Every
 * product keeps whatever updater it already had; none of them gain a
 * dependency on this one, and this one gains nothing from them.
 */
final class HubUpdater
{
    private const HOOK = 'update_plugins_fchub.co';

    private const PLUGIN_FILE = 'fchub/fchub.php';

    private const SLUG = 'fchub';

    public function __construct(private readonly CatalogueRepository $repository)
    {
    }

    public static function register(): void
    {
        // Shared, not forSite(): an update check and a REST read in the same
        // request should cost one catalogue resolution between them, not two.
        (new self(CatalogueRepository::forSiteShared()))->hook();
    }

    public function hook(): void
    {
        add_filter(self::HOOK, [$this, 'filterUpdate'], 10, 3);
    }

    /**
     * @param mixed $update Whatever an earlier filter decided. Untouched unless
     *                      this is FCHub and the catalogue offers something newer.
     * @param array<string, mixed> $pluginData
     * @return mixed
     */
    public function filterUpdate($update, array $pluginData, string $pluginFile)
    {
        if ($pluginFile !== self::PLUGIN_FILE) {
            return $update;
        }

        try {
            $hub = $this->repository->get()['catalogue']['hub'];
        } catch (UnexpectedValueException) {
            // A damaged catalogue is the one failure worth absorbing here: the
            // update screen is not the place to explain it. Caught by type, so
            // a genuine bug further down still surfaces instead of being
            // quietly buried on a screen nobody reads.
            return $update;
        }

        if (!version_compare($hub['version'], FCHUB_HUB_VERSION, '>')) {
            return $update;
        }

        return [
            'id' => 'fchub.co/' . self::SLUG,
            'slug' => self::SLUG,
            'plugin' => self::PLUGIN_FILE,
            'version' => $hub['version'],
            'url' => $hub['release_url'],
            'package' => $hub['package_url'],
        ];
    }
}

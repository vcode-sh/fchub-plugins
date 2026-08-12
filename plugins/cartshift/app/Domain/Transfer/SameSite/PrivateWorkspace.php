<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\SameSite;

use CartShift\Domain\Transfer\Execution\ConfiguredTransferEvidence;
use CartShift\Domain\Transfer\SourceIdentity;

defined('ABSPATH') || exit;

/**
 * The directory a guided run writes its package into, chosen rather than typed.
 *
 * `wp cartshift transfer export` refuses a `--destination` that is not an
 * existing absolute non-symlink directory outside the web root, and it is right
 * to: a package holds every customer, order and address in the shop, and one
 * served over HTTP is a data breach with a URL. The guided route removes the
 * typing, never the rule.
 *
 * Explicit server configuration wins outright when present. Otherwise the
 * guided route stores the exact validated directory it chose, so export and
 * target staging still resolve one name to one place.
 *
 * Without it, two fallbacks, each treated as a PARENT with CartShift owning a
 * subdirectory beneath it:
 *   1. The web root's parent, which is writable on most ordinary hosting.
 *   2. The system temp directory, which is what is left inside a container —
 *      the mounted playground has `/var/www` owned by root, so this is not a
 *      hypothetical branch.
 * The chosen path is sealed before it becomes durable configuration.
 *
 * With nothing usable it throws. Falling back into `wp-content/uploads` would
 * publish the shop, silently, on exactly the hosts least able to notice.
 */
final class PrivateWorkspace
{
    private const string DIRECTORY = 'cartshift-private';

    /** @param list<string>|null $candidateRoots Test seam. Null means the real three. */
    public function __construct(
        private readonly string $sourceKey,
        private readonly ?array $candidateRoots = null,
    ) {
        // The key becomes a path segment, so the guard that judges it is also
        // what makes traversal unrepresentable rather than something to strip.
        SourceIdentity::assertValidSourceKey($sourceKey);
    }

    /** Whether the exact directory used by the transfer pipeline is valid. */
    public static function isTransferDirectoryConfigured(): bool
    {
        try {
            ConfiguredTransferEvidence::privateDirectory();

            return true;
        } catch (\InvalidArgumentException|\RuntimeException) {
            return false;
        }
    }

    /**
     * The workspace, created if it is not there yet.
     *
     * THE CONFIGURED DIRECTORY WINS, VERBATIM. When
     * a configured path exists it is not a parent to nest under — it is the
     * exact directory the target pipeline will read, so nesting a
     * subdirectory beneath it would have the guided run export to one place and
     * the pipeline look in another. One name for one directory; the second
     * constant this class briefly invented was that drift waiting to happen.
     *
     * @throws \RuntimeException when no candidate can hold it.
     */
    public function path(): string
    {
        if (self::isTransferDirectoryConfigured()) {
            return ConfiguredTransferEvidence::privateDirectory();
        }

        foreach ($this->roots() as $root) {
            $path = $this->prepare($root);

            if ($path !== null) {
                return $path;
            }
        }

        throw new \RuntimeException(
            'private_workspace_unavailable: CartShift found nowhere outside the web root it may write to. A '
            . 'transfer package holds every customer and order in the shop, so it is not going anywhere a '
            . 'browser could reach. Provide a writable private directory outside the site and run this again.',
        );
    }

    /**
     * Is this path clear of the web root?
     *
     * Compared with a trailing separator on both sides, so `/srv/wordpress-old`
     * is not read as living inside `/srv/wordpress`.
     *
     * BOTH SIDES ARE NORMALISED THE SAME WAY, and that is the whole subtlety.
     * Resolving only the web root compares `/private/tmp/wordpress` against a
     * candidate still spelled `/tmp/wordpress` and answers "outside" for the web
     * root itself. Symlinked docroots are not exotic — macOS puts `/tmp` behind
     * one, and every atomic-deploy layout points `html` at `releases/N`.
     *
     * An unresolvable path falls back to its declared spelling rather than to
     * "no web root known". The permissive reading would answer "outside" for
     * every path on earth at precisely the moment CartShift has lost track of
     * where the site is, which is the wrong direction for a check whose whole
     * job is refusing to publish the shop.
     */
    public static function isOutsideWebRoot(string $path): bool
    {
        if (!defined('ABSPATH')) {
            return false;
        }

        $webRoot = self::normalise(ABSPATH);
        $candidate = self::normalise($path);

        return $candidate !== $webRoot && !str_starts_with($candidate . '/', $webRoot . '/');
    }

    private static function normalise(string $path): string
    {
        return rtrim(realpath($path) ?: $path, '/');
    }

    /** @return list<string> */
    private function roots(): array
    {
        if ($this->candidateRoots !== null) {
            return $this->candidateRoots;
        }

        return array_values(array_filter([
            dirname(rtrim(ABSPATH, '/')),
            sys_get_temp_dir(),
        ], static fn (string $root): bool => $root !== ''));
    }

    /** The workspace under this root, or null when the root will not do. */
    private function prepare(string $root): ?string
    {
        $real = is_dir($root) && !is_link($root) ? realpath($root) : false;

        if ($real === false || !self::isOutsideWebRoot($real) || !is_writable($real)) {
            return null;
        }

        $path = $real . '/' . self::DIRECTORY . '/' . $this->sourceKey;

        if (!is_dir($path) && !mkdir($path, 0700, true) && !is_dir($path)) {
            return null;
        }

        $this->seal($path);

        return $path;
    }

    /**
     * Mode 0700 is the real protection. The index and the deny rule are for the
     * host that ignores it — a misconfigured docroot cannot then list what is
     * here, and neither costs anything to write once.
     */
    private function seal(string $path): void
    {
        @chmod($path, 0700);

        if (!file_exists($path . '/index.php')) {
            @file_put_contents($path . '/index.php', "<?php\n// Silence is golden.\n");
        }

        if (!file_exists($path . '/.htaccess')) {
            @file_put_contents($path . '/.htaccess', "Require all denied\n");
        }
    }
}

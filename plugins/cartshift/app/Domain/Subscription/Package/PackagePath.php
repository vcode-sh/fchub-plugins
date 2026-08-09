<?php

declare(strict_types=1);

namespace CartShift\Domain\Subscription\Package;

defined('ABSPATH') || exit;

/**
 * Where a private package may live, decided fresh every single time.
 *
 * The package carries every customer, order and subscription the migration
 * touches. It is not encrypted and it is not signed, because for a local
 * owner-controlled file a checksum and a mode bit are proportionate — but that
 * only holds if the file is somewhere a web server will never serve it and Git
 * will never swallow it. Hence two refusals, both absolute:
 *
 * PUBLIC DIRECTORIES. Inside the uploads directory, inside `wp-content/uploads`
 * by name, or anywhere under the WordPress root. A package in the Media Library
 * is a customer database with a URL.
 *
 * GIT WORKING TREES. Any ancestor holding a `.git` entry. The plan's Global
 * Constraints forbid customer data in Git, and "I will remember not to commit
 * it" is not a control.
 *
 * And every resolution is performed again immediately before the file is
 * opened. A path checked once and opened later is a TOCTOU hole: between the
 * two, the directory can be replaced with a symlink into somewhere neither of
 * the rules above would have allowed.
 */
final class PackagePath
{
    public const string REASON_MISSING = 'package_path_missing';
    public const string REASON_NOT_ABSOLUTE = 'package_path_not_absolute';
    public const string REASON_DIRECTORY_UNKNOWN = 'package_path_directory_unknown';
    public const string REASON_PUBLIC_DIRECTORY = 'package_path_public_directory';
    public const string REASON_VERSION_CONTROL = 'package_path_version_control';
    public const string REASON_NOT_A_FILE = 'package_path_not_a_file';
    public const string REASON_UNREADABLE = 'package_path_unreadable';
    public const string REASON_UNWRITABLE = 'package_path_unwritable';
    public const string REASON_SYMLINK = 'package_path_symlink';

    /** The mode a package is created with where the filesystem supports it. */
    public const int PRIVATE_MODE = 0600;

    /** Marker directories that make an ancestor a working tree. */
    private const array VERSION_CONTROL_MARKERS = ['.git'];

    /**
     * A path fit to be written to, or the reasons it is not.
     *
     * @return array{path: string|null, failures: list<string>}
     */
    public static function resolveForWrite(string $path): array
    {
        $resolved = self::resolve($path);

        if ($resolved['path'] === null) {
            return $resolved;
        }

        $failures = $resolved['failures'];
        $canonical = $resolved['path'];

        if (file_exists($canonical) && !is_file($canonical)) {
            $failures[] = self::REASON_NOT_A_FILE;
        }

        if (!is_writable(dirname($canonical))) {
            $failures[] = self::REASON_UNWRITABLE;
        }

        return self::verdict($canonical, $failures);
    }

    /**
     * A path fit to be read from, or the reasons it is not.
     *
     * @return array{path: string|null, failures: list<string>}
     */
    public static function resolveForRead(string $path): array
    {
        $resolved = self::resolve($path);

        if ($resolved['path'] === null) {
            return $resolved;
        }

        $failures = $resolved['failures'];
        $canonical = $resolved['path'];

        if (!is_file($canonical)) {
            $failures[] = self::REASON_NOT_A_FILE;
        } elseif (!is_readable($canonical)) {
            $failures[] = self::REASON_UNREADABLE;
        }

        return self::verdict($canonical, $failures);
    }

    /**
     * @return array{path: string|null, failures: list<string>}
     */
    private static function resolve(string $path): array
    {
        $path = trim($path);

        if ($path === '') {
            return ['path' => null, 'failures' => [self::REASON_MISSING]];
        }

        if (!str_starts_with($path, '/') && preg_match('/^[A-Za-z]:[\\\\\/]/', $path) !== 1) {
            // Relative paths resolve against whatever the current working
            // directory happens to be, which for a WP-CLI run is wherever the
            // operator was standing. A private package's location is not a
            // matter of where somebody's shell was.
            return ['path' => null, 'failures' => [self::REASON_NOT_ABSOLUTE]];
        }

        $directory = realpath(dirname($path));

        if ($directory === false) {
            return ['path' => null, 'failures' => [self::REASON_DIRECTORY_UNKNOWN]];
        }

        $canonical = rtrim($directory, '/') . '/' . basename($path);

        $failures = [];

        if (self::isPublic($canonical)) {
            $failures[] = self::REASON_PUBLIC_DIRECTORY;
        }

        if (self::isVersionControlled($directory)) {
            $failures[] = self::REASON_VERSION_CONTROL;
        }

        // The directory is canonical; the file name on the end of it is not,
        // and a symlink there defeats both refusals above. `/srv/private/x.ndjson`
        // pointing into wp-content/uploads passes isPublic(), which tests the
        // un-followed string, while every write and read follows the link — so
        // `export` would put the entire customer and order dataset inside the
        // web root and report success.
        //
        // The other half is worse, because it is silent: `delete-package` would
        // unlink the link, print "Deleted", and leave the real customer data
        // exactly where it was. A false assurance on the one command whose whole
        // job is destroying evidence.
        //
        // Refused outright rather than followed and re-checked. A package is a
        // file CartShift wrote; there is no legitimate reason for it to be a
        // symlink, and "follow it and validate the target" leaves the delete
        // ambiguity intact even when the target is somewhere allowed. Directory
        // symlinks are unaffected — realpath() above already resolved those,
        // so an operator whose /srv/private is a link to another volume is fine.
        if (is_link($canonical)) {
            $failures[] = self::REASON_SYMLINK;
        }

        return self::verdict($canonical, $failures);
    }

    /**
     * @param list<string> $failures
     * @return array{path: string|null, failures: list<string>}
     */
    private static function verdict(string $canonical, array $failures): array
    {
        $failures = array_values(array_unique($failures));
        sort($failures);

        return [
            'path'     => $failures === [] ? $canonical : null,
            'failures' => $failures,
        ];
    }

    /**
     * Anywhere a web server might reasonably hand this file to a stranger.
     */
    private static function isPublic(string $canonical): bool
    {
        if (str_contains($canonical, '/wp-content/uploads/')) {
            return true;
        }

        if (function_exists('wp_upload_dir')) {
            $uploads = wp_upload_dir();
            $baseDirectory = is_array($uploads) ? (string) ($uploads['basedir'] ?? '') : '';

            if ($baseDirectory !== '' && self::isUnder($canonical, $baseDirectory)) {
                return true;
            }
        }

        return defined('ABSPATH') && ABSPATH !== '' && self::isUnder($canonical, (string) ABSPATH);
    }

    private static function isVersionControlled(string $directory): bool
    {
        $current = $directory;

        while (true) {
            foreach (self::VERSION_CONTROL_MARKERS as $marker) {
                if (file_exists($current . '/' . $marker)) {
                    return true;
                }
            }

            $parent = dirname($current);

            if ($parent === $current) {
                return false;
            }

            $current = $parent;
        }
    }

    private static function isUnder(string $candidate, string $ancestor): bool
    {
        $ancestor = realpath($ancestor) ?: rtrim($ancestor, '/');

        return $ancestor !== '' && str_starts_with($candidate, rtrim($ancestor, '/') . '/');
    }
}

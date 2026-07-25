<?php

namespace FChubHub\Operations;

use Closure;

defined('ABSPATH') || exit;

/**
 * Fetches a product archive and refuses to hand it on until it has proved it is
 * the archive the catalogue described.
 *
 * The host allow-list here is a deliberate second copy of the one in
 * CatalogueValidator. That one decides what a catalogue may *claim*; this one
 * decides what FCHub will actually *dial*, and it decides it before a single
 * byte moves. If a validated catalogue ever reaches this class with a package
 * URL pointing somewhere else, the answer is still no. Both read the same
 * filter, so a site that legitimately extends the list extends both at once.
 *
 * The downloader arrives as a callable so the whole verification story can be
 * told in unit tests without a network stack, a GitHub outage, or a 40 MB ZIP.
 */
final class VerifiedPackageDownloader
{
    private const PACKAGE_HOSTS = [
        'github.com',
        'objects.githubusercontent.com',
        'release-assets.githubusercontent.com',
    ];

    /** Generous, because a plugin archive on a tired shared host is not a fast thing. */
    private const TIMEOUT = 300;

    /** A bare digest, or the two-field layout sha256sum writes. Nothing else. */
    private const CHECKSUM_PATTERN = '/\A([a-f0-9]{64})(?:\s+\*?.+)?\z/i';

    /**
     * Diagnostics from the most recent download, for the debug log and nobody
     * else. Currently only ever `checksum_unavailable`.
     */
    private ?string $lastNote = null;

    /**
     * @param Closure(string): (string|object) $downloader Returns a temporary
     *        file path, or a WP_Error. Mirrors download_url() exactly.
     */
    public function __construct(private readonly Closure $downloader)
    {
    }

    public static function forSite(): self
    {
        return new self(static function (string $url) {
            // Admin-only include, pulled in at call time so a front-end request
            // never pays for it.
            require_once ABSPATH . 'wp-admin/includes/file.php';

            // Residual risk, recorded rather than papered over: download_url()
            // hands off to wp_safe_remote_get(), which re-validates a redirect
            // target only against private and otherwise unsafe addresses — not
            // against this class's host allow-list. A release host that
            // redirected somewhere arbitrary would be followed.
            //
            // The checksum catches it everywhere except the legacy
            // checksum_unavailable path, and exploiting it means controlling a
            // response from GitHub in the first place. The durable fix is a
            // .sha256 on every published release, which is Task 9's contract.
            return download_url($url, self::TIMEOUT);
        });
    }

    /**
     * @param array<string, mixed> $product A validated catalogue product entry.
     * @return string Path to a temporary ZIP the caller now owns, and must delete.
     *
     * @throws OperationError when the URLs are not trustworthy, the download
     *         fails, or the archive is not what its checksum says it is.
     */
    public function download(array $product): string
    {
        // Cleared before anything else, so a refused URL cannot leave the
        // previous download's note standing on a reused instance.
        $this->lastNote = null;

        $packageUrl = (string) ($product['package_url'] ?? '');
        $checksumUrl = (string) ($product['checksum_url'] ?? '');

        // Both URLs are checked before either is requested. Verifying the
        // package against a checksum fetched from somewhere untrusted would be
        // an elaborate way of verifying nothing.
        $this->assertTrusted($packageUrl, 'package_url');
        $this->assertTrusted($checksumUrl, 'checksum_url');

        $package = $this->fetch($packageUrl, 'package');
        $verified = false;

        try {
            $this->verify($package, $checksumUrl);
            $verified = true;
        } finally {
            // Deliberately not keyed on the exception type. A site whose error
            // handler promotes warnings to ErrorException — several monitoring
            // plugins do exactly that — would otherwise leave an unverified
            // archive on disk, and "the package is always deleted unless it
            // passed" is meant to hold without an asterisk.
            if (!$verified) {
                self::discard($package);
            }
        }

        return $package;
    }

    /**
     * Internal diagnostics from the most recent download. Never rendered.
     */
    public function lastNote(): ?string
    {
        return $this->lastNote;
    }

    /**
     * Deletes a temporary file this class handed out. Public because the
     * operation service owns the package once it has it, and has to be able to
     * clean up after an upgrader that failed halfway.
     */
    public static function discard(string $path): void
    {
        if ($path !== '' && is_file($path)) {
            unlink($path);
        }
    }

    private function assertTrusted(string $url, string $context): void
    {
        $scheme = wp_parse_url($url, PHP_URL_SCHEME);
        $host = wp_parse_url($url, PHP_URL_HOST);

        if (!is_string($scheme) || !is_string($host) || $host === '') {
            throw OperationError::create('package_host_not_allowed', [], $context . ' is not a usable URL');
        }

        // The Task 7 lifecycle harness serves fixtures over HTTP from a
        // container. Production ships no such filter, so production stays
        // HTTPS-only whatever anyone claims.
        $allowHttp = (bool) apply_filters('fchub/catalogue/allow_http', false, $url);

        if ($scheme !== 'https' && !($scheme === 'http' && $allowHttp)) {
            throw OperationError::create('package_host_not_allowed', [], $context . ' is not served over HTTPS');
        }

        if (!in_array(strtolower($host), self::allowedHosts(), true)) {
            throw OperationError::create('package_host_not_allowed', [], $context . ' host ' . $host . ' is not trusted');
        }
    }

    private function fetch(string $url, string $what): string
    {
        $result = ($this->downloader)($url);

        if (is_string($result) && $result !== '' && is_file($result)) {
            return $result;
        }

        throw OperationError::create('package_unavailable', [], $what . ' download failed: ' . self::describe($result));
    }

    private function verify(string $package, string $checksumUrl): void
    {
        $result = ($this->downloader)($checksumUrl);

        if (is_wp_error($result)) {
            if ($result->get_error_code() === 'http_404') {
                // Releases published before the sidecar existed. The archive
                // still arrived over HTTPS from a trusted release host, so it
                // goes through with a note only the log will ever read.
                $this->lastNote = 'checksum_unavailable';

                return;
            }

            // Anything other than a flat "there is no such file" means we do
            // not know what the checksum is, and guessing is not verification.
            throw OperationError::create(
                'package_unavailable',
                [],
                'checksum download failed: ' . self::describe($result)
            );
        }

        if (!is_string($result) || $result === '' || !is_file($result)) {
            throw OperationError::create('package_unavailable', [], 'checksum download returned nothing usable');
        }

        try {
            $body = (string) file_get_contents($result);
        } finally {
            // The checksum file is ours the moment it lands, success or not.
            self::discard($result);
        }

        if (preg_match(self::CHECKSUM_PATTERN, trim($body), $matches) !== 1) {
            throw OperationError::create('checksum_invalid', [], 'checksum body did not parse');
        }

        $expected = strtolower($matches[1]);
        $actual = hash_file('sha256', $package);

        if (!is_string($actual) || !hash_equals($expected, $actual)) {
            throw OperationError::create('package_verification_failed', [], 'sha256 mismatch');
        }
    }

    /**
     * @return list<string>
     */
    private static function allowedHosts(): array
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

        // A filter that returns nothing usable gets the shipped list back,
        // rather than an empty allow-list that would wave everything through.
        return $clean === [] ? self::PACKAGE_HOSTS : $clean;
    }

    /**
     * A short internal label for whatever came back. Deliberately never the
     * remote body, and never a path.
     *
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

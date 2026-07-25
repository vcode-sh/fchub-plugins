<?php

namespace FChubHub\Catalogue;

use Closure;
use UnexpectedValueException;

defined('ABSPATH') || exit;

/**
 * Serves the trusted catalogue from the best layer still standing:
 *
 *     fresh transient -> valid remote -> last-known-good option -> bundled file
 *
 * A catalogue outage is meant to be boring, which means never dialling a sick
 * endpoint on every page load. One transient governs both cadences: six hours
 * after a successful refresh, fifteen minutes after a failed one. So the remote
 * is consulted at most once every six hours while it is healthy, and at most
 * once every fifteen minutes while it is not. An explicit refresh — the
 * administrator pressing the button — ignores both windows, because "try
 * again" should mean try again.
 *
 * Anything the remote returns has to survive CatalogueValidator before it is
 * stored, and a response that fails leaves the previous copy exactly where it
 * was.
 *
 * Every dependency arrives as a callable so the precedence chain can be tested
 * without a WordPress install, a database, or a single packet leaving the
 * machine. forSite() wires the real ones.
 */
final class CatalogueRepository
{
    public const ENDPOINT = 'https://fchub.co/api/v1/products';

    public const OPTION_LAST_GOOD = 'fchub_catalogue_last_good';
    public const OPTION_ETAG = 'fchub_catalogue_etag';
    public const OPTION_LAST_REFRESH = 'fchub_catalogue_last_refresh';
    public const TRANSIENT_FRESH = 'fchub_catalogue_fresh';

    /** Six hours after a good refresh, spelled out so nothing depends on WordPress constants. */
    public const FRESH_TTL = 21600;

    /** Fifteen minutes after a failed one. Long enough to stop hammering, short enough to recover. */
    public const BACKOFF_TTL = 900;

    private const TIMEOUT = 8;

    private readonly string $bundledPath;

    /**
     * Per-request memoisation. Task 4's REST route and HubUpdater can both run
     * in one WordPress request, and neither should pay for the other's read.
     *
     * @var array{source: string, last_refresh: string|null, catalogue: array<string, mixed>}|null
     */
    private ?array $resolved = null;

    /**
     * The one instance production shares. Memoisation is per-instance, so two
     * callers building their own repository each pay for their own read — which
     * is exactly what a hub updater and a REST route do inside a single request.
     */
    private static ?self $shared = null;

    /**
     * @param Closure(string, array<string, string>): (array<string, mixed>|null) $fetch
     * @param Closure(string, mixed): mixed $readOption
     * @param Closure(string, mixed): void $writeOption
     * @param Closure(string): void $deleteOption
     * @param Closure(string): mixed $readTransient
     * @param Closure(string, mixed, int): void $writeTransient
     * @param Closure(): int $clock
     */
    public function __construct(
        private readonly Closure $fetch,
        private readonly Closure $readOption,
        private readonly Closure $writeOption,
        private readonly Closure $deleteOption,
        private readonly Closure $readTransient,
        private readonly Closure $writeTransient,
        private readonly Closure $clock,
        ?string $bundledPath = null
    ) {
        $this->bundledPath = $bundledPath ?? FCHUB_HUB_PATH . 'resources/catalog.json';
    }

    public static function forSite(): self
    {
        return new self(
            fetch: static function (string $url, array $headers): ?array {
                $response = wp_safe_remote_get($url, [
                    'timeout' => self::TIMEOUT,
                    // GitHub and most CDNs answer a canonical URL in one or two
                    // hops. Two follows those without letting a redirect chain
                    // turn into its own timeout budget; wherever it lands, the
                    // body still has to pass CatalogueValidator.
                    'redirection' => 2,
                    'headers' => $headers,
                ]);

                if (is_wp_error($response)) {
                    return null;
                }

                return [
                    'code' => (int) wp_remote_retrieve_response_code($response),
                    'body' => (string) wp_remote_retrieve_body($response),
                    'etag' => (string) wp_remote_retrieve_header($response, 'etag'),
                ];
            },
            readOption: static fn (string $name, $default = null) => get_option($name, $default),
            writeOption: static function (string $name, $value): void {
                // Never autoloaded. The stored catalogue is a few kilobytes of
                // serialised array, and WordPress puts anything under 150 KB
                // into alloptions by default — so leaving this to the default
                // would unserialise the whole catalogue on every front-end
                // request of a storefront that has no idea FCHub exists. The
                // admin screen and the update check are the only readers, and
                // both are happy to pay for their own query.
                update_option($name, $value, false);
            },
            deleteOption: static function (string $name): void {
                delete_option($name);
            },
            readTransient: static fn (string $name) => get_transient($name),
            writeTransient: static function (string $name, $value, int $ttl): void {
                set_transient($name, $value, $ttl);
            },
            clock: static fn (): int => time()
        );
    }

    /**
     * The accessor every production caller should use. One instance per
     * request, so a catalogue read costs one validation and at most one HTTP
     * call no matter how many parts of FCHub ask for it. Use forSite() only
     * when a genuinely independent instance is wanted.
     */
    public static function forSiteShared(): self
    {
        return self::$shared ??= self::forSite();
    }

    /**
     * Test seam only. A shared instance living across test classes would carry
     * one class's memoised catalogue into the next one's assertions.
     */
    public static function resetSharedInstanceForTests(): void
    {
        self::$shared = null;
    }

    /**
     * @return array{source: string, last_refresh: string|null, catalogue: array<string, mixed>}
     *         `source` is `remote` when the data came from a healthy endpoint
     *         (just fetched, or fetched within the freshness window),
     *         `last_good` when the endpoint failed and a stored copy is
     *         standing in, and `bundled` for the offline fallback.
     *
     *         `last_refresh` describes the last *successful* refresh, so it can
     *         be non-null alongside `bundled` — it is the age of the last good
     *         answer, not of the data currently being served. Task 5 should
     *         render it as "last checked", not as the age of this catalogue.
     *
     * @throws UnexpectedValueException only when the bundled fallback itself is
     *         missing or corrupt, which means the plugin files are damaged.
     *         Every other failure resolves quietly to an older valid layer.
     */
    public function get(bool $forceRefresh = false): array
    {
        if ($forceRefresh) {
            $this->resolved = null;
        }

        return $this->resolved ??= $this->resolve($forceRefresh);
    }

    /**
     * @return array{source: string, last_refresh: string|null, catalogue: array<string, mixed>}
     */
    private function resolve(bool $forceRefresh): array
    {
        // Read and validate the stored copy exactly once per resolution: every
        // branch below either serves it or falls straight past it.
        $stored = $this->storedCatalogue();
        $window = $forceRefresh ? null : $this->refreshWindow();

        if ($window !== null) {
            if ($stored !== null) {
                return $this->envelope($window === 'fresh' ? 'remote' : 'last_good', $stored);
            }

            // Inside a backoff window with nothing stored, the bundled copy is
            // the answer. Dialling the endpoint again is precisely what backing
            // off means not doing.
            if ($window === 'backoff') {
                return $this->envelope('bundled', $this->bundledCatalogue());
            }
        }

        $response = $this->requestRemote();

        if ($response !== null && $response['status'] === 'ok') {
            $this->store($response['catalogue'], $response['etag']);

            return $this->envelope('remote', $response['catalogue']);
        }

        if ($response !== null && $response['status'] === 'not_modified' && $stored !== null) {
            $this->markFresh();

            return $this->envelope('remote', $stored);
        }

        if ($response !== null && $response['status'] === 'not_modified') {
            // A 304 describing a body we no longer have — the stored copy was
            // rejected and deleted, but the ETag outlived it. Marking this
            // fresh would be a lie, and keeping the ETag would guarantee the
            // server never sends a body again: one conditional request per page
            // load, for ever, while the System page reports a healthy check.
            // Drop the ETag so the next call asks unconditionally and actually
            // gets something back.
            if ($response['conditional']) {
                ($this->deleteOption)(self::OPTION_ETAG);
            } else {
                // A 304 answering an unconditional request is a broken server,
                // not a cache hit. There is no ETag left to drop, so back off
                // rather than spin.
                $this->markBackoff();
            }
        } else {
            // Transport error, unusable status code, unparseable body, or a
            // payload the validator threw out. Back off, so a broken endpoint
            // costs one slow request every fifteen minutes rather than one on
            // every single admin page load.
            $this->markBackoff();
        }

        if ($stored !== null) {
            return $this->envelope('last_good', $stored);
        }

        return $this->envelope('bundled', $this->bundledCatalogue());
    }

    /**
     * @return string|null `fresh` after a good refresh, `backoff` after a failed
     *                     one, null once the window has expired.
     */
    private function refreshWindow(): ?string
    {
        $marker = ($this->readTransient)(self::TRANSIENT_FRESH);

        if (!$marker) {
            return null;
        }

        return is_array($marker) && ($marker['state'] ?? '') === 'backoff' ? 'backoff' : 'fresh';
    }

    /**
     * @return array{status: string, catalogue: array<string, mixed>, etag: string, conditional: bool}|null
     *         `conditional` records whether an If-None-Match header went out,
     *         which is the difference between a legitimate 304 and a server
     *         inventing one.
     */
    private function requestRemote(): ?array
    {
        $etag = ($this->readOption)(self::OPTION_ETAG, '');

        $headers = [
            'Accept' => 'application/json',
            'User-Agent' => 'FCHub/' . FCHUB_HUB_VERSION,
        ];

        $conditional = is_string($etag) && $etag !== '';

        if ($conditional) {
            $headers['If-None-Match'] = $etag;
        }

        $response = ($this->fetch)($this->endpoint(), $headers);

        if (!is_array($response)) {
            return null;
        }

        $code = (int) ($response['code'] ?? 0);

        if ($code === 304) {
            return ['status' => 'not_modified', 'catalogue' => [], 'etag' => '', 'conditional' => $conditional];
        }

        if ($code !== 200) {
            return null;
        }

        $decoded = json_decode((string) ($response['body'] ?? ''), true);

        if (!is_array($decoded)) {
            return null;
        }

        try {
            $catalogue = (new CatalogueValidator())->validate($decoded);
        } catch (UnexpectedValueException) {
            // A rejected response is not an error the administrator can act
            // on, and it must never displace the copy already stored. The
            // caller falls through to the previous valid layer.
            return null;
        }

        return [
            'status' => 'ok',
            'catalogue' => $catalogue,
            'etag' => (string) ($response['etag'] ?? ''),
            'conditional' => $conditional,
        ];
    }

    private function endpoint(): string
    {
        return defined('FCHUB_CATALOGUE_URL') ? (string) FCHUB_CATALOGUE_URL : self::ENDPOINT;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function storedCatalogue(): ?array
    {
        $stored = ($this->readOption)(self::OPTION_LAST_GOOD, null);

        if (!is_array($stored)) {
            return null;
        }

        try {
            // Validated again on the way out: the option is a database row like
            // any other, and a row that has been edited is not a trusted one.
            return (new CatalogueValidator())->validate($stored);
        } catch (UnexpectedValueException) {
            // Rejected once is rejected for good. Leaving it in place would
            // mean re-decoding and re-validating the same bad row on every
            // read until some later fetch happens to succeed.
            ($this->deleteOption)(self::OPTION_LAST_GOOD);

            return null;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function bundledCatalogue(): array
    {
        if (!is_file($this->bundledPath) || !is_readable($this->bundledPath)) {
            throw new UnexpectedValueException('catalogue_bundled_unreadable: resources/catalog.json');
        }

        $decoded = json_decode((string) file_get_contents($this->bundledPath), true);

        if (!is_array($decoded)) {
            throw new UnexpectedValueException('catalogue_bundled_invalid: resources/catalog.json');
        }

        return (new CatalogueValidator())->validate($decoded);
    }

    /**
     * @param array<string, mixed> $catalogue
     */
    private function store(array $catalogue, string $etag): void
    {
        ($this->writeOption)(self::OPTION_LAST_GOOD, $catalogue);
        ($this->writeOption)(self::OPTION_ETAG, $etag);

        $this->markFresh();
    }

    private function markFresh(): void
    {
        $stamp = gmdate('c', ($this->clock)());

        ($this->writeOption)(self::OPTION_LAST_REFRESH, $stamp);
        ($this->writeTransient)(self::TRANSIENT_FRESH, ['state' => 'fresh', 'at' => $stamp], self::FRESH_TTL);
    }

    private function markBackoff(): void
    {
        // Deliberately leaves last_refresh alone: nothing was refreshed, and a
        // timestamp claiming otherwise would be the System page's first lie.
        ($this->writeTransient)(
            self::TRANSIENT_FRESH,
            ['state' => 'backoff', 'at' => gmdate('c', ($this->clock)())],
            self::BACKOFF_TTL
        );
    }

    /**
     * @param array<string, mixed> $catalogue
     * @return array{source: string, last_refresh: string|null, catalogue: array<string, mixed>}
     */
    private function envelope(string $source, array $catalogue): array
    {
        $lastRefresh = ($this->readOption)(self::OPTION_LAST_REFRESH, '');

        return [
            'source' => $source,
            'last_refresh' => is_string($lastRefresh) && $lastRefresh !== '' ? $lastRefresh : null,
            'catalogue' => $catalogue,
        ];
    }
}

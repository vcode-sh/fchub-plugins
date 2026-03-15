<?php

defined('ABSPATH') || exit;

/**
 * GitHub-based auto-updater for FCHub plugins.
 *
 * Queries the GitHub Releases API for the public vcode-sh/fchub-plugins monorepo,
 * parses slash-tags (e.g. fchub-p24/v1.0.2), and feeds update data to WordPress
 * via the Update URI mechanism (WP 5.8+).
 *
 * Each plugin ships a copy of this file. The class_exists guard ensures only the
 * first loaded copy wins — all plugins share a single instance.
 *
 * @package FCHub
 */

if (!class_exists('FCHub_GitHub_Updater')) {

class FCHub_GitHub_Updater
{
    private const GITHUB_REPO    = 'vcode-sh/fchub-plugins';
    private const API_URL        = 'https://api.github.com/repos/vcode-sh/fchub-plugins/releases';
    private const TRANSIENT_KEY  = 'fchub_github_releases';
    private const CACHE_TTL      = 6 * HOUR_IN_SECONDS;

    private const KNOWN_SLUGS = [
        'fchub-p24',
        'fchub-fakturownia',
        'fchub-memberships',
        'fchub-portal-extender',
        'fchub-wishlist',
        'fchub-stream',
        'fchub-multi-currency',
        'cartshift',
    ];

    /** @var array<string, array{file: string, version: string}> */
    private static array $plugins = [];

    private static bool $hooked = false;

    /** @var array<string, array>|null In-memory cache */
    private static ?array $releasesCache = null;

    /**
     * Register a plugin for update checks.
     */
    public static function register(string $slug, string $pluginFile, string $version): void
    {
        self::$plugins[$slug] = [
            'file'    => $pluginFile,
            'version' => $version,
        ];

        if (!self::$hooked) {
            add_filter('update_plugins_fchub.co', [self::class, 'checkUpdate'], 10, 4);
            add_filter('plugins_api', [self::class, 'pluginInfo'], 10, 3);
            add_action('delete_site_transient_update_plugins', [self::class, 'clearCache']);
            self::$hooked = true;
        }
    }

    /**
     * Filter callback for update_plugins_fchub.co.
     *
     * @param array|false $update     Existing update data (false if none).
     * @param array       $pluginData Plugin header data parsed by WP.
     * @param string      $pluginFile Plugin basename (e.g. "fchub-p24/fchub-p24.php").
     * @param string[]    $locales    Available locales.
     * @return array|false Update data or false if no update.
     */
    public static function checkUpdate($update, array $pluginData, string $pluginFile, array $locales)
    {
        $slug = self::slugFromFile($pluginFile);

        if ($slug === null) {
            return $update;
        }

        $releases = self::fetchReleases();
        if (empty($releases[$slug])) {
            return $update;
        }

        $release        = $releases[$slug];
        $currentVersion = $pluginData['Version'] ?? '0.0.0';

        if (!version_compare($release['version'], $currentVersion, '>')) {
            return $update;
        }

        return [
            'slug'    => $slug,
            'version' => $release['version'],
            'url'     => $release['html_url'],
            'package' => $release['zip_url'],
        ];
    }

    /**
     * Inject plugin info into the "View details" modal.
     *
     * @param false|object|array $result
     * @param string             $action
     * @param object             $args
     * @return false|object
     */
    public static function pluginInfo($result, string $action, object $args)
    {
        if ($action !== 'plugin_information') {
            return $result;
        }

        $slug = $args->slug ?? '';
        if (!in_array($slug, self::KNOWN_SLUGS, true)) {
            return $result;
        }

        $releases = self::fetchReleases();
        if (empty($releases[$slug])) {
            return $result;
        }

        $release = $releases[$slug];

        $info = (object) [
            'name'          => $release['name'],
            'slug'          => $slug,
            'version'       => $release['version'],
            'author'        => '<a href="https://x.com/vcode_sh">Vibe Code</a>',
            'homepage'      => 'https://fchub.co',
            'download_link' => $release['zip_url'],
            'requires'      => '6.4',
            'requires_php'  => '8.1',
            'sections'      => [
                'changelog'   => self::markdownToHtml($release['body']),
                'description' => sprintf('FCHub plugin: %s', $slug),
            ],
            'banners'       => [],
            'last_updated'  => $release['published_at'],
        ];

        return $info;
    }

    /**
     * Clear our transient when WP clears its own update cache.
     */
    public static function clearCache(): void
    {
        delete_transient(self::TRANSIENT_KEY);
        self::$releasesCache = null;
    }

    /**
     * Fetch releases with 3-tier cache: static → transient → API.
     *
     * @return array<string, array> Keyed by plugin slug.
     */
    private static function fetchReleases(): array
    {
        // 1. In-memory cache (same request)
        if (self::$releasesCache !== null) {
            return self::$releasesCache;
        }

        // 2. Transient cache
        $cached = get_transient(self::TRANSIENT_KEY);
        if (is_array($cached)) {
            self::$releasesCache = $cached;
            return $cached;
        }

        // 3. Rate-limit backoff — don't hammer the API after a 403/429
        if (get_transient('fchub_github_rate_limited')) {
            self::$releasesCache = [];
            return [];
        }

        // 4. Fetch from GitHub API
        $response = wp_remote_get(self::API_URL, [
            'timeout'    => 10,
            'user-agent' => 'FCHub-Updater/' . (self::$plugins ? reset(self::$plugins)['version'] : '1.0.0'),
            'headers'    => ['Accept' => 'application/vnd.github.v3+json'],
        ]);

        if (is_wp_error($response)) {
            // Network error — cache empty for 5 minutes to avoid hammering
            set_transient(self::TRANSIENT_KEY, [], 5 * MINUTE_IN_SECONDS);
            self::$releasesCache = [];
            return [];
        }

        $statusCode = wp_remote_retrieve_response_code($response);

        if ($statusCode === 403 || $statusCode === 429) {
            // Rate limited — back off for 15 minutes
            set_transient('fchub_github_rate_limited', true, 15 * MINUTE_IN_SECONDS);
            self::$releasesCache = [];
            return [];
        }

        if ($statusCode !== 200) {
            set_transient(self::TRANSIENT_KEY, [], 5 * MINUTE_IN_SECONDS);
            self::$releasesCache = [];
            return [];
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (!is_array($data)) {
            set_transient(self::TRANSIENT_KEY, [], 5 * MINUTE_IN_SECONDS);
            self::$releasesCache = [];
            return [];
        }

        $parsed = self::parseReleases($data);

        set_transient(self::TRANSIENT_KEY, $parsed, self::CACHE_TTL);
        self::$releasesCache = $parsed;

        return $parsed;
    }

    /**
     * Parse GitHub releases into a slug-keyed array (latest version per slug).
     *
     * @param array $releases Raw GitHub API response.
     * @return array<string, array>
     */
    private static function parseReleases(array $releases): array
    {
        $result = [];

        foreach ($releases as $release) {
            // Skip drafts and prereleases
            if (!empty($release['draft']) || !empty($release['prerelease'])) {
                continue;
            }

            $tag = $release['tag_name'] ?? '';

            // Parse slash-tag: "fchub-p24/v1.0.2" → slug="fchub-p24", version="1.0.2"
            if (!preg_match('#^([a-z0-9-]+)/v(\d+\.\d+\.\d+)$#', $tag, $m)) {
                continue;
            }

            $slug    = $m[1];
            $version = $m[2];

            // Only process known plugin slugs
            if (!in_array($slug, self::KNOWN_SLUGS, true)) {
                continue;
            }

            // Keep only the latest version per slug
            if (isset($result[$slug]) && version_compare($result[$slug]['version'], $version, '>=')) {
                continue;
            }

            // Find the ZIP asset
            $zipUrl = null;
            $expectedName = "{$slug}-{$version}.zip";

            foreach ($release['assets'] ?? [] as $asset) {
                if (($asset['name'] ?? '') === $expectedName) {
                    $zipUrl = $asset['browser_download_url'] ?? null;
                    break;
                }
            }

            // No ZIP asset — skip this release
            if ($zipUrl === null) {
                continue;
            }

            $result[$slug] = [
                'version'      => $version,
                'zip_url'      => $zipUrl,
                'html_url'     => $release['html_url'] ?? '',
                'body'         => $release['body'] ?? '',
                'name'         => $release['name'] ?? "{$slug} v{$version}",
                'published_at' => $release['published_at'] ?? '',
            ];
        }

        return $result;
    }

    /**
     * Derive plugin slug from the plugin file basename.
     *
     * "fchub-p24/fchub-p24.php" → "fchub-p24"
     * "cartshift/cartshift.php" → "cartshift"
     */
    private static function slugFromFile(string $pluginFile): ?string
    {
        $dir = dirname($pluginFile);

        if (in_array($dir, self::KNOWN_SLUGS, true)) {
            return $dir;
        }

        return null;
    }

    /**
     * Lightweight markdown → HTML for changelogs.
     *
     * Handles headers, lists, bold, inline code, code blocks, and links.
     * Output is sanitised with wp_kses_post().
     */
    private static function markdownToHtml(string $md): string
    {
        if (trim($md) === '') {
            return '<p>No changelog available.</p>';
        }

        $html = esc_html($md);

        // Code blocks (```...```) — must come before line-level processing
        $html = preg_replace('/```[\s\S]*?```/', '<pre><code>$0</code></pre>', $html);

        // Headers
        $html = preg_replace('/^#### (.+)$/m', '<h4>$1</h4>', $html);
        $html = preg_replace('/^### (.+)$/m', '<h3>$1</h3>', $html);
        $html = preg_replace('/^## (.+)$/m', '<h2>$1</h2>', $html);

        // Bold
        $html = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $html);

        // Inline code
        $html = preg_replace('/`([^`]+)`/', '<code>$1</code>', $html);

        // Unordered lists
        $html = preg_replace('/^[\-\*] (.+)$/m', '<li>$1</li>', $html);
        $html = preg_replace('/(<li>.*<\/li>\n?)+/s', '<ul>$0</ul>', $html);

        // Links [text](url)
        $html = preg_replace('/\[([^\]]+)\]\(([^)]+)\)/', '<a href="$2">$1</a>', $html);

        // Paragraphs: double newlines
        $html = preg_replace('/\n{2,}/', '</p><p>', $html);
        $html = '<p>' . $html . '</p>';

        // Clean up empty paragraphs and misplaced tags
        $html = str_replace(['<p></p>', '<p><h', '</h2></p>', '</h3></p>', '</h4></p>'], ['', '<h', '</h2>', '</h3>', '</h4>'], $html);
        $html = str_replace(['<p><ul>', '</ul></p>', '<p><pre>', '</pre></p>'], ['<ul>', '</ul>', '<pre>', '</pre>'], $html);

        return wp_kses_post($html);
    }
}

} // end class_exists guard

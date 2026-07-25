<?php

namespace FChubHub\Support;

defined('ABSPATH') || exit;

/**
 * Resolves a Vite build manifest into the handful of values WordPress needs
 * to enqueue the FCHub admin bundle: the entry script, its imported
 * stylesheets (deduplicated, walked recursively through imports), and a
 * cache-busting version derived from the manifest file's own mtime.
 */
final class AssetManifest
{
    public function __construct(private readonly string $distPath)
    {
    }

    /**
     * @return array{script: string, styles: list<string>, version: string}|null
     */
    public function resolve(string $entryKey): ?array
    {
        $manifestPath = rtrim($this->distPath, '/') . '/.vite/manifest.json';

        // An unreadable manifest (permissions, a race with a concurrent
        // build) is a handled, expected outcome here — it falls through to
        // the same "no build yet" null return as a missing file. Checking
        // is_readable() up front, rather than suppressing file_get_contents()
        // with @, keeps that outcome explicit instead of silently masking
        // every possible warning the call could raise (open_basedir, a
        // misbehaving network filesystem, and so on).
        if (!is_file($manifestPath) || !is_readable($manifestPath)) {
            return null;
        }

        $contents = file_get_contents($manifestPath);
        $manifest = is_string($contents) ? json_decode($contents, true) : null;

        if (!is_array($manifest) || !isset($manifest[$entryKey]['file']) || !is_string($manifest[$entryKey]['file'])) {
            return null;
        }

        return [
            'script'  => $manifest[$entryKey]['file'],
            'styles'  => $this->collectStyles($manifest, $entryKey),
            'version' => (string) filemtime($manifestPath),
        ];
    }

    /**
     * Walks the entry's import graph breadth-first, collecting every CSS
     * asset exactly once. Vite can move shared CSS into imported chunks, and
     * WordPress will not discover those on its own — it does not process
     * modulepreload links the way a browser does.
     *
     * @param array<string, array<string, mixed>> $manifest
     * @return list<string>
     */
    private function collectStyles(array $manifest, string $entryKey): array
    {
        $styles = [];
        $seen = [];
        $visited = [];
        $pending = [$entryKey];

        while ($pending !== []) {
            $key = array_shift($pending);

            if (!is_string($key) || isset($visited[$key])) {
                continue;
            }
            $visited[$key] = true;

            $entry = $manifest[$key] ?? null;
            if (!is_array($entry)) {
                continue;
            }

            foreach ((array) ($entry['css'] ?? []) as $css) {
                if (is_string($css) && !isset($seen[$css])) {
                    $seen[$css] = true;
                    $styles[] = $css;
                }
            }

            foreach ((array) ($entry['imports'] ?? []) as $import) {
                if (is_string($import) && !isset($visited[$import])) {
                    $pending[] = $import;
                }
            }
        }

        return $styles;
    }
}

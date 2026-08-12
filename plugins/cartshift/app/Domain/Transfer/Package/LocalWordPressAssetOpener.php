<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\Package;

use CartShift\Domain\Transfer\SourceRecordException;

defined('ABSPATH') || exit;

/** Opens only regular files under the exact WordPress upload root. No network, wrappers or symlinks. */
final readonly class LocalWordPressAssetOpener
{
    private string $baseUrl;
    private string $baseDirectory;

    public function __construct(string $baseUrl, string $baseDirectory)
    {
        $real = realpath($baseDirectory);
        if ($real === false || !is_dir($real) || is_link($baseDirectory)) {
            throw new \InvalidArgumentException('WordPress upload root is unavailable.');
        }
        $parts = parse_url(rtrim($baseUrl, '/'));
        if (!is_array($parts)
            || !in_array($parts['scheme'] ?? null, ['http', 'https'], true)
            || !is_string($parts['host'] ?? null)
            || ($parts['host'] ?? '') === ''
            || isset($parts['user']) || isset($parts['pass'])) {
            throw new \InvalidArgumentException('WordPress upload base URL is invalid.');
        }
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->baseDirectory = rtrim($real, DIRECTORY_SEPARATOR);
    }

    /** @param array{locator?: mixed} $reference @return resource */
    public function __invoke(array $reference): mixed
    {
        $locator = $reference['locator'] ?? null;
        if (!is_string($locator) || $locator === '') {
            throw $this->unsupported();
        }
        $candidate = str_starts_with($locator, '/')
            ? $locator
            : $this->pathFromUrl($locator);
        if ($candidate === null || str_contains($candidate, "\0") || is_link($candidate)) {
            throw $this->unsupported();
        }
        $real = realpath($candidate);
        if ($real === false || !is_file($real) || !is_readable($real)
            || !str_starts_with($real . DIRECTORY_SEPARATOR, $this->baseDirectory . DIRECTORY_SEPARATOR)) {
            throw $this->unsupported();
        }
        $stream = fopen($real, 'rb');
        if (!is_resource($stream)) {
            throw new SourceRecordException('asset_missing', 'Approved local asset could not be opened.');
        }

        return $stream;
    }

    private function pathFromUrl(string $locator): ?string
    {
        $base = parse_url($this->baseUrl);
        $candidate = parse_url($locator);
        if (!is_array($base) || !is_array($candidate)
            || ($candidate['scheme'] ?? null) !== ($base['scheme'] ?? null)
            || strtolower((string) ($candidate['host'] ?? '')) !== strtolower((string) ($base['host'] ?? ''))
            || ($candidate['port'] ?? null) !== ($base['port'] ?? null)
            || isset($candidate['user']) || isset($candidate['pass'])) {
            return null;
        }
        $basePath = rtrim((string) ($base['path'] ?? ''), '/');
        $path = (string) ($candidate['path'] ?? '');
        if (!str_starts_with($path, $basePath . '/')) {
            return null;
        }
        $relative = rawurldecode(substr($path, strlen($basePath) + 1));
        if ($relative === '' || str_contains($relative, '\\') || str_contains($relative, "\0")) {
            return null;
        }
        $segments = explode('/', $relative);
        if (array_filter($segments, static fn (string $segment): bool => $segment === '' || $segment === '.' || $segment === '..') !== []) {
            return null;
        }

        return $this->baseDirectory . DIRECTORY_SEPARATOR . implode(DIRECTORY_SEPARATOR, $segments);
    }

    private function unsupported(): SourceRecordException
    {
        return new SourceRecordException(
            'asset_locator_unsupported',
            'Asset locator is not an exact readable file under the local WordPress upload root.',
        );
    }
}

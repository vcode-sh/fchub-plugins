<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\Package;

use CartShift\Support\CanonicalJson;

defined('ABSPATH') || exit;

/** Derives a non-secret identity for the currently loaded WordPress source instance. */
final class LoadedSourceInstanceFingerprint
{
    /** @var \Closure(): string */
    private readonly \Closure $homeUrlReader;

    /** @var \Closure(): string */
    private readonly \Closure $databaseNameReader;

    /** @var \Closure(): string */
    private readonly \Closure $tablePrefixReader;

    /** @var \Closure(): string */
    private readonly \Closure $absolutePathReader;

    /** @var \Closure(): array<string, string> */
    private readonly \Closure $saltReader;

    /**
     * @param (callable(): string)|null $homeUrlReader
     * @param (callable(): string)|null $databaseNameReader
     * @param (callable(): string)|null $tablePrefixReader
     * @param (callable(): string)|null $absolutePathReader
     * @param (callable(): array<string, string>)|null $saltReader
     */
    public function __construct(
        ?callable $homeUrlReader = null,
        ?callable $databaseNameReader = null,
        ?callable $tablePrefixReader = null,
        ?callable $absolutePathReader = null,
        ?callable $saltReader = null,
    ) {
        $this->homeUrlReader = $homeUrlReader === null
            ? static fn (): string => function_exists('home_url') ? (string) home_url('/') : ''
            : $homeUrlReader(...);
        $this->databaseNameReader = $databaseNameReader === null
            ? static fn (): string => defined('DB_NAME') ? (string) DB_NAME : ''
            : $databaseNameReader(...);
        $this->tablePrefixReader = $tablePrefixReader === null
            ? static function (): string {
                global $wpdb;
                return isset($wpdb->prefix) ? (string) $wpdb->prefix : '';
            }
            : $tablePrefixReader(...);
        $this->absolutePathReader = $absolutePathReader === null
            ? static fn (): string => defined('ABSPATH') ? (string) ABSPATH : ''
            : $absolutePathReader(...);
        $this->saltReader = $saltReader === null
            ? static function (): array {
                $salts = [];
                foreach (['AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY', 'AUTH_SALT', 'SECURE_AUTH_SALT', 'LOGGED_IN_SALT', 'NONCE_SALT'] as $name) {
                    $salts[$name] = defined($name) ? (string) constant($name) : '';
                }
                return $salts;
            }
            : $saltReader(...);
    }

    public function fingerprint(): string
    {
        $url = self::normalisePublicUrl(($this->homeUrlReader)());
        $database = ($this->databaseNameReader)();
        $prefix = ($this->tablePrefixReader)();
        $path = self::normaliseAbsolutePath(($this->absolutePathReader)());
        $salts = ($this->saltReader)();

        if ($database === '' || $prefix === '' || $salts === []) {
            throw new \RuntimeException('source_instance_facts_incomplete');
        }
        foreach ($salts as $name => $value) {
            if (!is_string($name) || $name === '' || !is_string($value) || $value === '') {
                throw new \RuntimeException('source_instance_facts_incomplete');
            }
        }
        ksort($salts, SORT_STRING);

        return CanonicalJson::fingerprint([
            'source_instance' => [
                'version' => 1,
                'home_url' => $url,
                'database_name_sha256' => hash('sha256', $database),
                'table_prefix' => $prefix,
                'absolute_path_sha256' => hash('sha256', $path),
                'wordpress_salts_sha256' => CanonicalJson::fingerprint(['salts' => $salts]),
            ],
        ]);
    }

    private static function normalisePublicUrl(string $url): string
    {
        $parts = parse_url(trim($url));
        if (!is_array($parts)
            || !in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
            || !is_string($parts['host'] ?? null) || $parts['host'] === ''
            || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment'])) {
            throw new \RuntimeException('source_instance_home_url_invalid');
        }
        $scheme = strtolower((string) $parts['scheme']);
        $host = strtolower((string) $parts['host']);
        $port = isset($parts['port']) ? (int) $parts['port'] : null;
        $portPart = $port !== null && !(($scheme === 'https' && $port === 443) || ($scheme === 'http' && $port === 80))
            ? ':' . $port
            : '';
        $path = '/' . trim((string) ($parts['path'] ?? ''), '/');

        return $scheme . '://' . $host . $portPart . ($path === '/' ? '/' : $path . '/');
    }

    private static function normaliseAbsolutePath(string $path): string
    {
        $path = str_replace('\\', '/', trim($path));
        if ($path === '' || $path[0] !== '/' || str_contains($path, "\0")) {
            throw new \RuntimeException('source_instance_absolute_path_invalid');
        }

        return rtrim($path, '/') . '/';
    }
}

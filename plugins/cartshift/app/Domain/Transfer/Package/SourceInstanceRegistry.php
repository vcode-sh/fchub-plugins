<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\Package;

use CartShift\Domain\Transfer\SourceIdentity;
use CartShift\Support\CanonicalJson;

defined('ABSPATH') || exit;

final class SourceInstanceRegistry
{
    public function __construct(private readonly string $path)
    {
        if ($path === '' || $path[0] !== '/' || is_link($path)) {
            throw new \InvalidArgumentException('Source-instance registry path must be an absolute non-symlink path.');
        }
        $directory = dirname($path);
        if (!is_dir($directory) || is_link($directory) || !is_readable($directory)) {
            throw new \InvalidArgumentException('Source-instance registry directory is unavailable.');
        }
        $directoryReal = realpath($directory);
        $webRoot = defined('ABSPATH') ? realpath(ABSPATH) : false;
        if ($directoryReal === false
            || ($webRoot !== false && ($directoryReal === $webRoot || str_starts_with($directoryReal . '/', $webRoot . '/')))) {
            throw new \InvalidArgumentException('Source-instance registry must remain outside the WordPress web root.');
        }
        if ((fileperms($directory) & 0077) !== 0) {
            throw new \InvalidArgumentException('Source-instance registry directory permissions are not private.');
        }
        if (file_exists($path)) {
            $this->read();
        }
    }

    public static function approval(string $sourceKey, string $fingerprint): string
    {
        SourceIdentity::assertValidSourceKey($sourceKey);
        self::assertHash($fingerprint);
        return hash('sha256', 'cartshift-source-bind-v1:' . $sourceKey . ':' . $fingerprint);
    }

    public function bindOwnerApproved(string $sourceKey, string $fingerprint, string $approval): void
    {
        SourceIdentity::assertValidSourceKey($sourceKey);
        self::assertHash($fingerprint);
        if (!hash_equals(self::approval($sourceKey, $fingerprint), $approval)) {
            throw new \RuntimeException('Source-instance binding approval does not match the requested immutable identity.');
        }
        if (!is_writable(dirname($this->path))) {
            throw new \RuntimeException('Source-instance registry directory is read-only.');
        }
        $bindings = $this->read();
        if (isset($bindings[$sourceKey])) {
            if (!hash_equals($bindings[$sourceKey], $fingerprint)) {
                throw new \RuntimeException('Source key is already bound to a different source instance.');
            }
            return;
        }
        $bindings[$sourceKey] = $fingerprint;
        ksort($bindings);
        $temporary = $this->path . '.tmp-' . bin2hex(random_bytes(8));
        $stream = fopen($temporary, 'x+b');
        if (!is_resource($stream)) {
            throw new \RuntimeException('Private source-instance registry temporary file could not be created.');
        }
        chmod($temporary, 0600);
        try {
            $bytes = CanonicalJson::encode(['bindings' => $bindings]) . "\n";
            if (fwrite($stream, $bytes) !== strlen($bytes) || !fflush($stream) || !function_exists('fsync') || !fsync($stream)) {
                throw new \RuntimeException('Source-instance registry write is not durable.');
            }
        } catch (\Throwable $exception) {
            fclose($stream);
            unlink($temporary);
            throw $exception;
        }
        fclose($stream);
        if (!rename($temporary, $this->path)) {
            unlink($temporary);
            throw new \RuntimeException('Source-instance registry could not be promoted atomically.');
        }
        chmod($this->path, 0600);
    }

    public function requireBinding(string $sourceKey, string $fingerprint): void
    {
        SourceIdentity::assertValidSourceKey($sourceKey);
        self::assertHash($fingerprint);
        $bound = $this->read()[$sourceKey] ?? null;
        if (!is_string($bound) || !hash_equals($bound, $fingerprint)) {
            throw new \RuntimeException('Source key is not bound to this source instance.');
        }
    }

    public function binding(string $sourceKey): ?string
    {
        SourceIdentity::assertValidSourceKey($sourceKey);
        $fingerprint = $this->read()[$sourceKey] ?? null;

        return is_string($fingerprint) ? $fingerprint : null;
    }

    /** @return array<string, string> */
    private function read(): array
    {
        if (!file_exists($this->path)) return [];
        if (is_link($this->path) || !is_file($this->path) || !is_readable($this->path)) {
            throw new \RuntimeException('Source-instance registry is not a readable private file.');
        }
        $mode = fileperms($this->path) & 0777;
        if (($mode & 0077) !== 0) {
            throw new \RuntimeException('Source-instance registry permissions are not private.');
        }
        try {
            $data = json_decode((string) file_get_contents($this->path), true, 32, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            throw new \RuntimeException('Source-instance registry is malformed.');
        }
        if (!is_array($data) || array_keys($data) !== ['bindings'] || !is_array($data['bindings'])) {
            throw new \RuntimeException('Source-instance registry shape is invalid.');
        }
        $bindings = $data['bindings'];
        $keys = array_keys($bindings);
        $sorted = $keys;
        sort($sorted);
        if ($keys !== $sorted) throw new \RuntimeException('Source-instance registry bindings are not canonical.');
        foreach ($bindings as $key => $hash) {
            try { SourceIdentity::assertValidSourceKey($key); self::assertHash($hash); }
            catch (\Throwable) { throw new \RuntimeException('Source-instance registry binding is invalid.'); }
        }
        return $bindings;
    }

    private static function assertHash(string $fingerprint): void
    {
        if (preg_match('/\A[a-f0-9]{64}\z/D', $fingerprint) !== 1) {
            throw new \InvalidArgumentException('Source-instance fingerprint must be lowercase SHA-256.');
        }
    }
}

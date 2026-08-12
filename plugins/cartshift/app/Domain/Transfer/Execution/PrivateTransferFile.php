<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\Execution;

defined('ABSPATH') || exit;

final class PrivateTransferFile
{
    public static function directory(string $path): string
    {
        if ($path === '' || $path[0] !== '/' || is_link($path)) {
            throw new \InvalidArgumentException('Transfer evidence directory must be an absolute non-symlink path.');
        }
        $real = realpath($path);
        if ($real === false || !is_dir($real) || (fileperms($real) & 0077) !== 0) {
            throw new \InvalidArgumentException('Transfer evidence directory must exist with private permissions.');
        }
        $webRoot = defined('ABSPATH') ? realpath(ABSPATH) : false;
        if ($webRoot !== false && ($real === $webRoot || str_starts_with($real . '/', $webRoot . '/'))) {
            throw new \InvalidArgumentException('Transfer evidence directory must be outside the WordPress web root.');
        }
        return rtrim($real, '/');
    }

    public static function createDirectory(string $parent, string $name): string
    {
        if (preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9._-]{0,127}\z/D', $name) !== 1) {
            throw new \InvalidArgumentException('Transfer evidence directory name is invalid.');
        }
        $path = $parent . '/' . $name;
        if (!file_exists($path) && !mkdir($path, 0700)) {
            throw new \RuntimeException('Transfer evidence directory could not be created.');
        }
        chmod($path, 0700);
        if (is_link($path) || realpath($path) !== $path || (fileperms($path) & 0077) !== 0) {
            throw new \RuntimeException('Transfer evidence directory is not private and canonical.');
        }
        self::syncDirectory($parent);
        return $path;
    }

    public static function writeImmutable(string $directory, string $name, string $bytes, string $conflictCode): string
    {
        if (preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9._-]{0,191}\z/D', $name) !== 1) {
            throw new \InvalidArgumentException('Transfer evidence filename is invalid.');
        }
        $path = $directory . '/' . $name;
        if (file_exists($path) || is_link($path)) {
            $existing = is_file($path) && !is_link($path) ? file_get_contents($path) : false;
            if (!is_string($existing) || !hash_equals(hash('sha256', $existing), hash('sha256', $bytes))) {
                throw new \RuntimeException($conflictCode);
            }
            return $path;
        }
        $temporary = $directory . '/.' . $name . '.' . bin2hex(random_bytes(10));
        $stream = fopen($temporary, 'x+b');
        if (!is_resource($stream)) throw new \RuntimeException('Transfer evidence temporary file could not be created.');
        chmod($temporary, 0600);
        try {
            if (fwrite($stream, $bytes) !== strlen($bytes) || !fflush($stream) || !function_exists('fsync') || !fsync($stream)) {
                throw new \RuntimeException('Transfer evidence durability could not be proven.');
            }
        } catch (\Throwable $exception) {
            fclose($stream);
            unlink($temporary);
            throw $exception;
        }
        fclose($stream);
        self::syncDirectory($directory);
        if (!rename($temporary, $path)) {
            unlink($temporary);
            throw new \RuntimeException('Transfer evidence could not be promoted atomically.');
        }
        chmod($path, 0600);
        self::syncDirectory($directory);
        return $path;
    }

    public static function syncDirectory(string $path): void
    {
        $stream = fopen($path, 'r');
        if (!is_resource($stream)) throw new \RuntimeException('Transfer evidence directory cannot be opened.');
        try {
            if (!function_exists('fsync') || !fsync($stream)) {
                throw new \RuntimeException('Transfer evidence directory durability could not be proven.');
            }
        } finally {
            fclose($stream);
        }
    }
}

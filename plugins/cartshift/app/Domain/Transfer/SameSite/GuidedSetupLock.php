<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\SameSite;

defined('ABSPATH') || exit;

/** A request-scoped setup guard that the operating system releases after a crash. */
final class GuidedSetupLock
{
    private static ?int $processUserId = null;

    /** @param resource $handle */
    private function __construct(private mixed $handle)
    {
    }

    public static function acquire(string $name): self
    {
        $directory = self::directory();
        $path = $directory . '/' . hash('sha256', $name) . '.lock';
        if (is_link($path)) {
            throw new \RuntimeException('guided_transfer_setup_lock_unsafe');
        }

        $handle = @fopen($path, 'c');
        if ($handle === false) {
            throw new \RuntimeException('guided_transfer_setup_lock_unavailable');
        }
        if (!self::safeFile($path, $handle)) {
            fclose($handle);
            throw new \RuntimeException('guided_transfer_setup_lock_unsafe');
        }
        @chmod($path, 0600);

        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            throw new \RuntimeException('guided_transfer_setup_busy');
        }

        return new self($handle);
    }

    private static function directory(): string
    {
        $temporaryDirectory = realpath(sys_get_temp_dir());
        if ($temporaryDirectory === false || is_link($temporaryDirectory)) {
            throw new \RuntimeException('guided_transfer_setup_lock_directory_unsafe');
        }

        $directory = $temporaryDirectory . '/cartshift-locks-' . hash('sha256', ABSPATH);
        if (!@mkdir($directory, 0700) && !file_exists($directory) && !is_link($directory)) {
            throw new \RuntimeException('guided_transfer_setup_lock_directory_unavailable');
        }
        clearstatcache(true, $directory);
        $stat = @lstat($directory);

        if (!is_array($stat)
            || ($stat['mode'] & 0170000) !== 0040000
            || ($stat['mode'] & 0777) !== 0700
            || $stat['uid'] !== self::effectiveUserId($temporaryDirectory)
            || !is_writable($directory)
            || realpath($directory) !== $directory) {
            throw new \RuntimeException('guided_transfer_setup_lock_directory_unsafe');
        }

        return $directory;
    }

    /** @param resource $handle */
    private static function safeFile(string $path, mixed $handle): bool
    {
        clearstatcache(true, $path);
        $opened = fstat($handle);
        $stored = @lstat($path);

        return is_array($opened)
            && is_array($stored)
            && ($opened['mode'] & 0170000) === 0100000
            && ($stored['mode'] & 0170000) === 0100000
            && $opened['uid'] === self::effectiveUserId(realpath(sys_get_temp_dir()) ?: '')
            && $stored['uid'] === self::effectiveUserId(realpath(sys_get_temp_dir()) ?: '')
            && $opened['nlink'] === 1
            && $stored['nlink'] === 1
            && $opened['dev'] === $stored['dev']
            && $opened['ino'] === $stored['ino'];
    }

    private static function effectiveUserId(string $temporaryDirectory): int
    {
        if (self::$processUserId !== null) {
            return self::$processUserId;
        }

        $probe = $temporaryDirectory === '' ? false : tempnam($temporaryDirectory, 'cartshift-owner-');
        $stat = is_string($probe) ? @lstat($probe) : false;
        if (!is_string($probe)
            || dirname($probe) !== $temporaryDirectory
            || !is_array($stat)
            || ($stat['mode'] & 0170000) !== 0100000
            || $stat['nlink'] !== 1) {
            if (is_string($probe)) {
                @unlink($probe);
            }
            throw new \RuntimeException('guided_transfer_setup_owner_probe_failed');
        }

        self::$processUserId = $stat['uid'];
        @unlink($probe);

        return self::$processUserId;
    }

    public function __destruct()
    {
        if (is_resource($this->handle)) {
            flock($this->handle, LOCK_UN);
            fclose($this->handle);
        }
    }
}

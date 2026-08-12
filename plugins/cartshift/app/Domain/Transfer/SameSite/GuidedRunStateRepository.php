<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\SameSite;

use CartShift\Domain\Transfer\Execution\PrivateTransferFile;
use CartShift\Domain\Transfer\SourceIdentity;
use CartShift\Support\CanonicalJson;

defined('ABSPATH') || exit;

/** Atomic private-file storage for the one active guided run on a source site. */
final readonly class GuidedRunStateRepository
{
    private string $directory;

    public function __construct(string $directory, private string $sourceKey)
    {
        SourceIdentity::assertValidSourceKey($sourceKey);
        $this->directory = PrivateTransferFile::directory($directory);
    }

    public function path(): string
    {
        return $this->directory . '/guided-run-' . $this->sourceKey . '.json';
    }

    public function get(): ?GuidedRunState
    {
        $path = $this->path();
        if (!is_file($path)) {
            return null;
        }
        if (is_link($path) || ((int) fileperms($path) & 0077) !== 0) {
            throw new \RuntimeException('guided_run_state_file_invalid');
        }
        $bytes = file_get_contents($path);
        if (!is_string($bytes)) {
            throw new \RuntimeException('guided_run_state_file_invalid');
        }
        try {
            $data = json_decode($bytes, true, 64, JSON_THROW_ON_ERROR);
            $state = is_array($data) ? GuidedRunState::fromArray($data) : null;
        } catch (\Throwable) {
            throw new \RuntimeException('guided_run_state_file_invalid');
        }
        if (!$state instanceof GuidedRunState
            || !is_array($data)
            || !hash_equals(CanonicalJson::encode($data) . "\n", $bytes)
            || !hash_equals($this->sourceKey, $state->sourceKey)) {
            throw new \RuntimeException('guided_run_state_file_invalid');
        }

        return $state;
    }

    /** @param callable(?GuidedRunState): GuidedRunState $transition */
    public function transaction(callable $transition): GuidedRunState
    {
        $lockPath = $this->path() . '.lock';
        if (is_link($lockPath)) {
            throw new \RuntimeException('guided_run_state_lock_invalid');
        }
        $lock = fopen($lockPath, 'c+b');
        if (!is_resource($lock)) {
            throw new \RuntimeException('guided_run_state_lock_failed');
        }
        chmod($lockPath, 0600);
        try {
            if (is_link($lockPath) || !flock($lock, LOCK_EX)) {
                throw new \RuntimeException('guided_run_state_lock_failed');
            }
            $next = $transition($this->get());
            if (!hash_equals($this->sourceKey, $next->sourceKey)) {
                throw new \RuntimeException('guided_run_source_mismatch');
            }
            $this->write($next);

            return $next;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function write(GuidedRunState $state): void
    {
        $bytes = CanonicalJson::encode($state->toArray()) . "\n";
        $temporary = $this->path() . '.tmp-' . bin2hex(random_bytes(8));
        $stream = fopen($temporary, 'x+b');
        if (!is_resource($stream)) {
            throw new \RuntimeException('guided_run_state_write_failed');
        }
        chmod($temporary, 0600);
        try {
            if (fwrite($stream, $bytes) !== strlen($bytes) || !fflush($stream)
                || !function_exists('fsync') || !fsync($stream)) {
                throw new \RuntimeException('guided_run_state_write_failed');
            }
        } catch (\Throwable $failure) {
            fclose($stream);
            unlink($temporary);
            throw $failure;
        }
        fclose($stream);
        if (!rename($temporary, $this->path())) {
            unlink($temporary);
            throw new \RuntimeException('guided_run_state_write_failed');
        }
        chmod($this->path(), 0600);
        PrivateTransferFile::syncDirectory($this->directory);
    }
}

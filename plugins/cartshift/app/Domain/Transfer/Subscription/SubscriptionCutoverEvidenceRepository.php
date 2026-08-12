<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\Subscription;

use CartShift\Domain\Transfer\Execution\PrivateTransferFile;
use CartShift\Support\CanonicalJson;

defined('ABSPATH') || exit;

final readonly class SubscriptionCutoverEvidenceRepository
{
    private string $directory;

    public function __construct(string $directory) { $this->directory = PrivateTransferFile::directory($directory); }

    public function path(string $runId): string
    {
        if (preg_match('/\A[a-z0-9][a-z0-9-]{2,35}\z/D', $runId) !== 1) {
            throw new \InvalidArgumentException('subscription_cutover_evidence_run_invalid');
        }
        return $this->directory . '/' . $runId . '.subscription-cutover.json';
    }

    public function get(string $runId): SubscriptionCutoverEvidence
    {
        $path = $this->path($runId);
        if (!is_file($path) || is_link($path)) {
            throw new \RuntimeException('subscription_cutover_evidence_missing');
        }
        $permissions = fileperms($path);
        if (!is_int($permissions) || ($permissions & 0077) !== 0) throw new \RuntimeException('subscription_cutover_evidence_missing');
        $bytes = file_get_contents($path);
        if (!is_string($bytes)) throw new \RuntimeException('subscription_cutover_evidence_invalid');
        try {
            $data = json_decode($bytes, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new \RuntimeException('subscription_cutover_evidence_invalid');
        }
        if (!is_array($data)) throw new \RuntimeException('subscription_cutover_evidence_invalid');
        $evidence = SubscriptionCutoverEvidence::fromArray($data);
        if (!hash_equals(CanonicalJson::encode($evidence->toArray()) . "\n", $bytes)) {
            throw new \RuntimeException('subscription_cutover_evidence_not_canonical');
        }
        return $evidence;
    }

    public function create(SubscriptionCutoverEvidence $evidence): string
    {
        return $this->locked(
            $evidence->runId,
            fn (): string => PrivateTransferFile::writeImmutable(
                $this->directory,
                $evidence->runId . '.subscription-cutover.json',
                CanonicalJson::encode($evidence->toArray()) . "\n",
                'subscription_cutover_evidence_conflict',
            ),
        );
    }

    public function createPreparedIdempotently(SubscriptionCutoverEvidence $evidence): SubscriptionCutoverEvidence
    {
        if ($evidence->state !== SubscriptionCutoverEvidence::PREPARED) {
            throw new \InvalidArgumentException('subscription_cutover_preparation_state_invalid');
        }
        return $this->locked($evidence->runId, function () use ($evidence): SubscriptionCutoverEvidence {
            try {
                $existing = $this->get($evidence->runId);
            } catch (\RuntimeException $exception) {
                if ($exception->getMessage() !== 'subscription_cutover_evidence_missing') throw $exception;
                PrivateTransferFile::writeImmutable(
                    $this->directory,
                    $evidence->runId . '.subscription-cutover.json',
                    CanonicalJson::encode($evidence->toArray()) . "\n",
                    'subscription_cutover_evidence_conflict',
                );
                return $evidence;
            }
            $expected = $evidence->toArray();
            $actual = $existing->toArray();
            unset($expected['updated_at_utc'], $actual['updated_at_utc']);
            if (!hash_equals(CanonicalJson::fingerprint($expected), CanonicalJson::fingerprint($actual))) {
                throw new \RuntimeException('subscription_cutover_evidence_conflict');
            }
            return $existing;
        });
    }

    public function createPreparedIfPresent(?SubscriptionCutoverEvidence $evidence): ?SubscriptionCutoverEvidence
    {
        return $evidence === null ? null : $this->createPreparedIdempotently($evidence);
    }

    public function replace(SubscriptionCutoverEvidence $before, SubscriptionCutoverEvidence $after): void
    {
        if ($before->runId !== $after->runId) throw new \RuntimeException('subscription_cutover_evidence_run_changed');
        if (!hash_equals($this->immutableHeaderFingerprint($before), $this->immutableHeaderFingerprint($after))) {
            throw new \RuntimeException('subscription_cutover_evidence_header_changed');
        }
        $this->locked($before->runId, function () use ($before, $after): void {
            $current = $this->get($before->runId);
            if (!hash_equals($before->fingerprint(), $current->fingerprint())) {
                throw new \RuntimeException('subscription_cutover_evidence_concurrent_change');
            }
            $this->atomicReplace($this->path($before->runId), CanonicalJson::encode($after->toArray()) . "\n");
        });
    }

    private function immutableHeaderFingerprint(SubscriptionCutoverEvidence $evidence): string
    {
        $data = $evidence->toArray();
        unset($data['state'], $data['entries'], $data['updated_at_utc']);
        return CanonicalJson::fingerprint($data);
    }

    /** @template T @param callable():T $callback @return T */
    private function locked(string $runId, callable $callback): mixed
    {
        $lockPath = $this->path($runId) . '.lock';
        if (is_link($lockPath)) throw new \RuntimeException('subscription_cutover_evidence_lock_invalid');
        $stream = fopen($lockPath, 'c+b');
        if (!is_resource($stream)) throw new \RuntimeException('subscription_cutover_evidence_lock_failed');
        chmod($lockPath, 0600);
        try {
            if (is_link($lockPath) || !flock($stream, LOCK_EX)) {
                throw new \RuntimeException('subscription_cutover_evidence_lock_failed');
            }
            return $callback();
        } finally {
            flock($stream, LOCK_UN);
            fclose($stream);
        }
    }

    private function atomicReplace(string $path, string $bytes): void
    {
        $temporary = $path . '.tmp-' . bin2hex(random_bytes(8));
        $stream = fopen($temporary, 'x+b');
        if (!is_resource($stream)) throw new \RuntimeException('subscription_cutover_evidence_write_failed');
        chmod($temporary, 0600);
        try {
            if (fwrite($stream, $bytes) !== strlen($bytes) || !fflush($stream) || !function_exists('fsync') || !fsync($stream)) {
                throw new \RuntimeException('subscription_cutover_evidence_write_failed');
            }
        } catch (\Throwable $exception) {
            fclose($stream);
            unlink($temporary);
            throw $exception;
        }
        fclose($stream);
        if (!rename($temporary, $path)) {
            unlink($temporary);
            throw new \RuntimeException('subscription_cutover_evidence_write_failed');
        }
        chmod($path, 0600);
        PrivateTransferFile::syncDirectory(dirname($path));
    }
}

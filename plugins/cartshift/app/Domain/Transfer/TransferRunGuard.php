<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer;

use CartShift\Domain\Transfer\Execution\TransferRunBoundary;

defined('ABSPATH') || exit;

final readonly class TransferRunGuard implements TransferRunBoundary
{
    public function __construct(
        private TransferLock $lock,
        private TransferLease $lease,
    ) {
    }

    public function acquire(string $targetFingerprint, string $holderId, string $descriptorHash, int $ttl): void
    {
        $this->underMutex($targetFingerprint, fn (): mixed => $this->lease->acquire(
            $targetFingerprint,
            $holderId,
            $descriptorHash,
            $ttl,
        ));
    }

    public function renew(string $targetFingerprint, string $holderId, string $descriptorHash, int $ttl): void
    {
        $this->lease->bindTarget($targetFingerprint);
        $this->underMutex($targetFingerprint, fn (): mixed => $this->lease->renew($holderId, $descriptorHash, $ttl));
    }

    public function recover(
        string $targetFingerprint,
        string $holderId,
        string $descriptorHash,
        string $recoveryEvidenceHash,
        int $ttl,
    ): void {
        $this->lease->bindTarget($targetFingerprint);
        $this->underMutex($targetFingerprint, fn (): mixed => $this->lease->recoverExpired(
            $holderId,
            $descriptorHash,
            $recoveryEvidenceHash,
            $ttl,
        ));
    }

    public function release(string $targetFingerprint, string $holderId, string $descriptorHash): void
    {
        $this->lease->bindTarget($targetFingerprint);
        $this->underMutex($targetFingerprint, fn (): mixed => $this->lease->release($holderId, $descriptorHash));
    }

    public function criticalSection(
        string $targetFingerprint,
        string $holderId,
        string $descriptorHash,
        callable $mutation,
    ): mixed {
        $this->lease->bindTarget($targetFingerprint);
        return $this->underMutex($targetFingerprint, function () use ($holderId, $descriptorHash, $mutation): mixed {
            $this->lease->assertOwned($holderId, $descriptorHash);

            return $mutation();
        });
    }

    private function underMutex(string $targetFingerprint, callable $operation): mixed
    {
        $this->lock->acquireTargetMutex($targetFingerprint);

        try {
            return $operation();
        } finally {
            $this->lock->release();
        }
    }
}

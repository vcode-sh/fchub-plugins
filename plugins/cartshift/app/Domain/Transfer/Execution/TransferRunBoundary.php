<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\Execution;

defined('ABSPATH') || exit;

interface TransferRunBoundary
{
    public function acquire(string $targetFingerprint, string $holderId, string $descriptorHash, int $ttl): void;
    public function renew(string $targetFingerprint, string $holderId, string $descriptorHash, int $ttl): void;
    public function recover(string $targetFingerprint, string $holderId, string $descriptorHash, string $recoveryEvidenceHash, int $ttl): void;
    public function release(string $targetFingerprint, string $holderId, string $descriptorHash): void;
    public function criticalSection(string $targetFingerprint, string $holderId, string $descriptorHash, callable $mutation): mixed;
}

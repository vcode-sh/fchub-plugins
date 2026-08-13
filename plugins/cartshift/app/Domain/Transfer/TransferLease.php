<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer;

defined('ABSPATH') || exit;

final class TransferLease
{
    private ?string $targetFingerprint = null;
    /** @var \Closure(): \DateTimeImmutable */
    private readonly \Closure $clock;

    /** @param (callable(): \DateTimeImmutable)|null $clock */
    public function __construct(
        private readonly ?object $database = null,
        ?callable $clock = null,
    ) {
        $this->clock = $clock !== null
            ? $clock(...)
            : static fn (): \DateTimeImmutable => new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public function acquire(string $targetFingerprint, string $holderId, string $descriptorHash, int $ttl): void
    {
        $this->assertHash($targetFingerprint, 'Target fingerprint');
        $this->assertHolder($holderId);
        $this->assertHash($descriptorHash, 'Descriptor hash');
        $this->assertTtl($ttl);
        $this->targetFingerprint = $targetFingerprint;

        $database = $this->database();
        $now = $this->now();
        $inserted = $database->insert($this->table(), [
            'target_fingerprint' => $targetFingerprint,
            'holder_id' => $holderId,
            'descriptor_hash' => $descriptorHash,
            'expires_at' => $this->expires($now, $ttl),
            'heartbeat_at' => $now,
        ], ['%s', '%s', '%s', '%s', '%s']);

        if ($inserted !== false) {
            $this->verifyOwned($holderId, $descriptorHash, true);
            return;
        }

        $existing = $this->read();

        if (
            $existing !== null
            && hash_equals((string) $existing->holder_id, $holderId)
            && hash_equals((string) $existing->descriptor_hash, $descriptorHash)
            && (string) $existing->expires_at > $now
        ) {
            $this->renew($holderId, $descriptorHash, $ttl);
            return;
        }

        throw new \RuntimeException('transfer_lease_unavailable');
    }

    public function bindTarget(string $targetFingerprint): void
    {
        $this->assertHash($targetFingerprint, 'Target fingerprint');

        if ($this->targetFingerprint !== null && !hash_equals($this->targetFingerprint, $targetFingerprint)) {
            throw new \RuntimeException('transfer_lease_target_mismatch');
        }

        $this->targetFingerprint = $targetFingerprint;
    }

    public function renew(string $holderId, string $descriptorHash, int $ttl): void
    {
        $this->assertBound();
        $this->assertHolder($holderId);
        $this->assertHash($descriptorHash, 'Descriptor hash');
        $this->assertTtl($ttl);
        $now = $this->now();
        $database = $this->database();
        $updated = $database->query($database->prepare(
            "UPDATE {$this->table()}
             SET expires_at = %s, heartbeat_at = %s
             WHERE target_fingerprint = %s AND holder_id = %s AND descriptor_hash = %s
               AND expires_at > %s",
            $this->expires($now, $ttl),
            $now,
            $this->targetFingerprint,
            $holderId,
            $descriptorHash,
            $now,
        ));

        if ($updated === false) {
            throw new \RuntimeException('transfer_lease_database_failure');
        }
        if ($updated === 0) {
            try {
                $this->verifyOwned($holderId, $descriptorHash, true);
            } catch (\RuntimeException $exception) {
                throw new \RuntimeException('transfer_lease_renewal_conflict', 0, $exception);
            }
            return;
        }
        if ($updated !== 1) {
            throw new \RuntimeException('transfer_lease_renewal_conflict');
        }
        $this->verifyOwned($holderId, $descriptorHash, true);
    }

    public function recoverExpired(
        string $newHolderId,
        string $descriptorHash,
        string $recoveryEvidenceHash,
        int $ttl = 300,
    ): void {
        $this->assertBound();
        $this->assertHolder($newHolderId);
        $this->assertHash($descriptorHash, 'Descriptor hash');
        $this->assertHash($recoveryEvidenceHash, 'Recovery evidence hash');
        $this->assertTtl($ttl);
        $existing = $this->read();
        $now = $this->now();

        if (
            $existing === null
            || !hash_equals((string) $existing->descriptor_hash, $descriptorHash)
            || (string) $existing->expires_at > $now
        ) {
            throw new \RuntimeException('transfer_lease_recovery_conflict');
        }

        $database = $this->database();
        $updated = $database->query($database->prepare(
            "UPDATE {$this->table()}
             SET holder_id = %s, expires_at = %s, heartbeat_at = %s
             WHERE target_fingerprint = %s AND holder_id = %s AND descriptor_hash = %s
               AND expires_at = %s",
            $newHolderId,
            $this->expires($now, $ttl),
            $now,
            $this->targetFingerprint,
            (string) $existing->holder_id,
            $descriptorHash,
            (string) $existing->expires_at,
        ));

        $this->requireOneAffected($updated, 'transfer_lease_recovery_conflict');
        $this->verifyOwned($newHolderId, $descriptorHash, true);
    }

    public function release(string $holderId, string $descriptorHash): void
    {
        $this->assertBound();
        $this->assertHolder($holderId);
        $this->assertHash($descriptorHash, 'Descriptor hash');
        $database = $this->database();
        $deleted = $database->query($database->prepare(
            "DELETE FROM {$this->table()}
             WHERE target_fingerprint = %s AND holder_id = %s AND descriptor_hash = %s",
            $this->targetFingerprint,
            $holderId,
            $descriptorHash,
        ));

        $this->requireOneAffected($deleted, 'transfer_lease_release_conflict');
        $this->targetFingerprint = null;
    }

    public function assertOwned(string $holderId, string $descriptorHash): void
    {
        $this->verifyOwned($holderId, $descriptorHash, true);
    }

    private function verifyOwned(string $holderId, string $descriptorHash, bool $unexpired): void
    {
        $row = $this->read();

        if (
            $row === null
            || !hash_equals((string) $row->holder_id, $holderId)
            || !hash_equals((string) $row->descriptor_hash, $descriptorHash)
            || ($unexpired && (string) $row->expires_at <= $this->now())
        ) {
            throw new \RuntimeException('transfer_lease_ownership_mismatch');
        }
    }

    private function read(): ?object
    {
        $this->assertBound();
        $database = $this->database();
        $rows = $database->get_results($database->prepare(
            "SELECT target_fingerprint, holder_id, descriptor_hash, expires_at, heartbeat_at
             FROM {$this->table()} WHERE target_fingerprint = %s LIMIT 1",
            $this->targetFingerprint,
        ));

        if (trim((string) ($database->last_error ?? '')) !== '') {
            throw new \RuntimeException('transfer_lease_read_failed');
        }

        return is_array($rows) && isset($rows[0]) ? $rows[0] : null;
    }

    private function requireOneAffected(int|false $affected, string $message): void
    {
        if ($affected === false) {
            throw new \RuntimeException('transfer_lease_database_failure');
        }

        if ($affected !== 1) {
            throw new \RuntimeException($message);
        }
    }

    private function database(): object
    {
        if ($this->database !== null) {
            return $this->database;
        }

        global $wpdb;

        if (!is_object($wpdb)) {
            throw new \RuntimeException('transfer_lease_database_unavailable');
        }

        return $wpdb;
    }

    private function table(): string
    {
        return $this->database()->prefix . 'cartshift_transfer_leases';
    }

    private function now(): string
    {
        return ($this->clock)()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    private function expires(string $now, int $ttl): string
    {
        return (new \DateTimeImmutable($now, new \DateTimeZone('UTC')))->modify('+' . $ttl . ' seconds')->format('Y-m-d H:i:s');
    }

    private function assertBound(): void
    {
        if ($this->targetFingerprint === null) {
            throw new \RuntimeException('transfer_lease_target_not_bound');
        }
    }

    private function assertHash(string $value, string $label): void
    {
        if (preg_match('/\A[a-f0-9]{64}\z/D', $value) !== 1) {
            throw new \InvalidArgumentException($label . ' must be a lowercase SHA-256 value.');
        }
    }

    private function assertHolder(string $holderId): void
    {
        if ($holderId === '' || strlen($holderId) > 128 || preg_match('/\A[a-zA-Z0-9._:-]+\z/D', $holderId) !== 1) {
            throw new \InvalidArgumentException('Lease holder ID is invalid.');
        }
    }

    private function assertTtl(int $ttl): void
    {
        if ($ttl < 1 || $ttl > 86400) {
            throw new \InvalidArgumentException('Lease TTL must be between 1 and 86400 seconds.');
        }
    }
}

<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer;

defined('ABSPATH') || exit;

final class TransferLock
{
    private const string PREFIX = 'cartshift:';
    private const int HASH_LENGTH = 48;

    private ?string $heldName = null;

    public function __construct(private readonly ?object $database = null)
    {
    }

    public function acquireTargetMutex(string $targetFingerprint): void
    {
        if ($this->heldName !== null) {
            throw new \RuntimeException('transfer_lock_already_held');
        }

        $database = $this->database();
        $name = self::nameFor($targetFingerprint);
        $database->last_error = '';
        $result = $database->get_var($database->prepare('SELECT GET_LOCK(%s, 0)', $name));

        if (!in_array($result, [1, '1'], true) || trim((string) ($database->last_error ?? '')) !== '') {
            throw new \RuntimeException('transfer_lock_unavailable');
        }

        $this->heldName = $name;
    }

    public function release(): void
    {
        if ($this->heldName === null) {
            return;
        }

        $database = $this->database();
        $database->last_error = '';
        $result = $database->get_var($database->prepare('SELECT RELEASE_LOCK(%s)', $this->heldName));

        if (!in_array($result, [1, '1'], true) || trim((string) ($database->last_error ?? '')) !== '') {
            throw new \RuntimeException('transfer_lock_release_failed');
        }

        $this->heldName = null;
    }

    public function isHeld(): bool
    {
        return $this->heldName !== null;
    }

    public static function nameFor(string $targetFingerprint): string
    {
        if (preg_match('/\A[a-f0-9]{64}\z/D', $targetFingerprint) !== 1) {
            throw new \InvalidArgumentException('Target fingerprint must be a lowercase SHA-256 value.');
        }

        $name = self::PREFIX . substr(hash('sha256', $targetFingerprint), 0, self::HASH_LENGTH);

        if (strlen($name) > 64) {
            throw new \LogicException('Target mutex name exceeds the MariaDB limit.');
        }

        return $name;
    }

    private function database(): object
    {
        if ($this->database !== null) {
            return $this->database;
        }

        global $wpdb;

        if (!is_object($wpdb) || !method_exists($wpdb, 'prepare') || !method_exists($wpdb, 'get_var')) {
            throw new \RuntimeException('transfer_lock_unavailable');
        }

        return $wpdb;
    }
}

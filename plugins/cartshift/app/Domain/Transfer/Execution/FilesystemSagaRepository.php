<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\Execution;

use CartShift\Support\CanonicalJson;
use CartShift\Support\DatabaseTransaction;

defined('ABSPATH') || exit;

final readonly class FilesystemSagaRepository
{
    private string $directory;

    public function __construct(string $privateDirectory)
    {
        $this->directory = PrivateTransferFile::directory($privateDirectory);
    }

    public function begin(
        string $runId,
        int $generation,
        string $kind,
        string $sha256,
        int $bytes,
        string $relativePath,
        string $targetPath,
    ): string {
        $entry = $this->entry($runId, $generation, $kind, $sha256, $bytes, $relativePath, $targetPath);
        $run = PrivateTransferFile::createDirectory($this->directory, $runId);
        $sagas = PrivateTransferFile::createDirectory($run, 'filesystem-sagas');
        $path = $sagas . '/' . $entry['operation_id'] . '.pending.json';
        if (is_file($path) && !is_link($path)) {
            $existingEntry = $this->pending($runId, $entry['operation_id']);
            $entry['created_directories'] = $existingEntry['created_directories'];
            $bytesPayload = CanonicalJson::encode($entry) . "\n";
            $existing = file_get_contents($path);
            if (!is_string($existing) || !hash_equals($bytesPayload, $existing)) {
                throw new \RuntimeException('filesystem_saga_pending_conflict');
            }
            $state = $this->state($runId, $entry['operation_id']);
            if (in_array($state, ['reverted', 'rolled_back'], true)) {
                throw new \RuntimeException('filesystem_saga_generation_already_' . $state);
            }
            return $entry['operation_id'];
        }
        $bytesPayload = CanonicalJson::encode($entry) . "\n";
        PrivateTransferFile::writeImmutable($sagas, basename($path), $bytesPayload, 'filesystem_saga_pending_conflict');
        return $entry['operation_id'];
    }

    public function operationId(
        string $runId,
        int $generation,
        string $kind,
        string $sha256,
        string $relativePath,
        string $targetPath,
    ): string
    {
        if (preg_match('/\A[a-z0-9][a-z0-9-]{2,35}\z/D', $runId) !== 1
            || $generation < 1 || !in_array($kind, ['media', 'download'], true)
            || preg_match('/\A[a-f0-9]{64}\z/D', $sha256) !== 1
            || $relativePath === '' || $targetPath === '' || $targetPath[0] !== '/') {
            throw new \InvalidArgumentException('Filesystem saga identity is invalid.');
        }
        return CanonicalJson::fingerprint([
            'run_id' => $runId,
            'generation' => $generation,
            'kind' => $kind,
            'sha256' => $sha256,
            'relative_path' => $relativePath,
            'target_path_hash' => hash('sha256', $targetPath),
        ]);
    }

    public function stateIfExists(string $runId, string $operationId): ?string
    {
        $this->assertOperationId($operationId);
        $directory = $this->directory . '/' . $runId . '/filesystem-sagas';
        if (!is_dir($directory)) {
            return null;
        }
        return is_file($directory . '/' . $operationId . '.pending.json')
            ? $this->state($runId, $operationId)
            : null;
    }

    public function state(string $runId, string $operationId): string
    {
        $directory = $this->sagaDirectory($runId);
        $this->assertOperationId($operationId);
        $pending = $directory . '/' . $operationId . '.pending.json';
        if (!is_file($pending) || is_link($pending)) {
            throw new \RuntimeException('filesystem_saga_pending_missing');
        }
        $markers = [];
        foreach (['final', 'reverted', 'rolled_back'] as $state) {
            $path = $directory . '/' . $operationId . '.' . $state . '.json';
            if (is_file($path) && !is_link($path)) {
                $this->marker($path, $operationId, $state, $pending);
                $markers[$state] = true;
            }
        }
        if (isset($markers['final'], $markers['rolled_back']) && !isset($markers['reverted']) && count($markers) === 2) {
            return 'rolled_back';
        }
        if (count($markers) > 1) {
            throw new \RuntimeException('filesystem_saga_terminal_state_conflict');
        }
        return array_key_first($markers) ?? 'pending';
    }

    public function finalise(string $runId, string $operationId, string $targetPath): void
    {
        $entry = $this->pending($runId, $operationId);
        $state = $this->state($runId, $operationId);
        if (in_array($state, ['reverted', 'rolled_back'], true)) {
            throw new \RuntimeException('filesystem_saga_generation_already_' . $state);
        }
        $this->assertTarget($entry, $targetPath, true);
        if ($state === 'pending') {
            $this->writeMarker($runId, $operationId, 'final', $entry, $targetPath);
        }
    }

    public function finalisePending(string $runId, string $operationId): void
    {
        $entry = $this->pending($runId, $operationId);
        $state = $this->state($runId, $operationId);
        if (in_array($state, ['reverted', 'rolled_back'], true)) {
            throw new \RuntimeException('filesystem_saga_generation_already_' . $state);
        }
        $targetPath = (string) $entry['target_path'];
        $this->assertTarget($entry, $targetPath, true);
        if ($state === 'pending') {
            $this->writeMarker($runId, $operationId, 'final', $entry, $targetPath);
        }
    }

    public function revert(string $runId, string $operationId, string $targetPath): void
    {
        $entry = $this->pending($runId, $operationId);
        if ($this->state($runId, $operationId) !== 'pending') {
            throw new \RuntimeException('filesystem_saga_revert_requires_pending');
        }
        $this->assertTarget($entry, $targetPath, false);
        $this->writeMarker($runId, $operationId, 'reverted', $entry, $targetPath);
    }

    public function markRolledBack(string $runId, string $operationId): void
    {
        $entry = $this->pending($runId, $operationId);
        $state = $this->state($runId, $operationId);
        $targetPath = (string) $entry['target_path'];
        $this->assertTarget($entry, $targetPath, false);
        if ($state === 'rolled_back') {
            return;
        }
        if ($state !== 'final') {
            throw new \RuntimeException('filesystem_saga_rollback_requires_final');
        }
        $this->writeMarker($runId, $operationId, 'rolled_back', $entry, $targetPath);
    }

    /** Remove an unchanged finalised transfer file and seal the rollback marker. */
    public function deleteFinalisedTarget(string $runId, string $operationId): void
    {
        $entry = $this->pending($runId, $operationId);
        $state = $this->state($runId, $operationId);
        if ($state === 'rolled_back') {
            return;
        }
        if ($state !== 'final') {
            throw new \RuntimeException('filesystem_saga_rollback_requires_final');
        }
        $targetPath = (string) $entry['target_path'];
        if (file_exists($targetPath) || is_link($targetPath)) {
            $this->assertTarget($entry, $targetPath, true);
            if (!unlink($targetPath)) {
                throw new \RuntimeException('filesystem_saga_rollback_delete_failed');
            }
        }
        $this->markRolledBack($runId, $operationId);
    }

    /**
     * Move a verified committed file aside before deleting its database graph.
     *
     * The rename is on the same filesystem. A database rollback restores the
     * exact bytes, while a successful commit removes the quarantine and seals
     * the immutable rolled-back marker. The pending marker is written first so
     * a process death at either side of the rename remains recoverable.
     */
    public function quarantineFinalisedTarget(string $runId, string $operationId): void
    {
        if (DatabaseTransaction::depth() < 1) {
            throw new \RuntimeException('filesystem_saga_rollback_requires_transaction');
        }
        $entry = $this->pending($runId, $operationId);
        $state = $this->state($runId, $operationId);
        if ($state === 'rolled_back') {
            return;
        }
        if ($state !== 'final') {
            throw new \RuntimeException('filesystem_saga_rollback_requires_final');
        }

        $target = (string) $entry['target_path'];
        $quarantine = $this->quarantinePath($target, $operationId);
        $this->writeRollbackPendingMarker($runId, $operationId, $entry, $quarantine);

        $targetExists = file_exists($target) || is_link($target);
        $quarantineExists = file_exists($quarantine) || is_link($quarantine);
        if ($targetExists && $quarantineExists) {
            throw new \RuntimeException('filesystem_saga_rollback_quarantine_conflict');
        }
        if ($targetExists) {
            $this->assertTarget($entry, $target, true);
            if (!rename($target, $quarantine)) {
                throw new \RuntimeException('filesystem_saga_rollback_quarantine_failed');
            }
            $this->assertContent($entry, $quarantine);
        } elseif ($quarantineExists) {
            $this->assertContent($entry, $quarantine);
        } else {
            throw new \RuntimeException('filesystem_saga_rollback_target_missing');
        }

        DatabaseTransaction::afterRollback(fn () => $this->restoreQuarantinedTarget($runId, $operationId));
        DatabaseTransaction::afterCommit(fn () => $this->completeQuarantinedRollback($runId, $operationId));
    }

    /** Restore an interrupted quarantine after the database transaction aborts. */
    public function restoreQuarantinedTarget(string $runId, string $operationId): void
    {
        $entry = $this->pending($runId, $operationId);
        $quarantine = $this->verifiedRollbackPendingMarker($runId, $operationId, $entry);
        $target = (string) $entry['target_path'];
        $targetExists = file_exists($target) || is_link($target);
        $quarantineExists = file_exists($quarantine) || is_link($quarantine);
        if ($targetExists && !$quarantineExists) {
            $this->assertTarget($entry, $target, true);
            return;
        }
        if ($targetExists || !$quarantineExists) {
            throw new \RuntimeException('filesystem_saga_rollback_restore_conflict');
        }
        $this->assertContent($entry, $quarantine);
        if (!rename($quarantine, $target)) {
            throw new \RuntimeException('filesystem_saga_rollback_restore_failed');
        }
        $this->assertTarget($entry, $target, true);
    }

    /** Finish a committed database rollback, idempotently after process loss. */
    public function completeQuarantinedRollback(string $runId, string $operationId): void
    {
        $entry = $this->pending($runId, $operationId);
        if ($this->state($runId, $operationId) === 'rolled_back') {
            $this->pruneCreatedDirectories($entry);
            return;
        }
        $quarantine = $this->verifiedRollbackPendingMarker($runId, $operationId, $entry);
        $target = (string) $entry['target_path'];
        if (file_exists($target) || is_link($target)) {
            throw new \RuntimeException('filesystem_saga_rollback_target_still_live');
        }
        if (file_exists($quarantine) || is_link($quarantine)) {
            $this->assertContent($entry, $quarantine);
            if (!unlink($quarantine)) {
                throw new \RuntimeException('filesystem_saga_rollback_delete_failed');
            }
        }
        $this->markRolledBack($runId, $operationId);
        $this->pruneCreatedDirectories($entry);
    }

    /** @return array<string, mixed> */
    private function entry(string $runId, int $generation, string $kind, string $sha256, int $bytes, string $relativePath, string $targetPath): array
    {
        if (preg_match('/\A[a-z0-9][a-z0-9-]{2,35}\z/D', $runId) !== 1
            || $generation < 1
            || !in_array($kind, ['media', 'download'], true)
            || preg_match('/\A[a-f0-9]{64}\z/D', $sha256) !== 1
            || $bytes < 0 || $relativePath === '' || $targetPath === '' || $targetPath[0] !== '/') {
            throw new \InvalidArgumentException('Filesystem saga entry is invalid.');
        }
        $identity = [
            'run_id' => $runId,
            'generation' => $generation,
            'kind' => $kind,
            'sha256' => $sha256,
        ];
        return $identity + [
            'operation_id' => $this->operationId($runId, $generation, $kind, $sha256, $relativePath, $targetPath),
            'bytes' => $bytes,
            'created_directories' => $this->missingParentDirectories($targetPath),
            'relative_path' => $relativePath,
            'target_path' => $targetPath,
            'target_path_hash' => hash('sha256', $targetPath),
        ];
    }

    /** @return array<string, mixed> */
    private function pending(string $runId, string $operationId): array
    {
        $this->assertOperationId($operationId);
        $path = $this->sagaDirectory($runId) . '/' . $operationId . '.pending.json';
        $bytes = is_file($path) && !is_link($path) ? file_get_contents($path) : false;
        $data = is_string($bytes) ? json_decode($bytes, true, 32, JSON_THROW_ON_ERROR) : null;
        if (!is_array($data) || !hash_equals(CanonicalJson::encode($data) . "\n", (string) $bytes)
            || ($data['operation_id'] ?? null) !== $operationId || ($data['run_id'] ?? null) !== $runId) {
            throw new \RuntimeException('filesystem_saga_pending_invalid');
        }
        $keys = array_keys($data);
        sort($keys, SORT_STRING);
        if ($keys !== ['bytes', 'created_directories', 'generation', 'kind', 'operation_id', 'relative_path', 'run_id', 'sha256', 'target_path', 'target_path_hash']
            || !is_int($data['generation']) || !is_int($data['bytes'])
            || !is_string($data['kind']) || !is_string($data['sha256'])
            || !is_string($data['relative_path']) || !is_string($data['target_path'])
            || !is_string($data['target_path_hash'])
            || !$this->createdDirectoriesAreValid($data['created_directories'] ?? null, (string) $data['target_path'])
            || !hash_equals($operationId, $this->operationId(
                $runId,
                $data['generation'],
                $data['kind'],
                $data['sha256'],
                $data['relative_path'],
                $data['target_path'],
            ))
            || $data['bytes'] < 0 || $data['relative_path'] === ''
            || $data['target_path'] === '' || $data['target_path'][0] !== '/'
            || !hash_equals($data['target_path_hash'], hash('sha256', $data['target_path']))) {
            throw new \RuntimeException('filesystem_saga_pending_invalid');
        }
        return $data;
    }

    /** @return list<string> */
    private function missingParentDirectories(string $targetPath): array
    {
        $directories = [];
        $directory = dirname($targetPath);
        while (!file_exists($directory) && !is_link($directory)) {
            if ($directory === '/' || count($directories) >= 16) {
                throw new \RuntimeException('filesystem_saga_target_parent_invalid');
            }
            $directories[] = $directory;
            $parent = dirname($directory);
            if ($parent === $directory) {
                throw new \RuntimeException('filesystem_saga_target_parent_invalid');
            }
            $directory = $parent;
        }
        if (!is_dir($directory) || is_link($directory)) {
            throw new \RuntimeException('filesystem_saga_target_parent_invalid');
        }
        return $directories;
    }

    private function createdDirectoriesAreValid(mixed $directories, string $targetPath): bool
    {
        if (!is_array($directories) || count($directories) > 16 || array_is_list($directories) === false) {
            return false;
        }
        $expected = dirname($targetPath);
        foreach ($directories as $directory) {
            if (!is_string($directory) || $directory !== $expected || $directory === '/') {
                return false;
            }
            $expected = dirname($directory);
        }
        return true;
    }

    /** @param array<string,mixed> $entry */
    private function pruneCreatedDirectories(array $entry): void
    {
        foreach ($entry['created_directories'] as $directory) {
            if (!file_exists($directory) && !is_link($directory)) {
                continue;
            }
            if (!is_dir($directory) || is_link($directory)) {
                throw new \RuntimeException('filesystem_saga_rollback_directory_drift');
            }
            $contents = scandir($directory);
            if (!is_array($contents)) {
                throw new \RuntimeException('filesystem_saga_rollback_directory_unreadable');
            }
            if (array_diff($contents, ['.', '..']) !== []) {
                break;
            }
            if (!rmdir($directory)) {
                throw new \RuntimeException('filesystem_saga_rollback_directory_delete_failed');
            }
        }
    }

    /** @param array<string, mixed> $entry */
    private function assertTarget(array $entry, string $targetPath, bool $mustExist): void
    {
        if ($targetPath === '' || $targetPath[0] !== '/'
            || !hash_equals((string) $entry['target_path_hash'], hash('sha256', $targetPath))) {
            throw new \RuntimeException('filesystem_saga_target_changed');
        }
        if (!$mustExist) {
            if (file_exists($targetPath) || is_link($targetPath)) {
                throw new \RuntimeException('filesystem_saga_revert_target_still_exists');
            }
            return;
        }
        $actual = is_file($targetPath) && !is_link($targetPath) ? hash_file('sha256', $targetPath) : false;
        if (!is_string($actual) || !hash_equals((string) $entry['sha256'], $actual) || filesize($targetPath) !== (int) $entry['bytes']) {
            throw new \RuntimeException('filesystem_saga_final_target_mismatch');
        }
    }

    /** @param array<string,mixed> $entry */
    private function assertContent(array $entry, string $path): void
    {
        $actual = is_file($path) && !is_link($path) ? hash_file('sha256', $path) : false;
        if (!is_string($actual)
            || !hash_equals((string) $entry['sha256'], $actual)
            || filesize($path) !== (int) $entry['bytes']) {
            throw new \RuntimeException('filesystem_saga_final_target_mismatch');
        }
    }

    /** @param array<string,mixed> $entry */
    private function writeRollbackPendingMarker(string $runId, string $operationId, array $entry, string $quarantine): void
    {
        $directory = $this->sagaDirectory($runId);
        $pendingPath = $directory . '/' . $operationId . '.pending.json';
        $pendingBytes = file_get_contents($pendingPath);
        if (!is_string($pendingBytes)) {
            throw new \RuntimeException('filesystem_saga_pending_unreadable');
        }
        $document = CanonicalJson::encode([
            'operation_id' => $operationId,
            'pending_sha256' => hash('sha256', $pendingBytes),
            'quarantine_path_hash' => hash('sha256', $quarantine),
            'state' => 'rollback_pending',
            'target_path_hash' => (string) $entry['target_path_hash'],
        ]) . "\n";
        PrivateTransferFile::writeImmutable(
            $directory,
            $operationId . '.rollback-pending.json',
            $document,
            'filesystem_saga_rollback_pending_conflict',
        );
        $this->verifiedRollbackPendingMarker($runId, $operationId, $entry);
    }

    /** @param array<string,mixed> $entry */
    private function verifiedRollbackPendingMarker(string $runId, string $operationId, array $entry): string
    {
        $directory = $this->sagaDirectory($runId);
        $path = $directory . '/' . $operationId . '.rollback-pending.json';
        $pendingPath = $directory . '/' . $operationId . '.pending.json';
        $bytes = is_file($path) && !is_link($path) ? file_get_contents($path) : false;
        $pending = file_get_contents($pendingPath);
        $data = is_string($bytes) ? json_decode($bytes, true, 16, JSON_THROW_ON_ERROR) : null;
        $quarantine = $this->quarantinePath((string) $entry['target_path'], $operationId);
        if (!is_array($data) || !is_string($pending)
            || !hash_equals(CanonicalJson::encode($data) . "\n", (string) $bytes)
            || array_keys($data) !== ['operation_id', 'pending_sha256', 'quarantine_path_hash', 'state', 'target_path_hash']
            || ($data['operation_id'] ?? null) !== $operationId
            || ($data['state'] ?? null) !== 'rollback_pending'
            || !hash_equals((string) ($data['pending_sha256'] ?? ''), hash('sha256', $pending))
            || !hash_equals((string) ($data['target_path_hash'] ?? ''), (string) $entry['target_path_hash'])
            || !hash_equals((string) ($data['quarantine_path_hash'] ?? ''), hash('sha256', $quarantine))) {
            throw new \RuntimeException('filesystem_saga_rollback_pending_invalid');
        }
        return $quarantine;
    }

    private function quarantinePath(string $targetPath, string $operationId): string
    {
        return dirname($targetPath) . '/.' . basename($targetPath) . '.cartshift-rollback-' . substr($operationId, 0, 16);
    }

    /** @param array<string, mixed> $entry */
    private function writeMarker(string $runId, string $operationId, string $state, array $entry, string $targetPath): void
    {
        $directory = $this->sagaDirectory($runId);
        $pendingPath = $directory . '/' . $operationId . '.pending.json';
        $pendingBytes = file_get_contents($pendingPath);
        if (!is_string($pendingBytes)) {
            throw new \RuntimeException('filesystem_saga_pending_unreadable');
        }
        $document = CanonicalJson::encode([
            'operation_id' => $operationId,
            'pending_sha256' => hash('sha256', $pendingBytes),
            'state' => $state,
            'target_path_hash' => hash('sha256', $targetPath),
            'target_sha256' => $state === 'final' ? $entry['sha256'] : null,
        ]) . "\n";
        PrivateTransferFile::writeImmutable(
            $directory,
            $operationId . '.' . $state . '.json',
            $document,
            'filesystem_saga_terminal_conflict',
        );
        if ($this->state($runId, $operationId) !== $state) {
            throw new \RuntimeException('filesystem_saga_terminal_not_persisted');
        }
    }

    private function marker(string $path, string $operationId, string $state, string $pendingPath): void
    {
        $bytes = file_get_contents($path);
        $pending = file_get_contents($pendingPath);
        $data = is_string($bytes) ? json_decode($bytes, true, 16, JSON_THROW_ON_ERROR) : null;
        if (!is_array($data) || !is_string($pending)
            || !hash_equals(CanonicalJson::encode($data) . "\n", (string) $bytes)
            || ($data['operation_id'] ?? null) !== $operationId
            || ($data['state'] ?? null) !== $state
            || !hash_equals((string) ($data['pending_sha256'] ?? ''), hash('sha256', $pending))) {
            throw new \RuntimeException('filesystem_saga_terminal_invalid');
        }
    }

    private function sagaDirectory(string $runId): string
    {
        if (preg_match('/\A[a-z0-9][a-z0-9-]{2,35}\z/D', $runId) !== 1) {
            throw new \InvalidArgumentException('Filesystem saga run ID is invalid.');
        }
        $directory = $this->directory . '/' . $runId . '/filesystem-sagas';
        if (!is_dir($directory) || is_link($directory)) {
            throw new \RuntimeException('filesystem_saga_directory_missing');
        }
        return $directory;
    }

    private function assertOperationId(string $operationId): void
    {
        if (preg_match('/\A[a-f0-9]{64}\z/D', $operationId) !== 1) {
            throw new \InvalidArgumentException('Filesystem saga operation ID is invalid.');
        }
    }
}

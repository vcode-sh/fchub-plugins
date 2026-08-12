<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\Execution;

use CartShift\Domain\Transfer\SourceIdentity;
use CartShift\Support\CanonicalJson;

defined('ABSPATH') || exit;

final readonly class TransferReceipt
{
    /** @param array<string, int> $targetIds @param list<string> $filesystemOperationIds */
    public function __construct(
        public string $runId,
        public string $recordKind,
        public string $sourceIdentity,
        public int $generation,
        public string $sourceFingerprint,
        public string $action,
        public array $targetIds,
        public ?string $beforeFingerprint,
        public string $afterFingerprint,
        public int $sequence,
        public string $startedAtUtc,
        public string $completedAtUtc,
        public array $filesystemOperationIds = [],
    ) {
        $identity = SourceIdentity::fromCanonical($sourceIdentity);
        $catalogueStatus = $action === 'catalogue_status' && $recordKind === 'catalogue_status' && $identity->entityType === 'product';
        if (($identity->entityType !== $recordKind && !$catalogueStatus)
            || preg_match('/\A[a-z0-9][a-z0-9-]{2,35}\z/D', $runId) !== 1
            || $generation < 1 || $sequence < 1) {
            throw new \InvalidArgumentException('Transfer receipt identity is invalid.');
        }
        foreach ([$sourceFingerprint, $afterFingerprint] as $hash) self::assertHash($hash);
        if ($beforeFingerprint !== null) self::assertHash($beforeFingerprint);
        if (!in_array($action, ['created', 'reused', 'catalogue_status'], true)) {
            throw new \InvalidArgumentException('Transfer receipt action is unsupported.');
        }
        if (in_array($action, ['reused', 'catalogue_status'], true) !== ($beforeFingerprint !== null)) {
            throw new \InvalidArgumentException('Reused or status receipt must carry the exact before fingerprint.');
        }
        if ($targetIds === [] || !isset($targetIds['primary']) || array_filter($targetIds, static fn (mixed $id): bool => !is_int($id) || $id <= 0) !== []) {
            throw new \InvalidArgumentException('Transfer receipt target IDs are invalid.');
        }
        self::assertUtc($startedAtUtc);
        self::assertUtc($completedAtUtc);
        $sortedOperations = $filesystemOperationIds;
        sort($sortedOperations, SORT_STRING);
        if (!array_is_list($filesystemOperationIds) || $filesystemOperationIds !== array_values(array_unique($sortedOperations))
            || array_filter($filesystemOperationIds, static fn (mixed $id): bool => !is_string($id) || preg_match('/\A[a-f0-9]{64}\z/D', $id) !== 1) !== []) {
            throw new \InvalidArgumentException('Transfer receipt filesystem operations are invalid.');
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'run_id' => $this->runId,
            'record_kind' => $this->recordKind,
            'source_identity' => $this->sourceIdentity,
            'generation' => $this->generation,
            'source_fingerprint' => $this->sourceFingerprint,
            'action' => $this->action,
            'target_ids' => $this->targetIds,
            'before_fingerprint' => $this->beforeFingerprint,
            'after_fingerprint' => $this->afterFingerprint,
            'sequence' => $this->sequence,
            'started_at_utc' => $this->startedAtUtc,
            'completed_at_utc' => $this->completedAtUtc,
            'filesystem_operations' => $this->filesystemOperationIds,
        ];
    }

    public function payloadHash(): string
    {
        return CanonicalJson::fingerprint($this->toArray());
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $required = [
            'action', 'after_fingerprint', 'before_fingerprint', 'completed_at_utc', 'filesystem_operations', 'generation', 'record_kind',
            'run_id', 'sequence', 'source_fingerprint', 'source_identity', 'started_at_utc', 'target_ids',
        ];
        $keys = array_keys($data);
        sort($keys, SORT_STRING);
        if ($keys !== $required || !is_array($data['target_ids']) || !is_array($data['filesystem_operations'])) {
            throw new \InvalidArgumentException('Transfer receipt payload shape is invalid.');
        }
        return new self(
            (string) $data['run_id'],
            (string) $data['record_kind'],
            (string) $data['source_identity'],
            (int) $data['generation'],
            (string) $data['source_fingerprint'],
            (string) $data['action'],
            $data['target_ids'],
            $data['before_fingerprint'] === null ? null : (string) $data['before_fingerprint'],
            (string) $data['after_fingerprint'],
            (int) $data['sequence'],
            (string) $data['started_at_utc'],
            (string) $data['completed_at_utc'],
            $data['filesystem_operations'],
        );
    }

    private static function assertHash(string $hash): void
    {
        if (preg_match('/\A[a-f0-9]{64}\z/D', $hash) !== 1) throw new \InvalidArgumentException('Transfer receipt fingerprint is invalid.');
    }

    private static function assertUtc(string $value): void
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $value, new \DateTimeZone('UTC'));
        if (!$date instanceof \DateTimeImmutable || $date->format('Y-m-d\TH:i:s\Z') !== $value || \DateTimeImmutable::getLastErrors() !== false) {
            throw new \InvalidArgumentException('Transfer receipt time must be canonical UTC.');
        }
    }
}

<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\Execution;

use CartShift\Support\CanonicalJson;

defined('ABSPATH') || exit;

final readonly class PreparedTransfer
{
    /** @param list<string> $blockingFindings */
    public function __construct(
        public string $runId,
        public string $packagePath,
        public string $packageHash,
        public TargetStateFingerprint $targetState,
        public string $executionContext,
        public array $blockingFindings,
        public bool $leaveDraftAccepted,
        public string $createdAtUtc,
        public string $sourceKey,
        public int $generation = 1,
    ) {
        if (preg_match('/\A[a-z0-9][a-z0-9-]{2,35}\z/D', $runId) !== 1) {
            throw new \InvalidArgumentException('Prepared run ID is invalid.');
        }
        if ($packagePath === '' || $packagePath[0] !== '/' || str_contains($packagePath, "\0")) {
            throw new \InvalidArgumentException('Prepared package path must be absolute.');
        }
        if (!hash_equals($packageHash, $targetState->packageHash)) {
            throw new \InvalidArgumentException('Prepared package hashes disagree.');
        }
        if (!in_array($executionContext, ['rehearsal', 'cutover'], true)) {
            throw new \InvalidArgumentException('Prepared execution context is invalid.');
        }
        if (!array_is_list($blockingFindings) || array_filter($blockingFindings, 'is_string') !== $blockingFindings) {
            throw new \InvalidArgumentException('Prepared blocking findings are invalid.');
        }
        self::assertUtc($createdAtUtc);
        \CartShift\Domain\Transfer\SourceIdentity::assertValidSourceKey($sourceKey);
        if ($generation < 1) {
            throw new \InvalidArgumentException('Prepared generation is invalid.');
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'run_id' => $this->runId,
            'package_path' => $this->packagePath,
            'package_hash' => $this->packageHash,
            'target_state' => $this->targetState->toArray(),
            'execution_context' => $this->executionContext,
            'blocking_findings' => $this->blockingFindings,
            'leave_draft_accepted' => $this->leaveDraftAccepted,
            'created_at_utc' => $this->createdAtUtc,
            'source_key' => $this->sourceKey,
            'generation' => $this->generation,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $required = ['blocking_findings', 'created_at_utc', 'execution_context', 'generation', 'leave_draft_accepted', 'package_hash', 'package_path', 'run_id', 'source_key', 'target_state'];
        $keys = array_keys($data);
        sort($keys, SORT_STRING);
        if ($keys !== $required || !is_array($data['target_state']) || !is_array($data['blocking_findings']) || !is_bool($data['leave_draft_accepted'])) {
            throw new \InvalidArgumentException('Prepared transfer descriptor shape is invalid.');
        }
        return new self(
            (string) $data['run_id'],
            (string) $data['package_path'],
            (string) $data['package_hash'],
            TargetStateFingerprint::fromArray($data['target_state']),
            (string) $data['execution_context'],
            $data['blocking_findings'],
            $data['leave_draft_accepted'],
            (string) $data['created_at_utc'],
            (string) $data['source_key'],
            (int) $data['generation'],
        );
    }

    public function descriptorHash(): string
    {
        return CanonicalJson::fingerprint($this->toArray());
    }

    public function assertUnblocked(): void
    {
        if ($this->blockingFindings !== []) {
            throw new \RuntimeException('prepared_transfer_blocked');
        }
    }

    public function assertCurrent(TargetStateFingerprint $current): void
    {
        $changed = $this->targetState->changedField($current);
        if ($changed !== null) {
            throw new \RuntimeException('prepared_transfer_fingerprint_changed:' . $changed);
        }
    }

    private static function assertUtc(string $value): void
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $value, new \DateTimeZone('UTC'));
        if (!$date instanceof \DateTimeImmutable || $date->format('Y-m-d\TH:i:s\Z') !== $value || \DateTimeImmutable::getLastErrors() !== false) {
            throw new \InvalidArgumentException('Prepared descriptor time must be canonical UTC.');
        }
    }
}

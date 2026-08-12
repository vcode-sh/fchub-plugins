<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\Execution;

use CartShift\Support\CanonicalJson;

defined('ABSPATH') || exit;

final readonly class RollbackPlan
{
    /**
     * @param list<array{source_identity:string, receipt:TransferReceipt}> $deletions
     * @param list<string> $conflicts
     * @param list<array{source_identity:string, receipt:TransferReceipt}> $mappingRetirements
     */
    public function __construct(
        public string $runId,
        public int $generation,
        public array $deletions,
        public array $conflicts,
        public bool $safe,
        public array $mappingRetirements = [],
    ) {
        if (preg_match('/\A[a-z0-9][a-z0-9-]{2,35}\z/D', $runId) !== 1
            || $generation < 1 || !array_is_list($deletions) || !array_is_list($conflicts)
            || !array_is_list($mappingRetirements) || $safe !== ($conflicts === [])) {
            throw new \InvalidArgumentException('Rollback plan is invalid.');
        }
        foreach ($deletions as $deletion) {
            if (!is_array($deletion)
                || array_keys($deletion) !== ['source_identity', 'receipt']
                || !is_string($deletion['source_identity'])
                || !$deletion['receipt'] instanceof TransferReceipt
                || $deletion['receipt']->runId !== $runId
                || $deletion['receipt']->generation !== $generation
                || $deletion['receipt']->sourceIdentity !== $deletion['source_identity']
                || $deletion['receipt']->action !== 'created') {
                throw new \InvalidArgumentException('Rollback deletion is invalid.');
            }
        }
        foreach ($mappingRetirements as $retirement) {
            if (!is_array($retirement)
                || array_keys($retirement) !== ['source_identity', 'receipt']
                || !is_string($retirement['source_identity'])
                || !$retirement['receipt'] instanceof TransferReceipt
                || $retirement['receipt']->runId !== $runId
                || $retirement['receipt']->generation !== $generation
                || $retirement['receipt']->sourceIdentity !== $retirement['source_identity']
                || $retirement['receipt']->action !== 'reused') {
                throw new \InvalidArgumentException('Rollback mapping retirement is invalid.');
            }
        }
        if (array_filter($conflicts, 'is_string') !== $conflicts) {
            throw new \InvalidArgumentException('Rollback conflicts are invalid.');
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'run_id' => $this->runId,
            'generation' => $this->generation,
            'deletions' => array_map(static fn (array $item): array => [
                'source_identity' => $item['source_identity'],
                'receipt' => $item['receipt']->toArray(),
            ], $this->deletions),
            'mapping_retirements' => array_map(static fn (array $item): array => [
                'source_identity' => $item['source_identity'],
                'receipt' => $item['receipt']->toArray(),
            ], $this->mappingRetirements),
            'conflicts' => $this->conflicts,
            'safe' => $this->safe,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        if (array_keys($data) !== ['conflicts', 'deletions', 'generation', 'mapping_retirements', 'run_id', 'safe']
            || !is_array($data['deletions'])
            || !is_array($data['mapping_retirements'])
            || !is_array($data['conflicts'])
            || !is_bool($data['safe'])) {
            throw new \InvalidArgumentException('Rollback plan payload shape is invalid.');
        }
        $deletions = [];
        foreach ($data['deletions'] as $deletion) {
            if (!is_array($deletion)
                || array_keys($deletion) !== ['receipt', 'source_identity']
                || !is_array($deletion['receipt'])
                || !is_string($deletion['source_identity'])) {
                throw new \InvalidArgumentException('Rollback plan deletion payload is invalid.');
            }
            $deletions[] = [
                'source_identity' => $deletion['source_identity'],
                'receipt' => TransferReceipt::fromArray($deletion['receipt']),
            ];
        }
        $mappingRetirements = [];
        foreach ($data['mapping_retirements'] as $retirement) {
            if (!is_array($retirement)
                || array_keys($retirement) !== ['receipt', 'source_identity']
                || !is_array($retirement['receipt'])
                || !is_string($retirement['source_identity'])) {
                throw new \InvalidArgumentException('Rollback plan mapping retirement payload is invalid.');
            }
            $mappingRetirements[] = [
                'source_identity' => $retirement['source_identity'],
                'receipt' => TransferReceipt::fromArray($retirement['receipt']),
            ];
        }
        return new self(
            (string) $data['run_id'],
            (int) $data['generation'],
            $deletions,
            $data['conflicts'],
            $data['safe'],
            $mappingRetirements,
        );
    }

    public function fingerprint(): string
    {
        return CanonicalJson::fingerprint($this->toArray());
    }
}

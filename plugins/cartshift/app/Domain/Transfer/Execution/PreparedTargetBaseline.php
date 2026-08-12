<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\Execution;

use CartShift\Domain\Transfer\SourceIdentity;
use CartShift\Support\CanonicalJson;

defined('ABSPATH') || exit;

/** Immutable evidence for target facts which existed before a transfer run. */
final readonly class PreparedTargetBaseline
{
    /**
     * @param array<string, mixed> $snapshot
     * @param list<string> $blockingFindings
     */
    public function __construct(
        public string $sourceKey,
        public array $snapshot,
        public array $blockingFindings,
    ) {
        SourceIdentity::assertValidSourceKey($sourceKey);
        if (array_is_list($snapshot) && $snapshot !== []) {
            throw new \InvalidArgumentException('Prepared target baseline snapshot must be a map.');
        }
        foreach (array_keys($snapshot) as $key) {
            if (!is_string($key)) {
                throw new \InvalidArgumentException('Prepared target baseline snapshot keys must be strings.');
            }
        }
        if (!array_is_list($blockingFindings)
            || array_filter($blockingFindings, 'is_string') !== $blockingFindings) {
            throw new \InvalidArgumentException('Prepared target baseline blockers must be a string list.');
        }
        $sorted = array_values(array_unique($blockingFindings));
        sort($sorted, SORT_STRING);
        if ($sorted !== $blockingFindings) {
            throw new \InvalidArgumentException('Prepared target baseline blockers must be unique and sorted.');
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'source_key' => $this->sourceKey,
            'snapshot' => CanonicalJson::canonicalise($this->snapshot),
            'blocking_findings' => $this->blockingFindings,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $keys = array_keys($data);
        sort($keys, SORT_STRING);
        if ($keys !== ['blocking_findings', 'snapshot', 'source_key']
            || !is_array($data['snapshot'])
            || !is_array($data['blocking_findings'])) {
            throw new \InvalidArgumentException('Prepared target baseline shape is invalid.');
        }
        return new self((string) $data['source_key'], $data['snapshot'], $data['blocking_findings']);
    }

    public function fingerprint(): string
    {
        return CanonicalJson::fingerprint($this->toArray());
    }
}

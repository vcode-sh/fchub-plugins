<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\Audit;

defined('ABSPATH') || exit;

final readonly class SourceRecordContractReport
{
    /**
     * @param array<string, array{considered: int, ready: int, blocked: int}> $counts
     * @param list<array{code: string, identity: string, context: array<string, scalar|null>}> $findings
     */
    public function __construct(public array $counts, public array $findings)
    {
        foreach ($counts as $kind => $count) {
            if (!is_string($kind)
                || !isset($count['considered'], $count['ready'], $count['blocked'])
                || !is_int($count['considered'])
                || !is_int($count['ready'])
                || !is_int($count['blocked'])
                || min($count) < 0
                || $count['ready'] + $count['blocked'] !== $count['considered']) {
                throw new \InvalidArgumentException('Source record-contract counts are invalid.');
            }
        }
    }
}

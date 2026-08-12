<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\Graph;

use CartShift\Domain\Transfer\RecordEnvelope;

defined('ABSPATH') || exit;

final readonly class DependencyClosureResult
{
    /** @param list<RecordEnvelope> $orderedRecords @param list<string> $reasonCodes */
    public function __construct(public bool $closed, public array $orderedRecords, public array $reasonCodes)
    {
        if ($closed !== ($reasonCodes === [])) throw new \InvalidArgumentException('Dependency closure result disagrees with its blockers.');
    }
}

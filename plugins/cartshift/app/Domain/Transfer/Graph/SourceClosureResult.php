<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\Graph;

use CartShift\Domain\Transfer\RecordEnvelope;

defined('ABSPATH') || exit;

final readonly class SourceClosureResult
{
    /** @param list<RecordEnvelope> $records */
    public function __construct(
        public array $records,
        public string $rootSelectionFingerprint,
        public string $materializedClosureFingerprint,
    ) {}
}

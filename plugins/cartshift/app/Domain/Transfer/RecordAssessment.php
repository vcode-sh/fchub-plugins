<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer;

defined('ABSPATH') || exit;

final readonly class RecordAssessment
{
    /** @param array<string, scalar|list<scalar|null>|null> $context */
    public function __construct(
        public AssessmentOutcome $outcome,
        public string $reasonCode,
        public array $context = [],
    ) {
        if (preg_match('/\A[a-z][a-z0-9_]{2,63}\z/D', $reasonCode) !== 1) {
            throw new \InvalidArgumentException('Assessment reason code is invalid.');
        }
    }
}

<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer;

defined('ABSPATH') || exit;

final readonly class ReconciliationResult
{
    /** @param list<string> $failures */
    public function __construct(
        public bool $matches,
        public string $actualFingerprint,
        public array $failures,
    ) {
        if (preg_match('/\A[a-f0-9]{64}\z/D', $actualFingerprint) !== 1 || !array_is_list($failures)) {
            throw new \InvalidArgumentException('Reconciliation result is invalid.');
        }
        if ($matches !== ($failures === [])) {
            throw new \InvalidArgumentException('Reconciliation match flag disagrees with its failures.');
        }
    }
}

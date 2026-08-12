<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\Runtime;

defined('ABSPATH') || exit;

final readonly class TransferRuntimeReport
{
    /**
     * @param array<string, string> $versions
     * @param array<string, string> $schemaFingerprints
     * @param list<string> $errors
     * @param list<string> $warnings
     */
    public function __construct(
        public string $role,
        public string $fingerprint,
        public array $versions,
        public array $schemaFingerprints,
        public array $errors,
        public array $warnings,
    ) {
    }

    public function isReady(): bool
    {
        return $this->errors === [];
    }
}

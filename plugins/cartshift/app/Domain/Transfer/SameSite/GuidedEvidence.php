<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\SameSite;

defined('ABSPATH') || exit;

/**
 * What earlier steps of this run have actually returned.
 *
 * Three values, and every one of them is produced rather than chosen: the
 * selection fingerprint comes from `audit`, the package path from `export`, the
 * descriptor from `prepare`. They are the reason the later verbs cannot be
 * planned in advance, and carrying them in a named type rather than a loose
 * array is what stops a run confirming one selection while staging another.
 *
 * Immutable and additive. A run only ever learns more.
 */
final readonly class GuidedEvidence
{
    private function __construct(
        public ?string $selectionFingerprint = null,
        public ?string $packagePath = null,
        public ?string $descriptor = null,
    ) {
    }

    /** A run that has not started. */
    public static function none(): self
    {
        return new self();
    }

    public function withSelectionFingerprint(string $fingerprint): self
    {
        return new self($fingerprint, $this->packagePath, $this->descriptor);
    }

    public function withPackage(string $path): self
    {
        return new self($this->selectionFingerprint, $path, $this->descriptor);
    }

    public function withDescriptor(string $descriptor): self
    {
        return new self($this->selectionFingerprint, $this->packagePath, $descriptor);
    }
}

<?php

declare(strict_types=1);

namespace CartShift\Domain\Subscription;

defined('ABSPATH') || exit;

/**
 * A source row that could not become a valid record, kept rather than dropped.
 *
 * Dropping it would be the worse bug. The selected total is 564; if the one
 * malformed Lapka subscription silently vanishes at decode time, every count
 * downstream says 563 and the run looks complete while a live subscription sits
 * unmigrated on the source. So it stays, it counts, and it blocks — and the
 * operator repairs the source row or assigns the correct target contract by
 * hand. CartShift does not invent a product, a variation or a parent order to
 * make it fit.
 *
 * `safeSnapshot` is remediation material, not a copy of the row. It carries the
 * few non-secret fields needed to find the record in WooCommerce and see what
 * is wrong with it; payment identifiers stay out, because this ends up in
 * reports and logs.
 */
final readonly class InvalidSourceRecord
{
    public const string KIND = 'invalid';

    /**
     * @param list<string>         $reasonCodes
     * @param array<string, mixed> $safeSnapshot
     */
    public function __construct(
        public string $sourceKey,
        public string $entityKind,
        public string $sourceRef,
        public array $reasonCodes,
        public array $safeSnapshot,
        public string $fingerprint,
    ) {}

    public function kind(): string
    {
        return self::KIND;
    }

    public function withFingerprint(string $fingerprint): self
    {
        return new self(
            $this->sourceKey,
            $this->entityKind,
            $this->sourceRef,
            $this->reasonCodes,
            $this->safeSnapshot,
            $fingerprint,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function fingerprintPayload(): array
    {
        return [
            'entity_kind'  => $this->entityKind,
            'kind'         => self::KIND,
            'reason_codes' => $this->reasonCodes,
            'safe_snapshot' => $this->safeSnapshot,
            'source_key'   => $this->sourceKey,
            'source_ref'   => $this->sourceRef,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->fingerprintPayload() + ['fingerprint' => $this->fingerprint];
    }
}

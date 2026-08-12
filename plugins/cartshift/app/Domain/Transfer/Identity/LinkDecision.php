<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\Identity;

use CartShift\Domain\Transfer\RecordKind;
use CartShift\Domain\Transfer\SourceIdentity;
use CartShift\Support\CanonicalJson;

defined('ABSPATH') || exit;

final readonly class LinkDecision
{
    public function __construct(
        public SourceIdentity $source,
        public string $sourceFingerprint,
        public int $targetId,
        public string $targetFingerprint,
        public string $decisionFingerprint,
        public string $approvedAtUtc,
    ) {
        if (!in_array($source->kind(), [RecordKind::Product, RecordKind::Customer], true)) {
            throw new \InvalidArgumentException('Only products and customers may use shared link decisions.');
        }

        if ($targetId <= 0) {
            throw new \InvalidArgumentException('Link target ID must be positive.');
        }

        foreach ([$sourceFingerprint, $targetFingerprint, $decisionFingerprint] as $fingerprint) {
            if (preg_match('/\A[a-f0-9]{64}\z/D', $fingerprint) !== 1) {
                throw new \InvalidArgumentException('Link fingerprints must be lowercase SHA-256 values.');
            }
        }

        $approved = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $approvedAtUtc, new \DateTimeZone('UTC'));

        if ($approved === false || $approved->format('Y-m-d\TH:i:s\Z') !== $approvedAtUtc) {
            throw new \InvalidArgumentException('Link approval time must be canonical UTC.');
        }

        if (!hash_equals($this->expectedDecisionFingerprint(), $decisionFingerprint)) {
            throw new \InvalidArgumentException('Link decision fingerprint does not match the approved facts.');
        }
    }

    public static function fingerprint(
        SourceIdentity $source,
        string $sourceFingerprint,
        int $targetId,
        string $targetFingerprint,
        string $approvedAtUtc,
    ): string {
        return CanonicalJson::fingerprint([
            'source_identity' => $source->canonical(),
            'source_fingerprint' => $sourceFingerprint,
            'target_id' => $targetId,
            'target_fingerprint' => $targetFingerprint,
            'approved_at_utc' => $approvedAtUtc,
        ]);
    }

    private function expectedDecisionFingerprint(): string
    {
        return self::fingerprint(
            $this->source,
            $this->sourceFingerprint,
            $this->targetId,
            $this->targetFingerprint,
            $this->approvedAtUtc,
        );
    }
}

<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\Product;

use CartShift\Domain\Transfer\SourceIdentity;

defined('ABSPATH') || exit;

final readonly class LinkedVariationDecision
{
    public function __construct(
        public SourceIdentity $sourceVariation,
        public int $targetProductId,
        public int $targetVariationId,
        public string $sourceSemanticFingerprint,
        public string $targetSemanticFingerprint,
        public string $operatorDecisionFingerprint,
    ) {
        if ($targetProductId <= 0 || $targetVariationId <= 0) {
            throw new \InvalidArgumentException('Linked target IDs must be positive.');
        }

        foreach ([$sourceSemanticFingerprint, $targetSemanticFingerprint, $operatorDecisionFingerprint] as $fingerprint) {
            self::assertFingerprint($fingerprint);
        }
    }

    /** @return array<string, int|string> */
    public function toArray(): array
    {
        return [
            'source_variation' => $this->sourceVariation->canonical(),
            'target_product_id' => $this->targetProductId,
            'target_variation_id' => $this->targetVariationId,
            'source_semantic_fingerprint' => $this->sourceSemanticFingerprint,
            'target_semantic_fingerprint' => $this->targetSemanticFingerprint,
            'operator_decision_fingerprint' => $this->operatorDecisionFingerprint,
        ];
    }

    private static function assertFingerprint(string $fingerprint): void
    {
        if (preg_match('/\A[a-f0-9]{64}\z/D', $fingerprint) !== 1) {
            throw new \InvalidArgumentException('Linked variation fingerprint must be a lowercase SHA-256 value.');
        }
    }
}

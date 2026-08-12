<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\Product;

use CartShift\Domain\Transfer\SourceIdentity;

defined('ABSPATH') || exit;

final readonly class ProductTransferDecision
{
    /** @param list<LinkedVariationDecision> $linkedVariations */
    public function __construct(
        public SourceIdentity $source,
        public string $sourceFingerprint,
        public ProductTransferAction $action,
        public ?int $targetProductId,
        public ?string $targetFingerprint,
        public string $reasonCode,
        public array $linkedVariations,
    ) {
        self::assertFingerprint($sourceFingerprint);

        if (preg_match('/\A[a-z][a-z0-9_]{2,63}\z/D', $reasonCode) !== 1) {
            throw new \InvalidArgumentException('Product decision reason code is invalid.');
        }

        if (!array_is_list($linkedVariations)) {
            throw new \InvalidArgumentException('Linked variation decisions must be a list.');
        }

        foreach ($linkedVariations as $link) {
            if (!$link instanceof LinkedVariationDecision) {
                throw new \InvalidArgumentException('Linked variation decision is invalid.');
            }
        }

        if ($action === ProductTransferAction::Link) {
            if ($targetProductId === null || $targetProductId <= 0 || $targetFingerprint === null) {
                throw new \InvalidArgumentException('A link decision requires a target product and fingerprint.');
            }
            self::assertFingerprint($targetFingerprint);
        } elseif ($targetProductId !== null || $targetFingerprint !== null || $linkedVariations !== []) {
            throw new \InvalidArgumentException('Only a link decision may contain target data.');
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $links = $this->linkedVariations;
        usort($links, static fn (LinkedVariationDecision $left, LinkedVariationDecision $right): int =>
            $left->sourceVariation->canonical() <=> $right->sourceVariation->canonical()
        );

        return [
            'source' => $this->source->canonical(),
            'source_fingerprint' => $this->sourceFingerprint,
            'action' => $this->action->value,
            'target_product_id' => $this->targetProductId,
            'target_fingerprint' => $this->targetFingerprint,
            'reason_code' => $this->reasonCode,
            'linked_variations' => array_map(static fn (LinkedVariationDecision $link): array => $link->toArray(), $links),
        ];
    }

    private static function assertFingerprint(string $fingerprint): void
    {
        if (preg_match('/\A[a-f0-9]{64}\z/D', $fingerprint) !== 1) {
            throw new \InvalidArgumentException('Product decision fingerprint must be a lowercase SHA-256 value.');
        }
    }
}

<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\Product;

use CartShift\Support\CanonicalJson;

defined('ABSPATH') || exit;

final readonly class ProductDecisionSet
{
    /** @var array<string, ProductTransferDecision> */
    public array $decisions;
    public string $fingerprint;

    /** @param list<ProductTransferDecision> $decisions */
    public function __construct(array $decisions)
    {
        if (!array_is_list($decisions)) {
            throw new \InvalidArgumentException('Product decisions must be a list.');
        }

        $indexed = [];
        foreach ($decisions as $decision) {
            if (!$decision instanceof ProductTransferDecision) {
                throw new \InvalidArgumentException('Product decision is invalid.');
            }

            $key = $decision->source->canonical();
            if (isset($indexed[$key])) {
                throw new \InvalidArgumentException('A product has more than one transfer decision.');
            }
            $indexed[$key] = $decision;
        }

        ksort($indexed, SORT_STRING);
        $this->decisions = $indexed;
        $this->fingerprint = CanonicalJson::fingerprint(array_map(
            static fn (ProductTransferDecision $decision): array => $decision->toArray(),
            array_values($indexed),
        ));
    }

    /**
     * @param array<string, string> $sourceFingerprints keyed by canonical product or variation source identity
     * @param array<int|string, string> $targetFingerprints keyed by product ID or `variation:<id>`
     */
    public function assertCurrent(array $sourceFingerprints, array $targetFingerprints): void
    {
        foreach ($this->decisions as $source => $decision) {
            $currentSource = $sourceFingerprints[$source] ?? null;
            if (!is_string($currentSource) || !hash_equals($decision->sourceFingerprint, $currentSource)) {
                throw new \RuntimeException('Product decision source fingerprint drifted.');
            }

            if ($decision->action !== ProductTransferAction::Link) {
                continue;
            }

            $currentTarget = $targetFingerprints[$decision->targetProductId] ?? null;
            if (!is_string($currentTarget) || !hash_equals((string) $decision->targetFingerprint, $currentTarget)) {
                throw new \RuntimeException('Product decision target fingerprint drifted.');
            }

            foreach ($decision->linkedVariations as $link) {
                $currentSourceVariation = $sourceFingerprints[$link->sourceVariation->canonical()] ?? null;
                if (!is_string($currentSourceVariation)
                    || !hash_equals($link->sourceSemanticFingerprint, $currentSourceVariation)) {
                    throw new \RuntimeException('Linked source variation fingerprint drifted.');
                }

                $currentTargetVariation = $targetFingerprints['variation:' . $link->targetVariationId] ?? null;
                if (!is_string($currentTargetVariation)
                    || !hash_equals($link->targetSemanticFingerprint, $currentTargetVariation)) {
                    throw new \RuntimeException('Linked target variation fingerprint drifted.');
                }
            }
        }
    }
}

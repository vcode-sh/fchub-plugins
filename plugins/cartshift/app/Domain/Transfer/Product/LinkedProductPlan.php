<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\Product;

use CartShift\Domain\Transfer\RecordEnvelope;
use CartShift\Domain\Transfer\SourceIdentity;
use CartShift\Support\CanonicalJson;

defined('ABSPATH') || exit;

/** Describes an owner-approved link to an existing target without projecting target writes. */
final readonly class LinkedProductPlan
{
    /**
     * @param list<array{sourceVariation:SourceIdentity,targetVariationId:int,sourceFingerprint:string,targetFingerprint:string}> $variationLinks
     */
    private function __construct(
        public ProductRecord $record,
        public int $targetProductId,
        public string $sourceFingerprint,
        public string $targetFingerprint,
        public array $variationLinks,
    ) {
    }

    /** @param array<string,mixed> $decision @param array<string,mixed> $targetSnapshot */
    public static function fromDecision(
        ProductRecord $record,
        RecordEnvelope $envelope,
        array $decision,
        array $targetSnapshot,
        ProductTargetFingerprint $fingerprint = new ProductTargetFingerprint(),
    ): self {
        $targetProductId = (int) ($decision['target_product_id'] ?? 0);
        $sourcePayloads = [];
        foreach ((array) ($envelope->payload['variations'] ?? []) as $variation) {
            if (is_array($variation) && is_string($variation['identity'] ?? null)) {
                $sourcePayloads[$variation['identity']] = $variation;
            }
        }
        $sourceVariations = [];
        foreach ($record->variations as $variation) {
            $sourceVariations[$variation->identity->canonical()] = $variation->identity;
        }
        $targetVariations = [];
        foreach ((array) ($targetSnapshot['variations'] ?? []) as $variation) {
            if (is_array($variation) && is_int($variation['id'] ?? null) && $variation['id'] > 0) {
                $targetVariations[$variation['id']] = $variation;
            }
        }

        $links = [];
        $sourceMap = [$record->identity->canonical() => $targetProductId];
        foreach ((array) ($decision['variation_links'] ?? []) as $link) {
            $sourceCanonical = is_array($link) ? (string) ($link['source_variation'] ?? '') : '';
            $targetVariationId = is_array($link) ? (int) ($link['target_variation_id'] ?? 0) : 0;
            $sourceIdentity = $sourceVariations[$sourceCanonical] ?? null;
            $sourcePayload = $sourcePayloads[$sourceCanonical] ?? null;
            $targetVariation = $targetVariations[$targetVariationId] ?? null;
            if (!$sourceIdentity instanceof SourceIdentity
                || !is_array($sourcePayload)
                || !is_array($targetVariation)
                || !hash_equals(
                    (string) ($link['source_fingerprint'] ?? ''),
                    CanonicalJson::fingerprint($sourcePayload),
                )
                || !hash_equals(
                    (string) ($link['target_fingerprint'] ?? ''),
                    CanonicalJson::fingerprint($targetVariation),
                )) {
                throw new \RuntimeException('target_linked_product_changed');
            }
            $sourceMap[$sourceCanonical] = $targetVariationId;
            $links[] = [
                'sourceVariation' => $sourceIdentity,
                'targetVariationId' => $targetVariationId,
                'sourceFingerprint' => (string) $link['source_fingerprint'],
                'targetFingerprint' => (string) $link['target_fingerprint'],
            ];
        }
        ksort($sourceVariations, SORT_STRING);
        $linkedSources = array_keys($sourceMap);
        array_shift($linkedSources);
        sort($linkedSources, SORT_STRING);
        if ($targetProductId <= 0
            || $sourceVariations === []
            || array_keys($sourceVariations) !== $linkedSources
            || !hash_equals(
                (string) ($decision['target_fingerprint'] ?? ''),
                $fingerprint->fingerprint($targetSnapshot, $sourceMap),
            )) {
            throw new \RuntimeException('target_linked_product_changed');
        }
        usort($links, static fn (array $left, array $right): int =>
            $left['sourceVariation']->canonical() <=> $right['sourceVariation']->canonical()
        );

        return new self(
            $record,
            $targetProductId,
            $envelope->privateContentDigest,
            (string) $decision['target_fingerprint'],
            $links,
        );
    }

    /** @return array<string,int> */
    public function sourceTargetIds(): array
    {
        $map = [$this->record->identity->canonical() => $this->targetProductId];
        foreach ($this->variationLinks as $link) {
            $map[$link['sourceVariation']->canonical()] = $link['targetVariationId'];
        }
        ksort($map, SORT_STRING);
        return $map;
    }

    /** @return list<SourceIdentity> */
    public function sourceIdentities(): array
    {
        $identities = [$this->record->identity];
        foreach ($this->variationLinks as $link) {
            $identities[] = $link['sourceVariation'];
        }
        return $identities;
    }

    public function sourceFingerprintFor(SourceIdentity $identity): string
    {
        if ($identity == $this->record->identity) {
            return $this->sourceFingerprint;
        }
        foreach ($this->variationLinks as $link) {
            if ($link['sourceVariation'] == $identity) {
                return $link['sourceFingerprint'];
            }
        }
        throw new \RuntimeException('target_linked_product_source_missing');
    }
}

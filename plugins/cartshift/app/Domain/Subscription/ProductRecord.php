<?php

declare(strict_types=1);

namespace CartShift\Domain\Subscription;

defined('ABSPATH') || exit;

/**
 * A source product and every variation a subscription item can claim.
 *
 * A simple Woo subscription product still gets exactly one variation entry,
 * whose `pseudo_variation_key` is the product ID itself. That is not ceremony:
 * the existing 1.4.x mapping decision already persists a source-variation to
 * target-variation map, and giving a simple product a pseudo-variation is what
 * lets both Lapka source products point at the same FluentCart product while
 * selecting different target variations — without a second mapping kingdom
 * being built to house them.
 */
final readonly class ProductRecord
{
    public const string KIND = 'product';

    /**
     * @param list<array<string, mixed>> $variations
     */
    public function __construct(
        public string $sourceKey,
        public string $sourceRef,
        public int $sourceProductId,
        public string $type,
        public string $name,
        public string $sku,
        public array $variations,
        public string $fingerprint,
    ) {}

    public function kind(): string
    {
        return self::KIND;
    }

    public function hasVariation(string $pseudoVariationKey): bool
    {
        foreach ($this->variations as $variation) {
            if ((string) ($variation['pseudo_variation_key'] ?? '') === $pseudoVariationKey) {
                return true;
            }
        }

        return false;
    }

    public function withFingerprint(string $fingerprint): self
    {
        return new self(
            $this->sourceKey,
            $this->sourceRef,
            $this->sourceProductId,
            $this->type,
            $this->name,
            $this->sku,
            $this->variations,
            $fingerprint,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function fingerprintPayload(): array
    {
        return [
            'kind'              => self::KIND,
            'name'              => $this->name,
            'sku'               => $this->sku,
            'source_key'        => $this->sourceKey,
            'source_product_id' => $this->sourceProductId,
            'source_ref'        => $this->sourceRef,
            'type'              => $this->type,
            'variations'        => $this->variations,
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

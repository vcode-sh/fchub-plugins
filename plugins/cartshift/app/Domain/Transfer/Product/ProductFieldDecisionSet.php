<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\Product;

use CartShift\Support\CanonicalJson;

defined('ABSPATH') || exit;

final readonly class ProductFieldDecisionSet
{
    /** @var array<string, ProductFieldDisposition> */
    public array $decisions;
    public string $fingerprint;

    /** @param array<string, ProductFieldDisposition> $decisions */
    public function __construct(array $decisions, ProductFieldRegistry $registry = new ProductFieldRegistry())
    {
        $expected = $registry->recognizedKeysWithoutDuplicates();
        $actual = array_keys($decisions);
        sort($actual);

        if ($actual !== $expected) {
            throw new \InvalidArgumentException('Product field decisions must cover every recognized field exactly once.');
        }

        foreach ($decisions as $field => $decision) {
            if (!$decision instanceof ProductFieldDisposition) {
                throw new \InvalidArgumentException('Product field decision is invalid.');
            }
        }

        ksort($decisions);
        $this->decisions = $decisions;
        $this->fingerprint = CanonicalJson::fingerprint(array_map(
            static fn (ProductFieldDisposition $decision): string => $decision->value,
            $decisions,
        ));
    }

    public static function all(ProductFieldDisposition $disposition): self
    {
        return new self(array_fill_keys(
            (new ProductFieldRegistry())->recognizedKeysWithoutDuplicates(),
            $disposition,
        ));
    }

    public function for(string $field): ProductFieldDisposition
    {
        return $this->decisions[$field] ?? throw new \OutOfBoundsException('Product field decision is missing.');
    }
}

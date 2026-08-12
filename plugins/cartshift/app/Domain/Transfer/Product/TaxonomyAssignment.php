<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\Product;

use CartShift\Domain\Transfer\SourceIdentity;

defined('ABSPATH') || exit;

final readonly class TaxonomyAssignment
{
    public function __construct(
        public string $taxonomy,
        public SourceIdentity $termIdentity,
        public string $name,
        public string $slug,
        public string $description,
        public ?SourceIdentity $parent,
        public int $order,
        public string $targetDisposition,
        public bool $assigned = true,
    ) {
        if ($taxonomy === '' || $name === '' || $slug === '') {
            throw new \InvalidArgumentException('Taxonomy assignment requires taxonomy, name and slug.');
        }

        if (!in_array($targetDisposition, ['assign', 'provenance', 'block'], true)) {
            throw new \InvalidArgumentException('Unknown taxonomy target disposition.');
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'taxonomy' => $this->taxonomy,
            'term_identity' => $this->termIdentity->canonical(),
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'parent' => $this->parent?->canonical(),
            'order' => $this->order,
            'target_disposition' => $this->targetDisposition,
            'assigned' => $this->assigned,
        ];
    }
}

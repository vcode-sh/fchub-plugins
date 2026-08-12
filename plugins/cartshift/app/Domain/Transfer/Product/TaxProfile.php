<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\Product;

defined('ABSPATH') || exit;

final readonly class TaxProfile
{
    public function __construct(
        public string $status,
        public string $classSlug,
        public bool $pricesIncludeTax,
    ) {
        if (!in_array($status, ['taxable', 'shipping', 'none'], true)) {
            throw new \InvalidArgumentException('Unknown WooCommerce tax status.');
        }

        if ($classSlug === '' || preg_match('/\A[a-z0-9][a-z0-9_-]*\z/D', $classSlug) !== 1) {
            throw new \InvalidArgumentException('Tax class must use an explicit canonical slug.');
        }
    }

    /** @return array{status: string, class_slug: string, prices_include_tax: bool} */
    public function toArray(): array
    {
        return ['status' => $this->status, 'class_slug' => $this->classSlug, 'prices_include_tax' => $this->pricesIncludeTax];
    }
}

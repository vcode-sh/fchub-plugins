<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\Product;

defined('ABSPATH') || exit;

final class ProductTypeAdapterRegistry
{
    /** @var list<ProductTypeAdapter> */
    private array $adapters;

    /** @param list<ProductTypeAdapter>|null $builtins */
    public function __construct(?array $builtins = null)
    {
        $adapters = apply_filters(
            'cartshift/transfer/product_type_adapters',
            $builtins ?? [new BuiltinProductTypeAdapter()],
        );

        if (!is_array($adapters) || !array_is_list($adapters)) {
            throw new \UnexpectedValueException('Product type adapters must be returned as a list.');
        }

        foreach ($adapters as $adapter) {
            if (!$adapter instanceof ProductTypeAdapter) {
                throw new \UnexpectedValueException('Every filtered product type adapter must implement ProductTypeAdapter.');
            }
        }

        $this->adapters = $adapters;
    }

    public function adapterFor(string $sourceType): ?ProductTypeAdapter
    {
        $matches = array_values(array_filter(
            $this->adapters,
            static fn (ProductTypeAdapter $adapter): bool => $adapter->supports($sourceType),
        ));

        if (count($matches) > 1) {
            throw new \LogicException('The source type has multiple product type adapters.');
        }

        return $matches[0] ?? null;
    }
}

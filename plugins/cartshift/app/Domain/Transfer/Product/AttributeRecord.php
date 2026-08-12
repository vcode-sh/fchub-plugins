<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\Product;

defined('ABSPATH') || exit;

final readonly class AttributeRecord
{
    /**
     * @param list<string> $values
     * @param array<string, string> $valueLabels keyed by stored source value
     */
    public function __construct(
        public string $sourceKey,
        public string $displayName,
        public string $kind,
        public bool $variation,
        public bool $visible,
        public int $position,
        public ?string $defaultValue,
        public array $values,
        public array $valueLabels = [],
    ) {
        if (preg_match('/\A[a-z0-9][a-z0-9_-]*\z/D', $sourceKey) !== 1 || $displayName === '') {
            throw new \InvalidArgumentException('Attribute identity and display name are required.');
        }

        if (!in_array($kind, ['taxonomy', 'custom'], true)) {
            throw new \InvalidArgumentException('Attribute kind must be taxonomy or custom.');
        }

        if (!array_is_list($values) || count($values) !== count(array_unique($values, SORT_STRING))) {
            throw new \InvalidArgumentException('Attribute values must be a unique ordered list.');
        }

        foreach ($values as $value) {
            if (!is_string($value) || $value === '') {
                throw new \InvalidArgumentException('Attribute values cannot be blank.');
            }
        }


        foreach ($valueLabels as $value => $label) {
            if (!is_string($value) || !in_array($value, $values, true) || !is_string($label) || trim($label) === '') {
                throw new \InvalidArgumentException('Attribute value labels must cover known non-empty values.');
            }
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'source_key' => $this->sourceKey,
            'display_name' => $this->displayName,
            'kind' => $this->kind,
            'variation' => $this->variation,
            'visible' => $this->visible,
            'position' => $this->position,
            'default_value' => $this->defaultValue,
            'values' => $this->values,
            'value_labels' => $this->valueLabels,
        ];
    }
}

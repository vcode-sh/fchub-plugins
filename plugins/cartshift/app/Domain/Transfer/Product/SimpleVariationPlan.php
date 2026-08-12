<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\Product;

use CartShift\Domain\Transfer\SourceIdentity;

defined('ABSPATH') || exit;

final readonly class SimpleVariationPlan
{
    /**
     * @param array<string, mixed> $targetOtherInfo
     * @param array<string, mixed> $targetFields
     */
    public function __construct(
        public SourceIdentity $sourceVariation,
        public string $variationIdentifier,
        public string $targetTitle,
        public array $targetOtherInfo,
        public array $targetFields,
    ) {
        if ($variationIdentifier === '' || strlen($variationIdentifier) > 100 || $targetTitle === '') {
            throw new \InvalidArgumentException('Simple variation plan identity and title are required.');
        }

        if (($targetFields['variation_identifier'] ?? null) !== $variationIdentifier
            || ($targetFields['variation_title'] ?? null) !== $targetTitle
            || ($targetFields['other_info'] ?? null) !== $targetOtherInfo) {
            throw new \InvalidArgumentException('Simple variation plan fields do not match the canonical values.');
        }
    }
}

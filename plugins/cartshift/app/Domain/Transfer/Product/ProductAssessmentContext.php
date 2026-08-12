<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\Product;

defined('ABSPATH') || exit;

final readonly class ProductAssessmentContext
{
    /**
     * @param list<string> $targetTaxClasses
     * @param array<string, bool> $capabilities
     * @param list<string> $approvedDraftStatuses
     * @param array<string, int> $targetShippingClasses keyed by source shipping class slug
     */
    public function __construct(
        public array $targetTaxClasses,
        public array $capabilities,
        public ProductFieldDecisionSet $fieldDecisions,
        public int $dependentOrders = 0,
        public int $dependentSubscriptions = 0,
        public array $approvedDraftStatuses = [],
        public ?ProductTransferDecision $operatorDecision = null,
        public array $targetShippingClasses = ['none' => 0],
    ) {
        if ($dependentOrders < 0 || $dependentSubscriptions < 0) {
            throw new \InvalidArgumentException('Dependent record counts cannot be negative.');
        }

        if (!array_is_list($targetTaxClasses) || count($targetTaxClasses) !== count(array_unique($targetTaxClasses, SORT_STRING))) {
            throw new \InvalidArgumentException('Target tax classes must be a unique list.');
        }

        foreach ($targetTaxClasses as $taxClass) {
            if (!is_string($taxClass) || $taxClass === '') {
                throw new \InvalidArgumentException('Target tax class is invalid.');
            }
        }

        foreach ($capabilities as $capability => $supported) {
            if (!is_string($capability) || !is_bool($supported)) {
                throw new \InvalidArgumentException('Product capability report must be a boolean map.');
            }
        }

        if (!array_is_list($approvedDraftStatuses) || count($approvedDraftStatuses) !== count(array_unique($approvedDraftStatuses, SORT_STRING))) {
            throw new \InvalidArgumentException('Approved draft statuses must be a unique list.');
        }

        foreach ($targetShippingClasses as $slug => $targetId) {
            if (!is_string($slug) || $slug === '' || !is_int($targetId) || $targetId < 0) {
                throw new \InvalidArgumentException('Target shipping class map is invalid.');
            }
        }
    }

    public function supports(string $capability): bool
    {
        return ($this->capabilities[$capability] ?? false) === true;
    }
}

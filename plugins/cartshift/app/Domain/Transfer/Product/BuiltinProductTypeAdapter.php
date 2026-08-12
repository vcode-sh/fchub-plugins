<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\Product;

use CartShift\Domain\Transfer\AssessmentOutcome;
use CartShift\Domain\Transfer\RecordAssessment;

defined('ABSPATH') || exit;

final class BuiltinProductTypeAdapter implements ProductTypeAdapter
{
    private const array TYPES = ['simple', 'variable', 'subscription', 'variable-subscription'];

    public function supports(string $sourceType): bool
    {
        return in_array($sourceType, self::TYPES, true);
    }

    public function assess(ProductRecord $record, ProductAssessmentContext $context): RecordAssessment
    {
        return new RecordAssessment(AssessmentOutcome::Ready, 'builtin_type_supported');
    }

    public function targetPaymentType(ProductRecord $record): string
    {
        return in_array($record->productType, ['subscription', 'variable-subscription'], true)
            ? 'subscription'
            : 'onetime';
    }
}

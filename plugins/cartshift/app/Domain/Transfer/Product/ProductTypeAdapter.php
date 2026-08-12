<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\Product;

use CartShift\Domain\Transfer\RecordAssessment;

defined('ABSPATH') || exit;

interface ProductTypeAdapter
{
    public function supports(string $sourceType): bool;

    public function assess(ProductRecord $record, ProductAssessmentContext $context): RecordAssessment;

    public function targetPaymentType(ProductRecord $record): string;
}

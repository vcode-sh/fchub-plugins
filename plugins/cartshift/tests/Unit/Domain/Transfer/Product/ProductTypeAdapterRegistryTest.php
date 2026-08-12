<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\Domain\Transfer\Product;

use CartShift\Domain\Transfer\Product\ProductAssessmentContext;
use CartShift\Domain\Transfer\Product\ProductRecord;
use CartShift\Domain\Transfer\Product\ProductTypeAdapter;
use CartShift\Domain\Transfer\Product\ProductTypeAdapterRegistry;
use CartShift\Domain\Transfer\RecordAssessment;
use CartShift\Tests\Unit\PluginTestCase;

final class ProductTypeAdapterRegistryTest extends PluginTestCase
{
    public function testBuiltinsSupportOnlyTheFourProvedProductTypes(): void
    {
        $registry = new ProductTypeAdapterRegistry();

        self::assertSame('onetime', $registry->adapterFor('simple')->targetPaymentType(ProductAssessmentFixture::product()));
        self::assertSame('onetime', $registry->adapterFor('variable')->targetPaymentType(ProductAssessmentFixture::product(['productType' => 'variable'])));
        self::assertSame('subscription', $registry->adapterFor('subscription')->targetPaymentType(ProductAssessmentFixture::product(['productType' => 'subscription'])));
        self::assertSame('subscription', $registry->adapterFor('variable-subscription')->targetPaymentType(ProductAssessmentFixture::product(['productType' => 'variable-subscription'])));
        self::assertNull($registry->adapterFor('course'));
        self::assertNull($registry->adapterFor('grouped'));
        self::assertNull($registry->adapterFor('external'));
    }

    public function testInvalidFilteredAdapterIsABlockingRuntimeError(): void
    {
        add_filter('cartshift/transfer/product_type_adapters', static fn (array $adapters): array => [...$adapters, new \stdClass()]);

        $this->expectException(\UnexpectedValueException::class);
        new ProductTypeAdapterRegistry();
    }

    public function testTwoAdaptersClaimingTheSameTypeAreRejected(): void
    {
        add_filter('cartshift/transfer/product_type_adapters', static fn (array $adapters): array => [
            ...$adapters,
            new class implements ProductTypeAdapter {
                public function supports(string $sourceType): bool { return $sourceType === 'simple'; }
                public function assess(ProductRecord $record, ProductAssessmentContext $context): RecordAssessment { throw new \LogicException(); }
                public function targetPaymentType(ProductRecord $record): string { return 'other'; }
            },
        ]);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('multiple product type adapters');
        (new ProductTypeAdapterRegistry())->adapterFor('simple');
    }

    public function testProjectSpecificAdapterCanClaimCourseWithoutChangingCore(): void
    {
        $course = new class implements ProductTypeAdapter {
            public function supports(string $sourceType): bool { return $sourceType === 'course'; }
            public function assess(ProductRecord $record, ProductAssessmentContext $context): RecordAssessment { throw new \LogicException(); }
            public function targetPaymentType(ProductRecord $record): string { return 'onetime'; }
        };
        add_filter('cartshift/transfer/product_type_adapters', static fn (array $adapters): array => [...$adapters, $course]);

        self::assertSame($course, (new ProductTypeAdapterRegistry())->adapterFor('course'));
    }
}

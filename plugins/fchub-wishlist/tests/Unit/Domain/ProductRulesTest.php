<?php

declare(strict_types=1);

namespace FChubWishlist\Tests\Unit\Domain;

use FChubWishlist\Domain\Rules\ProductRules;
use FChubWishlist\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class ProductRulesTest extends TestCase
{
    #[Test]
    public function productExistenceUsesTheWordPressPostApi(): void
    {
        $this->setMockPost(42, 'fluent-products', ['status' => 'publish']);
        $this->setMockPost(43, 'fluent-products', ['status' => 'draft']);

        $rules = new ProductRules();

        $this->assertTrue($rules->productExists(42));
        $this->assertFalse($rules->productExists(43));
        $this->assertFalse($rules->productExists(999));
        $this->assertSame([], $GLOBALS['wpdb']->queries);
    }

    #[Test]
    public function variationLookupPreparesTheCustomTableIdentifier(): void
    {
        $this->setWpdbMockVar('1');

        $this->assertTrue((new ProductRules())->variantExists(81));
        $this->assertQueryContains('FROM `wp_fct_product_variations`');
        $this->assertQueryContains('id = 81');
    }
}

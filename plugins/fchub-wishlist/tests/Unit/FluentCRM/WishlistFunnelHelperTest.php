<?php

declare(strict_types=1);

namespace FChubWishlist\Tests\Unit\FluentCRM;

use FChubWishlist\FluentCRM\Helpers\WishlistFunnelHelper;
use FChubWishlist\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class WishlistFunnelHelperTest extends TestCase
{
    #[Test]
    public function productOptionsUseTheWordPressPostApiAndSearchFilter(): void
    {
        $this->setMockPost(10, 'fluent-products', ['title' => 'Blue jumper', 'status' => 'publish']);
        $this->setMockPost(11, 'fluent-products', ['title' => 'Red jumper', 'status' => 'publish']);
        $this->setMockPost(12, 'post', ['title' => 'Blue article', 'status' => 'publish']);

        $this->assertSame(
            [['id' => '10', 'title' => 'Blue jumper']],
            WishlistFunnelHelper::getProductOptions('Blue')
        );
        $this->assertSame([], $GLOBALS['wpdb']->queries);
    }

    #[Test]
    public function variantOptionsPrepareIdentifierAndOptionalValues(): void
    {
        $this->setWpdbMockResults([
            ['id' => '8', 'variation_title' => 'Blue / Large', 'post_id' => '10'],
        ]);

        $options = WishlistFunnelHelper::getVariantOptions(10, 'Large');

        $this->assertSame([['id' => '8', 'title' => 'Blue / Large']], $options);
        $this->assertQueryContains('FROM `wp_fct_product_variations`');
        $this->assertQueryContains('post_id = 10');
        $this->assertQueryContains("variation_title LIKE '%Large%'");
    }
}

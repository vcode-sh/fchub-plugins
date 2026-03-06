<?php

declare(strict_types=1);

namespace FChubMultiCurrency\Tests\Unit\Domain\Services;

use FChubMultiCurrency\Domain\Services\CheckoutDisclosureService;
use FChubMultiCurrency\Storage\OptionStore;
use FChubMultiCurrency\Tests\Support\MockBuilder;
use FChubMultiCurrency\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class CheckoutDisclosureEscapingTest extends TestCase
{
    private function makeService(): CheckoutDisclosureService
    {
        return new CheckoutDisclosureService(new OptionStore());
    }

    #[Test]
    public function testHtmlTagsInTemplateAreStrippedBySanitisation(): void
    {
        $this->setOption('fchub_mc_settings', [
            'checkout_disclosure_enabled' => 'yes',
            'checkout_disclosure_text'    => '<script>alert("xss")</script>Charged in {base_currency}',
        ]);

        $result = $this->makeService()->getDisclosure(MockBuilder::context());

        // wp_kses strips <script> tags — only <strong>, <em>, <br>, <span> are allowed
        $this->assertNotNull($result);
        $this->assertStringNotContainsString('<script>', $result);
        $this->assertStringContainsString('Charged in USD', $result);
    }

    #[Test]
    public function testAllowedHtmlTagsArePreserved(): void
    {
        $this->setOption('fchub_mc_settings', [
            'checkout_disclosure_enabled' => 'yes',
            'checkout_disclosure_text'    => '<strong>Payment</strong> in <em>{base_currency}</em>',
        ]);

        $result = $this->makeService()->getDisclosure(MockBuilder::context());

        $this->assertNotNull($result);
        $this->assertStringContainsString('<strong>', $result);
        $this->assertStringContainsString('<em>', $result);
    }

    #[Test]
    public function testTokenReplacementsWorkCorrectly(): void
    {
        $this->setOption('fchub_mc_settings', [
            'checkout_disclosure_enabled' => 'yes',
            'checkout_disclosure_text'    => 'Base: {base_currency}, Display: {display_currency}, Rate: {rate}',
        ]);

        $result = $this->makeService()->getDisclosure(MockBuilder::context());

        $this->assertNotNull($result);
        $this->assertStringContainsString('Base: USD', $result);
        $this->assertStringContainsString('Display: EUR', $result);
        $this->assertStringContainsString('Rate: 0.92000000', $result);
    }

    #[Test]
    public function testDisallowedHtmlTagsAreStripped(): void
    {
        $this->setOption('fchub_mc_settings', [
            'checkout_disclosure_enabled' => 'yes',
            'checkout_disclosure_text'    => '<div>Charged in {base_currency}</div><img src=x onerror=alert(1)>',
        ]);

        $result = $this->makeService()->getDisclosure(MockBuilder::context());

        $this->assertNotNull($result);
        $this->assertStringNotContainsString('<div>', $result);
        $this->assertStringNotContainsString('<img', $result);
        $this->assertStringContainsString('Charged in USD', $result);
    }
}

<?php

declare(strict_types=1);

namespace FChubMultiCurrency\Tests\Unit\Domain\Services;

use FChubMultiCurrency\Domain\Services\CheckoutDisclosureService;
use FChubMultiCurrency\Storage\OptionStore;
use FChubMultiCurrency\Tests\Support\MockBuilder;
use FChubMultiCurrency\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class CheckoutDisclosureServiceTest extends TestCase
{
    private function makeService(): CheckoutDisclosureService
    {
        return new CheckoutDisclosureService(new OptionStore());
    }

    #[Test]
    public function testReturnsNullWhenDisabled(): void
    {
        $this->setOption('fchub_mc_settings', ['checkout_disclosure_enabled' => 'no']);

        $result = $this->makeService()->getDisclosure(MockBuilder::context());

        $this->assertNull($result);
    }

    #[Test]
    public function testReturnsNullForBaseDisplayContext(): void
    {
        $result = $this->makeService()->getDisclosure(MockBuilder::baseOnlyContext());

        $this->assertNull($result);
    }

    #[Test]
    public function testDefaultTemplateSubstitution(): void
    {
        $this->setOption('fchub_mc_settings', ['checkout_disclosure_enabled' => 'yes']);

        $result = $this->makeService()->getDisclosure(MockBuilder::context());

        $this->assertSame('Your payment will be processed in USD.', $result);
    }

    #[Test]
    public function testCustomTemplateSubstitution(): void
    {
        $this->setOption('fchub_mc_settings', [
            'checkout_disclosure_enabled' => 'yes',
            'checkout_disclosure_text'    => 'Charged in {base_currency} at rate {rate}. Display: {display_currency}',
        ]);

        $result = $this->makeService()->getDisclosure(MockBuilder::context());

        $this->assertSame('Charged in USD at rate 0.92000000. Display: EUR', $result);
    }

    #[Test]
    public function testTemplateWithNoPlaceholders(): void
    {
        $this->setOption('fchub_mc_settings', [
            'checkout_disclosure_enabled' => 'yes',
            'checkout_disclosure_text'    => 'All prices in base currency',
        ]);

        $result = $this->makeService()->getDisclosure(MockBuilder::context());

        $this->assertSame('All prices in base currency', $result);
    }

    #[Test]
    public function testUsesDefaultSettingsWhenNoOptionSet(): void
    {
        // No option set — OptionStore merges with Constants::DEFAULT_SETTINGS
        $result = $this->makeService()->getDisclosure(MockBuilder::context());

        $this->assertSame('Your payment will be processed in USD.', $result);
    }
}

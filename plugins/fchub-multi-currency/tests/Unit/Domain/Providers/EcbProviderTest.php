<?php

declare(strict_types=1);

namespace FChubMultiCurrency\Tests\Unit\Domain\Providers;

use FChubMultiCurrency\Domain\Providers\EcbProvider;
use FChubMultiCurrency\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class EcbProviderTest extends TestCase
{
    private const SAMPLE_XML = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<gesmes:Envelope xmlns:gesmes="http://www.gesmes.org/xml/2002-08-01" xmlns="http://www.ecb.int/vocabulary/2002-08-01/eurofxref">
<gesmes:subject>Reference rates</gesmes:subject>
<Cube>
<Cube time="2026-03-06">
<Cube currency="USD" rate="1.0856"/>
<Cube currency="GBP" rate="0.83478"/>
<Cube currency="JPY" rate="161.42"/>
</Cube>
</Cube>
</gesmes:Envelope>
XML;

    protected function setUp(): void
    {
        parent::setUp();
        unset($GLOBALS['wp_mock_remote_response'], $GLOBALS['wp_mock_remote_body']);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        unset($GLOBALS['wp_mock_remote_response'], $GLOBALS['wp_mock_remote_body']);
    }

    #[Test]
    public function testFetchRatesWithEurBase(): void
    {
        $GLOBALS['wp_mock_remote_response'] = ['body' => self::SAMPLE_XML, 'response' => ['code' => 200]];
        $GLOBALS['wp_mock_remote_body'] = self::SAMPLE_XML;

        $provider = new EcbProvider();
        $rates = $provider->fetchRates('EUR');

        $this->assertIsArray($rates);
        $this->assertSame('1.00000000', $rates['EUR']);
        $this->assertSame('1.0856', $rates['USD']);
        $this->assertSame('0.83478', $rates['GBP']);
        $this->assertSame('161.42', $rates['JPY']);
    }

    #[Test]
    public function testFetchRatesWithUsdBaseRebasesRates(): void
    {
        $GLOBALS['wp_mock_remote_response'] = ['body' => self::SAMPLE_XML, 'response' => ['code' => 200]];
        $GLOBALS['wp_mock_remote_body'] = self::SAMPLE_XML;

        $provider = new EcbProvider();
        $rates = $provider->fetchRates('USD');

        $this->assertIsArray($rates);

        // USD base: EUR rate = 1.00000000 / 1.0856, USD = 1.0856 / 1.0856 = 1
        $usdRate = (float) $rates['USD'];
        $this->assertEqualsWithDelta(1.0, $usdRate, 0.000001);

        $eurRate = (float) $rates['EUR'];
        $expectedEur = 1.0 / 1.0856;
        $this->assertEqualsWithDelta($expectedEur, $eurRate, 0.0001);

        $gbpRate = (float) $rates['GBP'];
        $expectedGbp = 0.83478 / 1.0856;
        $this->assertEqualsWithDelta($expectedGbp, $gbpRate, 0.0001);
    }

    #[Test]
    public function testFetchRatesReturnsEmptyOnWpError(): void
    {
        $GLOBALS['wp_mock_remote_response'] = new \WP_Error('http_error', 'Connection failed');

        $provider = new EcbProvider();
        $rates = $provider->fetchRates('EUR');

        $this->assertSame([], $rates);
    }

    #[Test]
    public function testFetchRatesReturnsEmptyOnInvalidXml(): void
    {
        $GLOBALS['wp_mock_remote_response'] = ['body' => 'not xml', 'response' => ['code' => 200]];
        $GLOBALS['wp_mock_remote_body'] = 'not xml';

        $provider = new EcbProvider();
        $rates = @$provider->fetchRates('EUR');

        $this->assertSame([], $rates);
    }

    #[Test]
    public function testNameReturnsEcb(): void
    {
        $provider = new EcbProvider();
        $this->assertSame('ecb', $provider->name());
    }
}

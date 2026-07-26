<?php

declare(strict_types=1);

namespace FChubFakturownia\Tests\Unit;

use FChubFakturownia\Integration\FakturowniaSettings;
use FChubFakturownia\Tests\PluginTestCase;
use FChubFakturownia\Tests\WpSendJsonException;

final class LifecycleContractTest extends PluginTestCase
{
    public function testRenderingUnconfiguredSettingsDoesNotContactProvider(): void
    {
        $requestCount = 0;
        $this->mockApiHandler(function () use (&$requestCount) {
            ++$requestCount;

            return [
                'response' => ['code' => 200],
                'body'     => '{}',
                'headers'  => ['content-type' => 'application/json'],
            ];
        });

        try {
            FakturowniaSettings::getGlobalFields([], []);
        } catch (WpSendJsonException) {
            // The test bootstrap turns wp_send_json() into this exception.
        }

        self::assertSame(0, $requestCount);
        self::assertFalse(FakturowniaSettings::isConfigured());
    }

    public function testFluentCartRegistrationKeepsDefensiveRuntimeGuard(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/fchub-fakturownia.php');

        self::assertMatchesRegularExpression(
            "/add_action\\('init'.+if \\(!defined\\('FLUENTCART_VERSION'\\)\\) \\{\\s+return;/s",
            $source
        );
        self::assertStringContainsString('Requires Plugins: fluent-cart', $source);
    }
}

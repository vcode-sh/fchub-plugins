<?php

declare(strict_types=1);

namespace FChubMultiCurrency\Tests\Unit\Http\Controllers\Admin;

use FChubMultiCurrency\Http\Controllers\Admin\DiagnosticsController;
use FChubMultiCurrency\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class DiagnosticsControllerTest extends TestCase
{
    #[Test]
    public function itReportsConfiguredFluentCrmCustomFieldAvailability(): void
    {
        $GLOBALS['fluentcrm_mock_custom_fields'] = [
            ['slug' => 'preferred_currency'],
            ['slug' => 'last_order_fx_rate'],
        ];

        $response = (new DiagnosticsController())->get(new \WP_REST_Request());
        $data = $response->get_data();

        self::assertSame([
            'preferred_currency' => true,
            'last_order_display_currency' => false,
            'last_order_fx_rate' => true,
        ], $data['data']['fluentcrm_fields_status']);
    }
}

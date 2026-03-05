<?php

declare(strict_types=1);

namespace FChubMultiCurrency\Tests\Unit\Http\Controllers\Admin;

use FChubMultiCurrency\Http\Controllers\Admin\SettingsAdminController;
use FChubMultiCurrency\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class SettingsAdminControllerTest extends TestCase
{
    #[Test]
    public function testSaveReturnsBadRequestForInvalidJsonPayload(): void
    {
        $controller = new SettingsAdminController();
        $request = new \WP_REST_Request('POST', '/');
        $request->set_body('{invalid-json');

        $response = $controller->save($request);

        $this->assertSame(400, $response->get_status());
    }

    #[Test]
    public function testSaveSanitizesSettingsPayload(): void
    {
        $controller = new SettingsAdminController();
        $request = new \WP_REST_Request('POST', '/');
        $request->set_json_params([
            'rate_refresh_interval_hrs' => 999,
            'stale_threshold_hrs'       => 0,
            'rounding_mode'             => 'invalid-mode',
            'url_param_key'             => 'curr<>ency',
            'display_currencies'        => [
                [
                    'code'     => 'eur',
                    'name'     => 'Euro',
                    'symbol'   => '€',
                    'decimals' => 9,
                    'position' => 'left',
                ],
                [
                    'code'     => 'EUR',
                    'name'     => 'Euro Duplicate',
                    'symbol'   => '€',
                    'decimals' => 2,
                    'position' => 'right',
                ],
                [
                    'code' => 'XXX',
                ],
            ],
        ]);

        $response = $controller->save($request);
        $data = $response->get_data();
        $settings = $data['data']['settings'] ?? [];

        $this->assertSame(200, $response->get_status());
        $this->assertSame(168, $settings['rate_refresh_interval_hrs']);
        $this->assertSame(1, $settings['stale_threshold_hrs']);
        $this->assertSame('half_up', $settings['rounding_mode']);
        $this->assertSame('currency', $settings['url_param_key']);
        $this->assertCount(1, $settings['display_currencies']);
        $this->assertSame('EUR', $settings['display_currencies'][0]['code']);
        $this->assertSame(4, $settings['display_currencies'][0]['decimals']);
    }
}

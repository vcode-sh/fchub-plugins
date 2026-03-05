<?php

declare(strict_types=1);

namespace FChubMultiCurrency\Tests\Unit\Http\Controllers\Pub;

use FChubMultiCurrency\Http\Controllers\Pub\ContextController;
use FChubMultiCurrency\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class ContextControllerTest extends TestCase
{
    #[Test]
    public function testSetReturnsBadRequestForInvalidJsonPayload(): void
    {
        $controller = new ContextController();
        $request = new \WP_REST_Request('POST', '/');
        $request->set_body('{invalid-json');

        $response = $controller->set($request);

        $this->assertSame(400, $response->get_status());
    }

    #[Test]
    public function testSetHandlesInvalidDisplayCurrenciesShapeWithoutFatal(): void
    {
        $controller = new ContextController();
        $this->setOption('fchub_mc_settings', [
            'display_currencies' => 'invalid-shape',
        ]);

        $request = new \WP_REST_Request('POST', '/');
        $request->set_json_params([
            'currency' => 'EUR',
        ]);

        $response = $controller->set($request);

        $this->assertSame(422, $response->get_status());
    }

    #[Test]
    public function testSetAllowsSwitchingToBaseCurrencyEvenIfNotInDisplayList(): void
    {
        $controller = new ContextController();
        $this->setOption('fchub_mc_settings', [
            'base_currency'      => 'USD',
            'display_currencies' => [
                ['code' => 'EUR'],
            ],
        ]);

        $request = new \WP_REST_Request('POST', '/');
        $request->set_json_params([
            'currency' => 'USD',
        ]);

        $response = $controller->set($request);
        $data = $response->get_data();

        $this->assertSame(200, $response->get_status());
        $this->assertSame('USD', $data['data']['currency']);
    }
}

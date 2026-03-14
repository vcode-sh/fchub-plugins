<?php

declare(strict_types=1);

namespace FChubFakturownia\Tests;

use PHPUnit\Framework\TestCase;

abstract class PluginTestCase extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $GLOBALS['_fchub_test_scheduled_events'] = [];
        $GLOBALS['_fchub_test_cleared_events'] = [];
        $GLOBALS['_fchub_test_current_user_can'] = true;
        $GLOBALS['_fchub_test_wp_remote'] = null;
        $GLOBALS['_fchub_test_options'] = [];
        $GLOBALS['_fchub_test_filters'] = [];
        $GLOBALS['_fchub_test_transients'] = [];
        $GLOBALS['_fchub_test_orders'] = [];
        $GLOBALS['_fchub_test_wp_timezone'] = 'UTC';
        $GLOBALS['_fchub_test_is_admin'] = false;

        // Reset cached settings
        $this->resetSettingsCache();
    }

    protected function resetSettingsCache(): void
    {
        $ref = new \ReflectionClass(\FChubFakturownia\Integration\FakturowniaSettings::class);
        $prop = $ref->getProperty('cachedSettings');
        $prop->setAccessible(true);
        $prop->setValue(null, null);
    }

    /**
     * Create a mock order with sensible defaults
     */
    protected function createOrder(array $overrides = []): \FluentCart\App\Models\Order
    {
        $order = new \FluentCart\App\Models\Order();
        $order->id = $overrides['id'] ?? 42;
        $order->invoice_no = $overrides['invoice_no'] ?? 'FC-42';
        $order->payment_method = $overrides['payment_method'] ?? 'przelewy24';
        $order->shipping_total = $overrides['shipping_total'] ?? 0;
        $order->shipping_tax = $overrides['shipping_tax'] ?? 0;

        // Use array_key_exists for nullable fields — ?? treats null as "not set"
        $order->paid_at = array_key_exists('paid_at', $overrides) ? $overrides['paid_at'] : '2025-03-10 14:30:00';
        $order->created_at = array_key_exists('created_at', $overrides) ? $overrides['created_at'] : '2025-03-10 14:00:00';
        $order->billing_address = array_key_exists('billing_address', $overrides) ? $overrides['billing_address'] : $this->createBillingAddress();
        $order->customer = array_key_exists('customer', $overrides) ? $overrides['customer'] : $this->createCustomer();
        $order->order_items = array_key_exists('order_items', $overrides) ? $overrides['order_items'] : [$this->createOrderItem()];

        if (isset($overrides['meta'])) {
            $order->setTestMeta($overrides['meta']);
        }

        return $order;
    }

    protected function createBillingAddress(array $overrides = []): object
    {
        return (object) array_merge([
            'first_name'   => 'Jan',
            'last_name'    => 'Kowalski',
            'name'         => 'Jan Kowalski',
            'company_name' => '',
            'address_1'    => 'ul. Testowa 1',
            'address_2'    => '',
            'city'         => 'Warszawa',
            'postcode'     => '00-001',
            'country'      => 'PL',
            'meta'         => [],
        ], $overrides);
    }

    protected function createCustomer(array $overrides = []): object
    {
        return (object) array_merge([
            'first_name' => 'Jan',
            'last_name'  => 'Kowalski',
            'email'      => 'jan@example.com',
        ], $overrides);
    }

    protected function createOrderItem(array $overrides = []): object
    {
        return (object) array_merge([
            'title'      => 'Widget Pro',
            'quantity'   => 1,
            'subtotal'   => 10000, // 100.00 PLN in cents
            'line_total' => 10000,
            'tax_amount' => 2300,  // 23% VAT
        ], $overrides);
    }

    /**
     * Configure Fakturownia settings for tests
     */
    protected function setSettings(array $overrides = []): void
    {
        $defaults = [
            'domain'          => 'testfirma',
            'api_token'       => 'test-token-123',
            'status'          => true,
            'department_id'   => '12345',
            'invoice_kind'    => 'vat',
            'payment_type'    => 'transfer',
            'invoice_lang'    => 'pl',
            'ksef_auto_send'  => 'no',
            'show_nip_toggle' => 'yes',
        ];

        $GLOBALS['_fchub_test_options']['_integration_api_fakturownia'] = array_merge($defaults, $overrides);
        $this->resetSettingsCache();
    }

    /**
     * Mock HTTP responses from Fakturownia API
     */
    protected function mockApiResponse(array $responseBody, int $statusCode = 200): void
    {
        $GLOBALS['_fchub_test_wp_remote'] = function () use ($responseBody, $statusCode) {
            return [
                'response' => ['code' => $statusCode],
                'body'     => json_encode($responseBody),
                'headers'  => ['content-type' => 'application/json'],
            ];
        };
    }

    /**
     * Mock HTTP responses with a callable for request inspection
     */
    protected function mockApiHandler(callable $handler): void
    {
        $GLOBALS['_fchub_test_wp_remote'] = $handler;
    }
}

<?php

declare(strict_types=1);

namespace FChubFakturownia\Tests\Unit;

use FChubFakturownia\Checkout\CheckoutFields;
use FChubFakturownia\Integration\FakturowniaSettings;
use FChubFakturownia\Tests\PluginTestCase;
use FChubFakturownia\Tests\WpSendJsonException;

/**
 * Frontend-to-backend wiring tests — verifying data flows correctly
 * between FluentCart's checkout/admin and our plugin.
 */
final class FrontendWiringTest extends PluginTestCase
{
    // ──────────────────────────────────────────────────────────
    // NIP validation with FluentCart's actual data shape
    // ──────────────────────────────────────────────────────────

    public function testNipValidationWithFluentCartWrappedData(): void
    {
        // FluentCart passes ['data' => $formData, 'cart' => $cart] as second arg
        $errors = null;
        $result = CheckoutFields::validateCheckoutNip($errors, [
            'data' => ['billing_nip' => '1234567890'], // invalid NIP
            'cart' => [],
        ]);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $messages = $result->get_error_messages('billing_nip');
        $this->assertNotEmpty($messages);
    }

    public function testValidNipPassesWithFluentCartWrappedData(): void
    {
        $errors = null;
        $result = CheckoutFields::validateCheckoutNip($errors, [
            'data' => ['billing_nip' => '5213017228'], // valid NIP
            'cart' => [],
        ]);

        $this->assertNull($result);
    }

    public function testEmptyNipSkipsValidationWithWrappedData(): void
    {
        $errors = null;
        $result = CheckoutFields::validateCheckoutNip($errors, [
            'data' => ['billing_nip' => ''],
            'cart' => [],
        ]);

        $this->assertNull($result);
    }

    public function testMissingNipKeySkipsValidationWithWrappedData(): void
    {
        $errors = null;
        $result = CheckoutFields::validateCheckoutNip($errors, [
            'data' => ['first_name' => 'Jan'],
            'cart' => [],
        ]);

        $this->assertNull($result);
    }

    public function testNipValidationFallsBackToDirectDataAccess(): void
    {
        // If data is passed without wrapping (backwards compat), the fallback
        // $data['data'] ?? $data returns $data itself — still validates
        $errors = null;
        $result = CheckoutFields::validateCheckoutNip($errors, [
            'billing_nip' => '1234567890', // invalid NIP, no wrapping
        ]);

        // Should still validate since fallback $data['data'] ?? $data = $data
        $this->assertInstanceOf(\WP_Error::class, $result);
    }

    // ──────────────────────────────────────────────────────────
    // Settings page response format
    // getGlobalFields() now sends wp_send_json (throws in tests),
    // so we catch the exception and inspect the response data.
    // ──────────────────────────────────────────────────────────

    private function captureGlobalFieldsResponse(): array
    {
        $this->setSettings();
        try {
            FakturowniaSettings::getGlobalFields([], []);
            $this->fail('Should have thrown WpSendJsonException');
        } catch (WpSendJsonException $e) {
            $this->assertSame(200, $e->statusCode);
            return $e->data;
        }
    }

    public function testGlobalFieldsResponseHasDataWrapper(): void
    {
        $response = $this->captureGlobalFieldsResponse();
        $this->assertArrayHasKey('data', $response);
        $this->assertArrayHasKey('integration', $response['data']);
        $this->assertArrayHasKey('settings', $response['data']);
    }

    public function testGlobalFieldsContainsMenuTitle(): void
    {
        $response = $this->captureGlobalFieldsResponse();
        $fields = $response['data']['settings'];

        $this->assertArrayHasKey('menu_title', $fields);
        $this->assertNotEmpty($fields['menu_title']);
    }

    public function testGlobalFieldsContainsMenuDescription(): void
    {
        $response = $this->captureGlobalFieldsResponse();
        $fields = $response['data']['settings'];

        $this->assertArrayHasKey('menu_description', $fields);
        $this->assertNotEmpty($fields['menu_description']);
    }

    public function testGlobalFieldsContainsLogo(): void
    {
        $response = $this->captureGlobalFieldsResponse();
        $fields = $response['data']['settings'];

        $this->assertArrayHasKey('logo', $fields);
        $this->assertStringContainsString('fakturownia.webp', $fields['logo']);
    }

    public function testGlobalFieldsContainsAllExpectedFields(): void
    {
        $response = $this->captureGlobalFieldsResponse();
        $fieldKeys = array_keys($response['data']['settings']['fields']);
        $expected = ['domain', 'api_token', 'department_id', 'invoice_kind',
            'payment_type', 'invoice_lang', 'ksef_auto_send', 'show_nip_toggle'];

        foreach ($expected as $key) {
            $this->assertContains($key, $fieldKeys, "Missing field: $key");
        }
    }

    public function testGlobalFieldsHaveValidTypes(): void
    {
        $response = $this->captureGlobalFieldsResponse();
        $fields = $response['data']['settings']['fields'];

        $validTypes = ['text', 'password', 'select', 'link', 'authenticate-button'];
        foreach ($fields as $key => $field) {
            $this->assertContains(
                $field['type'],
                $validTypes,
                "Field '$key' has unsupported type '{$field['type']}'"
            );
        }
    }

    // ──────────────────────────────────────────────────────────
    // Settings → save → read roundtrip
    // ──────────────────────────────────────────────────────────

    public function testGlobalSettingsReturnsCurrentValues(): void
    {
        $this->setSettings([
            'domain'       => 'mojafirma',
            'invoice_kind' => 'proforma',
            'invoice_lang' => 'en',
        ]);

        $settings = FakturowniaSettings::getGlobalSettings([], []);

        $this->assertSame('mojafirma', $settings['domain']);
        $this->assertSame('proforma', $settings['invoice_kind']);
        $this->assertSame('en', $settings['invoice_lang']);
    }

    // ──────────────────────────────────────────────────────────
    // Select field options match save whitelist
    // ──────────────────────────────────────────────────────────

    public function testInvoiceKindOptionsMatchWhitelist(): void
    {
        $response = $this->captureGlobalFieldsResponse();
        $options = array_keys($response['data']['settings']['fields']['invoice_kind']['options']);

        $whitelist = ['vat', 'proforma', 'bill'];
        $this->assertSame($whitelist, $options);
    }

    public function testPaymentTypeOptionsMatchWhitelist(): void
    {
        $response = $this->captureGlobalFieldsResponse();
        $options = array_keys($response['data']['settings']['fields']['payment_type']['options']);

        $whitelist = ['transfer', 'card', 'cash', 'paypal'];
        $this->assertSame($whitelist, $options);
    }

    public function testInvoiceLangOptionsMatchWhitelist(): void
    {
        $response = $this->captureGlobalFieldsResponse();
        $options = array_keys($response['data']['settings']['fields']['invoice_lang']['options']);

        $whitelist = ['pl', 'en', 'de', 'fr', 'pl/en'];
        $this->assertSame($whitelist, $options);
    }
}

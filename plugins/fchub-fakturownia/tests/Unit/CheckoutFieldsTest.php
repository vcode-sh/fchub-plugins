<?php

declare(strict_types=1);

namespace FChubFakturownia\Tests\Unit;

use FChubFakturownia\Checkout\CheckoutFields;
use FChubFakturownia\Tests\PluginTestCase;

/**
 * Tests for CheckoutFields — covers BUG 16 (NIP validation wiring)
 * and the validateNip() checksum algorithm.
 */
final class CheckoutFieldsTest extends PluginTestCase
{
    // ──────────────────────────────────────────────────────────
    // NIP checksum validation
    // ──────────────────────────────────────────────────────────

    public function testValidNipNumbers(): void
    {
        // Verified valid NIPs (checksum matches last digit)
        $this->assertTrue(CheckoutFields::validateNip('5213017228')); // sum=118, mod=8, digit=8
        $this->assertTrue(CheckoutFields::validateNip('1234563218')); // sum=118, mod=8, digit=8
        $this->assertTrue(CheckoutFields::validateNip('7740001454')); // sum=169, mod=4, digit=4
        $this->assertTrue(CheckoutFields::validateNip('1060000062')); // sum=90,  mod=2, digit=2
    }

    public function testValidNipWithDashes(): void
    {
        $this->assertTrue(CheckoutFields::validateNip('521-301-72-28'));
    }

    public function testValidNipWithSpaces(): void
    {
        $this->assertTrue(CheckoutFields::validateNip('521 301 72 28'));
    }

    public function testInvalidNipChecksum(): void
    {
        $this->assertFalse(CheckoutFields::validateNip('5213017229')); // last digit wrong (9 vs 8)
        $this->assertFalse(CheckoutFields::validateNip('7681883286')); // checksum=5, digit=6
        $this->assertFalse(CheckoutFields::validateNip('1234567890'));
    }

    public function testTooShortNip(): void
    {
        $this->assertFalse(CheckoutFields::validateNip('12345'));
    }

    public function testTooLongNip(): void
    {
        $this->assertFalse(CheckoutFields::validateNip('12345678901'));
    }

    public function testEmptyNip(): void
    {
        $this->assertFalse(CheckoutFields::validateNip(''));
    }

    public function testNipWithLetters(): void
    {
        $this->assertFalse(CheckoutFields::validateNip('521ABC7228'));
    }

    public function testAllZerosNipIsValidByChecksum(): void
    {
        // 0*6+0*5+...+0*7 = 0, 0%11=0, last digit=0 → checksum passes
        // This is mathematically valid even though it's not a real NIP
        $this->assertTrue(CheckoutFields::validateNip('0000000000'));
    }

    // ──────────────────────────────────────────────────────────
    // BUG 16: Checkout validation wiring
    // ──────────────────────────────────────────────────────────

    public function testValidNipPassesCheckoutValidation(): void
    {
        $errors = null;
        $result = CheckoutFields::validateCheckoutNip($errors, [
            'billing_nip' => '5213017228',
        ]);

        $this->assertNull($result);
    }

    public function testInvalidNipFailsCheckoutValidation(): void
    {
        $errors = null;
        $result = CheckoutFields::validateCheckoutNip($errors, [
            'billing_nip' => '1234567890',
        ]);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $messages = $result->get_error_messages('billing_nip');
        $this->assertNotEmpty($messages);
        $this->assertStringContainsString('Invalid NIP', $messages[0]);
    }

    public function testEmptyNipSkipsValidation(): void
    {
        $errors = null;
        $result = CheckoutFields::validateCheckoutNip($errors, [
            'billing_nip' => '',
        ]);

        $this->assertNull($result);
    }

    public function testMissingNipFieldSkipsValidation(): void
    {
        $errors = null;
        $result = CheckoutFields::validateCheckoutNip($errors, []);

        $this->assertNull($result);
    }

    public function testInvalidNipAddsToExistingWpError(): void
    {
        $errors = new \WP_Error('some_field', 'Some other error');
        $result = CheckoutFields::validateCheckoutNip($errors, [
            'billing_nip' => '0000000001', // invalid checksum (mod=0, digit=1)
        ]);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $allMessages = $result->get_error_messages();
        $this->assertCount(2, $allMessages);
    }

    // ──────────────────────────────────────────────────────────
    // Registration wiring
    // ──────────────────────────────────────────────────────────

    public function testRegisterHooksValidationFilter(): void
    {
        $this->setSettings(['show_nip_toggle' => 'yes']);
        $GLOBALS['_fchub_test_filters'] = [];

        CheckoutFields::register();

        $registered = $GLOBALS['_fchub_test_filters'];
        $this->assertArrayHasKey('fluent_cart/checkout/validate_data', $registered);
    }

    public function testRegisterSkipsWhenDisabled(): void
    {
        $this->setSettings(['show_nip_toggle' => 'no']);
        $GLOBALS['_fchub_test_filters'] = [];

        CheckoutFields::register();

        $registered = $GLOBALS['_fchub_test_filters'];
        $this->assertEmpty($registered);
    }

    // ──────────────────────────────────────────────────────────
    // Adversarial NIP inputs
    // ──────────────────────────────────────────────────────────

    public function testNipWithSpecialCharacters(): void
    {
        $this->assertFalse(CheckoutFields::validateNip('521-30!-72-28'));
    }

    public function testNipWithUnicodeDigits(): void
    {
        // Full-width digits — preg_replace strips them, resulting in empty string
        $this->assertFalse(CheckoutFields::validateNip("\xEF\xBC\x95\xEF\xBC\x92"));
    }

    public function testNipWithSqlInjection(): void
    {
        $this->assertFalse(CheckoutFields::validateNip("'; DROP TABLE users; --"));
    }

    public function testNipWithNewlinesAndValidDigits(): void
    {
        // preg_replace strips \n, yielding '5213017228' which IS valid
        // This is correct behaviour — the NIP is valid after cleaning
        $this->assertTrue(CheckoutFields::validateNip("5213\n01\n7228"));
    }

    public function testNipMaxLengthInput(): void
    {
        $longNip = str_repeat('1', 10000);
        $this->assertFalse(CheckoutFields::validateNip($longNip));
    }

    // ──────────────────────────────────────────────────────────
    // Field schema
    // ──────────────────────────────────────────────────────────

    public function testNipFieldSchemaIsAdded(): void
    {
        $fields = CheckoutFields::addNipFields([]);

        $this->assertArrayHasKey('billing_nip', $fields);
        $this->assertSame('text', $fields['billing_nip']['type']);
        $this->assertSame('billing_nip', $fields['billing_nip']['name']);
        $this->assertSame('billing_nip', $fields['billing_nip']['id']);
    }

    public function testNipFieldDoesNotOverrideExistingFields(): void
    {
        $existing = [
            'first_name' => ['name' => 'first_name', 'type' => 'text'],
            'last_name'  => ['name' => 'last_name', 'type' => 'text'],
        ];

        $fields = CheckoutFields::addNipFields($existing);

        $this->assertCount(3, $fields);
        $this->assertArrayHasKey('first_name', $fields);
        $this->assertArrayHasKey('last_name', $fields);
        $this->assertArrayHasKey('billing_nip', $fields);
    }
}

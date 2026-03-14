<?php

declare(strict_types=1);

namespace FChubFakturownia\Tests\Unit;

use FChubFakturownia\Integration\FakturowniaSettings;
use FChubFakturownia\Tests\PluginTestCase;
use FChubFakturownia\Tests\WpSendJsonException;

/**
 * Tests for FakturowniaSettings — covers BUG 11 (select field validation)
 */
final class FakturowniaSettingsTest extends PluginTestCase
{
    // ──────────────────────────────────────────────────────────
    // BUG 11: Whitelist validation for select fields
    // ──────────────────────────────────────────────────────────

    public function testValidInvoiceKindAccepted(): void
    {
        $this->setSettings();

        foreach (['vat', 'proforma', 'bill'] as $kind) {
            $this->resetSettingsCache();
            try {
                FakturowniaSettings::saveGlobalSettings([
                    'integration' => [
                        'domain'       => 'test',
                        'api_token'    => 'token',
                        'invoice_kind' => $kind,
                    ],
                ]);
            } catch (WpSendJsonException $e) {
                // Expected — captures wp_send_json output
            }

            $saved = $GLOBALS['_fchub_test_options']['_integration_api_fakturownia'] ?? [];
            $this->assertSame($kind, $saved['invoice_kind'], "invoice_kind=$kind should be accepted");
        }
    }

    public function testInvalidInvoiceKindFallsBackToDefault(): void
    {
        $this->setSettings();

        try {
            FakturowniaSettings::saveGlobalSettings([
                'integration' => [
                    'domain'       => 'test',
                    'api_token'    => 'token',
                    'invoice_kind' => 'evil_injection',
                ],
            ]);
        } catch (WpSendJsonException $e) {
            // Expected
        }

        $saved = $GLOBALS['_fchub_test_options']['_integration_api_fakturownia'];
        $this->assertSame('vat', $saved['invoice_kind'], 'Invalid invoice_kind should fall back to vat');
    }

    public function testValidPaymentTypeAccepted(): void
    {
        $this->setSettings();

        foreach (['transfer', 'card', 'cash', 'paypal'] as $type) {
            $this->resetSettingsCache();
            try {
                FakturowniaSettings::saveGlobalSettings([
                    'integration' => [
                        'domain'       => 'test',
                        'api_token'    => 'token',
                        'payment_type' => $type,
                    ],
                ]);
            } catch (WpSendJsonException $e) {
                // Expected
            }

            $saved = $GLOBALS['_fchub_test_options']['_integration_api_fakturownia'];
            $this->assertSame($type, $saved['payment_type']);
        }
    }

    public function testInvalidPaymentTypeFallsBackToDefault(): void
    {
        $this->setSettings();

        try {
            FakturowniaSettings::saveGlobalSettings([
                'integration' => [
                    'domain'       => 'test',
                    'api_token'    => 'token',
                    'payment_type' => 'bitcoin',
                ],
            ]);
        } catch (WpSendJsonException $e) {
            // Expected
        }

        $saved = $GLOBALS['_fchub_test_options']['_integration_api_fakturownia'];
        $this->assertSame('transfer', $saved['payment_type']);
    }

    public function testValidInvoiceLangAccepted(): void
    {
        $this->setSettings();

        foreach (['pl', 'en', 'de', 'fr', 'pl/en'] as $lang) {
            $this->resetSettingsCache();
            try {
                FakturowniaSettings::saveGlobalSettings([
                    'integration' => [
                        'domain'       => 'test',
                        'api_token'    => 'token',
                        'invoice_lang' => $lang,
                    ],
                ]);
            } catch (WpSendJsonException $e) {
                // Expected
            }

            $saved = $GLOBALS['_fchub_test_options']['_integration_api_fakturownia'];
            $this->assertSame($lang, $saved['invoice_lang']);
        }
    }

    public function testInvalidInvoiceLangFallsBackToDefault(): void
    {
        $this->setSettings();

        try {
            FakturowniaSettings::saveGlobalSettings([
                'integration' => [
                    'domain'       => 'test',
                    'api_token'    => 'token',
                    'invoice_lang' => 'xx',
                ],
            ]);
        } catch (WpSendJsonException $e) {
            // Expected
        }

        $saved = $GLOBALS['_fchub_test_options']['_integration_api_fakturownia'];
        $this->assertSame('pl', $saved['invoice_lang']);
    }

    // ──────────────────────────────────────────────────────────
    // Boolean select fields (ksef_auto_send, show_nip_toggle)
    // ──────────────────────────────────────────────────────────

    public function testKsefAutoSendOnlyAcceptsYesNo(): void
    {
        $this->setSettings();

        try {
            FakturowniaSettings::saveGlobalSettings([
                'integration' => [
                    'domain'         => 'test',
                    'api_token'      => 'token',
                    'ksef_auto_send' => 'maybe',
                ],
            ]);
        } catch (WpSendJsonException $e) {
            // Expected
        }

        $saved = $GLOBALS['_fchub_test_options']['_integration_api_fakturownia'];
        $this->assertSame('no', $saved['ksef_auto_send'], 'Non-yes value should fall back to no');
    }

    public function testShowNipToggleOnlyAcceptsYesNo(): void
    {
        $this->setSettings();

        try {
            FakturowniaSettings::saveGlobalSettings([
                'integration' => [
                    'domain'          => 'test',
                    'api_token'       => 'token',
                    'show_nip_toggle' => 'always',
                ],
            ]);
        } catch (WpSendJsonException $e) {
            // Expected
        }

        $saved = $GLOBALS['_fchub_test_options']['_integration_api_fakturownia'];
        $this->assertSame('no', $saved['show_nip_toggle'], 'Non-yes value should fall back to no');
    }

    // ──────────────────────────────────────────────────────────
    // Settings retrieval
    // ──────────────────────────────────────────────────────────

    public function testDefaultSettings(): void
    {
        // No saved settings
        $settings = FakturowniaSettings::getSettings();

        $this->assertSame('', $settings['domain']);
        $this->assertSame('vat', $settings['invoice_kind']);
        $this->assertSame('transfer', $settings['payment_type']);
        $this->assertSame('pl', $settings['invoice_lang']);
        $this->assertSame('no', $settings['ksef_auto_send']);
        $this->assertSame('yes', $settings['show_nip_toggle']);
    }

    public function testIsConfiguredReturnsFalseWhenMissing(): void
    {
        $this->assertFalse(FakturowniaSettings::isConfigured());
    }

    public function testIsConfiguredReturnsTrueWhenComplete(): void
    {
        $this->setSettings();
        $this->assertTrue(FakturowniaSettings::isConfigured());
    }

    public function testSettingsCacheIsCleared(): void
    {
        $this->setSettings(['invoice_kind' => 'proforma']);
        $this->assertSame('proforma', FakturowniaSettings::getInvoiceKind());

        $this->setSettings(['invoice_kind' => 'vat']);
        $this->assertSame('vat', FakturowniaSettings::getInvoiceKind());
    }

    // ──────────────────────────────────────────────────────────
    // Adversarial: HTML/script injection in settings values
    // ──────────────────────────────────────────────────────────

    public function testHtmlInDomainIsSanitized(): void
    {
        $this->setSettings();

        try {
            FakturowniaSettings::saveGlobalSettings([
                'integration' => [
                    'domain'    => '<script>alert(1)</script>testfirma',
                    'api_token' => 'token',
                ],
            ]);
        } catch (WpSendJsonException $e) {
            // Expected
        }

        $saved = $GLOBALS['_fchub_test_options']['_integration_api_fakturownia'];
        $this->assertStringNotContainsString('<script>', $saved['domain']);
    }
}

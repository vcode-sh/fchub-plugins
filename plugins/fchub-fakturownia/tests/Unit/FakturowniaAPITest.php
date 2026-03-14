<?php

declare(strict_types=1);

namespace FChubFakturownia\Tests\Unit;

use FChubFakturownia\API\FakturowniaAPI;
use FChubFakturownia\Tests\PluginTestCase;

/**
 * Tests for FakturowniaAPI — covers BUG 14 (dead sendToKSeF removed),
 * URL construction, and error handling.
 */
final class FakturowniaAPITest extends PluginTestCase
{
    // ──────────────────────────────────────────────────────────
    // BUG 14: sendToKSeF method should not exist
    // ──────────────────────────────────────────────────────────

    public function testSendToKsefMethodDoesNotExist(): void
    {
        $this->assertFalse(
            method_exists(FakturowniaAPI::class, 'sendToKSeF'),
            'Dead sendToKSeF() method should have been removed'
        );
    }

    // ──────────────────────────────────────────────────────────
    // BUG 15: Reserved methods still exist
    // ──────────────────────────────────────────────────────────

    public function testFindClientByTaxNoStillExists(): void
    {
        $this->assertTrue(method_exists(FakturowniaAPI::class, 'findClientByTaxNo'));
    }

    public function testCreateClientStillExists(): void
    {
        $this->assertTrue(method_exists(FakturowniaAPI::class, 'createClient'));
    }

    // ──────────────────────────────────────────────────────────
    // Base URL construction
    // ──────────────────────────────────────────────────────────

    public function testBaseUrlFromSubdomain(): void
    {
        $api = new FakturowniaAPI('mojafirma', 'token');
        $this->assertSame('https://mojafirma.fakturownia.pl', $api->getBaseUrl());
    }

    public function testBaseUrlFromFullDomain(): void
    {
        $api = new FakturowniaAPI('mojafirma.fakturownia.pl', 'token');
        $this->assertSame('https://mojafirma.fakturownia.pl', $api->getBaseUrl());
    }

    public function testBaseUrlRejectsCustomDomain(): void
    {
        // SSRF prevention: non-fakturownia domains are rejected
        $api = new FakturowniaAPI('invoices.mycompany.com', 'token');
        $this->assertSame('https://invalid.fakturownia.pl', $api->getBaseUrl());
    }

    public function testBaseUrlRejectsInternalIp(): void
    {
        $api = new FakturowniaAPI('169.254.169.254', 'token');
        $this->assertSame('https://invalid.fakturownia.pl', $api->getBaseUrl());
    }

    public function testBaseUrlRejectsAttackerDomain(): void
    {
        $api = new FakturowniaAPI('evil.attacker.com', 'token');
        $this->assertSame('https://invalid.fakturownia.pl', $api->getBaseUrl());
    }

    public function testBaseUrlAllowsFakturowniaFullDomain(): void
    {
        $api = new FakturowniaAPI('mojafirma.fakturownia.pl', 'token');
        $this->assertSame('https://mojafirma.fakturownia.pl', $api->getBaseUrl());
    }

    public function testBaseUrlStripsTrailingSlash(): void
    {
        // rtrim in constructor removes trailing slash → clean subdomain
        $api = new FakturowniaAPI('mojafirma/', 'token');
        $this->assertSame('https://mojafirma.fakturownia.pl', $api->getBaseUrl());
    }

    // ──────────────────────────────────────────────────────────
    // API request handling
    // ──────────────────────────────────────────────────────────

    public function testCreateInvoiceSendsCorrectPayload(): void
    {
        $capturedArgs = null;
        $this->mockApiHandler(function ($method, $url, $args) use (&$capturedArgs) {
            $capturedArgs = ['method' => $method, 'url' => $url, 'body' => json_decode($args['body'] ?? '{}', true)];
            return [
                'response' => ['code' => 200],
                'body'     => json_encode(['id' => 1, 'number' => 'FV 1']),
                'headers'  => [],
            ];
        });

        $api = new FakturowniaAPI('test', 'my-token');
        $api->createInvoice(['kind' => 'vat', 'buyer_name' => 'Test']);

        $this->assertSame('POST', $capturedArgs['method']);
        $this->assertStringContainsString('/invoices.json', $capturedArgs['url']);
        $this->assertSame('my-token', $capturedArgs['body']['api_token']);
        $this->assertSame('vat', $capturedArgs['body']['invoice']['kind']);
    }

    public function testCreateInvoiceWithKsefSendsGovFlag(): void
    {
        $capturedBody = null;
        $this->mockApiHandler(function ($method, $url, $args) use (&$capturedBody) {
            $capturedBody = json_decode($args['body'] ?? '{}', true);
            return [
                'response' => ['code' => 200],
                'body'     => json_encode(['id' => 1]),
                'headers'  => [],
            ];
        });

        $api = new FakturowniaAPI('test', 'token');
        $api->createInvoiceWithKSeF(['kind' => 'vat']);

        $this->assertTrue($capturedBody['gov_save_and_send']);
    }

    public function testGetInvoiceUsesGet(): void
    {
        $capturedMethod = null;
        $this->mockApiHandler(function ($method, $url) use (&$capturedMethod) {
            $capturedMethod = $method;
            return [
                'response' => ['code' => 200],
                'body'     => json_encode(['id' => 42]),
                'headers'  => [],
            ];
        });

        $api = new FakturowniaAPI('test', 'token');
        $api->getInvoice(42);

        $this->assertSame('GET', $capturedMethod);
    }

    public function testApiErrorReturnsErrorArray(): void
    {
        $this->mockApiResponse(
            ['code' => 'error', 'message' => 'Buyer name is required'],
            422
        );

        $api = new FakturowniaAPI('test', 'token');
        $result = $api->createInvoice([]);

        $this->assertArrayHasKey('error', $result);
        $this->assertSame(422, $result['code']);
    }

    public function testNetworkErrorReturnsErrorArray(): void
    {
        $this->mockApiHandler(function () {
            return new \WP_Error('http_error', 'Connection timed out');
        });

        $api = new FakturowniaAPI('test', 'token');
        $result = $api->createInvoice([]);

        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('Connection timed out', $result['error']);
    }

    public function testValidationErrorsFormatted(): void
    {
        $this->mockApiResponse(
            ['code' => 'error', 'message' => ['buyer_name' => ['is required'], 'positions' => ['is empty']]],
            422
        );

        $api = new FakturowniaAPI('test', 'token');
        $result = $api->createInvoice([]);

        $this->assertStringContainsString('buyer_name: is required', $result['error']);
        $this->assertStringContainsString('positions: is empty', $result['error']);
    }

    // ──────────────────────────────────────────────────────────
    // PDF download
    // ──────────────────────────────────────────────────────────

    public function testDownloadPdfReturnsBody(): void
    {
        $this->mockApiHandler(function ($method, $url) {
            return [
                'response' => ['code' => 200],
                'body'     => '%PDF-1.4 fake pdf content',
                'headers'  => ['content-type' => 'application/pdf'],
            ];
        });

        $api = new FakturowniaAPI('test', 'token');
        $result = $api->downloadInvoicePdf(42);

        $this->assertArrayNotHasKey('error', $result);
        $this->assertStringStartsWith('%PDF', $result['body']);
        $this->assertSame('application/pdf', $result['content_type']);
    }

    public function testDownloadPdf404ReturnsError(): void
    {
        $this->mockApiHandler(function () {
            return [
                'response' => ['code' => 404],
                'body'     => '',
                'headers'  => [],
            ];
        });

        $api = new FakturowniaAPI('test', 'token');
        $result = $api->downloadInvoicePdf(999);

        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('404', $result['error']);
    }

    // ──────────────────────────────────────────────────────────
    // Correction invoice
    // ──────────────────────────────────────────────────────────

    public function testCorrectionInvoiceIncludesKsefFlag(): void
    {
        $capturedBody = null;
        $this->mockApiHandler(function ($method, $url, $args) use (&$capturedBody) {
            if ($method === 'POST') {
                $capturedBody = json_decode($args['body'], true);
            }
            return [
                'response' => ['code' => 200],
                'body'     => json_encode(['id' => 200]),
                'headers'  => [],
            ];
        });

        $api = new FakturowniaAPI('test', 'token');
        $api->createCorrection(100, 'Refund', [], true);

        $this->assertTrue($capturedBody['gov_save_and_send']);
        $this->assertSame('correction', $capturedBody['invoice']['kind']);
        $this->assertSame('100', $capturedBody['invoice']['invoice_id']);
    }

    public function testCorrectionInvoiceWithoutKsef(): void
    {
        $capturedBody = null;
        $this->mockApiHandler(function ($method, $url, $args) use (&$capturedBody) {
            if ($method === 'POST') {
                $capturedBody = json_decode($args['body'], true);
            }
            return [
                'response' => ['code' => 200],
                'body'     => json_encode(['id' => 200]),
                'headers'  => [],
            ];
        });

        $api = new FakturowniaAPI('test', 'token');
        $api->createCorrection(100, 'Refund', [], false);

        $this->assertArrayNotHasKey('gov_save_and_send', $capturedBody);
    }
}

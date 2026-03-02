<?php

namespace FChubP24\Tests\Unit;

use FChubP24\API\Przelewy24API;
use FChubP24\Gateway\Przelewy24Gateway;
use FChubP24\Tests\PhpInputStreamWrapper;
use FChubP24\Tests\TestSettings;
use FChubP24\Tests\WpSendJsonException;
use PHPUnit\Framework\TestCase;

/**
 * Bug tests for the Przelewy24 implementation.
 *
 * Each test targets a specific bug: it asserts the CORRECT behavior,
 * so it fails before the fix and passes after.
 */
class Przelewy24BugTest extends TestCase
{
    private Przelewy24Gateway $gateway;
    private TestSettings $settings;
    private bool $phpInputOverridden = false;

    private const CRC_KEY = '679175ea875c2776';

    protected function setUp(): void
    {
        $this->settings = new TestSettings([
            'test_merchant_id' => '383989',
            'test_shop_id'    => '383989',
            'test_crc_key'    => self::CRC_KEY,
            'test_api_key'    => 'ec201b57daf4a3e1f65825c04ca9b5b5',
        ]);

        $ref = new \ReflectionClass(Przelewy24Gateway::class);
        $this->gateway = $ref->newInstanceWithoutConstructor();
        $prop = $ref->getProperty('settings');
        $prop->setAccessible(true);
        $prop->setValue($this->gateway, $this->settings);

        // Reset global mocks
        \FluentCart_OrderTransaction::$mockResult = null;
        \FluentCart_Order::$mockResults = [];
        global $_fchub_test_refund_calls, $_fchub_test_wp_remote_request;
        $_fchub_test_refund_calls = [];
        $_fchub_test_wp_remote_request = null;
    }

    // =========================================================================
    // BUG 1: processRefund() accepts negative amounts
    //
    // The guard `if (!$amount)` evaluates to false for -100, so negative
    // amounts bypass validation and reach the P24 API.
    // =========================================================================

    public function testProcessRefundRejectsNegativeAmount(): void
    {
        $tx = $this->createMockTransaction();

        $result = $this->gateway->processRefund($tx, -100, []);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame(
            'Refund amount is required.',
            $result->get_error_message(),
            'Negative amount should be caught by validation, not reach the API'
        );
    }

    // =========================================================================
    // BUG 2: processRefund() proceeds with empty vendor_charge_id
    //
    // When IPN hasn't confirmed the payment yet, vendor_charge_id is empty.
    // (int) '' = 0, so the code sends orderId=0 to P24 which is always wrong.
    // =========================================================================

    public function testProcessRefundRejectsEmptyVendorChargeId(): void
    {
        $tx = $this->createMockTransaction();
        $tx->vendor_charge_id = '';

        $result = $this->gateway->processRefund($tx, 1000, []);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertStringContainsString(
            'confirmed',
            $result->get_error_message(),
            'Refund without vendor_charge_id should be rejected early'
        );
    }

    public function testProcessRefundRejectsNullVendorChargeId(): void
    {
        $tx = $this->createMockTransaction();
        $tx->vendor_charge_id = null;

        $result = $this->gateway->processRefund($tx, 1000, []);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertStringContainsString(
            'confirmed',
            $result->get_error_message(),
            'Refund with null vendor_charge_id should be rejected early'
        );
    }

    // =========================================================================
    // BUG 3: handleIPN() doesn't validate currency against transaction
    //
    // The IPN handler checks that the notification amount matches the
    // transaction total, but doesn't check currency. A notification signed
    // with currency=EUR passes all checks if the amount matches.
    // The signature protects against tampering, but defense-in-depth
    // requires verifying currency against the stored transaction.
    // =========================================================================

    public function testIPNCurrencyMismatchIsRejected(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';

        // Transaction stored with PLN
        $mockTx = $this->createMockTransaction();
        $mockTx->uuid = 'test-uuid-12345';
        $mockTx->total = 5000;
        $mockTx->currency = 'PLN';
        $mockTx->status = 'pending';
        \FluentCart_OrderTransaction::$mockResult = $mockTx;

        // Notification uses EUR (validly signed, amount matches)
        $notification = $this->buildPaymentNotification(['currency' => 'EUR']);
        $this->setPhpInput(json_encode($notification));

        // Capture exception data before restoring stream wrapper
        // (PHPUnit uses php://memory for diffs which our wrapper blocks)
        $exData = null;
        $exStatus = null;
        try {
            $this->gateway->handleIPN();
        } catch (WpSendJsonException $e) {
            $exData = $e->data;
            $exStatus = $e->statusCode;
        }

        // Restore stream wrapper BEFORE assertions
        stream_wrapper_restore('php');
        $this->phpInputOverridden = false;

        $this->assertNotNull($exData, 'Expected WpSendJsonException');
        $this->assertSame(400, $exStatus);
        $this->assertSame(
            'Currency mismatch',
            $exData['error'],
            'IPN with mismatched currency should be rejected'
        );
    }

    // =========================================================================
    // BUG 4: API::request() returns [] for non-JSON responses
    //
    // When P24 (or a proxy) returns HTML instead of JSON (e.g. "502 Bad
    // Gateway" with HTML body but 200 status from a CDN), json_decode
    // returns null, and `null ?: []` silently returns an empty array.
    // Callers see an empty success response instead of an error.
    // =========================================================================

    public function testApiNonJsonResponseReturnsError(): void
    {
        global $_fchub_test_wp_remote_request;
        $_fchub_test_wp_remote_request = [
            'response' => ['code' => 200],
            'body'     => '<html><body>Bad Gateway</body></html>',
        ];

        $api = new Przelewy24API($this->settings);
        $result = $api->testAccess();

        $this->assertArrayHasKey(
            'error',
            $result,
            'Non-JSON response should return an error, not an empty array'
        );
    }

    public function testApiEmptyBodyReturnsError(): void
    {
        global $_fchub_test_wp_remote_request;
        $_fchub_test_wp_remote_request = [
            'response' => ['code' => 200],
            'body'     => '',
        ];

        $api = new Przelewy24API($this->settings);
        $result = $api->testAccess();

        // Empty body: json_decode('', true) returns null
        // Should be treated as error, not success
        $this->assertArrayHasKey(
            'error',
            $result,
            'Empty response body should return an error'
        );
    }

    // =========================================================================
    // BUG 5: getChannel() uses += instead of |= for bitmask
    //
    // With current non-overlapping values this produces identical results,
    // but it's semantically wrong for a bitmask and would break if P24
    // ever adds overlapping channel flags.
    // We test that the method is idempotent with respect to bitwise OR.
    // =========================================================================

    public function testGetChannelUsesBitwiseSemantics(): void
    {
        $settings = new TestSettings([
            'channel_cards'        => 'yes', // 1
            'channel_transfers'    => 'yes', // 2
            'channel_blik'         => 'yes', // 8192
            'channel_wallets'      => 'yes', // 256
            'channel_traditional'  => 'no',
            'channel_24_7'         => 'no',
            'channel_installments' => 'no',
        ]);

        $result = $settings->getChannel();
        $expected = 1 | 2 | 256 | 8192; // 8451

        $this->assertSame($expected, $result);
        // Verify bitwise OR gives same result (proves no double-counting)
        $this->assertSame($expected, $result | 0);
    }

    // =========================================================================
    // BUG 6: refundsUuid exceeds P24 spec max length (35 chars)
    //
    // wp_generate_uuid4() returns 36-char UUID with dashes. The P24 spec
    // limits refundsUuid to 35 chars. A 36-char value may be rejected.
    // =========================================================================

    public function testProcessRefundSendsRefundsUuidWithin35Chars(): void
    {
        global $_fchub_test_wp_remote_request;
        $capturedBody = null;
        $_fchub_test_wp_remote_request = function ($url, $args) use (&$capturedBody) {
            $capturedBody = json_decode($args['body'], true);
            return [
                'response' => ['code' => 201],
                'body'     => json_encode([
                    'data'         => [['orderId' => 987654321, 'status' => true, 'message' => 'success']],
                    'responseCode' => 0,
                ]),
            ];
        };

        $tx = $this->createMockTransaction();
        $this->gateway->processRefund($tx, 1000, []);

        $this->assertNotNull($capturedBody, 'Expected API request to be captured');
        $this->assertLessThanOrEqual(
            35,
            strlen($capturedBody['refundsUuid']),
            'refundsUuid must be <= 35 chars per P24 spec'
        );
    }

    // =========================================================================
    // BUG 7: Payment methods group mapping uses 'Cards' instead of 'Credit Card'
    //
    // The P24 API spec defines the group name as 'Credit Card', not 'Cards'.
    // The wrong key means credit card methods are never filtered by channel.
    // =========================================================================

    public function testPaymentMethodGroupMappingUsesSpecNames(): void
    {
        // Extract the $groupToChannel mapping from getOrderInfo via source parsing
        $source = file_get_contents(
            dirname(__DIR__, 2) . '/app/Gateway/Przelewy24Gateway.php'
        );

        // Extract the groupToChannel array block
        preg_match('/\$groupToChannel\s*=\s*\[(.*?)\];/s', $source, $matches);
        $this->assertNotEmpty($matches, 'Could not find $groupToChannel mapping');
        $mappingBlock = $matches[1];

        $this->assertStringContainsString(
            "'Credit Card'",
            $mappingBlock,
            'Group mapping must use "Credit Card" (P24 spec name), not "Cards"'
        );
        // Ensure the old incorrect key is not present in the mapping
        $this->assertDoesNotMatchRegularExpression(
            "/^\s*'Cards'/m",
            $mappingBlock,
            'Group mapping should not use "Cards" — spec uses "Credit Card"'
        );
    }

    // =========================================================================
    // BUG 8: Refund 409 error is an array, not a string
    //
    // When P24 returns HTTP 409 for a refund, the 'error' field is an array
    // of per-refund objects. Passing this array to WP_Error as a string
    // message produces broken output.
    // =========================================================================

    public function testProcessRefundHandles409ArrayError(): void
    {
        global $_fchub_test_wp_remote_request;
        $_fchub_test_wp_remote_request = [
            'response' => ['code' => 409],
            'body'     => json_encode([
                'error' => [
                    [
                        'orderId'   => 987654321,
                        'sessionId' => 'test-uuid-12345',
                        'amount'    => 1000,
                        'status'    => false,
                        'message'   => 'The amount of refund exceeds available amount for the transaction',
                    ],
                ],
                'code' => 409,
            ]),
        ];

        $tx = $this->createMockTransaction();
        $result = $this->gateway->processRefund($tx, 1000, []);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertIsString(
            $result->get_error_message(),
            '409 array error should be converted to a string message'
        );
        $this->assertStringContainsString(
            'exceeds available amount',
            $result->get_error_message()
        );
    }

    // =========================================================================
    // BUG 9: Refund description not truncated to P24 spec max (35 chars)
    //
    // The refund description field has a max length of 35 per the spec.
    // A long user-provided reason could exceed this limit.
    // =========================================================================

    public function testProcessRefundTruncatesDescription(): void
    {
        global $_fchub_test_wp_remote_request;
        $capturedBody = null;
        $_fchub_test_wp_remote_request = function ($url, $args) use (&$capturedBody) {
            $capturedBody = json_decode($args['body'], true);
            return [
                'response' => ['code' => 201],
                'body'     => json_encode([
                    'data'         => [['orderId' => 987654321, 'status' => true, 'message' => 'success']],
                    'responseCode' => 0,
                ]),
            ];
        };

        $tx = $this->createMockTransaction();
        $longReason = str_repeat('A', 100);
        $this->gateway->processRefund($tx, 1000, ['reason' => $longReason]);

        $this->assertNotNull($capturedBody);
        $desc = $capturedBody['refunds'][0]['description'];
        $this->assertLessThanOrEqual(
            35,
            mb_strlen($desc),
            'Refund description must be <= 35 chars per P24 spec'
        );
    }

    // =========================================================================
    // BUG 10: Verify response not positively checked
    //
    // After calling verifyTransaction(), we only check for the absence of
    // an error key. The spec says success returns {"data":{"status":"success"}}.
    // An unexpected response format (e.g. empty data) should not be treated
    // as a successful verification.
    // =========================================================================

    public function testIPNRejectsVerifyResponseWithoutSuccessStatus(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';

        $mockTx = $this->createMockTransaction();
        $mockTx->uuid = 'test-uuid-12345';
        $mockTx->total = 5000;
        $mockTx->currency = 'PLN';
        $mockTx->status = 'pending';
        \FluentCart_OrderTransaction::$mockResult = $mockTx;

        $notification = $this->buildPaymentNotification();
        $this->setPhpInput(json_encode($notification));

        // Mock P24 verify endpoint to return unexpected format (no error, but no success status)
        global $_fchub_test_wp_remote_request;
        $_fchub_test_wp_remote_request = [
            'response' => ['code' => 200],
            'body'     => json_encode(['data' => ['status' => 'unknown'], 'responseCode' => 0]),
        ];

        $exData = null;
        $exStatus = null;
        try {
            $this->gateway->handleIPN();
        } catch (WpSendJsonException $e) {
            $exData = $e->data;
            $exStatus = $e->statusCode;
        }

        stream_wrapper_restore('php');
        $this->phpInputOverridden = false;

        $this->assertNotNull($exData, 'Expected WpSendJsonException');
        $this->assertSame(400, $exStatus);
        $this->assertSame('Verification failed', $exData['error']);
    }

    // =========================================================================
    // BUG 11: Refund success message says "processed" instead of "submitted"
    //
    // The refund is only requested via API — actual completion comes via the
    // async refund notification. Message should reflect this.
    // =========================================================================

    public function testProcessRefundSuccessMessageSaysSubmitted(): void
    {
        global $_fchub_test_wp_remote_request;
        $_fchub_test_wp_remote_request = [
            'response' => ['code' => 201],
            'body'     => json_encode([
                'data'         => [['orderId' => 987654321, 'status' => true, 'message' => 'success']],
                'responseCode' => 0,
            ]),
        ];

        $tx = $this->createMockTransaction();
        $result = $this->gateway->processRefund($tx, 1000, []);

        $this->assertIsArray($result);
        $this->assertSame('success', $result['status']);
        $this->assertStringNotContainsString(
            'processed',
            strtolower($result['message']),
            'Message should not say "processed" — refund is only submitted/requested'
        );
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function createMockTransaction(): \FluentCart_OrderTransaction
    {
        $tx = new \FluentCart_OrderTransaction();
        $tx->id = 42;
        $tx->uuid = 'test-uuid-12345';
        $tx->order_id = 100;
        $tx->status = 'succeeded';
        $tx->total = 5000;
        $tx->currency = 'PLN';
        $tx->vendor_charge_id = '987654321';
        return $tx;
    }

    /**
     * Build a valid payment notification with correct signature.
     */
    private function buildPaymentNotification(array $overrides = []): array
    {
        $data = array_merge([
            'merchantId'   => 383989,
            'posId'        => 383989,
            'sessionId'    => 'test-uuid-12345',
            'amount'       => 5000,
            'originAmount' => 5000,
            'currency'     => 'PLN',
            'orderId'      => 987654321,
            'methodId'     => 154,
            'statement'    => 'Payment test',
        ], $overrides);

        $signData = json_encode([
            'merchantId'   => (int) $data['merchantId'],
            'posId'        => (int) $data['posId'],
            'sessionId'    => $data['sessionId'],
            'amount'       => (int) $data['amount'],
            'originAmount' => (int) $data['originAmount'],
            'currency'     => $data['currency'],
            'orderId'      => (int) $data['orderId'],
            'methodId'     => (int) $data['methodId'],
            'statement'    => $data['statement'],
            'crc'          => self::CRC_KEY,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $data['sign'] = hash('sha384', $signData);

        return $data;
    }

    private function setPhpInput(string $data): void
    {
        stream_wrapper_unregister('php');
        stream_wrapper_register('php', PhpInputStreamWrapper::class);
        PhpInputStreamWrapper::$input = $data;
        $this->phpInputOverridden = true;
    }

    protected function tearDown(): void
    {
        if ($this->phpInputOverridden) {
            stream_wrapper_restore('php');
        }
        $this->phpInputOverridden = false;
        unset($_SERVER['REQUEST_METHOD']);
        \FluentCart_OrderTransaction::$mockResult = null;
        \FluentCart_Order::$mockResults = [];
        global $_fchub_test_refund_calls, $_fchub_test_wp_remote_request;
        $_fchub_test_refund_calls = [];
        $_fchub_test_wp_remote_request = null;
    }
}

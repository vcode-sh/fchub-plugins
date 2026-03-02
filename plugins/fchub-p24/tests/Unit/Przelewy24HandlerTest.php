<?php

namespace FChubP24\Tests\Unit;

use FChubP24\Gateway\Przelewy24Handler;
use PHPUnit\Framework\TestCase;

/**
 * Tests for Przelewy24Handler helper methods
 * (handlePayment itself requires too many FluentCart dependencies for unit tests)
 */
class Przelewy24HandlerTest extends TestCase
{
    /**
     * Test resolveLanguage returns supported P24 language
     */
    public function testResolveLanguagePolish(): void
    {
        $handler = $this->createHandlerWithReflection();
        $method = new \ReflectionMethod($handler, 'resolveLanguage');

        // get_locale() returns 'pl_PL' by default in our bootstrap
        $result = $method->invoke($handler);
        $this->assertSame('pl', $result);
    }

    /**
     * Test resolveCountry with billing address
     */
    public function testResolveCountryFromBillingAddress(): void
    {
        $handler = $this->createHandlerWithReflection();
        $method = new \ReflectionMethod($handler, 'resolveCountry');

        $address = (object) ['country' => 'DE'];
        $this->assertSame('DE', $method->invoke($handler, $address));
    }

    /**
     * Test resolveCountry fallback to PL
     */
    public function testResolveCountryFallback(): void
    {
        $handler = $this->createHandlerWithReflection();
        $method = new \ReflectionMethod($handler, 'resolveCountry');

        $this->assertSame('PL', $method->invoke($handler, null));

        $emptyAddress = (object) ['country' => ''];
        $this->assertSame('PL', $method->invoke($handler, $emptyAddress));
    }

    /**
     * Test resolvePayerEmail with valid customer email
     */
    public function testResolvePayerEmailFromCustomer(): void
    {
        $handler = $this->createHandlerWithReflection();
        $method = new \ReflectionMethod($handler, 'resolvePayerEmail');

        $order = (object) [
            'customer' => (object) ['email' => 'test@example.com'],
            'billing_address' => null,
            'shipping_address' => null,
        ];

        $this->assertSame('test@example.com', $method->invoke($handler, $order));
    }

    /**
     * Test resolvePayerEmail returns empty for invalid email
     */
    public function testResolvePayerEmailInvalid(): void
    {
        $handler = $this->createHandlerWithReflection();
        $method = new \ReflectionMethod($handler, 'resolvePayerEmail');

        $order = (object) [
            'customer' => (object) ['email' => 'not-an-email'],
            'billing_address' => null,
            'shipping_address' => null,
        ];

        $this->assertSame('', $method->invoke($handler, $order));
    }

    /**
     * Test resolvePayerEmail returns empty when no customer
     */
    public function testResolvePayerEmailNoCustomer(): void
    {
        $handler = $this->createHandlerWithReflection();
        $method = new \ReflectionMethod($handler, 'resolvePayerEmail');

        $order = (object) [
            'customer' => null,
            'billing_address' => null,
            'shipping_address' => null,
        ];

        $this->assertSame('', $method->invoke($handler, $order));
    }

    /**
     * Test buildCartItems with order items
     */
    public function testBuildCartItems(): void
    {
        $handler = $this->createHandlerWithReflection();
        $method = new \ReflectionMethod($handler, 'buildCartItems');

        $itemsArray = [
            (object) [
                'post_title' => 'Test Product',
                'title' => '',
                'quantity' => 2,
                'line_total' => 5000,
                'id' => 42,
            ],
        ];

        $collection = new class($itemsArray) implements \IteratorAggregate {
            private array $items;
            public function __construct(array $items) { $this->items = $items; }
            public function isEmpty(): bool { return count($this->items) === 0; }
            public function getIterator(): \ArrayIterator { return new \ArrayIterator($this->items); }
        };

        $order = (object) ['order_items' => $collection];

        $result = $method->invoke($handler, $order);

        $this->assertCount(1, $result);
        $this->assertSame('Test Product', $result[0]['name']);
        $this->assertSame(2, $result[0]['quantity']);
        // P24 expects unit price: line_total(5000) / quantity(2) = 2500
        $this->assertSame(2500, $result[0]['price']);
        $this->assertSame('42', $result[0]['number']);
    }

    /**
     * Test buildCartItems with empty items
     */
    public function testBuildCartItemsEmpty(): void
    {
        $handler = $this->createHandlerWithReflection();
        $method = new \ReflectionMethod($handler, 'buildCartItems');

        $order = (object) ['order_items' => null];
        $this->assertSame([], $method->invoke($handler, $order));
    }

    /**
     * Test buildDescription with order items
     */
    public function testBuildDescriptionWithItems(): void
    {
        $handler = $this->createHandlerWithReflection();
        $method = new \ReflectionMethod($handler, 'buildDescription');

        $itemsArray = [
            (object) ['post_title' => 'Widget', 'title' => '', 'quantity' => 2],
            (object) ['post_title' => 'Gadget', 'title' => '', 'quantity' => 1],
        ];

        $collection = new class($itemsArray) implements \IteratorAggregate {
            private array $items;
            public function __construct(array $items) { $this->items = $items; }
            public function isEmpty(): bool { return count($this->items) === 0; }
            public function getIterator(): \ArrayIterator { return new \ArrayIterator($this->items); }
        };

        $order = (object) [
            'invoice_no' => '123',
            'id' => 1,
            'order_items' => $collection,
        ];

        $result = $method->invoke($handler, $order);
        $this->assertSame('Order #123 | Widget x2, Gadget x1', $result);
    }

    /**
     * Test buildDescription without order items
     */
    public function testBuildDescriptionWithoutItems(): void
    {
        $handler = $this->createHandlerWithReflection();
        $method = new \ReflectionMethod($handler, 'buildDescription');

        $order = (object) [
            'invoice_no' => '123',
            'id' => 1,
            'order_items' => null,
        ];

        $result = $method->invoke($handler, $order);
        $this->assertSame('Order #123', $result);
    }

    /**
     * Test buildDescription truncates at 1024 chars
     */
    public function testBuildDescriptionTruncation(): void
    {
        $handler = $this->createHandlerWithReflection();
        $method = new \ReflectionMethod($handler, 'buildDescription');

        $longName = str_repeat('A', 1100);
        $itemsArray = [
            (object) ['post_title' => $longName, 'title' => '', 'quantity' => 1],
        ];

        $collection = new class($itemsArray) implements \IteratorAggregate {
            private array $items;
            public function __construct(array $items) { $this->items = $items; }
            public function isEmpty(): bool { return count($this->items) === 0; }
            public function getIterator(): \ArrayIterator { return new \ArrayIterator($this->items); }
        };

        $order = (object) [
            'invoice_no' => '1',
            'id' => 1,
            'order_items' => $collection,
        ];

        $result = $method->invoke($handler, $order);
        $this->assertSame(1024, mb_strlen($result));
    }

    /**
     * Test buildTransferLabel basic format
     */
    public function testBuildTransferLabelBasic(): void
    {
        $handler = $this->createHandlerWithReflection();
        $method = new \ReflectionMethod($handler, 'buildTransferLabel');

        $order = (object) ['invoice_no' => 'INV-001', 'id' => 1];
        $result = $method->invoke($handler, $order);
        $this->assertSame('Zam INV-001', $result);
    }

    /**
     * Test buildTransferLabel strips special characters
     */
    public function testBuildTransferLabelStripsSpecial(): void
    {
        $handler = $this->createHandlerWithReflection();
        $method = new \ReflectionMethod($handler, 'buildTransferLabel');

        $order = (object) ['invoice_no' => 'INV#2024@001!', 'id' => 1];
        $result = $method->invoke($handler, $order);
        $this->assertSame('Zam INV2024001', $result);
    }

    /**
     * Test buildTransferLabel preserves Polish characters
     */
    public function testBuildTransferLabelPolishChars(): void
    {
        $handler = $this->createHandlerWithReflection();
        $method = new \ReflectionMethod($handler, 'buildTransferLabel');

        $order = (object) ['invoice_no' => 'Żółć123', 'id' => 1];
        $result = $method->invoke($handler, $order);
        $this->assertSame('Zam Żółć123', $result);
    }

    /**
     * Test buildTransferLabel truncates to 20 chars
     */
    public function testBuildTransferLabelMaxLength(): void
    {
        $handler = $this->createHandlerWithReflection();
        $method = new \ReflectionMethod($handler, 'buildTransferLabel');

        $order = (object) ['invoice_no' => 'VERY-LONG-INVOICE-NUMBER-12345', 'id' => 1];
        $result = $method->invoke($handler, $order);
        $this->assertSame(20, mb_strlen($result));
    }

    /**
     * Test buildCustomerParams with complete billing address
     */
    public function testBuildCustomerParamsComplete(): void
    {
        $handler = $this->createHandlerWithReflection();
        $method = new \ReflectionMethod($handler, 'buildCustomerParams');

        $order = (object) [
            'billing_address' => (object) [
                'first_name' => 'Jan',
                'last_name' => 'Kowalski',
                'address_line_1' => 'ul. Testowa 1',
                'address_line_2' => 'm. 5',
                'zip' => '00-001',
                'city' => 'Warszawa',
                'phone' => '+48 123 456 789',
            ],
            'customer' => null,
        ];

        $result = $method->invoke($handler, $order);
        $this->assertSame('Jan Kowalski', $result['client']);
        $this->assertSame('ul. Testowa 1 m. 5', $result['address']);
        $this->assertSame('00-001', $result['zip']);
        $this->assertSame('Warszawa', $result['city']);
        $this->assertSame('48123456789', $result['phone']);
    }

    /**
     * Test buildCustomerParams with no billing address
     */
    public function testBuildCustomerParamsEmpty(): void
    {
        $handler = $this->createHandlerWithReflection();
        $method = new \ReflectionMethod($handler, 'buildCustomerParams');

        $order = (object) [
            'billing_address' => null,
            'customer' => null,
        ];

        $result = $method->invoke($handler, $order);
        $this->assertSame([], $result);
    }

    /**
     * Test buildCustomerParams strips non-digits from phone
     */
    public function testBuildCustomerParamsPhoneDigitsOnly(): void
    {
        $handler = $this->createHandlerWithReflection();
        $method = new \ReflectionMethod($handler, 'buildCustomerParams');

        $order = (object) [
            'billing_address' => (object) [
                'first_name' => '',
                'last_name' => '',
                'address_line_1' => '',
                'address_line_2' => '',
                'zip' => '',
                'city' => '',
                'phone' => '+48 (123) 456-789',
            ],
            'customer' => null,
        ];

        $result = $method->invoke($handler, $order);
        $this->assertSame('48123456789', $result['phone']);
        $this->assertArrayNotHasKey('client', $result);
    }

    /**
     * Test buildPsuData with REMOTE_ADDR only
     */
    public function testBuildPsuDataWithRemoteAddr(): void
    {
        $handler = $this->createHandlerWithReflection();
        $method = new \ReflectionMethod($handler, 'buildPsuData');

        unset($_SERVER['HTTP_X_FORWARDED_FOR']);
        $_SERVER['REMOTE_ADDR'] = '192.168.1.1';
        $_SERVER['HTTP_USER_AGENT'] = 'TestBrowser/1.0';

        $result = $method->invoke($handler);
        $this->assertSame('192.168.1.1', $result['IP']);
        $this->assertSame('TestBrowser/1.0', $result['userAgent']);

        unset($_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_USER_AGENT']);
    }

    /**
     * Test buildPsuData uses rightmost X-Forwarded-For IP
     */
    public function testBuildPsuDataXForwardedForRightmost(): void
    {
        $handler = $this->createHandlerWithReflection();
        $method = new \ReflectionMethod($handler, 'buildPsuData');

        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '10.0.0.1, 192.168.1.100';
        unset($_SERVER['HTTP_USER_AGENT']);

        $result = $method->invoke($handler);
        // Should take rightmost (proxy-appended) IP, not client-controlled first
        $this->assertSame('192.168.1.100', $result['IP']);
        $this->assertArrayNotHasKey('userAgent', $result);

        unset($_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_X_FORWARDED_FOR']);
    }

    /**
     * Test buildPsuData with empty server vars
     */
    public function testBuildPsuDataEmpty(): void
    {
        $handler = $this->createHandlerWithReflection();
        $method = new \ReflectionMethod($handler, 'buildPsuData');

        $origAddr = $_SERVER['REMOTE_ADDR'] ?? null;
        $origFwd = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? null;
        unset($_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_X_FORWARDED_FOR']);

        $result = $method->invoke($handler);
        $this->assertSame([], $result);

        // Restore
        if ($origAddr !== null) $_SERVER['REMOTE_ADDR'] = $origAddr;
        if ($origFwd !== null) $_SERVER['HTTP_X_FORWARDED_FOR'] = $origFwd;
    }

    /**
     * Test buildDescription falls back to order id when invoice_no is empty
     */
    public function testBuildDescriptionFallbackToId(): void
    {
        $handler = $this->createHandlerWithReflection();
        $method = new \ReflectionMethod($handler, 'buildDescription');

        $order = (object) [
            'invoice_no' => '',
            'id' => 42,
            'order_items' => null,
        ];

        $result = $method->invoke($handler, $order);
        $this->assertSame('Order #42', $result);
    }

    /**
     * Test buildTransferLabel falls back to order id when invoice_no is empty
     */
    public function testBuildTransferLabelFallbackToId(): void
    {
        $handler = $this->createHandlerWithReflection();
        $method = new \ReflectionMethod($handler, 'buildTransferLabel');

        $order = (object) ['invoice_no' => '', 'id' => 42];
        $result = $method->invoke($handler, $order);
        $this->assertSame('Zam 42', $result);
    }

    /**
     * Test buildCustomerParams uses customer->full_name as fallback
     */
    public function testBuildCustomerParamsFullNameFallback(): void
    {
        $handler = $this->createHandlerWithReflection();
        $method = new \ReflectionMethod($handler, 'buildCustomerParams');

        $order = (object) [
            'billing_address' => (object) [
                'first_name' => '',
                'last_name' => '',
                'address_line_1' => '',
                'address_line_2' => '',
                'zip' => '',
                'city' => '',
                'phone' => '',
            ],
            'customer' => (object) ['full_name' => 'Jan Kowalski'],
        ];

        $result = $method->invoke($handler, $order);
        $this->assertSame('Jan Kowalski', $result['client']);
    }

    /**
     * Test buildCustomerParams truncates phone to 12 digits
     */
    public function testBuildCustomerParamsPhoneTruncation(): void
    {
        $handler = $this->createHandlerWithReflection();
        $method = new \ReflectionMethod($handler, 'buildCustomerParams');

        $order = (object) [
            'billing_address' => (object) [
                'first_name' => '',
                'last_name' => '',
                'address_line_1' => '',
                'address_line_2' => '',
                'zip' => '',
                'city' => '',
                'phone' => '+48 123 456 789 00',
            ],
            'customer' => null,
        ];

        $result = $method->invoke($handler, $order);
        $this->assertSame('481234567890', $result['phone']);
        $this->assertSame(12, strlen($result['phone']));
    }

    /**
     * Test buildDescription with empty collection (non-null but isEmpty=true)
     */
    public function testBuildDescriptionEmptyCollection(): void
    {
        $handler = $this->createHandlerWithReflection();
        $method = new \ReflectionMethod($handler, 'buildDescription');

        $collection = new class([]) implements \IteratorAggregate {
            private array $items;
            public function __construct(array $items) { $this->items = $items; }
            public function isEmpty(): bool { return count($this->items) === 0; }
            public function getIterator(): \ArrayIterator { return new \ArrayIterator($this->items); }
        };

        $order = (object) [
            'invoice_no' => '99',
            'id' => 1,
            'order_items' => $collection,
        ];

        $result = $method->invoke($handler, $order);
        $this->assertSame('Order #99', $result);
    }

    /**
     * Test P24 supported languages constant
     */
    public function testSupportedLanguages(): void
    {
        $expected = ['bg', 'cs', 'de', 'en', 'es', 'fr', 'hr', 'hu', 'it', 'nl', 'pl', 'pt', 'se', 'sk', 'ro'];

        $reflection = new \ReflectionClass(Przelewy24Handler::class);
        $constant = $reflection->getConstant('P24_SUPPORTED_LANGUAGES');

        $this->assertSame($expected, $constant);
    }

    /**
     * Create a handler instance bypassing the constructor
     */
    private function createHandlerWithReflection(): Przelewy24Handler
    {
        $reflection = new \ReflectionClass(Przelewy24Handler::class);
        return $reflection->newInstanceWithoutConstructor();
    }
}

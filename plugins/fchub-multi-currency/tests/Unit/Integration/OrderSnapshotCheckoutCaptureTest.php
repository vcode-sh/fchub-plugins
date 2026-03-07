<?php

declare(strict_types=1);

namespace FChubMultiCurrency\Tests\Unit\Integration;

use FChubMultiCurrency\Integration\OrderSnapshotHooks;
use FChubMultiCurrency\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class OrderSnapshotCheckoutCaptureTest extends TestCase
{
    private object $order;

    protected function setUp(): void
    {
        parent::setUp();

        // Reset cached resolver chain
        $ref = new \ReflectionClass(\FChubMultiCurrency\Bootstrap\Modules\ContextModule::class);
        $prop = $ref->getProperty('cachedChain');
        $prop->setAccessible(true);
        $prop->setValue(null, null);

        \FChubMultiCurrency\Domain\Services\CurrencyContextService::reset();

        $_GET = [];
        $_COOKIE = [];

        // Create a mock order object
        $this->order = new class {
            public int $user_id = 0;
            private array $meta = [];

            public function updateMeta(string $key, $value): void
            {
                $this->meta[$key] = $value;
            }

            public function getMeta(string $key, $default = '')
            {
                return $this->meta[$key] ?? $default;
            }
        };
    }

    private function configureMultiCurrency(string $displayCode = 'GBP', string $rate = '0.79000000'): void
    {
        $this->setOption('fchub_mc_settings', [
            'enabled'            => 'yes',
            'base_currency'      => 'USD',
            'display_currencies' => [
                ['code' => $displayCode, 'name' => 'British Pound', 'symbol' => '£', 'decimals' => 2, 'position' => 'left'],
                ['code' => 'EUR', 'name' => 'Euro', 'symbol' => '€', 'decimals' => 2, 'position' => 'left'],
            ],
            'cookie_enabled'     => 'yes',
        ]);

        $this->setWpdbMockRow([
            'base_currency'  => 'USD',
            'quote_currency' => $displayCode,
            'rate'           => $rate,
            'provider'       => 'manual',
            'fetched_at'     => gmdate('Y-m-d H:i:s'),
        ]);
    }

    #[Test]
    public function testCheckoutCaptureWritesMeta(): void
    {
        $this->configureMultiCurrency('GBP');
        $_COOKIE['fchub_mc_currency'] = 'GBP';

        OrderSnapshotHooks::captureAtCheckout(['order' => $this->order]);

        $this->assertSame('GBP', $this->order->getMeta('_fchub_mc_display_currency'));
        $this->assertSame('USD', $this->order->getMeta('_fchub_mc_base_currency'));
        $this->assertNotEmpty($this->order->getMeta('_fchub_mc_rate'));
        $this->assertNotEmpty($this->order->getMeta('_fchub_mc_disclosure_version'));
    }

    #[Test]
    public function testOrderPaidDoneSkipsWhenMetaExists(): void
    {
        $this->configureMultiCurrency('GBP');
        $this->order->updateMeta('_fchub_mc_display_currency', 'GBP');
        $this->order->updateMeta('_fchub_mc_base_currency', 'USD');
        $this->order->updateMeta('_fchub_mc_rate', '0.79000000');
        $this->order->updateMeta('_fchub_mc_disclosure_version', '1.2.1');

        // Set cookie to EUR — if saveSnapshot runs, it would overwrite with EUR
        $_COOKIE['fchub_mc_currency'] = 'EUR';

        OrderSnapshotHooks::saveSnapshot($this->order);

        // Should still be GBP — not overwritten
        $this->assertSame('GBP', $this->order->getMeta('_fchub_mc_display_currency'));
    }

    #[Test]
    public function testGuestCheckoutCaptured(): void
    {
        $this->configureMultiCurrency('GBP');
        $_COOKIE['fchub_mc_currency'] = 'GBP';
        $this->order->user_id = 0; // Guest

        OrderSnapshotHooks::captureAtCheckout(['order' => $this->order]);

        // Guest should still get the snapshot via checkout capture
        $this->assertSame('GBP', $this->order->getMeta('_fchub_mc_display_currency'));
    }

    #[Test]
    public function testCheckoutCaptureNotOverwrittenByPaymentHook(): void
    {
        $this->configureMultiCurrency('GBP');

        // Simulate checkout capture with GBP
        $_COOKIE['fchub_mc_currency'] = 'GBP';
        OrderSnapshotHooks::captureAtCheckout(['order' => $this->order]);
        $this->assertSame('GBP', $this->order->getMeta('_fchub_mc_display_currency'));

        // Reset resolver chain to simulate a different context (admin/webhook)
        $ref = new \ReflectionClass(\FChubMultiCurrency\Bootstrap\Modules\ContextModule::class);
        $prop = $ref->getProperty('cachedChain');
        $prop->setAccessible(true);
        $prop->setValue(null, null);
        \FChubMultiCurrency\Domain\Services\CurrencyContextService::reset();

        // Now simulate order_paid_done firing in admin context — should NOT overwrite
        $this->order->user_id = 99;
        $this->setCurrentUserId(99);
        $this->setUserMeta(99, '_fchub_mc_currency', 'EUR');

        OrderSnapshotHooks::saveSnapshot($this->order);

        // Should still be GBP from checkout capture, not EUR from admin
        $this->assertSame('GBP', $this->order->getMeta('_fchub_mc_display_currency'));
    }

    #[Test]
    public function testDisabledCurrencyRejectedInFallback(): void
    {
        // Only EUR is enabled, but user preference is GBP
        $this->setOption('fchub_mc_settings', [
            'enabled'            => 'yes',
            'base_currency'      => 'USD',
            'display_currencies' => [
                ['code' => 'EUR', 'name' => 'Euro', 'symbol' => '€', 'decimals' => 2, 'position' => 'left'],
            ],
        ]);

        $this->order->user_id = 42;
        $this->setUserMeta(42, '_fchub_mc_currency', 'GBP');

        // No checkout capture ran — simulate a manual order
        OrderSnapshotHooks::saveSnapshot($this->order);

        // GBP is not enabled, so no snapshot should be written
        $this->assertSame('', $this->order->getMeta('_fchub_mc_display_currency', ''));
    }
}

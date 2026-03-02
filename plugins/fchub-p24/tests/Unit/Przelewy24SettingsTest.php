<?php

namespace FChubP24\Tests\Unit;

use FChubP24\Tests\TestSettings;
use PHPUnit\Framework\TestCase;

class Przelewy24SettingsTest extends TestCase
{
    /**
     * Test channel bitmask with all enabled
     */
    public function testChannelBitmaskAllEnabled(): void
    {
        $settings = new TestSettings([
            'channel_cards'        => 'yes',
            'channel_transfers'    => 'yes',
            'channel_traditional'  => 'yes',
            'channel_blik'         => 'yes',
            'channel_24_7'         => 'yes',
            'channel_installments' => 'yes',
            'channel_wallets'      => 'yes',
        ]);

        // 1 + 2 + 4 + 16 + 128 + 256 + 8192 = 8599
        $this->assertSame(8599, $settings->getChannel());
    }

    /**
     * Test channel bitmask with defaults (cards, transfers, blik, wallets)
     */
    public function testChannelBitmaskDefaults(): void
    {
        $settings = new TestSettings();

        // Default: cards(1) + transfers(2) + blik(8192) + wallets(256) = 8451
        $this->assertSame(8451, $settings->getChannel());
    }

    /**
     * Test channel bitmask with none enabled falls back to 63
     */
    public function testChannelBitmaskNoneEnabledFallback(): void
    {
        $settings = new TestSettings([
            'channel_cards'        => 'no',
            'channel_transfers'    => 'no',
            'channel_traditional'  => 'no',
            'channel_blik'         => 'no',
            'channel_24_7'         => 'no',
            'channel_installments' => 'no',
            'channel_wallets'      => 'no',
        ]);

        // Should fallback to 63 (all basic channels)
        $this->assertSame(63, $settings->getChannel());
    }

    /**
     * Test channel bitmask with only BLIK
     */
    public function testChannelBitmaskOnlyBlik(): void
    {
        $settings = new TestSettings([
            'channel_cards'        => 'no',
            'channel_transfers'    => 'no',
            'channel_traditional'  => 'no',
            'channel_blik'         => 'yes',
            'channel_24_7'         => 'no',
            'channel_installments' => 'no',
            'channel_wallets'      => 'no',
        ]);

        $this->assertSame(8192, $settings->getChannel());
    }

    /**
     * Test individual channel values match P24 docs
     */
    public function testIndividualChannelValues(): void
    {
        $channelValues = [
            'channel_cards'        => 1,
            'channel_transfers'    => 2,
            'channel_traditional'  => 4,
            'channel_24_7'         => 16,
            'channel_installments' => 128,
            'channel_wallets'      => 256,
            'channel_blik'         => 8192,
        ];

        foreach ($channelValues as $key => $expectedValue) {
            $overrides = array_fill_keys(array_keys($channelValues), 'no');
            $overrides[$key] = 'yes';

            $settings = new TestSettings($overrides);
            $this->assertSame(
                $expectedValue,
                $settings->getChannel(),
                "Channel {$key} should have bitmask value {$expectedValue}"
            );
        }
    }

    /**
     * Test sandbox base URL
     */
    public function testSandboxBaseUrl(): void
    {
        $settings = new TestSettings([], 'test');
        $this->assertSame('https://sandbox.przelewy24.pl', $settings->getBaseUrl());
    }

    /**
     * Test live base URL
     */
    public function testLiveBaseUrl(): void
    {
        $settings = new TestSettings([], 'live');
        $this->assertSame('https://secure.przelewy24.pl', $settings->getBaseUrl());
    }

    /**
     * Test getMerchantId from test settings
     */
    public function testGetMerchantIdTest(): void
    {
        $settings = new TestSettings([
            'test_merchant_id' => '383989',
            'live_merchant_id' => '999999',
        ], 'test');

        $this->assertSame('383989', $settings->getMerchantId());
    }

    /**
     * Test getMerchantId from live settings
     */
    public function testGetMerchantIdLive(): void
    {
        $settings = new TestSettings([
            'test_merchant_id' => '383989',
            'live_merchant_id' => '999999',
        ], 'live');

        $this->assertSame('999999', $settings->getMerchantId());
    }

    /**
     * Test getShopId falls back to merchantId
     */
    public function testGetShopIdFallsBackToMerchantId(): void
    {
        $settings = new TestSettings([
            'test_merchant_id' => '383989',
            'test_shop_id'    => '',
        ], 'test');

        $this->assertSame('383989', $settings->getShopId());
    }

    /**
     * Test getShopId uses custom shop ID when set
     */
    public function testGetShopIdCustom(): void
    {
        $settings = new TestSettings([
            'test_merchant_id' => '383989',
            'test_shop_id'    => '111111',
        ], 'test');

        $this->assertSame('111111', $settings->getShopId());
    }

    /**
     * Test isActive
     */
    public function testIsActive(): void
    {
        $active = new TestSettings(['is_active' => 'yes']);
        $this->assertTrue($active->isActive());

        $inactive = new TestSettings(['is_active' => 'no']);
        $this->assertFalse($inactive->isActive());
    }

    /**
     * Test defaults contain all expected keys
     */
    public function testDefaultsContainAllKeys(): void
    {
        $defaults = TestSettings::getDefaults();

        $expectedKeys = [
            'is_active', 'payment_mode',
            'test_merchant_id', 'test_shop_id', 'test_crc_key', 'test_api_key',
            'live_merchant_id', 'live_shop_id', 'live_crc_key', 'live_api_key',
            'channel_cards', 'channel_transfers', 'channel_traditional',
            'channel_blik', 'channel_24_7', 'channel_installments', 'channel_wallets',
            'time_limit',
        ];

        foreach ($expectedKeys as $key) {
            $this->assertArrayHasKey($key, $defaults, "Missing default key: {$key}");
        }
    }

    /**
     * Test time_limit default is 15
     */
    public function testTimeLimitDefault(): void
    {
        $settings = new TestSettings();
        $this->assertSame('15', $settings->get('time_limit'));
    }
}

<?php

namespace FChubP24\Gateway;

defined('ABSPATH') || exit;

use FluentCart\Api\StoreSettings;
use FluentCart\App\Modules\PaymentMethods\Core\BaseGatewaySettings;

class Przelewy24Settings extends BaseGatewaySettings
{
    public $settings;
    public $methodHandler = 'fluent_cart_payment_settings_przelewy24';

    public static function getDefaults(): array
    {
        return [
            'is_active'       => 'no',
            'payment_mode'    => 'test',
            'test_merchant_id' => '',
            'test_shop_id'    => '',
            'test_crc_key'    => '',
            'test_api_key'    => '',
            'live_merchant_id' => '',
            'live_shop_id'    => '',
            'live_crc_key'    => '',
            'live_api_key'    => '',
            'channel_cards'       => 'yes',
            'channel_transfers'   => 'yes',
            'channel_traditional' => 'no',
            'channel_blik'        => 'yes',
            'channel_24_7'        => 'no',
            'channel_installments' => 'no',
            'channel_wallets'     => 'yes',
            'time_limit'          => '15',
            'enable_recurring'    => 'yes',
        ];
    }

    public function isActive(): bool
    {
        return $this->get('is_active') === 'yes';
    }

    public function getMode()
    {
        return (new StoreSettings)->get('order_mode');
    }

    public function get($key = '')
    {
        if ($key && isset($this->settings[$key])) {
            return $this->settings[$key];
        }

        return $this->settings;
    }

    public function getMerchantId(): string
    {
        $mode = $this->getMode();

        if ($mode === 'test') {
            return defined('FCHUB_P24_TEST_MERCHANT_ID')
                ? FCHUB_P24_TEST_MERCHANT_ID
                : ($this->get('test_merchant_id') ?: '');
        }

        return defined('FCHUB_P24_LIVE_MERCHANT_ID')
            ? FCHUB_P24_LIVE_MERCHANT_ID
            : ($this->get('live_merchant_id') ?: '');
    }

    public function getShopId(): string
    {
        $mode = $this->getMode();

        if ($mode === 'test') {
            return defined('FCHUB_P24_TEST_SHOP_ID')
                ? FCHUB_P24_TEST_SHOP_ID
                : ($this->get('test_shop_id') ?: $this->getMerchantId());
        }

        return defined('FCHUB_P24_LIVE_SHOP_ID')
            ? FCHUB_P24_LIVE_SHOP_ID
            : ($this->get('live_shop_id') ?: $this->getMerchantId());
    }

    public function getCrcKey(): string
    {
        $mode = $this->getMode();

        if ($mode === 'test') {
            return defined('FCHUB_P24_TEST_CRC_KEY')
                ? FCHUB_P24_TEST_CRC_KEY
                : ($this->get('test_crc_key') ?: '');
        }

        return defined('FCHUB_P24_LIVE_CRC_KEY')
            ? FCHUB_P24_LIVE_CRC_KEY
            : ($this->get('live_crc_key') ?: '');
    }

    public function getApiKey(): string
    {
        $mode = $this->getMode();

        if ($mode === 'test') {
            return defined('FCHUB_P24_TEST_API_KEY')
                ? FCHUB_P24_TEST_API_KEY
                : ($this->get('test_api_key') ?: '');
        }

        return defined('FCHUB_P24_LIVE_API_KEY')
            ? FCHUB_P24_LIVE_API_KEY
            : ($this->get('live_api_key') ?: '');
    }

    public function getChannel(): int
    {
        $channelMap = [
            'channel_cards'        => 1,
            'channel_transfers'    => 2,
            'channel_traditional'  => 4,
            'channel_24_7'         => 16,
            'channel_installments' => 128,
            'channel_wallets'      => 256,
            'channel_blik'         => 8192,
        ];

        $channel = 0;
        foreach ($channelMap as $key => $value) {
            if ($this->get($key) === 'yes') {
                $channel |= $value;
            }
        }

        return $channel ?: 63; // default: all basic channels
    }

    public function getBaseUrl(): string
    {
        if ($this->getMode() === 'test') {
            return 'https://sandbox.przelewy24.pl';
        }

        return 'https://secure.przelewy24.pl';
    }
}

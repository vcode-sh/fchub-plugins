<?php

declare(strict_types=1);

namespace FluentCart\App\Helpers {
    final class CurrenciesHelper
    {
        public static function getCurrencySign(string $code): string
        {
            return '&euro;';
        }
    }
}

namespace FChubMemberships\Tests\Unit\Support {

    use FChubMemberships\Support\AdminMenu;
    use FChubMemberships\Tests\Unit\PluginTestCase;

    final class AdminMenuCurrencyFeatureTest extends PluginTestCase
    {
        public function test_render_uses_fluentcart_currency_config_when_helper_is_available(): void
        {
            $GLOBALS['_fchub_test_options']['fluent_cart_store_settings'] = [
                'currency' => 'EUR',
                'currency_position' => 'after',
                'decimal_separator' => 'comma',
            ];
            $GLOBALS['_fchub_test_options']['date_format'] = 'Y-m-d';
            $GLOBALS['_fchub_test_options']['time_format'] = 'H:i';

            ob_start();
            AdminMenu::render();
            ob_end_clean();

            self::assertStringContainsString('"code":"EUR"', $GLOBALS['_fchub_test_inline_scripts'][0][1]);
            self::assertStringContainsString('"symbol":"\\u20ac"', $GLOBALS['_fchub_test_inline_scripts'][0][1]);
            self::assertStringContainsString('"decimal_sep":","', $GLOBALS['_fchub_test_inline_scripts'][0][1]);
            self::assertStringContainsString('"thousand_sep":"."', $GLOBALS['_fchub_test_inline_scripts'][0][1]);
        }
    }
}

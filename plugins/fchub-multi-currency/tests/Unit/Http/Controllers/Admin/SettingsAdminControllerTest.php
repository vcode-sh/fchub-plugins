<?php

declare(strict_types=1);

namespace FChubMultiCurrency\Tests\Unit\Http\Controllers\Admin;

use FChubMultiCurrency\Http\Controllers\Admin\SettingsAdminController;
use FChubMultiCurrency\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class SettingsAdminControllerTest extends TestCase
{
    #[Test]
    public function testSaveReturnsBadRequestForInvalidJsonPayload(): void
    {
        $controller = new SettingsAdminController();
        $request = new \WP_REST_Request('POST', '/');
        $request->set_body('{invalid-json');

        $response = $controller->save($request);

        $this->assertSame(400, $response->get_status());
    }

    #[Test]
    public function testSaveSanitizesSettingsPayload(): void
    {
        $controller = new SettingsAdminController();
        $request = new \WP_REST_Request('POST', '/');
        $request->set_json_params([
            'rate_refresh_interval_hrs' => 999,
            'stale_threshold_hrs'       => 0,
            'rounding_mode'             => 'invalid-mode',
            'url_param_key'             => 'curr<>ency',
            'display_currencies'        => [
                [
                    'code'     => 'eur',
                    'name'     => 'Euro',
                    'symbol'   => '€',
                    'decimals' => 9,
                    'position' => 'left',
                    'decimal_separator' => ',',
                    'thousand_separator' => 'none',
                ],
                [
                    'code'     => 'EUR',
                    'name'     => 'Euro Duplicate',
                    'symbol'   => '€',
                    'decimals' => 2,
                    'position' => 'right',
                ],
                [
                    'code' => 'XXX',
                ],
            ],
            'switcher_defaults' => [
                'preset' => 'contrast',
                'label_position' => 'below',
                'show_symbol' => 'yes',
                'search_mode' => 'inline',
                'favorite_currencies' => [' eur ', 'usd', 'BAD'],
                'dropdown_position' => 'auto',
                'dropdown_direction' => 'auto',
            ],
        ]);

        $response = $controller->save($request);
        $data = $response->get_data();
        $settings = $data['data']['settings'] ?? [];

        $this->assertSame(200, $response->get_status());
        $this->assertSame(168, $settings['rate_refresh_interval_hrs']);
        $this->assertSame(1, $settings['stale_threshold_hrs']);
        $this->assertSame('half_up', $settings['rounding_mode']);
        $this->assertSame('currency', $settings['url_param_key']);
        $this->assertCount(1, $settings['display_currencies']);
        $this->assertSame('EUR', $settings['display_currencies'][0]['code']);
        $this->assertSame(4, $settings['display_currencies'][0]['decimals']);
        $this->assertSame(',', $settings['display_currencies'][0]['decimal_separator']);
        $this->assertSame('none', $settings['display_currencies'][0]['thousand_separator']);
        $this->assertSame('contrast', $settings['switcher_defaults']['preset']);
        $this->assertSame('below', $settings['switcher_defaults']['label_position']);
        $this->assertSame('yes', $settings['switcher_defaults']['show_symbol']);
        $this->assertSame('inline', $settings['switcher_defaults']['search_mode']);
        $this->assertSame(['EUR', 'USD'], $settings['switcher_defaults']['favorite_currencies']);
    }

    #[Test]
    public function testRemovingTheDefaultDisplayCurrencyFallsBackToFluentCartsBase(): void
    {
        $this->setOption('fchub_mc_settings', [
            'default_display_currency' => 'EUR',
            'display_currencies' => [
                ['code' => 'EUR', 'name' => 'Euro', 'symbol' => '€'],
                ['code' => 'GBP', 'name' => 'British Pound', 'symbol' => '£'],
            ],
        ]);
        $request = new \WP_REST_Request('POST', '/');
        $request->set_json_params([
            'default_display_currency' => 'EUR',
            'display_currencies' => [
                ['code' => 'GBP', 'name' => 'British Pound', 'symbol' => '£'],
            ],
        ]);

        $response = (new SettingsAdminController())->save($request);
        $settings = $response->get_data()['data']['settings'];

        $this->assertSame(200, $response->get_status());
        $this->assertSame('USD', $settings['base_currency']);
        $this->assertSame(
            '',
            $settings['default_display_currency'],
            'An invalid pick becomes "follow the base", not a frozen copy of today\'s base code.',
        );
    }

    /**
     * The empty value is the shipped default and means "same as the base
     * currency, whatever it is" — it must survive a save untouched, because
     * freezing it to today's base code would stop it following a later base
     * change in FluentCart.
     */
    #[Test]
    public function testAnEmptyDefaultDisplayCurrencyIsPreservedAsFollowTheBase(): void
    {
        $request = new \WP_REST_Request('POST', '/');
        $request->set_json_params([
            'default_display_currency' => '',
            'display_currencies' => [
                ['code' => 'EUR', 'name' => 'Euro', 'symbol' => '€'],
            ],
        ]);

        $response = (new SettingsAdminController())->save($request);
        $settings = $response->get_data()['data']['settings'];

        $this->assertSame(200, $response->get_status());
        $this->assertSame('', $settings['default_display_currency']);
    }

    #[Test]
    public function invalidProviderIsRejectedWithoutSavingOrScheduling(): void
    {
        $controller = new SettingsAdminController();
        $request = new \WP_REST_Request('POST', '/');
        $request->set_json_params(['rate_provider' => 'surprisingly_free_money']);

        $response = $controller->save($request);

        self::assertSame(422, $response->get_status());
        self::assertSame('manual', $GLOBALS['wp_options']['fchub_mc_settings']['rate_provider'] ?? 'manual');
        self::assertFalse(wp_next_scheduled('fchub_mc_refresh_rates'));
    }

    #[Test]
    public function keyedRemoteProvidersAreRejectedUntilTheirKeyIsSupplied(): void
    {
        $controller = new SettingsAdminController();

        foreach (['exchange_rate_api', 'open_exchange_rates'] as $provider) {
            $request = new \WP_REST_Request('POST', '/');
            $request->set_json_params([
                'rate_provider' => $provider,
                'rate_provider_api_key' => '',
            ]);

            $response = $controller->save($request);

            self::assertSame(422, $response->get_status());
            self::assertFalse(wp_next_scheduled('fchub_mc_refresh_rates'));
        }
    }

    #[Test]
    public function switchingBetweenKeyedProvidersRequiresTheNewProvidersKey(): void
    {
        $this->setOption('fchub_mc_settings', [
            'rate_provider' => 'exchange_rate_api',
            'rate_provider_api_key' => 'exchange-rate-api-key',
        ]);
        $controller = new SettingsAdminController();
        $request = new \WP_REST_Request('POST', '/');
        $request->set_json_params(['rate_provider' => 'open_exchange_rates']);

        $response = $controller->save($request);

        self::assertSame(422, $response->get_status());
        self::assertSame(
            'exchange_rate_api',
            $GLOBALS['wp_options']['fchub_mc_settings']['rate_provider'],
        );
        self::assertFalse(wp_next_scheduled('fchub_mc_refresh_rates'));
    }

    #[Test]
    public function savingEcbSchedulesARecurringRefreshWithoutAnApiKey(): void
    {
        $controller = new SettingsAdminController();
        $request = new \WP_REST_Request('POST', '/');
        $request->set_json_params([
            'rate_provider' => 'ecb',
            'rate_provider_api_key' => '',
        ]);

        $response = $controller->save($request);

        self::assertSame(200, $response->get_status());
        self::assertIsInt(wp_next_scheduled('fchub_mc_refresh_rates'));
        self::assertSame(
            'fchub_mc_rate_interval',
            $GLOBALS['wp_scheduled_events']['fchub_mc_refresh_rates']['recurrence'] ?? null,
        );
    }

    #[Test]
    public function savingAKeyedRemoteProviderSchedulesOneRecurringRefresh(): void
    {
        $controller = new SettingsAdminController();
        $request = new \WP_REST_Request('POST', '/');
        $request->set_json_params([
            'rate_provider' => 'exchange_rate_api',
            'rate_provider_api_key' => 'super-secret-key',
        ]);

        $response = $controller->save($request);
        $controller->save($request);

        self::assertSame(200, $response->get_status());
        self::assertCount(1, $GLOBALS['wp_scheduled_events']);
    }

    #[Test]
    public function switchingToManualClearsTheRecurringRefresh(): void
    {
        wp_schedule_event(time(), 'fchub_mc_rate_interval', 'fchub_mc_refresh_rates');
        $controller = new SettingsAdminController();
        $request = new \WP_REST_Request('POST', '/');
        $request->set_json_params(['rate_provider' => 'manual']);

        $response = $controller->save($request);

        self::assertSame(200, $response->get_status());
        self::assertFalse(wp_next_scheduled('fchub_mc_refresh_rates'));
    }

    #[Test]
    public function testCharmRoundingAcceptsKnownRulesAndDegradesUnknownOnes(): void
    {
        $request = new \WP_REST_Request('POST', '/');
        $request->set_json_params(['charm_rounding' => 'ending_99']);
        $settings = (new SettingsAdminController())->save($request)->get_data()['data']['settings'];
        $this->assertSame('ending_99', $settings['charm_rounding']);

        $request = new \WP_REST_Request('POST', '/');
        $request->set_json_params(['charm_rounding' => 'exotic']);
        $settings = (new SettingsAdminController())->save($request)->get_data()['data']['settings'];
        $this->assertSame('none', $settings['charm_rounding']);
    }
}

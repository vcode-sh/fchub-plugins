<?php

/**
 * Generates the browser-lane fixture from the plugin's own renderers.
 *
 * The lane must measure the real switcher, the real config payload and the real
 * REST envelopes — a hand-drawn page would drift the moment a renderer changes
 * and would quietly stop testing the thing that broke. Everything here comes out
 * of the same classes WordPress calls; only the WordPress and FluentCart
 * surfaces underneath are mocked, by the existing PHPUnit bootstrap.
 *
 * One variant is produced per selectable currency, because on 1.4.7 the resolved
 * display currency is baked into both the markup and the config. That is exactly
 * the per-visitor state a shared cache is free to hand to the wrong visitor, and
 * having it visible in the fixture keeps the problem honest.
 *
 * Usage: php tests/e2e/generate-fixture.php
 */

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use FChubMultiCurrency\Bootstrap\Modules\ContextModule;
use FChubMultiCurrency\Bootstrap\Modules\FrontendModule;
use FChubMultiCurrency\Domain\Services\CurrencyContextService;
use FChubMultiCurrency\Frontend\CurrencyContextPayload;
use FChubMultiCurrency\Storage\OptionStore;
use FChubMultiCurrency\Support\Constants;
use FluentCart\Api\CurrencySettings;

const BASE_CURRENCY = 'USD';
const QUOTE_CURRENCY = 'EUR';
const QUOTE_RATE = '0.92000000';

/**
 * Puts the mock world back to a logged-out visitor of a two-currency USD store.
 */
function resetStore(): void
{
    $GLOBALS['wp_options'] = [];
    $GLOBALS['wp_transients'] = [];
    $GLOBALS['wp_cache_store'] = [];
    $GLOBALS['wp_mock_current_user_id'] = 0;
    $GLOBALS['wp_mock_user_meta'] = [];
    $GLOBALS['wp_mock_cookies'] = [];
    $GLOBALS['wp_registered_scripts'] = [];
    $GLOBALS['wp_registered_styles'] = [];
    $GLOBALS['wp_enqueued_scripts'] = [];
    $GLOBALS['wp_enqueued_styles'] = [];
    $GLOBALS['wp_inline_scripts'] = [];
    $_GET = [];
    $_POST = [];
    $_COOKIE = [];
    $_SERVER['REQUEST_METHOD'] = 'GET';

    CurrencySettings::resetMock();
    CurrencySettings::setMock(['currency' => BASE_CURRENCY, 'currency_sign' => '$']);

    // One mocked rate row serves every lookup. The store therefore has exactly two
    // currencies, so no lookup can silently borrow another pair's rate.
    $GLOBALS['wpdb_mock_row'] = [
        'base_currency'  => BASE_CURRENCY,
        'quote_currency' => QUOTE_CURRENCY,
        'rate'           => QUOTE_RATE,
        'provider'       => 'manual',
        'fetched_at'     => current_time('mysql'),
    ];

    $GLOBALS['wp_options'][Constants::OPTION_SETTINGS] = [
        'enabled'                     => 'yes',
        'base_currency'               => BASE_CURRENCY,
        'default_display_currency'    => BASE_CURRENCY,
        'display_currencies'          => [
            [
                'code'     => QUOTE_CURRENCY,
                'name'     => 'Euro',
                'symbol'   => '€',
                'decimals' => 2,
                'position' => 'left',
            ],
        ],
        'url_param_enabled'           => 'yes',
        'url_param_key'               => 'currency',
        'cookie_enabled'              => 'yes',
        'account_persistence_enabled' => 'yes',
        'cookie_lifetime_days'        => 90,
        'rate_provider'               => 'manual',
        'stale_threshold_hrs'         => 24,
        'stale_fallback'              => 'base',
        'rounding_mode'               => 'half_up',
        'show_rate_freshness_badge'   => 'yes',
        'checkout_disclosure_enabled' => 'yes',
        'checkout_disclosure_text'    => 'Your payment will be processed in {base_currency}.',
    ];

    ContextModule::resetChain();
    CurrencyContextService::reset();
    FrontendModule::registerAssets();
}

/**
 * Renders the page state a cache would store for a visitor resolved to $currency.
 *
 * @return array{switcherHtml: string, config: array<string, mixed>}
 */
function renderVariant(string $currency): array
{
    resetStore();

    // The cookie is how a real request reaches a non-default currency, and it is
    // precisely what the edge later refuses to forward.
    if ($currency !== BASE_CURRENCY) {
        $_COOKIE[Constants::COOKIE_KEY] = $currency;
    }

    ContextModule::resetChain();
    CurrencyContextService::reset();

    $switcherHtml = FrontendModule::renderSwitcher(['label' => 'Currency']);
    $config = FrontendModule::buildFrontendConfig();

    if (($config['displayCurrency'] ?? '') !== $currency) {
        fwrite(STDERR, sprintf(
            "Fixture generation failed: asked for %s, renderer resolved %s.\n",
            $currency,
            (string) ($config['displayCurrency'] ?? 'nothing'),
        ));
        exit(1);
    }

    return ['switcherHtml' => $switcherHtml, 'config' => $config];
}

/**
 * The exact body ContextController::get() returns for an explicit currency.
 *
 * @return array<string, mixed>
 */
function restContext(string $currency): array
{
    resetStore();
    $optionStore = new OptionStore();
    $context = CurrencyContextService::applyContextFilter(
        ContextModule::resolveExplicitPreference($optionStore, $currency),
    );

    return [
        'data' => [
            'display_currency' => $context->displayCurrency->code,
            'base_currency'    => $context->baseCurrency->code,
            'rate'             => $context->rate->rate,
            'source'           => $context->source->value,
            'is_base_display'  => $context->isBaseDisplay,
            'context'          => CurrencyContextPayload::buildRecovery($context, $optionStore),
        ],
    ];
}

$currencies = [BASE_CURRENCY, QUOTE_CURRENCY];
$fixture = [
    'generatedFrom' => FCHUB_MC_VERSION,
    'baseCurrency'  => BASE_CURRENCY,
    'currencies'    => $currencies,
    'variants'      => [],
    'restContext'   => [],
];

foreach ($currencies as $currency) {
    $fixture['variants'][$currency] = renderVariant($currency);
    $fixture['restContext'][$currency] = restContext($currency);
}

$outputDir = __DIR__ . '/.fixture';
if (!is_dir($outputDir) && !mkdir($outputDir, 0o777, true) && !is_dir($outputDir)) {
    fwrite(STDERR, "Could not create {$outputDir}\n");
    exit(1);
}

$path = $outputDir . '/fixture.json';
file_put_contents($path, json_encode($fixture, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

printf(
    "Wrote %s (%d bytes) for plugin %s — variants: %s\n",
    $path,
    (int) filesize($path),
    FCHUB_MC_VERSION,
    implode(', ', $currencies),
);

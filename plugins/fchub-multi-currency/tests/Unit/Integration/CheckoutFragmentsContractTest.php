<?php

declare(strict_types=1);

namespace FChubMultiCurrency\Tests\Unit\Integration;

use FChubMultiCurrency\Bootstrap\Modules\CheckoutModule;
use FChubMultiCurrency\Support\Constants;
use FChubMultiCurrency\Tests\Support\TestCase;
use FluentCart\Api\CurrencySettings;
use PHPUnit\Framework\Attributes\Test;

/**
 * FluentCart builds its checkout patch fragments as a sequential list of
 * selector/content/type entries and sends the filtered result straight through
 * wp_send_json to a client that calls fragments.forEach(...). One string-keyed
 * entry from a filter turns that JSON array into a JSON object and the checkout
 * stops applying patches. Whatever this plugin hooks into that filter must
 * leave the list a list.
 */
final class CheckoutFragmentsContractTest extends TestCase
{
    #[Test]
    public function testCheckoutFragmentsStayASequentialListForANonBaseVisitor(): void
    {
        CurrencySettings::setMock(['currency' => 'USD']);
        $this->setOption('fchub_mc_settings', [
            'enabled'            => 'yes',
            'base_currency'      => 'USD',
            'display_currencies' => [
                ['code' => 'EUR', 'name' => 'Euro', 'symbol' => '€', 'decimals' => 2, 'position' => 'left'],
            ],
        ]);
        $this->setWpdbMockRow([
            'base_currency'  => 'USD',
            'quote_currency' => 'EUR',
            'rate'           => '0.9200',
            'provider'       => 'manual',
            'fetched_at'     => gmdate('Y-m-d H:i:s'),
        ]);
        $_COOKIE[Constants::COOKIE_KEY] = 'EUR';

        (new CheckoutModule())->register();

        $fluentCartFragments = [
            ['selector' => '[data-a]', 'content' => '<div>a</div>', 'type' => 'replace'],
            ['selector' => '[data-b]', 'content' => '<div>b</div>', 'type' => 'replace'],
        ];

        $filtered = apply_filters(
            'fluent_cart/checkout/after_patch_checkout_data_fragments',
            $fluentCartFragments,
            ['cart' => null, 'changes' => []],
        );

        $this->assertIsArray($filtered);
        $this->assertTrue(
            array_is_list($filtered),
            'A string-keyed fragment entry becomes a JSON object and breaks FluentCart\'s fragments.forEach()',
        );
    }
}

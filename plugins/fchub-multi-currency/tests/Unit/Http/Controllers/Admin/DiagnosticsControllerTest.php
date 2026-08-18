<?php

declare(strict_types=1);

namespace FChubMultiCurrency\Tests\Unit\Http\Controllers\Admin;

use FChubMultiCurrency\Http\Controllers\Admin\DiagnosticsController;
use FChubMultiCurrency\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class DiagnosticsControllerTest extends TestCase
{
    #[Test]
    public function itReportsConfiguredFluentCrmCustomFieldAvailability(): void
    {
        $GLOBALS['fluentcrm_mock_custom_fields'] = [
            ['slug' => 'preferred_currency'],
            ['slug' => 'last_order_fx_rate'],
        ];

        $response = (new DiagnosticsController())->get(new \WP_REST_Request());
        $data = $response->get_data();

        self::assertSame([
            'preferred_currency' => true,
            'last_order_display_currency' => false,
            'last_order_fx_rate' => true,
        ], $data['data']['fluentcrm_fields_status']);
    }

    /**
     * The Diagnostics quick action: one click creates whichever of the three
     * FluentCRM custom fields are missing, so the CRM sync has somewhere to
     * write without the admin hand-building fields from a doc page.
     */
    #[Test]
    public function itCreatesTheMissingCrmFields(): void
    {
        $GLOBALS['fluentcrm_mock_custom_fields'] = [];

        $response = (new DiagnosticsController())->createCrmFields(new \WP_REST_Request());
        $data = $response->get_data();

        $slugs = array_column($GLOBALS['fluentcrm_mock_custom_fields'], 'slug');
        self::assertSame(
            ['preferred_currency', 'last_order_display_currency', 'last_order_fx_rate'],
            $slugs,
        );
        foreach ($GLOBALS['fluentcrm_mock_custom_fields'] as $field) {
            self::assertSame('text', $field['type']);
            self::assertNotSame('', (string) $field['label']);
        }
        self::assertSame([
            'preferred_currency' => true,
            'last_order_display_currency' => true,
            'last_order_fx_rate' => true,
        ], $data['data']['fluentcrm_fields_status']);
    }

    #[Test]
    public function itLeavesExistingCrmFieldsAloneAndIsIdempotent(): void
    {
        $GLOBALS['fluentcrm_mock_custom_fields'] = [
            ['slug' => 'preferred_currency', 'label' => 'My Own Label', 'type' => 'select-one'],
        ];

        $controller = new DiagnosticsController();
        $controller->createCrmFields(new \WP_REST_Request());
        $controller->createCrmFields(new \WP_REST_Request());

        $fields = $GLOBALS['fluentcrm_mock_custom_fields'];
        self::assertCount(3, $fields, 'A second run creates nothing new.');
        self::assertSame('My Own Label', $fields[0]['label'], 'An existing field keeps its own definition.');
        self::assertSame('select-one', $fields[0]['type']);
    }

    #[Test]
    public function itCreatesCrmFieldsUnderTheConfiguredSlugs(): void
    {
        $GLOBALS['fluentcrm_mock_custom_fields'] = [];
        $this->setOption('fchub_mc_settings', [
            'fluentcrm_field_preferred' => 'my_currency_pref',
        ]);

        (new DiagnosticsController())->createCrmFields(new \WP_REST_Request());

        $slugs = array_column($GLOBALS['fluentcrm_mock_custom_fields'], 'slug');
        self::assertContains('my_currency_pref', $slugs);
        self::assertNotContains('preferred_currency', $slugs);
    }
}

<?php

declare(strict_types=1);

namespace FChubMultiCurrency\Tests\Unit\Integration;

use FChubMultiCurrency\Integration\FluentCrmSync;
use FChubMultiCurrency\Support\Constants;
use FChubMultiCurrency\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class FluentCrmSyncTest extends TestCase
{
    #[Test]
    public function testOnContextSwitchedSyncsCustomField(): void
    {
        $GLOBALS['fluentcrm_mock_contact'] = new \FluentCrm_Mock_Contact();

        FluentCrmSync::onContextSwitched('EUR', 1);

        $updates = $GLOBALS['fluentcrm_custom_field_updates'];
        $this->assertNotEmpty($updates);
        $found = array_filter($updates, fn($u) => $u['slug'] === 'preferred_currency' && $u['value'] === 'EUR');
        $this->assertNotEmpty($found, 'Expected preferred_currency=EUR in custom field updates.');
    }

    #[Test]
    public function testOnContextSwitchedCreatesAndAttachesTag(): void
    {
        $GLOBALS['fluentcrm_mock_contact'] = new \FluentCrm_Mock_Contact();
        $GLOBALS['fluentcrm_mock_tag_id'] = 42;

        FluentCrmSync::onContextSwitched('EUR', 1);

        $this->assertNotEmpty($GLOBALS['fluentcrm_attached_tags']);
        $allAttached = array_merge(...$GLOBALS['fluentcrm_attached_tags']);
        $this->assertContains(42, $allAttached);
    }

    #[Test]
    public function testOnContextSwitchedSkipsForUserIdZero(): void
    {
        $GLOBALS['fluentcrm_mock_contact'] = new \FluentCrm_Mock_Contact();

        FluentCrmSync::onContextSwitched('EUR', 0);

        $this->assertEmpty($GLOBALS['fluentcrm_custom_field_updates']);
        $this->assertEmpty($GLOBALS['fluentcrm_attached_tags']);
    }

    #[Test]
    public function testOnContextSwitchedSkipsWhenDisabled(): void
    {
        $GLOBALS['fluentcrm_mock_contact'] = new \FluentCrm_Mock_Contact();
        $this->setOption(Constants::OPTION_SETTINGS, ['fluentcrm_enabled' => 'no']);

        FluentCrmSync::onContextSwitched('EUR', 1);

        $this->assertEmpty($GLOBALS['fluentcrm_custom_field_updates']);
        $this->assertEmpty($GLOBALS['fluentcrm_attached_tags']);
    }

    #[Test]
    public function testOnContextSwitchedSkipsWhenNoContact(): void
    {
        // $GLOBALS['fluentcrm_mock_contact'] remains null (set by setUp)

        FluentCrmSync::onContextSwitched('EUR', 1);

        $this->assertEmpty($GLOBALS['fluentcrm_custom_field_updates']);
        $this->assertEmpty($GLOBALS['fluentcrm_attached_tags']);
    }

    #[Test]
    public function testOnContextSwitchedSkipsTagsWhenAutoCreateDisabled(): void
    {
        $GLOBALS['fluentcrm_mock_contact'] = new \FluentCrm_Mock_Contact();
        $this->setOption(Constants::OPTION_SETTINGS, ['fluentcrm_auto_create_tags' => 'no']);

        FluentCrmSync::onContextSwitched('EUR', 1);

        $updates = $GLOBALS['fluentcrm_custom_field_updates'];
        $found = array_filter($updates, fn($u) => $u['slug'] === 'preferred_currency' && $u['value'] === 'EUR');
        $this->assertNotEmpty($found, 'Expected custom field to be updated.');
        $this->assertEmpty($GLOBALS['fluentcrm_attached_tags'], 'Expected no tags when auto-create disabled.');
    }

    #[Test]
    public function testOnOrderPaidSyncsDisplayCurrencyAndRate(): void
    {
        $GLOBALS['fluentcrm_mock_contact'] = new \FluentCrm_Mock_Contact();

        $order = new class {
            public int $user_id = 1;
            private array $meta = ['_fchub_mc_display_currency' => 'EUR', '_fchub_mc_rate' => '0.92'];
            public function getMeta(string $key) { return $this->meta[$key] ?? null; }
        };

        FluentCrmSync::onOrderPaid($order);

        $updates = $GLOBALS['fluentcrm_custom_field_updates'];
        $currencyUpdate = array_filter($updates, fn($u) => $u['slug'] === 'last_order_display_currency' && $u['value'] === 'EUR');
        $rateUpdate = array_filter($updates, fn($u) => $u['slug'] === 'last_order_fx_rate' && $u['value'] === '0.92');

        $this->assertNotEmpty($currencyUpdate, 'Expected last_order_display_currency=EUR.');
        $this->assertNotEmpty($rateUpdate, 'Expected last_order_fx_rate=0.92.');
    }

    #[Test]
    public function testOnOrderPaidSkipsForGuestOrder(): void
    {
        $GLOBALS['fluentcrm_mock_contact'] = new \FluentCrm_Mock_Contact();

        $order = new class {
            public int $user_id = 0;
            public function getMeta(string $key) { return null; }
        };

        FluentCrmSync::onOrderPaid($order);

        $this->assertEmpty($GLOBALS['fluentcrm_custom_field_updates']);
    }

    #[Test]
    public function testOnContextSwitchedUsesCustomFieldKeyFromOptionStore(): void
    {
        $GLOBALS['fluentcrm_mock_contact'] = new \FluentCrm_Mock_Contact();
        $this->setOption(Constants::OPTION_SETTINGS, [
            'fluentcrm_field_preferred' => 'display_currency_pref',
        ]);

        FluentCrmSync::onContextSwitched('GBP', 1);

        $updates = $GLOBALS['fluentcrm_custom_field_updates'];
        $found = array_filter($updates, fn($u) => $u['slug'] === 'display_currency_pref' && $u['value'] === 'GBP');
        $this->assertNotEmpty($found, 'Expected custom field key from OptionStore to be used.');
    }

    #[Test]
    public function testOnOrderPaidUsesCustomFieldKeysFromOptionStore(): void
    {
        $GLOBALS['fluentcrm_mock_contact'] = new \FluentCrm_Mock_Contact();
        $this->setOption(Constants::OPTION_SETTINGS, [
            'fluentcrm_field_last_order' => 'custom_order_currency',
            'fluentcrm_field_last_rate'  => 'custom_fx_rate',
        ]);

        $order = new class {
            public int $user_id = 1;
            private array $meta = [
                '_fchub_mc_display_currency' => 'EUR',
                '_fchub_mc_rate'             => '0.92',
            ];
            public function getMeta(string $key) { return $this->meta[$key] ?? null; }
        };

        FluentCrmSync::onOrderPaid($order);

        $updates = $GLOBALS['fluentcrm_custom_field_updates'];
        $currencyUpdate = array_filter($updates, fn($u) => $u['slug'] === 'custom_order_currency');
        $rateUpdate = array_filter($updates, fn($u) => $u['slug'] === 'custom_fx_rate');

        $this->assertNotEmpty($currencyUpdate, 'Expected custom order currency field key from OptionStore.');
        $this->assertNotEmpty($rateUpdate, 'Expected custom FX rate field key from OptionStore.');
    }
}

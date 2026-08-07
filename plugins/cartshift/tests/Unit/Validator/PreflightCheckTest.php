<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\Validator;

use CartShift\Tests\Unit\PluginTestCase;
use CartShift\Validator\PreflightCheck;

require_once dirname(__DIR__, 2) . '/stubs/PreflightStubs.php';

/**
 * The preflight gate.
 *
 * Two things are being pinned down here. First, that a store on legacy post storage
 * cannot start a migration — that was the bug where CartShift read an empty
 * {prefix}wc_orders, counted zero of everything, skipped every order for a missing
 * customer, and reported success. Second, that readiness comes from real failures and
 * not from a hand-picked list, so an advisory warning about memory can no longer look
 * like a hard stop in the UI while quietly not blocking anything.
 */
final class PreflightCheckTest extends PluginTestCase
{
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $GLOBALS['_cartshift_test_hpos_enabled']      = false;
        $GLOBALS['_cartshift_test_hpos_sync_enabled'] = false;
        $GLOBALS['_cartshift_test_hpos_in_sync']      = true;
        $GLOBALS['_cartshift_test_order_util_throws'] = false;

        // Migration tables present, every COUNT() zero: a clean, empty target.
        $GLOBALS['_cartshift_test_get_var_callback'] = static function (string $query): string|null {
            if (str_contains($query, 'SHOW TABLES LIKE')) {
                return 'exists';
            }

            return '0';
        };

        $GLOBALS['_cartshift_test_get_results_callback'] = static fn(): array => [];
    }

    #[\Override]
    protected function tearDown(): void
    {
        unset(
            $GLOBALS['_cartshift_test_hpos_enabled'],
            $GLOBALS['_cartshift_test_hpos_sync_enabled'],
            $GLOBALS['_cartshift_test_hpos_in_sync'],
            $GLOBALS['_cartshift_test_order_util_throws'],
            $GLOBALS['_cartshift_test_get_var_callback'],
            $GLOBALS['_cartshift_test_get_results_callback'],
        );

        parent::tearDown();
    }

    // ──────────────────────────────────────────────
    // BUG 1: HPOS gate
    // ──────────────────────────────────────────────

    public function testHposDisabledBlocksMigration(): void
    {
        $GLOBALS['_cartshift_test_hpos_enabled'] = false;

        $result = (new PreflightCheck())->run();
        $check  = $result['checks']['order_storage'];

        $this->assertSame(PreflightCheck::SEVERITY_FAIL, $check['severity']);
        $this->assertFalse($check['pass']);
        $this->assertFalse($check['hpos']);
        $this->assertFalse($result['ready'], 'Legacy post storage must block the migration.');
    }

    /**
     * The message has to be actionable. An admin reading it should know where to click.
     */
    public function testHposFailureMessageTellsTheAdminWhatToDo(): void
    {
        $GLOBALS['_cartshift_test_hpos_enabled'] = false;

        $message = (new PreflightCheck())->run()['checks']['order_storage']['message'];

        $this->assertStringContainsString('High-Performance Order Storage', $message);
        $this->assertStringContainsString('WooCommerce > Settings > Advanced > Features', $message);
        $this->assertStringContainsString('sync', $message);
        $this->assertStringContainsString('preflight', $message);
    }

    public function testHposEnabledAllowsMigration(): void
    {
        $GLOBALS['_cartshift_test_hpos_enabled'] = true;

        $result = (new PreflightCheck())->run();
        $check  = $result['checks']['order_storage'];

        $this->assertSame(PreflightCheck::SEVERITY_PASS, $check['severity']);
        $this->assertTrue($check['pass']);
        $this->assertFalse($check['warning']);
        $this->assertTrue($check['hpos']);
        $this->assertTrue($result['ready']);
    }

    /**
     * Sync switched off is the recommended end state for an HPOS store, and
     * is_custom_order_tables_in_sync() returns false in exactly that situation. Warning
     * on it would put a yellow triangle on every healthy shop.
     */
    public function testSyncDisabledDoesNotWarn(): void
    {
        $GLOBALS['_cartshift_test_hpos_enabled']      = true;
        $GLOBALS['_cartshift_test_hpos_sync_enabled'] = false;

        $check = (new PreflightCheck())->run()['checks']['order_storage'];

        $this->assertSame(PreflightCheck::SEVERITY_PASS, $check['severity']);
        $this->assertFalse($check['warning']);
        $this->assertNull($check['pending_sync']);
    }

    /**
     * Sync on and behind means the posts-to-HPOS migration may still be running, so the
     * HPOS tables could be incomplete. Worth saying. Not worth blocking — we cannot tell
     * which side is stale, and refusing to migrate a store whose posts table is merely
     * out of date would be wrong.
     */
    public function testPendingSyncWarnsWithoutBlocking(): void
    {
        $GLOBALS['_cartshift_test_hpos_enabled']      = true;
        $GLOBALS['_cartshift_test_hpos_sync_enabled'] = true;
        $GLOBALS['_cartshift_test_hpos_in_sync']      = false;

        $result = (new PreflightCheck())->run();
        $check  = $result['checks']['order_storage'];

        $this->assertSame(PreflightCheck::SEVERITY_WARN, $check['severity']);
        $this->assertTrue($check['pass']);
        $this->assertTrue($check['warning']);
        $this->assertTrue($check['pending_sync']);
        $this->assertTrue($result['ready'], 'A pending sync is a warning, not a blocker.');
    }

    public function testSyncCaughtUpDoesNotWarn(): void
    {
        $GLOBALS['_cartshift_test_hpos_enabled']      = true;
        $GLOBALS['_cartshift_test_hpos_sync_enabled'] = true;
        $GLOBALS['_cartshift_test_hpos_in_sync']      = true;

        $check = (new PreflightCheck())->run()['checks']['order_storage'];

        $this->assertSame(PreflightCheck::SEVERITY_PASS, $check['severity']);
        $this->assertFalse($check['pending_sync']);
    }

    /**
     * OrderUtil resolves through WooCommerce's DI container and throws when WooCommerce
     * is half booted. The documented fallback is the raw opt-in option.
     */
    public function testFallsBackToOptionWhenOrderUtilThrows(): void
    {
        $GLOBALS['_cartshift_test_order_util_throws'] = true;
        update_option('woocommerce_custom_orders_table_enabled', 'yes');

        $check = (new PreflightCheck())->run()['checks']['order_storage'];

        $this->assertSame(PreflightCheck::SEVERITY_PASS, $check['severity']);
        $this->assertTrue($check['hpos']);
    }

    public function testFallbackOptionUnsetMeansHposIsOff(): void
    {
        $GLOBALS['_cartshift_test_order_util_throws'] = true;

        $check = (new PreflightCheck())->run()['checks']['order_storage'];

        $this->assertSame(PreflightCheck::SEVERITY_FAIL, $check['severity']);
    }

    // ──────────────────────────────────────────────
    // BUG 2: readiness reflects real failures
    // ──────────────────────────────────────────────

    /**
     * Every check now carries a severity, and readiness is computed from it. No more
     * hand-picked list of three checks that happen to count.
     */
    public function testEveryCheckCarriesASeverity(): void
    {
        $GLOBALS['_cartshift_test_hpos_enabled'] = true;

        $valid = [
            PreflightCheck::SEVERITY_PASS,
            PreflightCheck::SEVERITY_WARN,
            PreflightCheck::SEVERITY_FAIL,
        ];

        foreach ((new PreflightCheck())->run()['checks'] as $key => $check) {
            $this->assertArrayHasKey('severity', $check, "{$key} has no severity");
            $this->assertContains($check['severity'], $valid, "{$key} has a nonsense severity");

            // The UI reads pass/warning. They must agree with severity.
            $this->assertSame($check['severity'] !== PreflightCheck::SEVERITY_FAIL, $check['pass']);
            $this->assertSame($check['severity'] === PreflightCheck::SEVERITY_WARN, $check['warning']);
        }
    }

    public function testReadyIsFalseIfAndOnlyIfSomethingFails(): void
    {
        $GLOBALS['_cartshift_test_hpos_enabled'] = true;

        $result = (new PreflightCheck())->run();

        $failures = array_filter(
            $result['checks'],
            static fn(array $check): bool => $check['severity'] === PreflightCheck::SEVERITY_FAIL,
        );

        $this->assertSame($failures === [], $result['ready']);
    }

    public function testMissingMigrationTablesBlocks(): void
    {
        $GLOBALS['_cartshift_test_hpos_enabled'] = true;

        $GLOBALS['_cartshift_test_get_var_callback'] = static function (string $query): string|null {
            if (str_contains($query, 'SHOW TABLES LIKE') && str_contains($query, 'cartshift_')) {
                return null;
            }
            if (str_contains($query, 'SHOW TABLES LIKE')) {
                return 'exists';
            }

            return '0';
        };

        $result = (new PreflightCheck())->run();

        $this->assertSame(PreflightCheck::SEVERITY_FAIL, $result['checks']['migration_tables']['severity']);
        $this->assertFalse($result['ready']);
    }

    /**
     * The old code computed an "adequate" flag, reported it as `pass`, and then ignored
     * it when deciding readiness — so the UI drew a red cross next to a check that let
     * you through anyway. A low memory limit is advice, and now it says so.
     */
    public function testLowMemoryWarnsButNeverBlocks(): void
    {
        $GLOBALS['_cartshift_test_hpos_enabled'] = true;

        $original = ini_get('memory_limit');

        if (@ini_set('memory_limit', '128M') === false) {
            $this->markTestSkipped('memory_limit is not writable in this environment.');
        }

        try {
            $result = (new PreflightCheck())->run();
            $memory = $result['checks']['php_memory'];

            $this->assertSame(PreflightCheck::SEVERITY_WARN, $memory['severity']);
            $this->assertTrue($memory['pass'], 'Memory advice must never render as a hard failure.');
            $this->assertTrue($memory['warning']);
            $this->assertStringContainsString('Not a blocker', $memory['message']);
            $this->assertTrue($result['ready']);
        } finally {
            @ini_set('memory_limit', (string) $original);
        }
    }

    public function testAmpleMemoryPasses(): void
    {
        $GLOBALS['_cartshift_test_hpos_enabled'] = true;

        $original = ini_get('memory_limit');

        if (@ini_set('memory_limit', '512M') === false) {
            $this->markTestSkipped('memory_limit is not writable in this environment.');
        }

        try {
            $memory = (new PreflightCheck())->run()['checks']['php_memory'];

            $this->assertSame(PreflightCheck::SEVERITY_PASS, $memory['severity']);
            $this->assertFalse($memory['warning']);
        } finally {
            @ini_set('memory_limit', (string) $original);
        }
    }

    /**
     * Unsupported product types are skipped per-record and logged. Refusing to move 500
     * simple products over one grouped product would be theatre.
     */
    public function testUnsupportedProductTypesWarnWithoutBlocking(): void
    {
        $GLOBALS['_cartshift_test_hpos_enabled'] = true;

        $GLOBALS['_cartshift_test_get_results_callback'] = static function (string $query): array {
            if (str_contains($query, 'product_type')) {
                return [
                    (object) ['slug' => 'simple', 'count' => 500],
                    (object) ['slug' => 'grouped', 'count' => 1],
                ];
            }

            return [];
        };

        $result = (new PreflightCheck())->run();
        $check  = $result['checks']['product_types'];

        $this->assertSame(PreflightCheck::SEVERITY_WARN, $check['severity']);
        $this->assertTrue($check['pass']);
        $this->assertArrayHasKey('grouped', $check['unsupported']);
        $this->assertSame(1, $check['unsupported']['grouped']);
        $this->assertTrue($result['ready']);
    }

    /**
     * The bug this fixes: unsupported product types were excluded from
     * getProductTypes(), which countTotal() and fetchBatch() both filter on. That
     * makes them invisible in the migration summary rather than skipped-and-reported.
     * This check is where they finally get named, along with how many orders carry
     * them, so an admin can go find those orders instead of discovering the gap later.
     */
    public function testUnsupportedProductTypesAreReportedWithTheirOrderImpact(): void
    {
        $GLOBALS['_cartshift_test_hpos_enabled'] = true;

        $GLOBALS['_cartshift_test_get_results_callback'] = static function (string $query): array {
            if (str_contains($query, 'product_type')) {
                return [
                    (object) ['slug' => 'simple', 'count' => 13],
                    (object) ['slug' => 'course', 'count' => 2],
                ];
            }

            return [];
        };

        $GLOBALS['_cartshift_test_get_var_callback'] = static function (string $query): string|null {
            if (str_contains($query, 'SHOW TABLES LIKE')) {
                return 'exists';
            }
            if (str_contains($query, 'woocommerce_order_items')) {
                return '41';
            }
            if (str_contains($query, 'wc_orders')) {
                return '699';
            }

            return '0';
        };

        $result = (new PreflightCheck())->run();
        $check  = $result['checks']['product_types'];

        $this->assertSame(PreflightCheck::SEVERITY_WARN, $check['severity']);
        $this->assertSame(['course' => 2], $check['unsupported']);
        $this->assertStringContainsString('course', $check['message']);
        $this->assertStringContainsString('41', $check['message']);
        $this->assertStringContainsString('699', $check['message']);
        $this->assertSame(
            ['types' => ['course' => 2], 'orders_affected' => 41],
            $check['unsupported_product_types'],
        );
        $this->assertTrue($result['ready'], 'An unsupported type is advisory, not blocking.');
    }

    /**
     * The exact real-world numbers this task fixes: a live store (WooCommerce
     * 11.0.0, HPOS, 699 orders) with 27 products, 2 of them LearnDash `course`
     * products invisible to getProductTypes(), appearing in 41 of the 699 orders.
     */
    public function testUnsupportedProductTypesMatchesTheLiveStoreDefect(): void
    {
        $GLOBALS['_cartshift_test_hpos_enabled'] = true;

        $GLOBALS['_cartshift_test_get_results_callback'] = static function (string $query): array {
            if (str_contains($query, 'product_type')) {
                return [
                    (object) ['slug' => 'simple', 'count' => 25],
                    (object) ['slug' => 'course', 'count' => 2],
                ];
            }

            return [];
        };

        $GLOBALS['_cartshift_test_get_var_callback'] = static function (string $query): string|null {
            if (str_contains($query, 'SHOW TABLES LIKE')) {
                return 'exists';
            }
            if (str_contains($query, 'woocommerce_order_items')) {
                return '41';
            }
            if (str_contains($query, 'wc_orders')) {
                return '699';
            }

            return '0';
        };

        $check = (new PreflightCheck())->run()['checks']['product_types'];

        $this->assertSame(2, array_sum($check['unsupported']));
        $this->assertSame(27, array_sum($check['types']));
        $this->assertSame(41, $check['unsupported_product_types']['orders_affected']);
        $this->assertStringContainsString(
            '2 products use a type CartShift can\'t migrate (course). '
            . 'They appear in 41 of your 699 orders',
            $check['message'],
        );
    }

    /**
     * No unsupported types, nothing to report: the new key stays present but empty
     * rather than being omitted, so a generic UI reading it never has to guess.
     */
    public function testUnsupportedProductTypesKeyIsEmptyWhenEverythingIsSupported(): void
    {
        $GLOBALS['_cartshift_test_hpos_enabled'] = true;

        $GLOBALS['_cartshift_test_get_results_callback'] = static function (string $query): array {
            if (str_contains($query, 'product_type')) {
                return [
                    (object) ['slug' => 'simple', 'count' => 10],
                    (object) ['slug' => 'variable', 'count' => 5],
                ];
            }

            return [];
        };

        $check = (new PreflightCheck())->run()['checks']['product_types'];

        $this->assertSame(PreflightCheck::SEVERITY_PASS, $check['severity']);
        $this->assertSame([], $check['unsupported']);
        $this->assertSame(
            ['types' => [], 'orders_affected' => 0],
            $check['unsupported_product_types'],
        );
    }

    /**
     * Migration appends, it does not overwrite. Existing FluentCart data is a heads-up.
     */
    public function testExistingFluentCartDataWarnsWithoutBlocking(): void
    {
        $GLOBALS['_cartshift_test_hpos_enabled'] = true;

        $GLOBALS['_cartshift_test_get_var_callback'] = static function (string $query): string|null {
            if (str_contains($query, 'SHOW TABLES LIKE')) {
                return 'exists';
            }
            if (str_contains($query, 'fluent-products')) {
                return '7';
            }

            return '0';
        };

        $result = (new PreflightCheck())->run();
        $check  = $result['checks']['fc_data'];

        $this->assertSame(PreflightCheck::SEVERITY_WARN, $check['severity']);
        $this->assertTrue($check['pass']);
        $this->assertSame(7, $check['counts']['products']);
        $this->assertTrue($result['ready']);
    }

    /**
     * FluentCart 1.6.0 registers its product CPT as `fluent-products`
     * (app/CPT/FluentProducts.php). If that ever changes, this catches it.
     */
    public function testFluentCartProductsAreCountedByTheCorrectPostType(): void
    {
        $GLOBALS['_cartshift_test_hpos_enabled'] = true;

        $seen = [];

        $GLOBALS['_cartshift_test_get_var_callback'] = static function (string $query) use (&$seen): string|null {
            $seen[] = $query;

            return str_contains($query, 'SHOW TABLES LIKE') ? 'exists' : '0';
        };

        (new PreflightCheck())->run();

        $productQueries = array_filter($seen, static fn(string $q): bool => str_contains($q, 'post_type ='));

        $this->assertNotEmpty($productQueries);
        $this->assertStringContainsString("post_type = 'fluent-products'", implode("\n", $productQueries));
    }

    /**
     * WC Subscriptions is optional. Its absence trims the entity list; it is not a fault.
     */
    public function testSubscriptionsIsInformationalOnly(): void
    {
        $GLOBALS['_cartshift_test_hpos_enabled'] = true;

        $check = (new PreflightCheck())->run()['checks']['wc_subscriptions'];

        $this->assertSame(PreflightCheck::SEVERITY_PASS, $check['severity']);
        $this->assertTrue($check['optional']);
    }

    /**
     * The admin UI reads exactly these keys and renders the check list generically.
     */
    public function testResponseShapeStaysBackwardCompatible(): void
    {
        $GLOBALS['_cartshift_test_hpos_enabled'] = true;

        $result = (new PreflightCheck())->run();

        $this->assertArrayHasKey('checks', $result);
        $this->assertArrayHasKey('ready', $result);
        $this->assertIsBool($result['ready']);

        foreach ($result['checks'] as $key => $check) {
            $this->assertArrayHasKey('label', $check, "{$key} has no label");
            $this->assertArrayHasKey('message', $check, "{$key} has no message");
            $this->assertArrayHasKey('pass', $check, "{$key} has no pass");
            $this->assertArrayHasKey('warning', $check, "{$key} has no warning");
            $this->assertIsString($check['label']);
            $this->assertIsString($check['message']);
            $this->assertIsBool($check['pass']);
            $this->assertIsBool($check['warning']);
        }

        // fc_data.counts is read directly by PreflightScreen.vue.
        $this->assertArrayHasKey('counts', $result['checks']['fc_data']);
    }
}

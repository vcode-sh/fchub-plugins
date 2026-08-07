<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\Http\Controllers;

use CartShift\Validator\PreflightCheck;
use CartShift\Tests\Unit\PluginTestCase;

/**
 * Tests for PreflightCheck via direct instantiation.
 * PreflightController is a thin wrapper — testing the validator directly
 * is more meaningful and avoids routing complexity.
 */
final class PreflightControllerTest extends PluginTestCase
{
    /**
     * Clear the query callbacks.
     *
     * This class installs both, and PluginTestCase::setUp() does not reset
     * them, so without this they stay live for every test class that runs
     * afterwards — surfacing as a fatal in an unrelated file with nothing
     * pointing back here.
     *
     * Note the WooCommerce class this file eval()s into existence cannot be
     * torn down — a class declaration is process-global and there is no undo.
     *
     * That is benign only for as long as nothing expects the negative. A future
     * test asserting WooCommerce is *absent* — a preflight case covering the
     * "WooCommerce not installed" branch, say — will fail whenever it runs after
     * this file, and pass when run alone. It will look broken rather than
     * poisoned, so start here. The real fix is a separate process
     * (@runInSeparateProcess) for whichever test needs the negative, not
     * anything this tearDown can do.
     */
    #[\Override]
    protected function tearDown(): void
    {
        unset(
            $GLOBALS['_cartshift_test_get_var_callback'],
            $GLOBALS['_cartshift_test_get_results_callback'],
        );

        parent::tearDown();
    }

    /**
     * Verify table existence check detects missing migration tables.
     */
    public function testPreflightReturnsTableExistenceCheck(): void
    {
        // Configure wpdb::get_var to simulate "SHOW TABLES LIKE" results.
        // When both tables are missing, the check should fail.
        $GLOBALS['_cartshift_test_get_var_callback'] = function (string $query): string|null {
            if (str_contains($query, 'SHOW TABLES LIKE')) {
                if (str_contains($query, 'cartshift_id_map')) {
                    return 'wp_cartshift_id_map'; // exists
                }
                if (str_contains($query, 'cartshift_migration_log')) {
                    return null; // missing
                }
            }
            return null;
        };

        $check = new PreflightCheck();
        $result = $check->run();

        $this->assertArrayHasKey('migration_tables', $result['checks']);
        $tables = $result['checks']['migration_tables'];

        // One table missing => pass = false.
        $this->assertFalse($tables['pass']);
        $this->assertStringContainsString('cartshift_migration_log', $tables['message']);

        // Overall readiness should be false (migration_tables is required).
        $this->assertFalse($result['ready']);
    }

    /**
     * Verify product type breakdown reports counts and flags unsupported types.
     */
    public function testPreflightReturnsProductTypeBreakdown(): void
    {
        // Simulate WooCommerce being active. PreflightCheck gates on
        // class_exists('WooCommerce'), so the test needs the bare symbol to
        // exist and nothing more.
        //
        // The eval() is a fixed, hard-coded string with no interpolation and no
        // external input — it cannot execute anything a caller supplies, and it
        // runs only under PHPUnit, never in the shipped plugin. It is here
        // because the class must be declared conditionally at runtime, which a
        // normal declaration cannot do. Do not generalise it to take a variable.
        if (!class_exists('WooCommerce')) {
            // @phpcs:ignore
            eval('class WooCommerce {}');
        }

        // Return product type counts from the taxonomy query.
        $GLOBALS['_cartshift_test_get_results_callback'] = function (string $query, string $output): array {
            if (str_contains($query, 'product_type')) {
                return [
                    (object) ['slug' => 'simple', 'count' => 42],
                    (object) ['slug' => 'variable', 'count' => 15],
                    (object) ['slug' => 'grouped', 'count' => 3],
                    (object) ['slug' => 'external', 'count' => 1],
                ];
            }
            return [];
        };

        // Also need migration tables and FC checks to succeed for the test.
        $GLOBALS['_cartshift_test_get_var_callback'] = function (string $query): string|null {
            if (str_contains($query, 'SHOW TABLES')) {
                return 'exists';
            }
            return null;
        };

        $check = new PreflightCheck();
        $result = $check->run();

        $productTypes = $result['checks']['product_types'];

        $this->assertTrue($productTypes['pass']); // Never blocks migration.
        $this->assertTrue($productTypes['warning']); // Unsupported types present.
        $this->assertSame(42, $productTypes['types']['simple']);
        $this->assertSame(15, $productTypes['types']['variable']);
        $this->assertSame(3, $productTypes['types']['grouped']);
        $this->assertSame(1, $productTypes['types']['external']);
        $this->assertArrayHasKey('grouped', $productTypes['unsupported']);
        $this->assertArrayHasKey('external', $productTypes['unsupported']);
        $this->assertStringContainsString('unsupported', $productTypes['message']);
    }
}

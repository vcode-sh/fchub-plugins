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
        // Simulate WooCommerce being active.
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
        $this->assertContains('grouped', $productTypes['unsupported']);
        $this->assertContains('external', $productTypes['unsupported']);
        $this->assertStringContainsString('unsupported', $productTypes['message']);
    }
}

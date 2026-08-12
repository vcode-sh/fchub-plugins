<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\Http\Controllers;

use CartShift\Core\Container;
use CartShift\Http\Controllers\PreflightController;
use CartShift\State\MigrationState;
use CartShift\Storage\IdMapRepository;
use CartShift\Storage\MigrationLogRepository;
use CartShift\Validator\PreflightCheck;
use CartShift\Tests\Unit\PluginTestCase;

require_once dirname(__DIR__, 3) . '/stubs/EntityMigratorStubs.php';

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

    // ──────────────────────────────────────────────
    // The operation the wizard is asking about
    // ──────────────────────────────────────────────

    public function testTheEndpointDefaultsToGenericMigration(): void
    {
        $GLOBALS['_cartshift_test_get_var_callback'] = static fn (): string => 'exists';
        $GLOBALS['_cartshift_test_hpos_enabled'] = false;

        $response = $this->controller()->preflight(new \WP_REST_Request());
        $data = $response->get_data()['data'];

        $this->assertSame(PreflightCheck::OPERATION_MIGRATION, $data['operation']);
        $this->assertSame(
            PreflightCheck::SEVERITY_FAIL,
            $data['checks']['order_storage']['severity'],
            'The generic path keeps its HPOS gate.',
        );
    }

    public function testTheSubscriptionDatasetOperationPassesOnLegacyStorage(): void
    {
        $GLOBALS['_cartshift_test_get_var_callback'] = static fn (): string => 'exists';
        $GLOBALS['_cartshift_test_hpos_enabled'] = false;

        $request = new \WP_REST_Request();
        $request->set_param('operation', PreflightCheck::OPERATION_SUBSCRIPTION_DATASET);

        $data = $this->controller()->preflight($request)->get_data()['data'];

        $this->assertSame(PreflightCheck::OPERATION_SUBSCRIPTION_DATASET, $data['operation']);
        $this->assertSame('public_api', $data['checks']['order_storage']['access']);
        $this->assertSame('posts', $data['checks']['order_storage']['storage_authority']);
    }

    public function testAnUnknownOperationIsRefusedRatherThanDefaulted(): void
    {
        $request = new \WP_REST_Request();
        $request->set_param('operation', 'whatever-feels-right');

        $response = $this->controller()->preflight($request);

        $this->assertSame(400, $response->get_status());
        $this->assertSame('cartshift_unknown_operation', $response->get_data()['code']);
    }

    // ──────────────────────────────────────────────
    // A count that cannot be taken is not a count of zero
    // ──────────────────────────────────────────────

    /**
     * The assertion is `null`, and it has to be, because `0` is the bug.
     *
     * `WooSubscriptionDatasetSource::selectionIndex()` returns an empty index
     * when `wcs_get_subscriptions()` is absent, so the count came out 0 and a
     * shop with 564 subscribers read exactly like a shop with none. Asserting
     * "not zero" would pass for a fatal; asserting `null` is the only assertion
     * that says the endpoint declined to answer.
     */
    public function testSubscriptionsAreReportedAsUncountableRatherThanZeroWithoutTheApis(): void
    {
        $data = $this->countsWithout('wcs_get_subscriptions', 'wcs_get_subscription');

        $this->assertNull($data['counts']['subscription']);
        $this->assertSame('wc_subscriptions_inactive', $data['unavailable']['subscription']['reason']);
        $this->assertSame(
            ['wcs_get_subscriptions', 'wcs_get_subscription'],
            $data['unavailable']['subscription']['missing_apis'],
        );
        $this->assertStringContainsString(
            'not a count of zero',
            $data['unavailable']['subscription']['message'],
        );
    }

    /**
     * The optional add-on takes the subscription count with it and nothing else.
     */
    public function testEveryOtherEntityIsStillCountedWithoutTheSubscriptionApis(): void
    {
        $counts = $this->countsWithout('wcs_get_subscriptions')['counts'];

        foreach (['product', 'customer', 'coupon', 'order'] as $entity) {
            $this->assertArrayHasKey($entity, $counts);
            $this->assertIsInt($counts[$entity], sprintf('%s must still be counted.', $entity));
        }

        $this->assertArrayHasKey('fc_product_count', $counts);
    }

    /**
     * Not a vacuous pass: with every API present the count is a number again and
     * nothing is reported unavailable.
     */
    public function testTheSubscriptionCountIsANumberWhenTheApisArePresent(): void
    {
        $data = $this->countsWith(new PreflightCheck());

        $this->assertIsInt($data['counts']['subscription']);
        $this->assertSame([], $data['unavailable']);
    }

    /**
     * @return array<string, mixed>
     */
    private function countsWithout(string ...$functions): array
    {
        $symbols = new \CartShift\Tests\Unit\Domain\Subscription\FakeRuntimeSymbols();

        foreach ($functions as $function) {
            $symbols = $symbols->withoutFunction($function);
        }

        return $this->countsWith(new PreflightCheck($symbols));
    }

    /**
     * @return array<string, mixed>
     */
    private function countsWith(PreflightCheck $preflight): array
    {
        $GLOBALS['_cartshift_test_get_var_callback'] = static fn (): string => '0';

        $container = new Container();
        $container->singleton(IdMapRepository::class, static fn (): IdMapRepository => new IdMapRepository());
        $container->singleton(
            MigrationLogRepository::class,
            static fn (): MigrationLogRepository => new MigrationLogRepository(),
        );
        $container->singleton(MigrationState::class, static fn (): MigrationState => new MigrationState());

        return (new PreflightController($container, $preflight))
            ->counts(new \WP_REST_Request())
            ->get_data()['data'];
    }

    // ──────────────────────────────────────────────
    // The vocabulary, read back out of the admin app
    // ──────────────────────────────────────────────

    /**
     * Every operation the admin app asks for is one this endpoint answers.
     *
     * `TransferSafetyScreen.vue` shipped `preflight?operation=subscription`.
     * That is not a member of `PreflightCheck::OPERATIONS`, so every visit to
     * the screen opened on a 400 and a perfectly healthy shop read as broken.
     * The component test could not catch it: its mock had been written from the
     * component, so it answered the invented name and agreed with itself.
     *
     * Hence this. The operations are lifted out of the shipped source — the Vue
     * sources and the built bundle both, so a rebuilt-but-stale asset is caught
     * too — and each one is put through the real controller. Nothing here can
     * accept a name the endpoint refuses, because the endpoint is what answers.
     */
    public function testEveryPreflightOperationTheAdminAppAsksForIsOneTheEndpointAnswers(): void
    {
        $GLOBALS['_cartshift_test_get_var_callback'] = static fn (): string => 'exists';

        $requested = self::requestedOperations();

        $this->assertNotSame(
            [],
            $requested,
            'No preflight request found in the admin sources. Either the screen stopped asking or this '
            . 'scan stopped looking — both make the assertion below vacuous.',
        );

        foreach ($requested as $operation => $files) {
            $request = new \WP_REST_Request();
            $request->set_param('operation', $operation);

            $response = $this->controller()->preflight($request);

            $this->assertSame(
                200,
                $response->get_status(),
                sprintf(
                    'The admin app asks for preflight operation "%s" (%s), which this endpoint refuses. '
                    . 'Accepted: %s.',
                    $operation,
                    implode(', ', $files),
                    implode(', ', PreflightCheck::OPERATIONS),
                ),
            );
            $this->assertSame($operation, $response->get_data()['data']['operation']);
        }
    }

    /**
     * @return array<string, list<string>> Operation => the files asking for it.
     */
    private static function requestedOperations(): array
    {
        $root = dirname(__DIR__, 4);

        $sources = [
            ...glob($root . '/src/components/*.vue') ?: [],
            ...glob($root . '/src/composables/*.js') ?: [],
            // The bundle the browser actually loads. A source fixed and an asset
            // left unbuilt is the same 400 for the admin looking at the screen.
            ...glob($root . '/resources/admin/dist/assets/*.js') ?: [],
        ];

        $requested = [];

        foreach ($sources as $file) {
            preg_match_all(
                '/preflight\?operation=([A-Za-z0-9_-]+)/',
                (string) file_get_contents($file),
                $matches,
            );

            foreach ($matches[1] as $operation) {
                $requested[$operation][] = str_replace($root . '/', '', $file);
            }
        }

        return $requested;
    }

    private function controller(): PreflightController
    {
        return new PreflightController(new Container());
    }
}

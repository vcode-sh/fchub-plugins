<?php

declare(strict_types=1);

namespace CartShift\Tests\Integration\CLI;

use CartShift\CLI\MigrateCommand;
use CartShift\CLI\SubscriptionCommand;
use CartShift\Core\Container;
use CartShift\Domain\Migration\BatchProcessor;
use CartShift\Http\Controllers\FinalizeController;
use CartShift\Http\Controllers\MappingController;
use CartShift\Http\Controllers\MigrationController;
use CartShift\Http\Controllers\RollbackController;
use CartShift\Http\Controllers\SubscriptionPackageController;
use CartShift\State\MigrationState;
use CartShift\Tests\Unit\PluginTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use WP_REST_Request;

require_once dirname(__DIR__, 2) . '/stubs/HttpCliStubs.php';

final class LegacyEntryPointTest extends PluginTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['_cartshift_test_wp_cli'] = [];
    }

    /** @return list<array{callable, string, string}> */
    public static function cliWrites(): array
    {
        return [
            [[MigrateCommand::class, 'migrate'], 'legacy_generic_migration_closed', 'wp cartshift transfer prepare'],
            [[MigrateCommand::class, 'retry'], 'legacy_generic_migration_closed', 'wp cartshift transfer stage'],
            [[MigrateCommand::class, 'rollback'], 'legacy_generic_migration_closed', 'wp cartshift transfer rollback'],
            [[MigrateCommand::class, 'reset'], 'legacy_generic_migration_closed', 'wp cartshift transfer status'],
            [[MigrateCommand::class, 'finalize'], 'legacy_generic_migration_closed', 'wp cartshift transfer promote'],
            [[SubscriptionCommand::class, 'preparePackage'], 'legacy_subscription_v1_package_write_closed', 'wp cartshift transfer validate-package'],
            [[SubscriptionCommand::class, 'forgetPackage'], 'legacy_subscription_v1_package_write_closed', 'wp cartshift transfer validate-package'],
            [[SubscriptionCommand::class, 'deletePackage'], 'legacy_subscription_v1_package_write_closed', 'wp cartshift transfer validate-package'],
            [[SubscriptionCommand::class, 'stage'], 'legacy_subscription_v1_write_closed', 'wp cartshift transfer stage'],
            [[SubscriptionCommand::class, 'cutoverSource'], 'legacy_subscription_v1_write_closed', 'wp cartshift transfer stage'],
            [[SubscriptionCommand::class, 'activate'], 'legacy_subscription_v1_write_closed', 'wp cartshift transfer activate-catalogue'],
            [[SubscriptionCommand::class, 'reconcile'], 'legacy_subscription_v1_write_closed', 'wp cartshift transfer reconcile'],
            [[SubscriptionCommand::class, 'restoreSource'], 'legacy_subscription_v1_write_closed', 'wp cartshift transfer rollback'],
        ];
    }

    #[DataProvider('cliWrites')]
    public function testLegacyCliWritesRefuseBeforeAnyObservableMutation(
        callable $command,
        string $code,
        string $nextCommand,
    ): void {
        $beforeOptions = $GLOBALS['_cartshift_test_options'];
        $command(['../../hostile'], [
            'entities' => 'products,orders,customers',
            'dry-run' => true,
            'force' => true,
            'confirm' => true,
            'receipt' => '/tmp/should-not-exist.ndjson',
        ]);

        $message = $this->lastCliError();
        self::assertStringContainsString('[' . $code . ']', $message);
        self::assertStringContainsString($nextCommand, $message);
        self::assertSame($beforeOptions, $GLOBALS['_cartshift_test_options']);
        $this->assertNoMutation();
    }

    /** @return list<array{class-string, string, string, string}> */
    public static function restWrites(): array
    {
        return [
            [MigrationController::class, 'migrate', 'legacy_generic_migration_closed', 'wp cartshift transfer prepare'],
            [MigrationController::class, 'retry', 'legacy_generic_migration_closed', 'wp cartshift transfer stage'],
            [MigrationController::class, 'batch', 'legacy_generic_migration_closed', 'wp cartshift transfer stage'],
            [MigrationController::class, 'cancel', 'legacy_generic_migration_closed', 'wp cartshift transfer status'],
            [MigrationController::class, 'reset', 'legacy_generic_migration_closed', 'wp cartshift transfer status'],
            [FinalizeController::class, 'finalize', 'legacy_generic_migration_closed', 'wp cartshift transfer promote'],
            [RollbackController::class, 'rollback', 'legacy_generic_migration_closed', 'wp cartshift transfer rollback'],
            [MappingController::class, 'decide', 'legacy_mapping_write_closed', 'wp cartshift transfer prepare'],
            [MappingController::class, 'bulk', 'legacy_mapping_write_closed', 'wp cartshift transfer prepare'],
            [MappingController::class, 'clear', 'legacy_mapping_write_closed', 'wp cartshift transfer prepare'],
            [SubscriptionPackageController::class, 'prepare', 'legacy_subscription_v1_package_write_closed', 'wp cartshift transfer validate-package'],
            [SubscriptionPackageController::class, 'forget', 'legacy_subscription_v1_package_write_closed', 'wp cartshift transfer validate-package'],
        ];
    }

    #[DataProvider('restWrites')]
    public function testLegacyRestWritesReturnGoneAndCannotReachARepository(
        string $controllerClass,
        string $method,
        string $code,
        string $nextCommand,
    ): void {
        $response = (new $controllerClass(new Container()))->{$method}($this->hostileRequest());

        self::assertSame(410, $response->get_status());
        self::assertSame($code, $response->get_data()['data']['code']);
        self::assertSame($nextCommand, $response->get_data()['data']['next_command']);
        self::assertSame(['nothing' => true], $response->get_data()['data']['writes']);
        $this->assertNoMutation();
    }

    public function testLegacyScheduledBatchCannotResumeEvenAConvincingRunningState(): void
    {
        $state = new MigrationState();
        $state->start(['product']);
        $migrationId = (string) $state->getMigrationId();
        $called = false;
        $processor = new BatchProcessor(
            static function () use (&$called): never {
                $called = true;
                throw new \LogicException('Legacy scheduled callback reached its writer.');
            },
            $state,
        );

        $processor->handleBatch($migrationId);
        $processor->scheduleFirst($migrationId);

        self::assertFalse($called);
        self::assertSame([], $GLOBALS['_cartshift_test_as_scheduled']);
        self::assertSame($migrationId, (new MigrationState())->getMigrationId());
        $this->assertNoMutation();
    }

    private function hostileRequest(): WP_REST_Request
    {
        $request = new WP_REST_Request();
        foreach ([
            'migration_id' => "../wrong\0id",
            'force' => true,
            'decision' => 'link',
            'rows' => [['wc_id' => 1, 'fc_post_id' => 2]],
            'path' => '/tmp/should-not-exist.ndjson',
        ] as $key => $value) {
            $request->set_param($key, $value);
        }

        return $request;
    }

    private function lastCliError(): string
    {
        $errors = array_values(array_filter(
            $GLOBALS['_cartshift_test_wp_cli'],
            static fn (array $entry): bool => $entry['level'] === 'error',
        ));
        self::assertCount(1, $errors);

        return (string) $errors[0]['message'];
    }

    private function assertNoMutation(): void
    {
        self::assertSame([], $GLOBALS['_cartshift_test_queries']);
        self::assertSame([], $GLOBALS['_cartshift_test_as_scheduled']);
        self::assertSame([], $GLOBALS['_cartshift_test_as_unscheduled']);
        self::assertSame([], $GLOBALS['_cartshift_test_deleted_posts']);
        self::assertSame([], $GLOBALS['_cartshift_test_deleted_terms']);
    }
}

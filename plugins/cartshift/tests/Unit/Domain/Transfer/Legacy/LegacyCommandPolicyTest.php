<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\Domain\Transfer\Legacy;

use CartShift\CLI\MigrateCommand;
use CartShift\CLI\SubscriptionCommand;
use CartShift\CLI\TransferCommand;
use CartShift\Core\Container;
use CartShift\Domain\Migration\BatchProcessor;
use CartShift\Domain\Transfer\Legacy\LegacyCommandPolicy;
use CartShift\Modules\Migration\MigrationModule;
use CartShift\State\MigrationState;
use CartShift\Tests\Unit\PluginTestCase;

final class LegacyCommandPolicyTest extends PluginTestCase
{
    public function testEveryRuntimeMigrationEntryPointHasAClosedEffectClassification(): void
    {
        $GLOBALS['_cartshift_test_wp_cli_commands'] = [];
        $GLOBALS['_cartshift_test_rest_routes'] = [];

        MigrateCommand::register();
        SubscriptionCommand::register();
        TransferCommand::register();

        $container = new Container();
        foreach (MigrationModule::controllerClasses() as $class) {
            (new $class($container))->registerRoutes();
        }

        $processor = new BatchProcessor(static fn () => throw new \LogicException('Inventory must not execute a batch.'), new MigrationState());
        $processor->register();

        $actual = [];
        foreach (array_keys($GLOBALS['_cartshift_test_wp_cli_commands']) as $command) {
            $actual[] = 'cli:' . $command;
        }
        foreach ($GLOBALS['_cartshift_test_rest_routes'] as $path => $route) {
            $actual[] = 'rest:' . strtoupper((string) $route['methods']) . ':' . $path;
        }
        foreach (array_keys($GLOBALS['_cartshift_test_actions']) as $hook) {
            if ($hook === BatchProcessor::hookName()) {
                $actual[] = 'action:' . $hook;
            }
        }
        sort($actual, SORT_STRING);

        $classified = array_keys(LegacyCommandPolicy::inventory());
        sort($classified, SORT_STRING);

        self::assertSame($actual, $classified, 'A runtime migration callback was added, removed, or renamed without an effect classification.');
        self::assertNotContains('none', LegacyCommandPolicy::effectsFor('cli:cartshift migrate'));
        self::assertContains('id_map', LegacyCommandPolicy::effectsFor('cli:cartshift migrate'));
        self::assertContains('target_commerce_rows', LegacyCommandPolicy::effectsFor('rest:POST:cartshift/v1/migrate'));
        self::assertSame(['none'], LegacyCommandPolicy::effectsFor('rest:POST:cartshift/v1/preview'));
        self::assertContains('configuration_option', LegacyCommandPolicy::effectsFor('rest:POST:cartshift/v1/subscriptions/packages/prepare'));
        self::assertContains('scheduled_actions', LegacyCommandPolicy::effectsFor('action:' . BatchProcessor::hookName()));
        self::assertSame(
            ['id_map', 'journal', 'private_files', 'target_commerce_rows', 'wordpress_files'],
            LegacyCommandPolicy::effectsFor('rest:POST:cartshift/v1/migration/start'),
        );
        self::assertSame(
            ['private_files'],
            LegacyCommandPolicy::effectsFor('rest:POST:cartshift/v1/migration/cancel'),
        );
        self::assertSame(
            ['configuration_option', 'private_files'],
            LegacyCommandPolicy::effectsFor('rest:POST:cartshift/v1/migration/initialise'),
        );
        self::assertSame(
            ['id_map', 'journal', 'private_files', 'target_commerce_rows', 'wordpress_files'],
            LegacyCommandPolicy::effectsFor('rest:POST:cartshift/v1/migration/rollback'),
        );
        self::assertSame([], array_filter(
            array_keys($GLOBALS['_cartshift_test_actions']),
            static fn (string $hook): bool => str_starts_with($hook, 'wp_ajax_'),
        ), 'An AJAX migration callback appeared outside the classified REST/CLI contract.');
    }

    public function testLegacyWritesHaveStableRefusalsAndExactV2Replacements(): void
    {
        $policy = new LegacyCommandPolicy();

        self::assertSame([
            'reason_code' => 'legacy_generic_migration_closed',
            'next_command' => 'wp cartshift transfer prepare',
        ], $policy->refusal('cli:cartshift migrate'));
        self::assertSame([
            'reason_code' => 'legacy_subscription_v1_write_closed',
            'next_command' => 'wp cartshift transfer stage',
        ], $policy->refusal('cli:cartshift subscriptions stage'));
        self::assertSame([
            'reason_code' => 'legacy_mapping_write_closed',
            'next_command' => 'wp cartshift transfer prepare',
        ], $policy->refusal('rest:POST:cartshift/v1/mapping/decide'));

        $this->expectExceptionMessage('legacy_entry_point_not_classified');
        $policy->refusal('rest:POST:cartshift/v1/surprise-write');
    }
}

<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\CLI;

use CartShift\CLI\SubscriptionCommand;
use CartShift\Tests\Unit\PluginTestCase;

require_once dirname(__DIR__, 2) . '/stubs/PreflightStubs.php';

/**
 * `wp cartshift subscriptions compatibility`.
 *
 * The WP-CLI stubs are no-ops, so what is worth asserting here is what the
 * command does to the world rather than what it prints: it must refuse a role
 * it does not understand before touching anything, and it must leave the
 * database exactly as it found it. The rendering is tested through the private
 * methods that produce it, the same way MigrateCommandTest reaches its own.
 */
final class SubscriptionCommandTest extends PluginTestCase
{
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $GLOBALS['_cartshift_test_fc_gateways'] = [
            'stripe' => \CartShiftFakeGateway::stripe(),
            'paypal' => \CartShiftFakeGateway::paypal(),
        ];

        $GLOBALS['_cartshift_test_get_results_callback'] = static fn (): array => [];

        // Seeded, because the WP-CLI stub only records when a test is watching.
        $GLOBALS['_cartshift_test_wp_cli'] = [];
    }

    #[\Override]
    protected function tearDown(): void
    {
        unset($GLOBALS['_cartshift_test_get_results_callback']);

        parent::tearDown();
    }

    public function testEverySubcommandMapsToAPublicMethod(): void
    {
        $subcommands = SubscriptionCommand::subcommands();

        $this->assertArrayHasKey('compatibility', $subcommands);

        foreach ($subcommands as $name => $method) {
            $this->assertTrue(
                method_exists(SubscriptionCommand::class, $method),
                sprintf('`wp cartshift subscriptions %s` has no method behind it.', $name),
            );
        }
    }

    public function testAMissingRoleIsRefusedBeforeAnythingIsRead(): void
    {
        SubscriptionCommand::compatibility([], []);

        $this->assertSame([], $GLOBALS['_cartshift_test_queries']);
    }

    public function testAnUnknownRoleIsRefusedBeforeAnythingIsRead(): void
    {
        SubscriptionCommand::compatibility([], ['role' => 'referee']);

        $this->assertSame([], $GLOBALS['_cartshift_test_queries']);
    }

    public function testAnUnknownFormatIsRefusedBeforeAnythingIsRead(): void
    {
        SubscriptionCommand::compatibility([], ['role' => 'target', 'format' => 'interpretive-dance']);

        $this->assertSame([], $GLOBALS['_cartshift_test_queries']);
    }

    public function testTheCommandWritesNothing(): void
    {
        SubscriptionCommand::compatibility([], ['role' => 'target', 'format' => 'json']);
        SubscriptionCommand::compatibility([], ['role' => 'source', 'format' => 'json']);

        foreach ($GLOBALS['_cartshift_test_queries'] as $recorded) {
            $this->assertNotContains(
                $recorded[0],
                ['insert', 'update', 'delete', 'replace'],
                sprintf('The compatibility command must not write: %s', json_encode($recorded)),
            );
        }

        $this->assertSame([], $GLOBALS['_cartshift_test_options']);
        $this->assertSame([], $GLOBALS['_cartshift_test_transients'] ?? []);
        $this->assertSame([], $GLOBALS['_cartshift_test_as_scheduled']);
        $this->assertSame([], $GLOBALS['_cartshift_test_deleted_posts']);
    }

    public function testJsonOutputIsTheSortedReportIncludingItsFingerprint(): void
    {
        $report = (new \CartShift\Domain\Subscription\RuntimeCompatibilityProbe())->inspect('target');

        $json = (new \ReflectionMethod(SubscriptionCommand::class, 'renderJson'))
            ->invoke(null, $report);

        $decoded = json_decode((string) $json, true);

        $this->assertIsArray($decoded);
        $this->assertSame($report->toArray(), $decoded);
        $this->assertSame($report->fingerprint(), $decoded['fingerprint']);
    }

    public function testTableOutputFlattensTheReportIntoCheckAndResultRows(): void
    {
        $report = (new \CartShift\Domain\Subscription\RuntimeCompatibilityProbe())->inspect('target');

        $rows = (new \ReflectionMethod(SubscriptionCommand::class, 'renderTable'))
            ->invoke(null, $report);

        $this->assertNotEmpty($rows);

        foreach ($rows as $row) {
            $this->assertSame(['Check', 'Result'], array_keys($row));
            $this->assertIsString($row['Check']);
            $this->assertIsString($row['Result']);
        }

        $checks = array_column($rows, 'Check');

        $this->assertContains('role', $checks);
        $this->assertContains('topology', $checks);
        $this->assertContains('fingerprint', $checks);
    }

    // ──────────────────────────────────────────────
    // The staged cutover commands
    // ──────────────────────────────────────────────

    /**
     * The exact six the plan's command list names, so a renamed subcommand
     * cannot silently become "the operator's runbook no longer works".
     */
    public function testTheStagedCutoverSubcommandsAreRegistered(): void
    {
        foreach (['stage', 'cutover-source', 'activate', 'reconcile', 'restore-source'] as $name) {
            $this->assertArrayHasKey($name, SubscriptionCommand::subcommands());
        }
    }

    /**
     * @return list<array{0: string}>
     */
    public static function receiptRequiringSubcommands(): array
    {
        return [['stage'], ['cutoverSource'], ['activate'], ['reconcile'], ['restoreSource']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('receiptRequiringSubcommands')]
    public function testEveryStagedCommandRefusesWithoutAReceiptBeforeReadingAnything(string $method): void
    {
        SubscriptionCommand::$method([], ['confirm' => true, 'renewals-paused' => true]);

        $this->assertSame([], $GLOBALS['_cartshift_test_queries']);
        $this->assertSame('error', $this->lastCliMessage()['level']);
    }

    /**
     * @return list<array{0: string}>
     */
    public static function confirmRequiringSubcommands(): array
    {
        return [['stage'], ['cutoverSource'], ['activate'], ['restoreSource']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('confirmRequiringSubcommands')]
    public function testEveryMutatingCommandRefusesWithoutConfirm(string $method): void
    {
        SubscriptionCommand::$method([], [
            'receipt'         => '/srv/private/receipt.ndjson',
            'renewals-paused' => true,
        ]);

        $this->assertSame([], $GLOBALS['_cartshift_test_queries']);
        $this->assertStringContainsString('legacy_subscription_v1_write_closed', $this->lastCliMessage()['message']);
        $this->assertStringContainsString('wp cartshift transfer', $this->lastCliMessage()['message']);
    }

    /**
     * The whole point of the flag, at the command boundary: no acknowledgement,
     * no read, no mutation, and the stable code an operator can grep for.
     */
    public function testCutoverSourceRefusesWithoutTheRenewalsPausedAcknowledgement(): void
    {
        SubscriptionCommand::cutoverSource([], [
            'receipt' => '/srv/private/receipt.ndjson',
            'confirm' => true,
        ]);

        $this->assertSame([], $GLOBALS['_cartshift_test_queries']);
        $this->assertNotSame([], array_filter(
            $GLOBALS['_cartshift_test_wp_cli'],
            static fn (array $entry): bool => str_contains(
                $entry['message'],
                'legacy_subscription_v1_write_closed',
            ),
        ));
    }

    /**
     * WP-CLI hands `--renewals-paused=0` through as the string `'0'`, and a
     * `!== false` test reads that as a yes. On the one flag whose entire purpose
     * is to record a deliberate human statement, that is not a defensible
     * reading — so the falsy spellings are refused like an absent flag.
     *
     * @return list<array{0: mixed}>
     */
    public static function falsySpellings(): array
    {
        return [['0'], ['false'], ['no'], ['off'], ['']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('falsySpellings')]
    public function testAFalsyRenewalsPausedValueIsNotAnAcknowledgement(mixed $value): void
    {
        SubscriptionCommand::cutoverSource([], [
            'receipt'         => '/srv/private/receipt.ndjson',
            'confirm'         => true,
            'renewals-paused' => $value,
        ]);

        $this->assertNotSame([], array_filter(
            $GLOBALS['_cartshift_test_wp_cli'],
            static fn (array $entry): bool => str_contains(
                $entry['message'],
                'legacy_subscription_v1_write_closed',
            ),
        ));
    }

    /**
     * `reconcile` compares and reports; it changes no commerce data on either
     * side, so demanding --confirm for it would train operators to type it.
     */
    public function testReconcileNeedsNoConfirmation(): void
    {
        SubscriptionCommand::reconcile([], ['receipt' => '/srv/private/nothing-here.ndjson']);

        $messages = implode(' ', array_column($GLOBALS['_cartshift_test_wp_cli'], 'message'));

        $this->assertStringNotContainsString('--confirm', $messages);
    }

    // ──────────────────────────────────────────────
    // stage revalidates the file against what was prepared
    // ──────────────────────────────────────────────

    /**
     * A file swapped between `prepare-package` and `stage`.
     *
     * §6.5 and `preparePackage()`'s own docblock both promise the package is
     * revalidated byte for byte against the descriptor. The descriptor was being
     * read for a path and nothing else, so a DIFFERENT but internally consistent
     * export passed its own header check happily — and the mapping decisions the
     * operator approved in between were made against the first file.
     */
    public function testStagingRefusesAPackageThatIsNotTheOneThatWasPrepared(): void
    {
        $workspace = realpath(sys_get_temp_dir()) . '/cartshift-cli-' . bin2hex(random_bytes(6));
        mkdir($workspace, 0700, true);

        $path = $workspace . '/package.ndjson';

        $written = (new \CartShift\Domain\Subscription\Package\SubscriptionPackageWriter())->write(
            $path,
            $this->emptyDataset('lapka-club'),
            \CartShift\Domain\Subscription\SubscriptionSelection::all('lapka-club'),
        );

        $this->assertSame([], $written['failures'], 'The fixture export must succeed.');

        // Prepared as something else, and then replaced at the same path.
        (new \CartShift\Domain\Subscription\Package\PackageContextRepository())->remember(
            'lapka-club',
            (string) $written['path'],
            str_repeat('b', 64),
            'selection-fingerprint',
        );

        $GLOBALS['_cartshift_test_wp_cli'] = [];

        SubscriptionCommand::stage([], [
            'receipt'    => $workspace . '/receipt.ndjson',
            'source'     => 'package',
            'source-key' => 'lapka-club',
            'confirm'    => true,
        ]);

        $message = $this->lastCliMessage();

        $this->assertSame('error', $message['level']);
        $this->assertStringContainsString('legacy_subscription_v1_write_closed', $message['message']);
        $this->assertStringContainsString('wp cartshift transfer stage', $message['message']);
        $this->assertFileDoesNotExist($workspace . '/receipt.ndjson', 'Nothing may be staged.');

        // Naming the same swapped file explicitly must not bypass the prepared
        // descriptor for this source key.
        $GLOBALS['_cartshift_test_wp_cli'] = [];

        SubscriptionCommand::stage([], [
            'receipt'    => $workspace . '/receipt.ndjson',
            'file'       => $path,
            'source-key' => 'lapka-club',
            'confirm'    => true,
        ]);

        $message = $this->lastCliMessage();

        $this->assertSame('error', $message['level']);
        $this->assertStringContainsString('legacy_subscription_v1_write_closed', $message['message']);
        $this->assertFileDoesNotExist($workspace . '/receipt.ndjson', 'Explicit --file changes no checksum.');

        foreach ((array) glob($workspace . '/*') as $file) {
            @unlink((string) $file);
        }

        @rmdir($workspace);
    }

    public function testStagingUsesTheSelectionFrozenIntoThePackage(): void
    {
        $workspace = realpath(sys_get_temp_dir()) . '/cartshift-cli-' . bin2hex(random_bytes(6));
        mkdir($workspace, 0700, true);
        $path = $workspace . '/narrowed.ndjson';
        $receiptPath = $workspace . '/receipt.ndjson';
        $selection = new \CartShift\Domain\Subscription\SubscriptionSelection(
            'lapka-club',
            [],
            [],
            [910_116],
        );

        $written = (new \CartShift\Domain\Subscription\Package\SubscriptionPackageWriter())->write(
            $path,
            $this->emptyDataset('lapka-club'),
            $selection,
        );
        $this->assertSame([], $written['failures']);

        SubscriptionCommand::stage([], [
            'receipt'    => $receiptPath,
            'file'       => $path,
            'source-key' => 'lapka-club',
            'confirm'    => true,
        ]);

        $this->assertFileDoesNotExist($receiptPath);
        $this->assertStringContainsString('legacy_subscription_v1_write_closed', $this->lastCliMessage()['message']);
        $this->assertStringContainsString('wp cartshift transfer stage', $this->lastCliMessage()['message']);

        foreach ((array) glob($workspace . '/*') as $file) {
            @unlink((string) $file);
        }

        @rmdir($workspace);
    }

    /**
     * A package source with a manifest and no records — enough to be a valid
     * package, and nothing this test has to reason about.
     */
    private function emptyDataset(string $sourceKey): \CartShift\Domain\Subscription\SubscriptionDatasetSource
    {
        return new class ($sourceKey) implements \CartShift\Domain\Subscription\SubscriptionDatasetSource {
            public function __construct(private readonly string $sourceKey)
            {
            }

            public function manifest(): \CartShift\Domain\Subscription\DatasetManifest
            {
                return new \CartShift\Domain\Subscription\DatasetManifest(
                    \CartShift\Domain\Subscription\DatasetManifest::SCHEMA_VERSION,
                    $this->sourceKey,
                    'hpos',
                    ['PLN'],
                    '2026-01-01 00:00:00',
                    [],
                    '',
                    [],
                    0,
                    0,
                    '',
                );
            }

            public function records(\CartShift\Domain\Subscription\SubscriptionSelection $selection): iterable
            {
                return [];
            }
        };
    }

    /**
     * @return array{level: string, message: string}
     */
    private function lastCliMessage(): array
    {
        $recorded = $GLOBALS['_cartshift_test_wp_cli'] ?? [];

        $this->assertNotSame([], $recorded, 'The command said nothing at all.');

        return $recorded[count($recorded) - 1];
    }
}

<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\CLI;

use CartShift\CLI\TransferCommand;
use CartShift\Domain\Transfer\Audit\SourceInventoryInspector;
use CartShift\Domain\Transfer\Audit\SourceInventoryReport;
use CartShift\Domain\Transfer\Audit\TransferAuditor;
use CartShift\Domain\Transfer\Decision\TransferDecisionSet;
use CartShift\Domain\Transfer\Execution\RollbackPlan;
use CartShift\Domain\Transfer\Execution\RollbackPlanRepository;
use CartShift\Domain\Transfer\Execution\ConfiguredTransferEvidence;
use CartShift\Domain\Transfer\Runtime\TransferRuntimeInspector;
use CartShift\Domain\Transfer\Runtime\TransferRuntimeReport;
use CartShift\Domain\Transfer\Subscription\SubscriptionCutoverEvidence;
use CartShift\Domain\Transfer\Subscription\SubscriptionCutoverEvidenceRepository;
use CartShift\Domain\Transfer\Identity\LegacyMapAuditor;
use CartShift\Domain\Transfer\Package\TransferPackageValidator;
use CartShift\Domain\Transfer\Package\TransferPackageWriter;
use CartShift\Domain\Transfer\RecordEnvelope;
use CartShift\Domain\Transfer\SelectionClause;
use CartShift\Domain\Transfer\SourceIdentity;
use CartShift\Domain\Transfer\TransferSelection;
use CartShift\Tests\Unit\PluginTestCase;

final class TransferCommandTest extends PluginTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['_cartshift_test_wp_cli'] = [];
    }

    public function testCommandsMapToPublicMethods(): void
    {
        self::assertSame(
            [
                'compatibility' => 'compatibility',
                'upgrade-schema' => 'upgradeSchema',
                'source-instance' => 'sourceInstance',
                'propose-decisions' => 'proposeDecisions',
                'audit' => 'audit',
                'export' => 'export',
                'validate-package' => 'validatePackage',
                'inspect-target' => 'inspectTarget',
                'prepare' => 'prepare',
                'stage' => 'stage',
                'reconcile' => 'reconcile',
                'promote' => 'promote',
                'prepare-subscription-cutover' => 'prepareSubscriptionCutover',
                'release-subscription-source' => 'releaseSubscriptionSource',
                'activate-subscriptions' => 'activateSubscriptions',
                'activate-catalogue' => 'activateCatalogue',
                'complete' => 'complete',
                'rollback' => 'rollback',
                'status' => 'status',
            ],
            TransferCommand::subcommands(),
        );
    }

    public function testSourceReleaseRegistersRenewalPauseAsARecognisedWpCliFlag(): void
    {
        $GLOBALS['_cartshift_test_wp_cli_commands'] = [];
        TransferCommand::register();

        $command = $GLOBALS['_cartshift_test_wp_cli_commands']['cartshift transfer release-subscription-source'] ?? null;
        self::assertIsArray($command);
        self::assertSame(
            '[--renewals-paused]',
            array_values(array_filter(
                explode(' ', (string) ($command['args']['synopsis'] ?? '')),
                static fn (string $token): bool => str_contains($token, 'renewals-paused'),
            ))[0] ?? null,
            'WP-CLI only registers valueless flags when the synopsis uses its optional-flag grammar; the handler enforces that this one is present.',
        );
    }

    public function testSourceInstanceBindingRequiresExactTwoStepApprovalAndWritesOnlyPrivateEvidence(): void
    {
        $root = sys_get_temp_dir() . '/cartshift-source-instance-' . bin2hex(random_bytes(8));
        mkdir($root, 0700);
        $registry = $root . '/source-instance-registry.json';
        $fingerprint = str_repeat('9', 64);
        try {
            TransferCommand::sourceInstance([], [
                'role' => 'source',
                'source-key' => 'shop-alpha',
                'registry' => $registry,
                'action' => 'bind',
                'approval' => str_repeat('0', 64),
                'format' => 'json',
            ], static fn (): string => $fingerprint);
            self::assertStringContainsString('source_instance_binding_approval', $this->lastError());
            self::assertFileDoesNotExist($registry);

            $GLOBALS['_cartshift_test_wp_cli'] = [];
            TransferCommand::sourceInstance([], [
                'role' => 'source',
                'source-key' => 'shop-alpha',
                'registry' => $registry,
                'action' => 'bind',
                'approval' => \CartShift\Domain\Transfer\Package\SourceInstanceRegistry::approval('shop-alpha', $fingerprint),
                'format' => 'json',
            ], static fn (): string => $fingerprint);

            self::assertFileExists($registry);
            self::assertSame(0600, fileperms($registry) & 0777);
            self::assertStringContainsString(
                '"status":"bound"',
                implode("\n", array_column($GLOBALS['_cartshift_test_wp_cli'], 'message')),
            );
            self::assertSame([], array_filter(
                $GLOBALS['_cartshift_test_queries'],
                static fn (array $query): bool => in_array($query[0] ?? '', ['insert', 'update', 'delete', 'query'], true),
            ));
        } finally {
            if (is_file($registry)) unlink($registry);
            rmdir($root);
        }
    }

    public function testDecisionProposalIsReadOnlyAndKeepsOwnerReviewStatusExplicit(): void
    {
        $received = null;
        TransferCommand::proposeDecisions([], [
            'role' => 'source',
            'source-key' => 'shop-alpha',
            'all-kinds' => true,
            'operator' => 'owner',
            'decided-at' => '2026-08-11T01:00:00Z',
            'format' => 'json',
        ], static function ($selection, $decisions, string $operator, string $decidedAt) use (&$received): array {
            $received = [$selection->sourceKey, count($decisions->rows()), $operator, $decidedAt];
            return [
                'status' => 'owner_review_required',
                'writes' => ['wordpress' => false, 'filesystem' => false],
                'decision_set' => ['decisions' => []],
            ];
        });

        self::assertSame(['shop-alpha', 0, 'owner', '2026-08-11T01:00:00Z'], $received);
        self::assertStringContainsString(
            '"status":"owner_review_required"',
            implode("\n", array_column($GLOBALS['_cartshift_test_wp_cli'], 'message')),
        );
        self::assertSame([], array_filter(
            $GLOBALS['_cartshift_test_queries'],
            static fn (array $query): bool => in_array($query[0] ?? '', ['insert', 'update', 'delete', 'query'], true),
        ));
    }

    public function testSourceSubscriptionReleaseRequiresWorkerPauseBeforeEvidenceOrDatabaseRead(): void
    {
        TransferCommand::releaseSubscriptionSource([], [
            'role' => 'source',
            'private-dir' => '/srv/private/cartshift',
            'descriptor' => 'run-task-22',
            'execution-context' => 'rehearsal',
            'format' => 'json',
        ]);

        self::assertStringContainsString('--renewals-paused', $this->lastError());
        self::assertSame([], $GLOBALS['_cartshift_test_queries']);
    }

    public function testSourceSubscriptionReleaseDistinguishesInstanceAndRuntimeDriftBeforeMutation(): void
    {
        $root = sys_get_temp_dir() . '/cartshift-source-release-cli-' . bin2hex(random_bytes(8));
        mkdir($root, 0700);
        $repository = new SubscriptionCutoverEvidenceRepository($root);
        $repository->create(new SubscriptionCutoverEvidence(
            'run-task-22', 'shop-alpha', str_repeat('a', 64), str_repeat('b', 64), str_repeat('c', 64),
            str_repeat('d', 64), str_repeat('e', 64),
            'rehearsal', SubscriptionCutoverEvidence::PREPARED, [[
                'source_identity' => 'shop-alpha:subscription:31',
                'source_fingerprint' => str_repeat('1', 64),
                'target_id' => 9031,
                'staged_target_fingerprint' => str_repeat('2', 64),
                'source_release_required' => false,
                'intended_status' => 'expired',
                'release_state' => 'not_required',
                'activation_state' => 'activated',
            ]], '2026-08-10T12:00:00Z',
        ));
        $base = [
            'role' => 'source', 'private-dir' => $root, 'descriptor' => 'run-task-22',
            'execution-context' => 'rehearsal', 'renewals-paused' => true, 'format' => 'json',
        ];
        try {
            $called = false;
            TransferCommand::releaseSubscriptionSource(
                [],
                $base,
                static function () use (&$called): array { $called = true; return []; },
                static fn (): array => [str_repeat('f', 64), new TransferRuntimeReport('source', str_repeat('e', 64), [], [], [], [])],
            );
            self::assertFalse($called);
            self::assertStringContainsString('subscription_cutover_source_instance_changed', $this->lastError());

            $GLOBALS['_cartshift_test_wp_cli'] = [];
            TransferCommand::releaseSubscriptionSource(
                [],
                $base,
                static function () use (&$called): array { $called = true; return []; },
                static fn (): array => [str_repeat('d', 64), new TransferRuntimeReport('source', str_repeat('f', 64), [], [], [], [])],
            );
            self::assertFalse($called);
            self::assertStringContainsString('subscription_cutover_source_runtime_changed', $this->lastError());
        } finally {
            foreach (glob($root . '/*') ?: [] as $file) unlink($file);
            rmdir($root);
        }
    }

    public function testMutationCommandsRequireEverySealedInputAndExposeNoUnsafeOverride(): void
    {
        foreach (['stage', 'reconcile', 'promote', 'prepareSubscriptionCutover', 'activateSubscriptions', 'activateCatalogue', 'complete', 'rollback'] as $method) {
            $GLOBALS['_cartshift_test_wp_cli'] = [];
            TransferCommand::{$method}([], [
                'role' => 'target',
                'descriptor' => 'run-safe-22',
                'confirm' => str_repeat('a', 64),
                'execution-context' => 'rehearsal',
                'force' => true,
                'format' => 'json',
            ]);
            self::assertStringContainsString('unsupported_override', $this->lastError(), $method);
            self::assertSame([], $GLOBALS['_cartshift_test_queries'], $method . ' touched WordPress before validation.');
        }
    }

    public function testCutoverMutationRequiresApprovalBeforePipelineOrDatabaseAccess(): void
    {
        TransferCommand::stage([], [
            'role' => 'target',
            'package' => '/srv/private/package',
            'descriptor' => 'run-safe-22',
            'confirm' => str_repeat('a', 64),
            'execution-context' => 'cutover',
            'format' => 'json',
        ]);

        self::assertStringContainsString('--cutover-approval', $this->lastError());
        self::assertSame([], $GLOBALS['_cartshift_test_queries']);
    }

    public function testStagePassesCheckedSchedulingAndLeaseRecoveryEvidenceToPipeline(): void
    {
        $received = null;
        TransferCommand::stage([], [
            'role' => 'target',
            'package' => '/srv/private/package',
            'descriptor' => 'run-safe-22',
            'confirm' => str_repeat('a', 64),
            'execution-context' => 'rehearsal',
            'batch-size' => '17',
            'lease-recovery' => str_repeat('b', 64),
            'format' => 'json',
        ], static function (array $input) use (&$received): array {
            $received = $input;
            return ['state' => 'staging', 'processed' => 17, 'next_legal_actions' => ['stage']];
        });

        self::assertSame(17, $received['batch_size']);
        self::assertSame(str_repeat('b', 64), $received['lease_recovery']);
        self::assertSame([], array_filter(
            $GLOBALS['_cartshift_test_queries'],
            static fn (array $query): bool => in_array($query[0] ?? '', ['insert', 'update', 'delete', 'query'], true),
        ));
    }

    public function testEveryPostStageCommandPassesCheckedLeaseRecoveryEvidenceToPipeline(): void
    {
        $recovery = str_repeat('d', 64);
        foreach (['reconcile', 'promote', 'prepareSubscriptionCutover', 'activateSubscriptions', 'activateCatalogue', 'complete'] as $method) {
            $GLOBALS['_cartshift_test_wp_cli'] = [];
            $received = null;
            TransferCommand::{$method}([], [
                'role' => 'target',
                'package' => '/srv/private/package',
                'descriptor' => 'run-safe-22',
                'confirm' => str_repeat('a', 64),
                'execution-context' => 'rehearsal',
                'lease-recovery' => $recovery,
                'format' => 'json',
            ], static function (array $input) use (&$received): array {
                $received = $input;
                return ['state' => 'checked'];
            });

            self::assertSame($recovery, $received['lease_recovery'] ?? null, $method);
            self::assertSame([], array_filter(
                $GLOBALS['_cartshift_test_queries'],
                static fn (array $query): bool => in_array($query[0] ?? '', ['insert', 'update', 'delete', 'query'], true),
            ), $method);
        }
    }

    public function testCutoverLeaseRecoveryCannotUseAnArbitraryEvidenceHash(): void
    {
        $called = false;
        TransferCommand::stage([], [
            'role' => 'target',
            'package' => '/srv/private/package',
            'descriptor' => 'run-safe-22',
            'confirm' => str_repeat('a', 64),
            'execution-context' => 'cutover',
            'cutover-approval' => str_repeat('b', 64),
            'lease-recovery' => str_repeat('c', 64),
            'format' => 'json',
        ], static function () use (&$called): array {
            $called = true;
            return [];
        });

        self::assertFalse($called);
        self::assertStringContainsString('must equal the owner-approved cutover manifest SHA-256', $this->lastError());
        self::assertSame([], $GLOBALS['_cartshift_test_queries']);
    }

    public function testRollbackPassesApprovedPlanHashAsLeaseRecoveryEvidence(): void
    {
        $directory = sys_get_temp_dir() . '/cartshift-rollback-command-' . bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        try {
            $path = (new RollbackPlanRepository($directory))->save(new RollbackPlan(
                'run-safe-22',
                1,
                [],
                [],
                true,
            ));
            $recovery = hash_file('sha256', $path);
            $received = null;
            TransferCommand::rollback([], [
                'role' => 'target',
                'package' => '/srv/private/package',
                'descriptor' => 'run-safe-22',
                'confirm' => str_repeat('a', 64),
                'execution-context' => 'rehearsal',
                'rollback-plan' => $path,
                'lease-recovery' => $recovery,
                'format' => 'json',
            ], static function (array $input) use (&$received): array {
                $received = $input;
                return ['state' => 'rolled_back'];
            });

            self::assertSame($recovery, $received['lease_recovery']);
            self::assertSame([], array_filter(
                $GLOBALS['_cartshift_test_queries'],
                static fn (array $query): bool => in_array($query[0] ?? '', ['insert', 'update', 'delete', 'query'], true),
            ));
        } finally {
            foreach (glob($directory . '/*') ?: [] as $file) unlink($file);
            rmdir($directory);
        }
    }

    public function testRollbackRejectsLeaseRecoveryThatIsNotTheApprovedPlanDigest(): void
    {
        $directory = sys_get_temp_dir() . '/cartshift-rollback-recovery-' . bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        try {
            $path = (new RollbackPlanRepository($directory))->save(new RollbackPlan(
                'run-safe-22', 1, [], [], true,
            ));
            $called = false;
            TransferCommand::rollback([], [
                'role' => 'target',
                'package' => '/srv/private/package',
                'descriptor' => 'run-safe-22',
                'confirm' => str_repeat('a', 64),
                'execution-context' => 'rehearsal',
                'rollback-plan' => $path,
                'lease-recovery' => str_repeat('f', 64),
                'format' => 'json',
            ], static function () use (&$called): array {
                $called = true;
                return [];
            });

            self::assertFalse($called);
            self::assertStringContainsString('approved rollback plan SHA-256', $this->lastError());
        } finally {
            foreach (glob($directory . '/*') ?: [] as $file) unlink($file);
            rmdir($directory);
        }
    }

    public function testInvalidBatchRecoveryAndOverlongDescriptorStopBeforePipeline(): void
    {
        $base = [
            'role' => 'target',
            'package' => '/srv/private/package',
            'descriptor' => 'run-safe-22',
            'confirm' => str_repeat('a', 64),
            'execution-context' => 'rehearsal',
            'format' => 'json',
        ];
        foreach ([
            [$base + ['batch-size' => '0'], '--batch-size'],
            [$base + ['lease-recovery' => 'not-a-hash'], '--lease-recovery'],
            [array_replace($base, ['descriptor' => str_repeat('r', 37)]), '--descriptor'],
        ] as [$input, $expected]) {
            $GLOBALS['_cartshift_test_wp_cli'] = [];
            $called = false;
            TransferCommand::stage([], $input, static function () use (&$called): array {
                $called = true;
                return [];
            });
            self::assertFalse($called);
            self::assertStringContainsString($expected, $this->lastError());
        }
    }

    public function testExportUsesExplicitPrivateDestinationSelectionAndPerformsNoWordPressWrites(): void
    {
        $destination = sys_get_temp_dir() . '/cartshift-cli-export-' . bin2hex(random_bytes(8));
        mkdir($destination, 0700);
        $decisionPath = $destination . '/decisions.json';
        file_put_contents($decisionPath, TransferDecisionSet::empty()->canonicalJson());
        chmod($decisionPath, 0600);
        $received = null;
        try {
            TransferCommand::export([], [
                'role' => 'source', 'source-key' => 'shop-alpha', 'destination' => $destination,
                'decision-set' => $decisionPath,
                'products' => 'ids:10,9', 'customers' => 'none', 'orders' => 'ids:41', 'subscriptions' => 'none',
                'include-reverse-dependencies' => 'subscription,order', 'format' => 'json',
            ], static function (TransferSelection $selection, string $path, string $decisions) use (&$received): array {
                $received = [$selection, $path, $decisions];
                return ['path' => $path . '/sealed-package', 'records_sha256' => str_repeat('a', 64)];
            });

            self::assertInstanceOf(TransferSelection::class, $received[0]);
            self::assertSame([9, 10], $received[0]->products->ids);
            self::assertSame(['order', 'subscription'], $received[0]->reverseDependencies);
            self::assertSame(realpath($destination), $received[1]);
            self::assertSame($decisionPath, $received[2]);
            self::assertSame([], array_filter($GLOBALS['_cartshift_test_queries'], static fn (array $query): bool => in_array($query[0] ?? '', ['insert', 'update', 'delete'], true)));
        } finally {
            unlink($decisionPath);
            rmdir($destination);
        }
    }

    public function testPrepareIndependentlyValidatesPackageDecisionsClosureAndPrivateEvidenceDirectory(): void
    {
        $root = sys_get_temp_dir() . '/cartshift-cli-prepare-' . bin2hex(random_bytes(8));
        $packages = $root . '/packages';
        $evidence = $root . '/evidence';
        mkdir($packages, 0700, true);
        mkdir($evidence, 0700);
        $selection = new TransferSelection(
            'shop-alpha',
            SelectionClause::ids([9]),
            SelectionClause::none(),
            SelectionClause::none(),
            SelectionClause::none(),
        );
        $record = RecordEnvelope::forPayload(2, new SourceIdentity('shop-alpha', 'product', '9'), ['dependencies' => []]);
        $package = (new TransferPackageWriter(new TransferPackageValidator()))->write(
            new SourceIdentity('shop-alpha', 'product', '9'),
            $selection,
            [$record],
            [],
            [
                'destination' => $packages,
                'source_instance_fingerprint' => str_repeat('1', 64),
                'source_url_hash' => str_repeat('2', 64),
                'source_runtime_fingerprint' => str_repeat('3', 64),
                'source_settings_fingerprint' => str_repeat('4', 64),
                'source_capability_fingerprint' => str_repeat('5', 64),
                'cartshift_version' => '2.0.0',
                'woocommerce_version' => '11.0.0',
                'wcs_version' => null,
                'created_at_utc' => '2026-08-10T12:00:00Z',
            ],
        );
        $decisionPath = $evidence . '/decisions.json';
        $decisions = TransferDecisionSet::empty();
        file_put_contents($decisionPath, $decisions->canonicalJson());
        chmod($decisionPath, 0600);
        $received = null;
        try {
            TransferCommand::prepare([], [
                'role' => 'target',
                'package' => $package,
                'decision-set' => $decisionPath,
                'private-dir' => $evidence,
                'execution-context' => 'rehearsal',
                'format' => 'json',
            ], static function (array $input) use (&$received): array {
                $received = $input;
                return ['descriptor' => 'run-safe-22', 'blocking_findings' => []];
            });

            self::assertSame($selection->fingerprint(), $received['selection_hash']);
            self::assertSame($decisions->fingerprint(), $received['decision_hash']);
            self::assertSame(hash('sha256', file_get_contents($package . '/manifest.json')), $received['package_hash']);
            self::assertSame('shop-alpha', $received['source_key']);
            self::assertSame([], array_filter(
                $GLOBALS['_cartshift_test_queries'],
                static fn (array $query): bool => in_array($query[0] ?? '', ['insert', 'update', 'delete', 'query'], true),
            ));
        } finally {
            $this->removeTree($root);
        }
    }

    public function testSchemaUpgradeRequiresAValidBackupHashBeforeAnyDatabaseRead(): void
    {
        TransferCommand::upgradeSchema([], [
            'role' => 'target',
            'from' => '7',
            'to' => '8',
            'confirm-backup' => 'not-a-digest',
            'execution-context' => 'rehearsal',
        ]);

        self::assertStringContainsString('--confirm-backup', $this->lastError());
        self::assertSame([], $GLOBALS['_cartshift_test_queries']);
    }

    public function testSchemaUpgradeRejectsEveryUnsupportedOverrideBeforeAnyDatabaseRead(): void
    {
        foreach (['force', 'ignore-errors', 'skip-reconcile'] as $override) {
            $GLOBALS['_cartshift_test_wp_cli'] = [];
            TransferCommand::upgradeSchema([], [
                'role' => 'target',
                'from' => '7',
                'to' => '8',
                'confirm-backup' => str_repeat('a', 64),
                'execution-context' => 'rehearsal',
                $override => true,
            ]);

            self::assertSame('unsupported_override: --' . $override . ' does not exist for v2 transfers.', $this->lastError());
            self::assertSame([], $GLOBALS['_cartshift_test_queries'], $override . ' reached the database.');
        }
    }

    public function testSchemaUpgradeRequiresTheMaintenanceGateBeforeRuntimeReads(): void
    {
        TransferCommand::upgradeSchema([], [
            'role' => 'target',
            'from' => '7',
            'to' => '8',
            'confirm-backup' => str_repeat('a', 64),
            'execution-context' => 'rehearsal',
        ]);

        self::assertStringContainsString('transfer_maintenance_not_confirmed', $this->lastError());
        self::assertSame([], $GLOBALS['_cartshift_test_queries']);
    }

    public function testCutoverSchemaUpgradeRequiresApprovalEvidence(): void
    {
        TransferCommand::upgradeSchema([], [
            'role' => 'target',
            'from' => '7',
            'to' => '8',
            'confirm-backup' => str_repeat('a', 64),
            'execution-context' => 'cutover',
        ]);

        self::assertStringContainsString('--cutover-approval', $this->lastError());
        self::assertSame([], $GLOBALS['_cartshift_test_queries']);
    }

    public function testCutoverSchemaUpgradeRejectsAnUnconfiguredApprovalBeforeRuntimeReads(): void
    {
        putenv(ConfiguredTransferEvidence::CUTOVER_APPROVAL);
        putenv(ConfiguredTransferEvidence::CUTOVER_MANIFEST);

        TransferCommand::upgradeSchema([], [
            'role' => 'target',
            'from' => '7',
            'to' => '8',
            'confirm-backup' => str_repeat('a', 64),
            'execution-context' => 'cutover',
            'cutover-approval' => str_repeat('b', 64),
        ]);

        self::assertStringContainsString('cutover_approval_not_configured_or_changed', $this->lastError());
        self::assertSame([], $GLOBALS['_cartshift_test_queries']);
    }

    public function testAuditRequiresExplicitSourceKey(): void
    {
        TransferCommand::audit([], ['role' => 'source', 'format' => 'json']);

        self::assertSame('--source-key is required.', $this->lastError());
    }

    public function testInspectTargetRequiresCanonicalSourceKeyBeforeAnyRead(): void
    {
        TransferCommand::inspectTarget([], ['role' => 'target', 'source-key' => 'local', 'format' => 'json']);

        self::assertStringContainsString('--source-key', $this->lastError());
        self::assertSame([], $GLOBALS['_cartshift_test_queries']);
    }

    public function testInspectTargetRejectsTheSourceRoleBeforeAnyRead(): void
    {
        TransferCommand::inspectTarget([], [
            'role' => 'source',
            'source-key' => 'lapka-web',
            'format' => 'json',
        ]);

        self::assertSame('--role is required and must be target for inspect-target.', $this->lastError());
        self::assertSame([], $GLOBALS['_cartshift_test_queries']);
    }

    public function testInspectTargetJsonHasExactPublicSchemaAndPerformsNoWrites(): void
    {
        $auditor = new LegacyMapAuditor(static fn (): array => [
            'mappings' => [],
            'claims' => [],
            'shared_links' => [],
            'source_order_ids' => ['42'],
            'invoice_orders' => [['source_id' => '42', 'target_id' => 900]],
            'receipts' => [],
        ]);
        TransferCommand::inspectTarget([], [
            'role' => 'target',
            'source-key' => 'lapka-web',
            'format' => 'json',
        ], $auditor);
        $lines = array_values(array_filter(
            $GLOBALS['_cartshift_test_wp_cli'],
            static fn (array $entry): bool => $entry['level'] === 'line',
        ));
        $document = json_decode($lines[0]['message'], true, flags: JSON_THROW_ON_ERROR);

        self::assertSame([
            'source_key',
            'mapping_counts_by_entity',
            'legacy_mapping_counts',
            'missing_target_counts',
            'duplicate_target_ownership_counts',
            'invoice_collision_count',
            'unfingerprinted_mapping_count',
            'receipt_coverage_count',
            'blockers',
            'fingerprint',
        ], array_keys($document));
        self::assertSame(1, $document['invoice_collision_count']);
        self::assertSame([], array_filter(
            $GLOBALS['_cartshift_test_queries'] ?? [],
            static fn (array $query): bool => in_array($query[0] ?? '', ['insert', 'update', 'delete', 'query'], true),
        ));
    }

    public function testAuditRequiresEveryClauseOrAllKinds(): void
    {
        TransferCommand::audit([], [
            'role' => 'source',
            'source-key' => 'lapka-web',
            'products' => 'all',
        ]);

        self::assertStringContainsString('customers, orders, subscriptions', $this->lastError());
    }

    public function testAllKindsIsMutuallyExclusiveWithPerKindClauses(): void
    {
        TransferCommand::audit([], [
            'role' => 'source',
            'source-key' => 'lapka-web',
            'all-kinds' => true,
            'products' => 'none',
        ]);

        self::assertStringContainsString('--all-kinds cannot be combined', $this->lastError());
    }

    public function testAmbiguousIdCsvIsRejectedBeforeSourceReads(): void
    {
        TransferCommand::audit([], [
            'role' => 'source',
            'source-key' => 'lapka-web',
            'products' => 'ids:2,,9',
            'customers' => 'none',
            'orders' => 'none',
            'subscriptions' => 'none',
        ]);

        self::assertStringContainsString('--products', $this->lastError());
        self::assertStringContainsString('ids:<csv>', $this->lastError());
    }

    public function testAuditRejectsReverseDependenciesUntilClosureResolverOwnsThem(): void
    {
        TransferCommand::audit([], [
            'role' => 'source',
            'source-key' => 'lapka-web',
            'all-kinds' => true,
            'include-reverse-dependencies' => 'order',
        ]);

        self::assertStringContainsString('not available until the v2 closure resolver', $this->lastError());
    }

    public function testAuditJsonHasOnlyThePublicSchemaAndDoesNotAppendSuccessText(): void
    {
        $auditor = $this->auditor();
        TransferCommand::audit([], [
            'role' => 'source',
            'source-key' => 'lapka-web',
            'products' => 'ids:9,2',
            'customers' => 'none',
            'orders' => 'all',
            'subscriptions' => 'none',
            'format' => 'json',
        ], $auditor);

        $lines = array_values(array_filter(
            $GLOBALS['_cartshift_test_wp_cli'],
            static fn (array $entry): bool => $entry['level'] === 'line',
        ));
        self::assertCount(1, $lines);
        $document = json_decode($lines[0]['message'], true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(
            [
                'source_key',
                'selection_fingerprint',
                'decision_fingerprint',
                'runtime_fingerprint',
                'audit_fingerprint',
                'ready',
                'counts',
                'capabilities',
                'blockers',
            ],
            array_keys($document),
        );
        self::assertSame([], array_filter(
            $GLOBALS['_cartshift_test_wp_cli'],
            static fn (array $entry): bool => $entry['level'] === 'success',
        ));
    }

    public function testAuditLoadsCanonicalDecisionSetAndBindsItsFingerprintIntoTheReport(): void
    {
        $directory = sys_get_temp_dir() . '/cartshift-audit-decisions-' . bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        $path = $directory . '/decisions.json';
        $decisions = TransferDecisionSet::empty();
        file_put_contents($path, $decisions->canonicalJson());
        chmod($path, 0600);
        try {
            TransferCommand::audit([], [
                'role' => 'source',
                'source-key' => 'lapka-web',
                'all-kinds' => true,
                'decision-set' => $path,
                'format' => 'json',
            ], $this->auditor());

            $lines = array_values(array_filter(
                $GLOBALS['_cartshift_test_wp_cli'],
                static fn (array $entry): bool => $entry['level'] === 'line',
            ));
            $document = json_decode($lines[0]['message'], true, flags: JSON_THROW_ON_ERROR);
            self::assertSame($decisions->fingerprint(), $document['decision_fingerprint']);
            self::assertSame([], array_filter(
                $GLOBALS['_cartshift_test_queries'],
                static fn (array $query): bool => in_array($query[0] ?? '', ['insert', 'update', 'delete', 'query'], true),
            ));
        } finally {
            unlink($path);
            rmdir($directory);
        }
    }

    private function auditor(): TransferAuditor
    {
        $runtime = new class implements TransferRuntimeInspector {
            public function inspect(string $role): TransferRuntimeReport
            {
                return new TransferRuntimeReport($role, 'runtime-cli', [], [], [], []);
            }
        };
        $inventory = new class implements SourceInventoryInspector {
            public function inspect(TransferSelection $selection): SourceInventoryReport
            {
                return SourceInventoryReport::create(
                    $selection->sourceKey,
                    $selection->fingerprint(),
                    'runtime-cli',
                    ['product_duplicates' => 0, 'products_unaccounted' => 0],
                    ['product_types' => ['simple' => 2]],
                    [],
                );
            }
        };

        return new TransferAuditor($runtime, $inventory);
    }

    private function lastError(): string
    {
        $errors = array_values(array_filter(
            $GLOBALS['_cartshift_test_wp_cli'],
            static fn (array $entry): bool => $entry['level'] === 'error',
        ));

        return (string) ($errors[array_key_last($errors)]['message'] ?? '');
    }

    private function removeTree(string $path): void
    {
        if (is_file($path) || is_link($path)) {
            unlink($path);
            return;
        }
        foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $entry) {
            $this->removeTree($path . '/' . $entry);
        }
        rmdir($path);
    }
}

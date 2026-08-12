<?php

declare(strict_types=1);

namespace CartShift\CLI;

use CartShift\Domain\Transfer\Audit\AuditRenderer;
use CartShift\Domain\Transfer\Audit\CptStorageIntegrityReader;
use CartShift\Domain\Transfer\Audit\HposStorageIntegrityReader;
use CartShift\Domain\Transfer\Audit\LoadedWooSourceApi;
use CartShift\Domain\Transfer\Audit\LoadedWooRecordContractAttempts;
use CartShift\Domain\Transfer\Audit\LoadedWooTransferAuditor;
use CartShift\Domain\Transfer\Audit\RecordContractInspector;
use CartShift\Domain\Transfer\Audit\TransferAuditor;
use CartShift\Domain\Transfer\Audit\WooSourceInventoryReader;
use CartShift\Domain\Transfer\Runtime\TransferRuntimeInspector;
use CartShift\Domain\Transfer\Runtime\TransferRuntimeProbe;
use CartShift\Domain\Transfer\Runtime\TransferRuntimeReport;
use CartShift\Domain\Transfer\Identity\LegacyMapAuditor;
use CartShift\Domain\Transfer\Package\TransferPackageValidator;
use CartShift\Domain\Transfer\Package\LoadedWooExportPipeline;
use CartShift\Domain\Transfer\Package\LoadedSourceInstanceFingerprint;
use CartShift\Domain\Transfer\Package\SourceInstanceRegistry;
use CartShift\Domain\Transfer\Decision\LoadedWooDecisionProposalPipeline;
use CartShift\Domain\Transfer\Decision\TransferDecisionSet;
use CartShift\Domain\Transfer\Execution\LoadedTargetPreparePipeline;
use CartShift\Domain\Transfer\Execution\LoadedTargetTransferPipeline;
use CartShift\Domain\Transfer\SelectionClause;
use CartShift\Domain\Transfer\TransferSelection;
use CartShift\Support\WooStorage;
use CartShift\Support\Migrations;

defined('ABSPATH') || exit;

final class TransferCommand
{
    private const array SUBCOMMANDS = [
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
    ];
    private const array FORMATS = ['table', 'json'];
    private const array KINDS = ['products', 'customers', 'orders', 'subscriptions'];
    private const array SYNOPSIS = [
        'compatibility' => '--role=<source|target> [--format=<table|json>]',
        'upgrade-schema' => '--role=<role> --from=<version> --to=<version> --confirm-backup=<sha256> --execution-context=<rehearsal|cutover> [--cutover-approval=<sha256>] [--format=<table|json>]',
        'source-instance' => '--role=<source> --source-key=<key> --registry=<absolute-private-path> --action=<inspect|bind> [--approval=<sha256>] [--format=<table|json>]',
        'propose-decisions' => '--role=<source> --source-key=<key> [--all-kinds] [--products=<clause>] [--customers=<clause>] [--orders=<clause>] [--subscriptions=<clause>] [--decision-set=<absolute-private-path>] --operator=<identity> --decided-at=<UTC> [--format=<table|json>]',
        'audit' => '--role=<role> --source-key=<key> [--all-kinds] [--products=<clause>] [--customers=<clause>] [--orders=<clause>] [--subscriptions=<clause>] [--decision-set=<absolute-path>] [--format=<table|json>]',
        'export' => '--role=<role> --source-key=<key> [--all-kinds] [--products=<clause>] [--customers=<clause>] [--orders=<clause>] [--subscriptions=<clause>] --decision-set=<absolute-path> --destination=<absolute-private-dir> [--format=<table|json>]',
        'validate-package' => '--role=<role> --package=<absolute-path> [--format=<table|json>]',
        'inspect-target' => '--role=<role> --source-key=<key> [--format=<table|json>]',
        'prepare' => '--role=<role> --package=<absolute-path> --decision-set=<absolute-path> --private-dir=<absolute-path> --execution-context=<rehearsal|cutover> [--format=<table|json>]',
        'stage' => '--role=<role> --package=<absolute-path> --descriptor=<id> --confirm=<selection-fingerprint> --execution-context=<rehearsal|cutover> [--batch-size=<positive-int>] [--lease-recovery=<sha256>] [--cutover-approval=<sha256>] [--format=<table|json>]',
        'reconcile' => '--role=<role> --package=<absolute-path> --descriptor=<id> --confirm=<selection-fingerprint> --execution-context=<rehearsal|cutover> [--lease-recovery=<sha256>] [--cutover-approval=<sha256>] [--format=<table|json>]',
        'promote' => '--role=<role> --package=<absolute-path> --descriptor=<id> --confirm=<selection-fingerprint> --execution-context=<rehearsal|cutover> [--lease-recovery=<sha256>] [--cutover-approval=<sha256>] [--format=<table|json>]',
        'prepare-subscription-cutover' => '--role=<target> --package=<absolute-path> --descriptor=<id> --confirm=<selection-fingerprint> --execution-context=<rehearsal|cutover> [--lease-recovery=<sha256>] [--cutover-approval=<sha256>] [--format=<table|json>]',
        'release-subscription-source' => '--role=<source> --private-dir=<absolute-path> --descriptor=<id> --execution-context=<rehearsal|cutover> [--renewals-paused] [--rehearsal-source-proof=<absolute-private-path>] [--cutover-approval=<sha256>] [--format=<table|json>]',
        'activate-subscriptions' => '--role=<target> --package=<absolute-path> --descriptor=<id> --confirm=<selection-fingerprint> --execution-context=<rehearsal|cutover> [--lease-recovery=<sha256>] [--cutover-approval=<sha256>] [--format=<table|json>]',
        'activate-catalogue' => '--role=<role> --package=<absolute-path> --descriptor=<id> --confirm=<selection-fingerprint> --execution-context=<rehearsal|cutover> [--lease-recovery=<sha256>] [--cutover-approval=<sha256>] [--format=<table|json>]',
        'complete' => '--role=<role> --package=<absolute-path> --descriptor=<id> --confirm=<selection-fingerprint> --execution-context=<rehearsal|cutover> [--lease-recovery=<sha256>] [--cutover-approval=<sha256>] [--format=<table|json>]',
        'rollback' => '--role=<role> --package=<absolute-path> --descriptor=<id> --confirm=<selection-fingerprint> --execution-context=<rehearsal|cutover> --rollback-plan=<absolute-path> [--lease-recovery=<sha256>] [--cutover-approval=<sha256>] [--format=<table|json>]',
        'status' => '--role=<role> --descriptor=<id> [--format=<table|json>]',
    ];

    public static function register(): void
    {
        foreach (self::SUBCOMMANDS as $name => $method) {
            \WP_CLI::add_command('cartshift transfer ' . $name, [self::class, $method], [
                'shortdesc' => 'Run the checked CartShift transfer ' . $name . ' contract.',
                'synopsis' => self::SYNOPSIS[$name],
            ]);
        }
    }

    /** @return array<string, string> */
    public static function subcommands(): array
    {
        return self::SUBCOMMANDS;
    }

    /** @param list<string> $args @param array<string, mixed> $assocArgs */
    public static function compatibility(array $args, array $assocArgs): void
    {
        $role = (string) ($assocArgs['role'] ?? '');
        $format = (string) ($assocArgs['format'] ?? 'table');

        if (!in_array($role, [TransferRuntimeProbe::ROLE_SOURCE, TransferRuntimeProbe::ROLE_TARGET], true)) {
            \WP_CLI::error('--role is required and must be source or target.');
            return;
        }

        if (!in_array($format, self::FORMATS, true)) {
            \WP_CLI::error('--format must be table or json.');
            return;
        }

        $report = (new TransferRuntimeProbe())->inspect($role);
        $document = [
            'role' => $report->role,
            'runtime_fingerprint' => $report->fingerprint,
            'ready' => $report->isReady(),
            'versions' => $report->versions,
            'schema_fingerprints' => $report->schemaFingerprints,
            'errors' => $report->errors,
            'warnings' => $report->warnings,
        ];

        if ($format === 'json') {
            \WP_CLI::line(json_encode($document, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        } else {
            \WP_CLI\Utils\format_items('table', [
                ['Check' => 'Role', 'Result' => $report->role],
                ['Check' => 'Runtime fingerprint', 'Result' => $report->fingerprint],
                ['Check' => 'Ready', 'Result' => $report->isReady() ? 'yes' : 'no'],
                ['Check' => 'Errors', 'Result' => implode(', ', $report->errors)],
                ['Check' => 'Warnings', 'Result' => implode(', ', $report->warnings)],
            ], ['Check', 'Result']);
        }

        if (!$report->isReady()) {
            \WP_CLI::error('Runtime compatibility failed: ' . implode(', ', $report->errors));
            return;
        }

        if ($format === 'table') {
            \WP_CLI::success('Runtime compatibility contract is ready.');
        }
    }

    /**
     * @param list<string> $args
     * @param array<string, mixed> $assocArgs
     * @param (callable(): string)|null $fingerprintProvider
     * @internal The optional provider is a test seam; WP-CLI supplies two arguments.
     */
    public static function sourceInstance(array $args, array $assocArgs, ?callable $fingerprintProvider = null): void
    {
        if (($assocArgs['role'] ?? '') !== TransferRuntimeProbe::ROLE_SOURCE) {
            \WP_CLI::error('--role is required and must be source for source-instance.');
            return;
        }
        $sourceKey = (string) ($assocArgs['source-key'] ?? '');
        $path = self::absolutePath((string) ($assocArgs['registry'] ?? ''), '--registry');
        $action = (string) ($assocArgs['action'] ?? '');
        $format = (string) ($assocArgs['format'] ?? 'table');
        if ($path === null) return;
        try {
            \CartShift\Domain\Transfer\SourceIdentity::assertValidSourceKey($sourceKey);
        } catch (\InvalidArgumentException) {
            \WP_CLI::error('--source-key is required and must be a canonical non-local source key.');
            return;
        }
        if (!in_array($action, ['inspect', 'bind'], true)) {
            \WP_CLI::error('--action must be inspect or bind.');
            return;
        }
        if (!in_array($format, self::FORMATS, true)) {
            \WP_CLI::error('--format must be table or json.');
            return;
        }

        try {
            $fingerprint = ($fingerprintProvider ?? (new LoadedSourceInstanceFingerprint())->fingerprint(...))();
            if (preg_match('/\A[a-f0-9]{64}\z/D', $fingerprint) !== 1) {
                throw new \RuntimeException('source_instance_fingerprint_invalid');
            }
            $registry = new SourceInstanceRegistry($path);
            $approval = SourceInstanceRegistry::approval($sourceKey, $fingerprint);
            if ($action === 'bind') {
                $provided = (string) ($assocArgs['approval'] ?? '');
                if (!hash_equals($approval, $provided)) {
                    throw new \RuntimeException('source_instance_binding_approval_mismatch');
                }
                $registry->bindOwnerApproved($sourceKey, $fingerprint, $provided);
                $registry->requireBinding($sourceKey, $fingerprint);
            }
            $bound = $registry->binding($sourceKey);
            $document = [
                'status' => $bound === null ? 'unbound' : (hash_equals($bound, $fingerprint) ? 'bound' : 'conflict'),
                'source_key' => $sourceKey,
                'source_instance_fingerprint' => $fingerprint,
                'binding_approval' => $approval,
                'registry' => $path,
            ];
        } catch (\Throwable $exception) {
            \WP_CLI::error('source_instance_binding_failed: ' . $exception->getMessage());
            return;
        }

        self::renderCommandResult($document, $format, 'Source-instance binding contract inspected.');
    }

    /**
     * @param list<string> $args
     * @param array<string,mixed> $assocArgs
     * @param null|callable(TransferSelection, TransferDecisionSet, string, string): array<string,mixed> $pipeline
     * @internal The optional pipeline is a test seam; WP-CLI supplies two arguments.
     */
    public static function proposeDecisions(array $args, array $assocArgs, ?callable $pipeline = null): void
    {
        if (($assocArgs['role'] ?? '') !== TransferRuntimeProbe::ROLE_SOURCE) {
            \WP_CLI::error('--role is required and must be source for propose-decisions.');
            return;
        }
        $sourceKey = (string) ($assocArgs['source-key'] ?? '');
        $operator = trim((string) ($assocArgs['operator'] ?? ''));
        $decidedAt = (string) ($assocArgs['decided-at'] ?? '');
        $format = (string) ($assocArgs['format'] ?? 'json');
        try {
            \CartShift\Domain\Transfer\SourceIdentity::assertValidSourceKey($sourceKey);
        } catch (\InvalidArgumentException) {
            \WP_CLI::error('--source-key is required and must be a canonical non-local source key.');
            return;
        }
        if ($operator === '' || preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z\z/D', $decidedAt) !== 1) {
            \WP_CLI::error('--operator and canonical --decided-at=<UTC> are required for a reproducible proposal.');
            return;
        }
        if (!in_array($format, self::FORMATS, true)) {
            \WP_CLI::error('--format must be table or json.');
            return;
        }
        $allKinds = array_key_exists('all-kinds', $assocArgs);
        $presentKinds = array_values(array_filter(self::KINDS, static fn (string $kind): bool => array_key_exists($kind, $assocArgs)));
        if ($allKinds && $presentKinds !== []) {
            \WP_CLI::error('--all-kinds cannot be combined with per-kind clauses.');
            return;
        }
        if (!$allKinds && array_diff(self::KINDS, $presentKinds) !== []) {
            \WP_CLI::error('Explicit clauses are required for products, customers, orders, subscriptions.');
            return;
        }
        try {
            $clauses = $allKinds
                ? array_fill_keys(self::KINDS, SelectionClause::all())
                : array_combine(self::KINDS, array_map(
                    static fn (string $kind): SelectionClause => self::parseClause($kind, (string) $assocArgs[$kind]),
                    self::KINDS,
                ));
            $selection = new TransferSelection(
                $sourceKey,
                $clauses['products'],
                $clauses['customers'],
                $clauses['orders'],
                $clauses['subscriptions'],
            );
            $decisionPath = isset($assocArgs['decision-set'])
                ? self::absolutePath((string) $assocArgs['decision-set'], '--decision-set')
                : null;
            if (isset($assocArgs['decision-set']) && $decisionPath === null) return;
            $existing = $decisionPath === null ? TransferDecisionSet::empty() : TransferDecisionSet::fromFile($decisionPath);
            $existing->assertSourceKey($sourceKey);
        } catch (\Throwable $exception) {
            \WP_CLI::error('decision_proposal_input_invalid: ' . $exception->getMessage());
            return;
        }
        try {
            $pipeline ??= (LoadedWooDecisionProposalPipeline::create()->propose(...));
            $result = $pipeline($selection, $existing, $operator, $decidedAt);
        } catch (\Throwable $exception) {
            \WP_CLI::error('decision_proposal_blocked: ' . $exception->getMessage());
            return;
        }
        if (!is_array($result)
            || !in_array($result['status'] ?? null, ['owner_review_required', 'blocked'], true)
            || !is_array($result['decision_set']['decisions'] ?? null)) {
            \WP_CLI::error('decision_proposal_result_invalid');
            return;
        }
        self::renderCommandResult($result, $format, 'Decision proposal produced; owner review is still required.');
    }

    /** @param list<string> $args @param array<string, mixed> $assocArgs */
    public static function upgradeSchema(array $args, array $assocArgs): void
    {
        global $wpdb;

        if (self::rejectUnsupportedOverrides($assocArgs)) {
            return;
        }

        $role = (string) ($assocArgs['role'] ?? '');
        $from = (string) ($assocArgs['from'] ?? '');
        $to = (string) ($assocArgs['to'] ?? '');
        $backup = (string) ($assocArgs['confirm-backup'] ?? '');
        $context = (string) ($assocArgs['execution-context'] ?? '');
        $format = (string) ($assocArgs['format'] ?? 'table');
        $cutoverApproval = (string) ($assocArgs['cutover-approval'] ?? '');

        if ($role !== TransferRuntimeProbe::ROLE_TARGET) {
            \WP_CLI::error('--role is required and must be target for upgrade-schema.');
            return;
        }

        if ($from !== '7' || $to !== '8') {
            \WP_CLI::error('upgrade-schema requires the exact --from=7 --to=8 transition.');
            return;
        }

        if (preg_match('/\A[a-f0-9]{64}\z/D', $backup) !== 1) {
            \WP_CLI::error('--confirm-backup must be the lowercase SHA-256 of a recoverable target backup.');
            return;
        }

        if (!in_array($context, ['rehearsal', 'cutover'], true)) {
            \WP_CLI::error('--execution-context must be rehearsal or cutover.');
            return;
        }

        if ($context === 'cutover' && preg_match('/\A[a-f0-9]{64}\z/D', $cutoverApproval) !== 1) {
            \WP_CLI::error('Cutover upgrade requires --cutover-approval=<sha256>.');
            return;
        }

        try {
            if ($context === 'cutover') {
                \CartShift\Domain\Transfer\Execution\ConfiguredTransferEvidence::assertCutoverApproval($cutoverApproval)
                    ->assertSchemaUpgrade($from, $to, $backup);
            } elseif ($cutoverApproval !== '') {
                throw new \RuntimeException('rehearsal_cutover_approval_unexpected');
            }
        } catch (\Throwable $exception) {
            \WP_CLI::error('schema_upgrade_approval_blocked: ' . $exception->getMessage());
            return;
        }

        if (!in_array($format, self::FORMATS, true)) {
            \WP_CLI::error('--format must be table or json.');
            return;
        }

        if (!defined('CARTSHIFT_TRANSFER_MAINTENANCE') || CARTSHIFT_TRANSFER_MAINTENANCE !== true) {
            \WP_CLI::error('transfer_maintenance_not_confirmed: define CARTSHIFT_TRANSFER_MAINTENANCE=true during the isolated maintenance window.');
            return;
        }

        $before = (new TransferRuntimeProbe())->inspect(TransferRuntimeProbe::ROLE_TARGET);
        $unexpectedErrors = array_values(array_diff($before->errors, ['schema_upgrade_required']));

        if ($unexpectedErrors !== []) {
            \WP_CLI::error('Target runtime cannot be upgraded safely: ' . implode(', ', $unexpectedErrors));
            return;
        }

        $lockName = 'cartshift_v8_' . substr(hash('sha256', $before->fingerprint), 0, 40);
        $acquired = $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 0)', $lockName));

        if (!in_array($acquired, [1, '1'], true)) {
            \WP_CLI::error('transfer_lock_unavailable: target schema mutex was not acquired.');
            return;
        }

        $releaseOk = false;
        $failure = null;
        $document = null;

        try {
            if (!Migrations::upgradeExplicit($from, $to)) {
                $failure = 'schema_upgrade_failed: v8 postconditions did not pass and the version remains at v7.';
            } else {
                $after = (new TransferRuntimeProbe())->inspect(TransferRuntimeProbe::ROLE_TARGET);

                if (!$after->isReady()) {
                    $failure = 'schema_upgrade_postcondition_failed: ' . implode(', ', $after->errors);
                } else {
                    $document = [
                        'status' => 'upgraded',
                        'from' => $from,
                        'to' => $to,
                        'execution_context' => $context,
                        'backup_sha256' => $backup,
                        'runtime_fingerprint' => $after->fingerprint,
                    ];
                }
            }
        } finally {
            $released = $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lockName));
            $releaseOk = in_array($released, [1, '1'], true);
        }

        if ($failure !== null) {
            \WP_CLI::error($failure);
            return;
        }

        if (!$releaseOk) {
            \WP_CLI::error('transfer_lock_release_failed: schema upgraded but the mutex release was not confirmed.');
            return;
        }

        if (!is_array($document)) {
            \WP_CLI::error('schema_upgrade_postcondition_failed: no verified result was produced.');
            return;
        }

        if ($format === 'json') {
            \WP_CLI::line(json_encode($document, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        } else {
            \WP_CLI\Utils\format_items('table', [
                ['Check' => 'Status', 'Result' => 'upgraded'],
                ['Check' => 'Schema', 'Result' => '7 -> 8'],
                ['Check' => 'Runtime fingerprint', 'Result' => $document['runtime_fingerprint']],
            ], ['Check', 'Result']);
        }

        if ($format === 'table') {
            \WP_CLI::success('Schema v8 is installed and verified.');
        }
    }

    /**
     * @param list<string> $args
     * @param array<string, mixed> $assocArgs
     * @internal The optional auditor is a test seam; WP-CLI supplies two arguments.
     */
    public static function inspectTarget(
        array $args,
        array $assocArgs,
        ?LegacyMapAuditor $auditor = null,
    ): void {
        $role = (string) ($assocArgs['role'] ?? '');
        $sourceKey = (string) ($assocArgs['source-key'] ?? '');
        $format = (string) ($assocArgs['format'] ?? 'table');

        if ($role !== TransferRuntimeProbe::ROLE_TARGET) {
            \WP_CLI::error('--role is required and must be target for inspect-target.');
            return;
        }

        try {
            \CartShift\Domain\Transfer\SourceIdentity::assertValidSourceKey($sourceKey);
        } catch (\InvalidArgumentException $exception) {
            \WP_CLI::error('--source-key is required and must be a canonical non-local source key.');
            return;
        }

        if (!in_array($format, self::FORMATS, true)) {
            \WP_CLI::error('--format must be table or json.');
            return;
        }

        $report = ($auditor ?? new LegacyMapAuditor())->inspect($sourceKey);

        if ($format === 'json') {
            \WP_CLI::line(json_encode($report->toArray(), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            return;
        }

        \WP_CLI\Utils\format_items('table', [
            ['Check' => 'Source key', 'Result' => $report->sourceKey],
            ['Check' => 'Mappings', 'Result' => (string) array_sum($report->mappingCountsByEntity)],
            ['Check' => 'Legacy mappings', 'Result' => (string) array_sum($report->legacyMappingCounts)],
            ['Check' => 'Missing targets', 'Result' => (string) array_sum($report->missingTargetCounts)],
            ['Check' => 'Invoice collisions', 'Result' => (string) $report->invoiceCollisionCount],
            ['Check' => 'Receipt coverage', 'Result' => (string) $report->receiptCoverageCount],
            ['Check' => 'Blockers', 'Result' => implode(', ', $report->reasonCodes())],
            ['Check' => 'Fingerprint', 'Result' => $report->fingerprint],
        ], ['Check', 'Result']);
    }

    /**
     * @param list<string> $args
     * @param array<string, mixed> $assocArgs
     * @internal The optional auditor is a test seam; WP-CLI supplies two arguments.
     */
    public static function audit(array $args, array $assocArgs, ?TransferAuditor $auditor = null): void
    {
        $role = (string) ($assocArgs['role'] ?? '');
        $format = (string) ($assocArgs['format'] ?? 'table');
        $sourceKey = (string) ($assocArgs['source-key'] ?? '');

        if ($role !== TransferRuntimeProbe::ROLE_SOURCE) {
            \WP_CLI::error('--role is required and must be source for audit.');
            return;
        }

        if (!in_array($format, self::FORMATS, true)) {
            \WP_CLI::error('--format must be table or json.');
            return;
        }

        if ($sourceKey === '') {
            \WP_CLI::error('--source-key is required.');
            return;
        }

        if (isset($assocArgs['include-reverse-dependencies'])) {
            \WP_CLI::error('--include-reverse-dependencies is not available until the v2 closure resolver owns it.');
            return;
        }

        $allKinds = array_key_exists('all-kinds', $assocArgs);
        $presentKinds = array_values(array_filter(
            self::KINDS,
            static fn (string $kind): bool => array_key_exists($kind, $assocArgs),
        ));

        if ($allKinds && $presentKinds !== []) {
            \WP_CLI::error('--all-kinds cannot be combined with per-kind clauses.');
            return;
        }

        if (!$allKinds) {
            $missing = array_values(array_diff(self::KINDS, $presentKinds));

            if ($missing !== []) {
                \WP_CLI::error('Explicit clauses are required for: ' . implode(', ', $missing) . '.');
                return;
            }
        }

        try {
            $clauses = $allKinds
                ? array_fill_keys(self::KINDS, SelectionClause::all())
                : array_combine(
                    self::KINDS,
                    array_map(
                        static fn (string $kind): SelectionClause => self::parseClause(
                            $kind,
                            (string) $assocArgs[$kind],
                        ),
                        self::KINDS,
                    ),
                );
            $selection = new TransferSelection(
                $sourceKey,
                $clauses['products'],
                $clauses['customers'],
                $clauses['orders'],
                $clauses['subscriptions'],
            );
            $decisions = isset($assocArgs['decision-set'])
                ? \CartShift\Domain\Transfer\Decision\TransferDecisionSet::fromFile(
                    self::absolutePath((string) $assocArgs['decision-set'], '--decision-set')
                        ?? throw new \InvalidArgumentException('Decision path is invalid.'),
                )
                : \CartShift\Domain\Transfer\Decision\TransferDecisionSet::empty();
            $decisions->assertSourceKey($selection->sourceKey);
        } catch (\InvalidArgumentException $exception) {
            \WP_CLI::error($exception->getMessage());
            return;
        } catch (\Throwable $exception) {
            \WP_CLI::error('transfer_decision_set_invalid: ' . $exception->getMessage());
            return;
        }

        $auditor ??= self::loadedAuditor();
        $report = $auditor->audit($selection, $decisions);

        if ($format === 'json') {
            \WP_CLI::line(AuditRenderer::json($report));
        } else {
            \WP_CLI\Utils\format_items('table', AuditRenderer::table($report), ['Check', 'Result']);
        }

        if (!$report->ready) {
            \WP_CLI::error('Transfer audit blocked: ' . implode(', ', array_column($report->blockers, 'code')));
            return;
        }

        if ($format === 'table') {
            \WP_CLI::success('Transfer audit is ready.');
        }
    }

    /**
     * @param list<string> $args
     * @param array<string, mixed> $assocArgs
     * @param null|callable(TransferSelection, string, string): array{path:string,records_sha256:string} $pipeline
     */
    public static function export(array $args, array $assocArgs, ?callable $pipeline = null): void
    {
        if (($assocArgs['role'] ?? '') !== TransferRuntimeProbe::ROLE_SOURCE) {
            \WP_CLI::error('--role is required and must be source for export.');
            return;
        }
        $sourceKey = (string) ($assocArgs['source-key'] ?? '');
        $destination = (string) ($assocArgs['destination'] ?? '');
        $format = (string) ($assocArgs['format'] ?? 'table');
        try {
            \CartShift\Domain\Transfer\SourceIdentity::assertValidSourceKey($sourceKey);
        } catch (\InvalidArgumentException) {
            \WP_CLI::error('--source-key is required and must be a canonical non-local source key.');
            return;
        }
        if ($destination === '' || $destination[0] !== '/' || !is_dir($destination) || is_link($destination)) {
            \WP_CLI::error('--destination must be an existing absolute non-symlink directory outside the web root.');
            return;
        }
        $destinationReal = realpath($destination);
        $webRoot = defined('ABSPATH') ? realpath(ABSPATH) : false;
        if ($destinationReal === false || ($webRoot !== false && ($destinationReal === $webRoot || str_starts_with($destinationReal . '/', $webRoot . '/')))) {
            \WP_CLI::error('--destination must be an existing absolute non-symlink directory outside the web root.');
            return;
        }
        if (!in_array($format, self::FORMATS, true)) {
            \WP_CLI::error('--format must be table or json.');
            return;
        }
        $decisionPath = self::absolutePath((string) ($assocArgs['decision-set'] ?? ''), '--decision-set');
        if ($decisionPath === null) return;
        try {
            \CartShift\Domain\Transfer\Decision\TransferDecisionSet::fromFile($decisionPath);
        } catch (\Throwable $exception) {
            \WP_CLI::error('transfer_decision_set_invalid: ' . $exception->getMessage());
            return;
        }

        $allKinds = array_key_exists('all-kinds', $assocArgs);
        $presentKinds = array_values(array_filter(self::KINDS, static fn (string $kind): bool => array_key_exists($kind, $assocArgs)));
        if ($allKinds && $presentKinds !== []) {
            \WP_CLI::error('--all-kinds cannot be combined with per-kind clauses.');
            return;
        }
        if (!$allKinds && array_diff(self::KINDS, $presentKinds) !== []) {
            \WP_CLI::error('Explicit clauses are required for products, customers, orders, subscriptions.');
            return;
        }
        try {
            $clauses = $allKinds
                ? array_fill_keys(self::KINDS, SelectionClause::all())
                : array_combine(self::KINDS, array_map(static fn (string $kind): SelectionClause => self::parseClause($kind, (string) $assocArgs[$kind]), self::KINDS));
            $reverse = self::parseReverseDependencies((string) ($assocArgs['include-reverse-dependencies'] ?? ''));
            $selection = new TransferSelection($sourceKey, $clauses['products'], $clauses['customers'], $clauses['orders'], $clauses['subscriptions'], $reverse);
        } catch (\InvalidArgumentException $exception) {
            \WP_CLI::error($exception->getMessage());
            return;
        }

        if ($pipeline === null) {
            $pipeline = (new LoadedWooExportPipeline())(...);
        }
        try {
            $result = $pipeline($selection, $destinationReal, $decisionPath);
        } catch (\Throwable $exception) {
            \WP_CLI::error('Transfer export blocked: ' . $exception->getMessage());
            return;
        }
        if (!is_array($result) || !is_string($result['path'] ?? null) || !is_string($result['records_sha256'] ?? null)) {
            \WP_CLI::error('transfer_export_result_invalid: export pipeline returned no verified package receipt.');
            return;
        }
        $document = ['path' => $result['path'], 'selection_fingerprint' => $selection->fingerprint(), 'records_sha256' => $result['records_sha256']];
        if ($format === 'json') {
            \WP_CLI::line(json_encode($document, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        } else {
            \WP_CLI\Utils\format_items('table', [
                ['Check' => 'Package', 'Result' => $document['path']],
                ['Check' => 'Selection fingerprint', 'Result' => $document['selection_fingerprint']],
                ['Check' => 'Records SHA-256', 'Result' => $document['records_sha256']],
            ], ['Check', 'Result']);
            \WP_CLI::success('Immutable transfer package is validated and ready.');
        }
    }

    /** @param list<string> $args @param array<string, mixed> $assocArgs */
    public static function validatePackage(array $args, array $assocArgs, ?TransferPackageValidator $validator = null): void
    {
        if (($assocArgs['role'] ?? '') !== TransferRuntimeProbe::ROLE_TARGET) {
            \WP_CLI::error('--role is required and must be target for validate-package.');
            return;
        }
        $format = (string) ($assocArgs['format'] ?? 'table');
        if (!in_array($format, self::FORMATS, true)) {
            \WP_CLI::error('--format must be table or json.');
            return;
        }
        $package = self::absolutePath((string) ($assocArgs['package'] ?? ''), '--package');
        if ($package === null) return;
        try {
            $manifest = ($validator ?? new TransferPackageValidator())->assertValid($package);
        } catch (\Throwable $exception) {
            \WP_CLI::error('transfer_package_invalid: ' . $exception->getMessage());
            return;
        }
        self::renderCommandResult([
            'status' => 'validated',
            'source_key' => $manifest->sourceKey,
            'selection_fingerprint' => $manifest->selectionFingerprint,
            'records_sha256' => $manifest->recordsSha256,
            'record_counts' => $manifest->recordCounts,
        ], $format, 'Transfer package is valid.');
    }

    /** @param list<string> $args @param array<string, mixed> $assocArgs @param null|callable(array<string,mixed>):array<string,mixed> $pipeline */
    public static function prepare(array $args, array $assocArgs, ?callable $pipeline = null): void
    {
        if (($assocArgs['role'] ?? '') !== TransferRuntimeProbe::ROLE_TARGET) {
            \WP_CLI::error('--role is required and must be target for prepare.');
            return;
        }
        $format = (string) ($assocArgs['format'] ?? 'table');
        if (!in_array($format, self::FORMATS, true)) {
            \WP_CLI::error('--format must be table or json.');
            return;
        }
        $context = (string) ($assocArgs['execution-context'] ?? '');
        if (!in_array($context, ['rehearsal', 'cutover'], true)) {
            \WP_CLI::error('--execution-context must be rehearsal or cutover.');
            return;
        }
        $package = self::absolutePath((string) ($assocArgs['package'] ?? ''), '--package');
        $decisions = self::absolutePath((string) ($assocArgs['decision-set'] ?? ''), '--decision-set');
        $private = self::absolutePath((string) ($assocArgs['private-dir'] ?? ''), '--private-dir');
        if ($package === null || $decisions === null || $private === null) return;
        try {
            $validator = new \CartShift\Domain\Transfer\Package\TransferPackageValidator();
            $manifest = $validator->assertValid($package);
            $decisionSet = \CartShift\Domain\Transfer\Decision\TransferDecisionSet::fromFile($decisions);
            $decisionSet->assertSourceKey($manifest->sourceKey);
            $records = iterator_to_array((new \CartShift\Domain\Transfer\Package\TransferPackageReader($package, $validator))->records(), false);
            $closure = (new \CartShift\Domain\Transfer\Graph\TransferDependencyGraph())->validate($records, $decisionSet);
            if (!$closure->closed) {
                throw new \RuntimeException('transfer_dependency_graph_blocked:' . implode(',', $closure->reasonCodes));
            }
            $private = \CartShift\Domain\Transfer\Execution\PrivateTransferFile::directory($private);
            $package = realpath($package) ?: throw new \RuntimeException('transfer_package_path_changed');
            $decisions = realpath($decisions) ?: throw new \RuntimeException('transfer_decision_path_changed');
        } catch (\Throwable $exception) {
            \WP_CLI::error('transfer_prepare_input_invalid: ' . $exception->getMessage());
            return;
        }
        $pipeline ??= LoadedTargetPreparePipeline::create();
        try {
            $result = $pipeline([
                'package' => $package,
                'decision_set' => $decisions,
                'private_dir' => $private,
                'execution_context' => $context,
                'package_hash' => hash('sha256', $manifest->canonicalJson()),
                'decision_hash' => $decisionSet->fingerprint(),
                'selection_hash' => $manifest->selectionFingerprint,
                'source_key' => $manifest->sourceKey,
            ]);
        } catch (\Throwable $exception) {
            \WP_CLI::error('transfer_prepare_blocked: ' . $exception->getMessage());
            return;
        }
        self::renderCommandResult($result, $format, 'Prepared transfer descriptor is sealed.');
    }

    /** @param list<string> $args @param array<string, mixed> $assocArgs */
    public static function stage(array $args, array $assocArgs, ?callable $pipeline = null): void
    {
        self::targetMutation('stage', $assocArgs, $pipeline);
    }

    /** @param list<string> $args @param array<string, mixed> $assocArgs */
    public static function reconcile(array $args, array $assocArgs, ?callable $pipeline = null): void
    {
        self::targetMutation('reconcile', $assocArgs, $pipeline);
    }

    /** @param list<string> $args @param array<string, mixed> $assocArgs */
    public static function promote(array $args, array $assocArgs, ?callable $pipeline = null): void
    {
        self::targetMutation('promote', $assocArgs, $pipeline);
    }

    /** @param list<string> $args @param array<string, mixed> $assocArgs */
    public static function prepareSubscriptionCutover(array $args, array $assocArgs, ?callable $pipeline = null): void
    {
        self::targetMutation('prepare-subscription-cutover', $assocArgs, $pipeline);
    }

    /** @param list<string> $args @param array<string, mixed> $assocArgs */
    public static function activateSubscriptions(array $args, array $assocArgs, ?callable $pipeline = null): void
    {
        self::targetMutation('activate-subscriptions', $assocArgs, $pipeline);
    }

    /** @param list<string> $args @param array<string, mixed> $assocArgs */
    public static function releaseSubscriptionSource(
        array $args,
        array $assocArgs,
        ?callable $pipeline = null,
        ?callable $sourceEnvironment = null,
    ): void
    {
        if (self::rejectUnsupportedOverrides($assocArgs)) return;
        if (($assocArgs['role'] ?? '') !== TransferRuntimeProbe::ROLE_SOURCE) {
            \WP_CLI::error('--role is required and must be source for release-subscription-source.');
            return;
        }
        $context = (string) ($assocArgs['execution-context'] ?? '');
        $descriptor = (string) ($assocArgs['descriptor'] ?? '');
        $format = (string) ($assocArgs['format'] ?? 'table');
        $private = self::absolutePath((string) ($assocArgs['private-dir'] ?? ''), '--private-dir');
        if ($private === null) return;
        if (!in_array($context, ['rehearsal', 'cutover'], true)
            || preg_match('/\A[a-z0-9][a-z0-9-]{2,35}\z/D', $descriptor) !== 1
            || !in_array($format, self::FORMATS, true)) {
            \WP_CLI::error('Source release requires an exact descriptor, execution context and output format.');
            return;
        }
        if (($assocArgs['renewals-paused'] ?? false) !== true) {
            \WP_CLI::error('--renewals-paused is required and records an operator assertion; it does not pause workers.');
            return;
        }
        try {
            $private = \CartShift\Domain\Transfer\Execution\PrivateTransferFile::directory($private);
            $cutoverManifest = null;
            if ($context === 'cutover') {
                if (($assocArgs['rehearsal-source-proof'] ?? '') !== '') throw new \RuntimeException('cutover_rehearsal_source_proof_forbidden');
                $cutoverManifest = \CartShift\Domain\Transfer\Execution\ConfiguredTransferEvidence::assertCutoverApproval((string) ($assocArgs['cutover-approval'] ?? ''));
            } elseif (($assocArgs['cutover-approval'] ?? '') !== '') {
                throw new \RuntimeException('rehearsal_cutover_approval_unexpected');
            }
            $repository = new \CartShift\Domain\Transfer\Subscription\SubscriptionCutoverEvidenceRepository($private);
            $evidence = $repository->get($descriptor);
            if ($evidence->executionContext !== $context) throw new \RuntimeException('subscription_cutover_execution_context_changed');
            $cutoverManifest?->assertTransferIdentity(
                $evidence->sourceKey,
                $evidence->packageHash,
                $evidence->decisionHash,
                $evidence->selectionHash,
            );
            $sourceEnvironment ??= static fn (): array => [
                (new LoadedSourceInstanceFingerprint())->fingerprint(),
                (new TransferRuntimeProbe())->inspect(TransferRuntimeProbe::ROLE_SOURCE),
            ];
            [$sourceInstance, $runtime] = $sourceEnvironment();
            if (!is_string($sourceInstance) || !$runtime instanceof \CartShift\Domain\Transfer\Runtime\TransferRuntimeReport) {
                throw new \RuntimeException('subscription_cutover_source_environment_invalid');
            }
            $proofPath = (string) ($assocArgs['rehearsal-source-proof'] ?? '');
            if ($context === 'rehearsal' && $proofPath !== '') {
                $sourceInstance = \CartShift\Domain\Transfer\Subscription\RehearsalSourceProof::assertAndResolve(
                    $proofPath,
                    $private,
                    $descriptor,
                    $evidence->sourceInstanceFingerprint,
                    $sourceInstance,
                );
            }
            if (!hash_equals($evidence->sourceInstanceFingerprint, $sourceInstance)) {
                throw new \RuntimeException('subscription_cutover_source_instance_changed');
            }
            if (!$runtime->isReady()) throw new \RuntimeException('subscription_cutover_source_runtime_unready');
            if (!hash_equals($evidence->sourceRuntimeFingerprint, $runtime->fingerprint)) {
                throw new \RuntimeException('subscription_cutover_source_runtime_changed');
            }
            $pipeline ??= static fn (): \CartShift\Domain\Transfer\Subscription\SubscriptionCutoverEvidence =>
                (new \CartShift\Domain\Transfer\Subscription\SubscriptionSourceCutover(
                    $repository,
                    new \CartShift\Domain\Transfer\Subscription\LoadedSubscriptionSourceCutoverGateway(),
                ))->release($descriptor, true, gmdate('Y-m-d\TH:i:s\Z'));
            $result = $pipeline();
            if ($result instanceof \CartShift\Domain\Transfer\Subscription\SubscriptionCutoverEvidence) {
                $result = ['state' => $result->state, 'evidence_fingerprint' => $result->fingerprint(), 'entries' => count($result->entries)];
            }
            if (!is_array($result)) throw new \RuntimeException('subscription_source_release_result_invalid');
        } catch (\Throwable $exception) {
            \WP_CLI::error('subscription_source_release_blocked: ' . $exception->getMessage());
            return;
        }
        self::renderCommandResult($result, $format, 'Source subscription ownership is released and recorded.');
    }

    /** @param list<string> $args @param array<string, mixed> $assocArgs */
    public static function activateCatalogue(array $args, array $assocArgs, ?callable $pipeline = null): void
    {
        self::targetMutation('activate-catalogue', $assocArgs, $pipeline);
    }

    /** @param list<string> $args @param array<string, mixed> $assocArgs */
    public static function complete(array $args, array $assocArgs, ?callable $pipeline = null): void
    {
        self::targetMutation('complete', $assocArgs, $pipeline);
    }

    /** @param list<string> $args @param array<string, mixed> $assocArgs */
    public static function rollback(array $args, array $assocArgs, ?callable $pipeline = null): void
    {
        self::targetMutation('rollback', $assocArgs, $pipeline, true);
    }

    /** @param list<string> $args @param array<string, mixed> $assocArgs */
    public static function status(array $args, array $assocArgs, ?callable $pipeline = null): void
    {
        if (($assocArgs['role'] ?? '') !== TransferRuntimeProbe::ROLE_TARGET) {
            \WP_CLI::error('--role is required and must be target for status.');
            return;
        }
        $descriptor = (string) ($assocArgs['descriptor'] ?? '');
        $format = (string) ($assocArgs['format'] ?? 'table');
        if (preg_match('/\A[a-z0-9][a-z0-9-]{2,35}\z/D', $descriptor) !== 1) {
            \WP_CLI::error('--descriptor must be an exact prepared descriptor ID.');
            return;
        }
        if (!in_array($format, self::FORMATS, true)) {
            \WP_CLI::error('--format must be table or json.');
            return;
        }
        $pipeline ??= self::pipeline('status');
        if ($pipeline === null) {
            \WP_CLI::error('transfer_status_pipeline_unavailable');
            return;
        }
        try {
            $result = $pipeline(['descriptor' => $descriptor]);
        } catch (\Throwable $exception) {
            \WP_CLI::error('transfer_status_blocked: ' . $exception->getMessage());
            return;
        }
        self::renderCommandResult($result, $format, 'Transfer status loaded.');
    }

    /** @param array<string, mixed> $assocArgs @param null|callable(array<string,mixed>):array<string,mixed> $pipeline */
    private static function targetMutation(string $command, array $assocArgs, ?callable $pipeline, bool $rollback = false): void
    {
        if (self::rejectUnsupportedOverrides($assocArgs)) {
            return;
        }
        if (($assocArgs['role'] ?? '') !== TransferRuntimeProbe::ROLE_TARGET) {
            \WP_CLI::error('--role is required and must be target for ' . $command . '.');
            return;
        }
        $context = (string) ($assocArgs['execution-context'] ?? '');
        if (!in_array($context, ['rehearsal', 'cutover'], true)) {
            \WP_CLI::error('--execution-context must be rehearsal or cutover.');
            return;
        }
        $approval = (string) ($assocArgs['cutover-approval'] ?? '');
        if ($context === 'cutover' && preg_match('/\A[a-f0-9]{64}\z/D', $approval) !== 1) {
            \WP_CLI::error('Cutover mutation requires --cutover-approval=<sha256>.');
            return;
        }
        $package = self::absolutePath((string) ($assocArgs['package'] ?? ''), '--package');
        if ($package === null) return;
        $descriptor = (string) ($assocArgs['descriptor'] ?? '');
        $confirm = (string) ($assocArgs['confirm'] ?? '');
        $format = (string) ($assocArgs['format'] ?? 'table');
        if (preg_match('/\A[a-z0-9][a-z0-9-]{2,35}\z/D', $descriptor) !== 1) {
            \WP_CLI::error('--descriptor must be an exact prepared descriptor ID.');
            return;
        }
        if (preg_match('/\A[a-f0-9]{64}\z/D', $confirm) !== 1) {
            \WP_CLI::error('--confirm must be the exact selection fingerprint.');
            return;
        }
        if (!in_array($format, self::FORMATS, true)) {
            \WP_CLI::error('--format must be table or json.');
            return;
        }
        $input = [
            'command' => $command,
            'package' => $package,
            'descriptor' => $descriptor,
            'confirm' => $confirm,
            'execution_context' => $context,
            'cutover_approval' => $approval,
        ];
        if (array_key_exists('batch-size', $assocArgs)) {
            if ($command !== 'stage' || preg_match('/\A[1-9][0-9]*\z/D', (string) $assocArgs['batch-size']) !== 1) {
                \WP_CLI::error('--batch-size must be a positive integer and is available only for stage.');
                return;
            }
            $input['batch_size'] = (int) $assocArgs['batch-size'];
        }
        if (array_key_exists('lease-recovery', $assocArgs)) {
            if (!in_array($command, [
                'stage', 'reconcile', 'promote', 'prepare-subscription-cutover',
                'activate-subscriptions', 'activate-catalogue', 'complete', 'rollback',
            ], true)
                || preg_match('/\A[a-f0-9]{64}\z/D', (string) $assocArgs['lease-recovery']) !== 1) {
                \WP_CLI::error('--lease-recovery must be a lowercase SHA-256 value and is available only for resumable commands.');
                return;
            }
            $input['lease_recovery'] = (string) $assocArgs['lease-recovery'];
            if ($context === 'cutover' && !$rollback && !hash_equals($approval, $input['lease_recovery'])) {
                \WP_CLI::error('--lease-recovery must equal the owner-approved cutover manifest SHA-256 during cutover.');
                return;
            }
        }
        if ($rollback) {
            $plan = self::absolutePath((string) ($assocArgs['rollback-plan'] ?? ''), '--rollback-plan');
            if ($plan === null) return;
            try {
                $rollbackPlan = (new \CartShift\Domain\Transfer\Execution\RollbackPlanRepository(dirname($plan)))->get($plan);
            } catch (\Throwable $exception) {
                \WP_CLI::error('rollback_plan_invalid: ' . $exception->getMessage());
                return;
            }
            if ($rollbackPlan->runId !== $descriptor || !$rollbackPlan->safe) {
                \WP_CLI::error('rollback_plan_invalid: plan is conflicted or belongs to another descriptor.');
                return;
            }
            if (isset($input['lease_recovery'])) {
                $planDigest = hash_file('sha256', $plan);
                if (!is_string($planDigest) || !hash_equals($planDigest, (string) $input['lease_recovery'])) {
                    \WP_CLI::error('--lease-recovery must equal the approved rollback plan SHA-256.');
                    return;
                }
            }
            $input['rollback_plan'] = $plan;
            $input['rollback_plan_fingerprint'] = $rollbackPlan->fingerprint();
        }
        $pipeline ??= self::pipeline($command);
        if ($pipeline === null) {
            \WP_CLI::error('transfer_' . str_replace('-', '_', $command) . '_pipeline_unavailable');
            return;
        }
        try {
            $result = $pipeline($input);
        } catch (\Throwable $exception) {
            \WP_CLI::error('transfer_' . str_replace('-', '_', $command) . '_blocked: ' . $exception->getMessage());
            return;
        }
        self::renderCommandResult($result, $format, 'Transfer command completed its checked transition.');
    }

    private static function absolutePath(string $path, string $option): ?string
    {
        if ($path === '' || $path[0] !== '/' || str_contains($path, "\0")) {
            \WP_CLI::error($option . ' must be an absolute path.');
            return null;
        }
        return $path;
    }

    /** @param array<string, mixed> $assocArgs */
    private static function rejectUnsupportedOverrides(array $assocArgs): bool
    {
        foreach (['force', 'ignore-errors', 'skip-reconcile'] as $override) {
            if (array_key_exists($override, $assocArgs)) {
                \WP_CLI::error('unsupported_override: --' . $override . ' does not exist for v2 transfers.');
                return true;
            }
        }
        return false;
    }

    private static function pipeline(string $command): ?callable
    {
        return in_array($command, ['stage', 'reconcile', 'promote', 'prepare-subscription-cutover', 'activate-subscriptions', 'activate-catalogue', 'complete', 'rollback', 'status'], true)
            ? LoadedTargetTransferPipeline::create()
            : null;
    }

    /** @param array<string, mixed> $document */
    private static function renderCommandResult(array $document, string $format, string $success): void
    {
        if ($format === 'json') {
            \WP_CLI::line(json_encode($document, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            return;
        }
        $rows = [];
        foreach ($document as $key => $value) {
            $rows[] = ['Check' => (string) $key, 'Result' => is_scalar($value) || $value === null ? (string) $value : json_encode($value, JSON_THROW_ON_ERROR)];
        }
        \WP_CLI\Utils\format_items('table', $rows, ['Check', 'Result']);
        \WP_CLI::success($success);
    }

    private static function parseClause(string $kind, string $value): SelectionClause
    {
        if ($value === 'none') {
            return SelectionClause::none();
        }

        if ($value === 'all') {
            return SelectionClause::all();
        }

        if (str_starts_with($value, 'ids:')) {
            $csv = substr($value, 4);
            $parts = explode(',', $csv);

            if ($csv === '' || $parts === [] || array_filter(
                $parts,
                static fn (string $part): bool => preg_match('/\A[1-9][0-9]*\z/D', $part) !== 1,
            ) !== []) {
                throw new \InvalidArgumentException(sprintf(
                    '--%s must be none, all, ids:<csv>, or since:<UTC>.',
                    $kind,
                ));
            }

            return SelectionClause::ids(array_map('intval', $parts));
        }

        if (str_starts_with($value, 'since:')) {
            try {
                return SelectionClause::since(substr($value, 6));
            } catch (\InvalidArgumentException) {
                // Re-throw the stable option-qualified message below.
            }
        }

        throw new \InvalidArgumentException(sprintf(
            '--%s must be none, all, ids:<csv>, or since:<UTC>.',
            $kind,
        ));
    }

    /** @return list<string> */
    private static function parseReverseDependencies(string $value): array
    {
        if ($value === '') return [];
        $values = explode(',', $value);
        if (array_filter($values, static fn (string $kind): bool => !in_array($kind, ['order', 'subscription'], true)) !== []
            || count($values) !== count(array_unique($values))) {
            throw new \InvalidArgumentException('--include-reverse-dependencies accepts unique order,subscription values only.');
        }
        sort($values);
        return $values;
    }

    private static function loadedAuditor(): TransferAuditor
    {
        return LoadedWooTransferAuditor::create();
    }
}

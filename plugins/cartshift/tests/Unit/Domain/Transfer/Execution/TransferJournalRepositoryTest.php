<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\Domain\Transfer\Execution;

use CartShift\Domain\Transfer\Execution\PreparedTransferRepository;
use CartShift\Domain\Transfer\Execution\PreparedTransfer;
use CartShift\Domain\Transfer\Execution\TargetStateFingerprint;
use CartShift\Domain\Transfer\Execution\TransferJournalRepository;
use CartShift\Domain\Transfer\Execution\TransferReceipt;
use CartShift\Domain\Transfer\Execution\TransferRunState;
use CartShift\Domain\Transfer\RecordEnvelope;
use CartShift\Domain\Transfer\SourceIdentity;
use CartShift\Support\DatabaseTransaction;
use CartShift\Tests\Unit\PluginTestCase;

final class TransferJournalRepositoryTest extends PluginTestCase
{
    private string $directory;
    private object $originalDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalDatabase = $GLOBALS['wpdb'];
        $this->directory = sys_get_temp_dir() . '/cartshift-journal-' . bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
        chmod($this->directory, 0700);
    }

    protected function tearDown(): void
    {
        foreach (array_diff(scandir($this->directory) ?: [], ['.', '..']) as $file) unlink($this->directory . '/' . $file);
        rmdir($this->directory);
        $GLOBALS['wpdb'] = $this->originalDatabase;
        DatabaseTransaction::reset();
        parent::tearDown();
    }

    public function testJournalAndOutboxCommitTogetherAndRetryLoadsOnlyExactSuccessfulReceipt(): void
    {
        $database = new ExecutionJournalDatabase();
        $GLOBALS['wpdb'] = $database;
        $prepared = new PreparedTransfer(
            'run-journal-22',
            '/srv/private/package',
            str_repeat('1', 64),
            new TargetStateFingerprint(...array_map(static fn (string $digit): string => str_repeat($digit, 64), ['1', '2', '3', '4', '5', '6', '7'])),
            'rehearsal',
            [],
            false,
            '2026-08-10T12:00:00Z',
            'shop-alpha',
        );
        $descriptors = new PreparedTransferRepository($this->directory);
        $descriptors->save($prepared);
        $journal = new TransferJournalRepository($descriptors, $database);
        $record = RecordEnvelope::forPayload(1, new SourceIdentity('shop-alpha', 'product', '41'), [
            'dependencies' => [],
            'email' => 'must-not-enter-journal@example.test',
        ]);
        $receipt = new TransferReceipt(
            $prepared->runId, 'product', $record->identity->canonical(), 1, $record->privateContentDigest,
            'created', ['primary' => 901], null, str_repeat('a', 64), 1,
            '2026-08-10T12:00:00Z', '2026-08-10T12:00:01Z',
        );

        $journal->start($prepared);
        $changedTarget = TargetStateFingerprint::fromArray([
            ...$prepared->targetState->toArray(),
            'gateway' => str_repeat('f', 64),
        ]);
        $changedDescriptor = new PreparedTransfer(
            $prepared->runId,
            $prepared->packagePath,
            $prepared->packageHash,
            $changedTarget,
            $prepared->executionContext,
            $prepared->blockingFindings,
            $prepared->leaveDraftAccepted,
            $prepared->createdAtUtc,
            $prepared->sourceKey,
            $prepared->generation,
        );
        self::assertNotSame(
            $prepared->descriptorHash(),
            $changedDescriptor->descriptorHash(),
            'Gateway-registration drift escaped the descriptor hash persisted by the journal.',
        );
        $journal->transition($prepared->runId, TransferRunState::Prepared, TransferRunState::Staging, true);

        try {
            $journal->commitReceipt($receipt);
            self::fail('Receipt was accepted without the target transaction.');
        } catch (\RuntimeException $exception) {
            self::assertSame('transfer_receipt_requires_active_transaction', $exception->getMessage());
        }

        DatabaseTransaction::begin();
        $journal->commitReceipt($receipt);
        DatabaseTransaction::commit();

        self::assertSame($receipt->toArray(), $journal->successfulReceipt($prepared->runId, $record, 1)?->toArray());
        self::assertCount(1, $journal->pendingReceipts($prepared->runId));
        self::assertStringNotContainsString('must-not-enter-journal', json_encode($database->outbox, JSON_THROW_ON_ERROR));

        $journal->markReceiptExported($receipt);
        self::assertSame([], $journal->pendingReceipts($prepared->runId));
        self::assertSame(1, $journal->attempt($prepared->runId));
    }

    public function testInterruptedRunCanResumeOnlyThePersistedPhase(): void
    {
        $database = new ExecutionJournalDatabase();
        $GLOBALS['wpdb'] = $database;
        $prepared = new PreparedTransfer(
            'run-interrupted-22', '/srv/private/package', str_repeat('1', 64),
            new TargetStateFingerprint(...array_map(static fn (string $digit): string => str_repeat($digit, 64), ['1', '2', '3', '4', '5', '6', '7'])),
            'rehearsal', [], false, '2026-08-10T12:00:00Z', 'shop-alpha',
        );
        $descriptors = new PreparedTransferRepository($this->directory);
        $descriptors->save($prepared);
        $journal = new TransferJournalRepository($descriptors, $database);
        $journal->start($prepared);
        $journal->transition($prepared->runId, TransferRunState::Prepared, TransferRunState::Staging, true);
        $journal->transition($prepared->runId, TransferRunState::Staging, TransferRunState::Interrupted);

        self::assertSame(TransferRunState::Staging, $journal->interruptedFrom($prepared->runId));
        try {
            $journal->transition($prepared->runId, TransferRunState::Interrupted, TransferRunState::Reconciling, true);
            self::fail('A staging interruption resumed in reconciliation.');
        } catch (\RuntimeException $exception) {
            self::assertSame('transfer_run_interrupted_phase_mismatch', $exception->getMessage());
        }

        $journal->transition($prepared->runId, TransferRunState::Interrupted, TransferRunState::Staging, true);
        self::assertNull($journal->interruptedFrom($prepared->runId));
    }

    public function testFailedRunRetainsThePhaseThatDeterminesRollbackCutoverSafety(): void
    {
        $database = new ExecutionJournalDatabase();
        $GLOBALS['wpdb'] = $database;
        $prepared = new PreparedTransfer(
            'run-failed-phase-22', '/srv/private/package', str_repeat('1', 64),
            new TargetStateFingerprint(...array_map(static fn (string $digit): string => str_repeat($digit, 64), ['1', '2', '3', '4', '5', '6', '7'])),
            'rehearsal', [], false, '2026-08-10T12:00:00Z', 'shop-alpha',
        );
        $descriptors = new PreparedTransferRepository($this->directory);
        $descriptors->save($prepared);
        $journal = new TransferJournalRepository($descriptors, $database);
        $journal->start($prepared);
        $journal->transition($prepared->runId, TransferRunState::Prepared, TransferRunState::Staging, true);
        $journal->transition($prepared->runId, TransferRunState::Staging, TransferRunState::Failed);

        self::assertSame(TransferRunState::Staging, $journal->failedFrom($prepared->runId));
    }

    public function testRollbackMarksEveryCanonicalMapAndExclusiveClaimWithExactReceiptIdentity(): void
    {
        $database = new ExecutionJournalDatabase();
        $GLOBALS['wpdb'] = $database;
        $prepared = new PreparedTransfer(
            'run-rollback-22', '/srv/private/package', str_repeat('1', 64),
            new TargetStateFingerprint(...array_map(static fn (string $digit): string => str_repeat($digit, 64), ['1', '2', '3', '4', '5', '6', '7'])),
            'rehearsal', [], false, '2026-08-10T12:00:00Z', 'shop-alpha',
        );
        $descriptors = new PreparedTransferRepository($this->directory);
        $descriptors->save($prepared);
        $journal = new TransferJournalRepository($descriptors, $database);
        $receipt = new TransferReceipt(
            $prepared->runId,
            'order',
            'shop-alpha:order:91',
            1,
            str_repeat('b', 64),
            'created',
            ['primary' => 801, 'shop-alpha:order:91' => 801, 'shop-alpha:order:91:transaction:1' => 901],
            null,
            str_repeat('c', 64),
            1,
            '2026-08-10T12:00:00Z',
            '2026-08-10T12:00:01Z',
        );
        $journal->start($prepared);
        DatabaseTransaction::begin();
        $journal->commitReceipt($receipt);
        DatabaseTransaction::commit();
        foreach (['shop-alpha:order:91' => 801, 'shop-alpha:order:91:transaction:1' => 901] as $canonical => $targetId) {
            $identity = SourceIdentity::fromCanonical($canonical);
            $database->maps[$canonical] = [
                'source_key' => $identity->sourceKey,
                'entity_type' => $identity->entityType,
                'wc_id' => $identity->sourceId,
                'fc_id' => $targetId,
                'migration_id' => $receipt->runId,
                'is_simulated' => 0,
                'target_fingerprint' => $receipt->afterFingerprint,
                'record_state' => 'reconciled',
            ];
        }
        $database->claims['order|801'] = [
            'entity_type' => 'order',
            'target_id' => 801,
            'run_id' => $receipt->runId,
            'source_fingerprint' => $receipt->sourceFingerprint,
            'target_fingerprint' => $receipt->afterFingerprint,
            'claim_state' => 'reconciled',
        ];

        DatabaseTransaction::begin();
        $journal->markRecordRolledBack($receipt);
        DatabaseTransaction::commit();

        self::assertSame('rolled_back', $database->records['run-rollback-22|order|shop-alpha:order:91|1']['state']);
        self::assertSame(['rolled_back'], array_values(array_unique(array_column($database->maps, 'record_state'))));
        self::assertSame('rolled_back', $database->claims['order|801']['claim_state']);
    }

    public function testRollbackRefusesAReceiptWhoseExactMappingEvidenceIsMissing(): void
    {
        $database = new ExecutionJournalDatabase();
        $GLOBALS['wpdb'] = $database;
        $prepared = new PreparedTransfer(
            'run-map-stop-22', '/srv/private/package', str_repeat('1', 64),
            new TargetStateFingerprint(...array_map(static fn (string $digit): string => str_repeat($digit, 64), ['1', '2', '3', '4', '5', '6', '7'])),
            'rehearsal', [], false, '2026-08-10T12:00:00Z', 'shop-alpha',
        );
        $descriptors = new PreparedTransferRepository($this->directory);
        $descriptors->save($prepared);
        $journal = new TransferJournalRepository($descriptors, $database);
        $receipt = new TransferReceipt(
            $prepared->runId, 'product', 'shop-alpha:product:41', 1, str_repeat('b', 64),
            'created', ['primary' => 901, 'shop-alpha:product:41' => 901], null, str_repeat('c', 64), 1,
            '2026-08-10T12:00:00Z', '2026-08-10T12:00:01Z',
        );
        $journal->start($prepared);
        DatabaseTransaction::begin();
        $journal->commitReceipt($receipt);
        DatabaseTransaction::commit();

        DatabaseTransaction::begin();
        try {
            $journal->markRecordRolledBack($receipt);
            self::fail('Rollback accepted a receipt whose exact ID map was absent.');
        } catch (\RuntimeException $exception) {
            self::assertSame('transfer_record_rollback_map_conflict', $exception->getMessage());
        } finally {
            DatabaseTransaction::rollback();
        }
    }

    public function testRollbackJournalMutationRequiresTheTargetTransaction(): void
    {
        $database = new ExecutionJournalDatabase();
        $GLOBALS['wpdb'] = $database;
        $prepared = new PreparedTransfer(
            'run-tx-stop-22', '/srv/private/package', str_repeat('1', 64),
            new TargetStateFingerprint(...array_map(static fn (string $digit): string => str_repeat($digit, 64), ['1', '2', '3', '4', '5', '6', '7'])),
            'rehearsal', [], false, '2026-08-10T12:00:00Z', 'shop-alpha',
        );
        $descriptors = new PreparedTransferRepository($this->directory);
        $descriptors->save($prepared);
        $journal = new TransferJournalRepository($descriptors, $database);
        $receipt = new TransferReceipt(
            $prepared->runId, 'product', 'shop-alpha:product:41', 1, str_repeat('b', 64),
            'created', ['primary' => 901], null, str_repeat('c', 64), 1,
            '2026-08-10T12:00:00Z', '2026-08-10T12:00:01Z',
        );

        $this->expectExceptionMessage('transfer_record_rollback_requires_active_transaction');
        $journal->markRecordRolledBack($receipt);
    }

    public function testReverseRollbackKeepsASharedTaxonomyMapUntilItsOwningProductIsRolledBack(): void
    {
        $database = new ExecutionJournalDatabase();
        $GLOBALS['wpdb'] = $database;
        $prepared = new PreparedTransfer(
            'run-shared-map-22', '/srv/private/package', str_repeat('1', 64),
            new TargetStateFingerprint(...array_map(static fn (string $digit): string => str_repeat($digit, 64), ['1', '2', '3', '4', '5', '6', '7'])),
            'rehearsal', [], false, '2026-08-10T12:00:00Z', 'shop-alpha',
        );
        $descriptors = new PreparedTransferRepository($this->directory);
        $descriptors->save($prepared);
        $journal = new TransferJournalRepository($descriptors, $database);
        $journal->start($prepared);
        $taxonomy = 'shop-alpha:taxonomy_term:10:product-cat';
        $first = new TransferReceipt(
            $prepared->runId, 'product', 'shop-alpha:product:41', 1, str_repeat('a', 64),
            'created', ['primary' => 901, 'shop-alpha:product:41' => 901, $taxonomy => 501],
            null, str_repeat('b', 64), 1, '2026-08-10T12:00:00Z', '2026-08-10T12:00:01Z',
        );
        $second = new TransferReceipt(
            $prepared->runId, 'product', 'shop-alpha:product:42', 1, str_repeat('c', 64),
            'created', ['primary' => 902, 'shop-alpha:product:42' => 902, $taxonomy => 501],
            null, str_repeat('d', 64), 2, '2026-08-10T12:00:02Z', '2026-08-10T12:00:03Z',
        );
        DatabaseTransaction::begin();
        $journal->commitReceipt($first);
        $journal->commitReceipt($second);
        DatabaseTransaction::commit();
        foreach ([
            'shop-alpha:product:41' => [901, $first->afterFingerprint],
            $taxonomy => [501, $first->afterFingerprint],
            'shop-alpha:product:42' => [902, $second->afterFingerprint],
        ] as $canonical => [$targetId, $fingerprint]) {
            $identity = SourceIdentity::fromCanonical($canonical);
            $database->maps[$canonical] = [
                'source_key' => $identity->sourceKey, 'entity_type' => $identity->entityType,
                'wc_id' => $identity->sourceId, 'fc_id' => $targetId,
                'migration_id' => $prepared->runId, 'is_simulated' => 0,
                'target_fingerprint' => $fingerprint, 'record_state' => 'reconciled',
            ];
        }

        DatabaseTransaction::begin();
        $journal->markRecordRolledBack($second);
        self::assertSame('reconciled', $database->maps[$taxonomy]['record_state']);
        $journal->markRecordRolledBack($first);
        DatabaseTransaction::commit();

        self::assertSame(['rolled_back'], array_values(array_unique(array_column($database->maps, 'record_state'))));
    }
}

final class ExecutionJournalDatabase extends \wpdb
{
    /** @var array<string, array<string, mixed>> */
    public array $runs = [];
    /** @var array<string, array<string, mixed>> */
    public array $records = [];
    /** @var array<string, array<string, mixed>> */
    public array $outbox = [];
    /** @var array<string, array<string, mixed>> */
    public array $maps = [];
    /** @var array<string, array<string, mixed>> */
    public array $claims = [];

    public function insert(string $table, array $data, ?array $format = null): int|false
    {
        if (str_ends_with($table, 'cartshift_transfer_runs')) {
            if (isset($this->runs[$data['run_id']])) return false;
            $this->runs[$data['run_id']] = $data;
            return 1;
        }
        $key = $data['run_id'] . '|' . $data['record_kind'] . '|' . $data['source_identity'] . '|' . $data['generation'];
        if (str_ends_with($table, 'cartshift_transfer_records')) {
            if (isset($this->records[$key])) return false;
            $this->records[$key] = $data;
            return 1;
        }
        if (str_ends_with($table, 'cartshift_transfer_outbox')) {
            if (isset($this->outbox[$key])) return false;
            $data['id'] = count($this->outbox) + 1;
            $this->outbox[$key] = $data;
            return 1;
        }
        return parent::insert($table, $data, $format);
    }

    public function update(string $table, array $data, array $where, ?array $format = null, ?array $whereFormat = null): int|false
    {
        $rows =& $this->rowsFor($table);
        foreach ($rows as &$row) {
            if ($this->matches($row, $where)) {
                $row = $data + $row;
                return 1;
            }
        }
        return 0;
    }

    public function get_results(string $query, string $output = OBJECT): array
    {
        if (str_contains($query, 'cartshift_transfer_runs')) {
            return $this->objects(array_values(array_filter($this->runs, fn (array $row): bool => str_contains($query, "'" . $row['run_id'] . "'"))));
        }
        if (str_contains($query, 'cartshift_transfer_outbox')) {
            $rows = array_values(array_filter($this->outbox, function (array $row) use ($query): bool {
                if (!str_contains($query, "'" . $row['run_id'] . "'")) return false;
                if (str_contains($query, 'exported_at IS NULL') && $row['exported_at'] !== null) return false;
                if (str_contains($query, "source_identity = '") && !str_contains($query, "'" . $row['source_identity'] . "'")) return false;
                return true;
            }));
            usort($rows, static fn (array $a, array $b): int => ((int) $a['id']) <=> ((int) $b['id']));
            return $this->objects($rows);
        }
        if (str_contains($query, 'cartshift_id_map')) {
            $rows = array_values(array_filter($this->maps, static function (array $row) use ($query): bool {
                return str_contains($query, "'" . $row['source_key'] . "'")
                    && str_contains($query, "'" . $row['entity_type'] . "'")
                    && str_contains($query, "'" . $row['wc_id'] . "'")
                    && str_contains($query, (string) $row['fc_id'])
                    && str_contains($query, "'" . $row['migration_id'] . "'");
            }));
            return $this->objects(array_slice($rows, 0, 2));
        }
        if (str_contains($query, 'cartshift_transfer_records') && str_contains($query, 'SELECT after_hash')) {
            $rows = array_values(array_filter($this->records, static function (array $row) use ($query): bool {
                return ($row['state'] ?? null) === 'successful'
                    && str_contains($query, "'" . $row['run_id'] . "'")
                    && str_contains($query, "'" . $row['after_hash'] . "'");
            }));
            return $this->objects(array_slice($rows, 0, 2));
        }
        return [];
    }

    /** @return array<string, array<string, mixed>> */
    private function &rowsFor(string $table): array
    {
        if (str_ends_with($table, 'cartshift_transfer_runs')) return $this->runs;
        if (str_ends_with($table, 'cartshift_transfer_records')) return $this->records;
        if (str_ends_with($table, 'cartshift_id_map')) return $this->maps;
        if (str_ends_with($table, 'cartshift_target_claims')) return $this->claims;
        return $this->outbox;
    }

    /** @param array<string, mixed> $row @param array<string, mixed> $where */
    private function matches(array $row, array $where): bool
    {
        foreach ($where as $key => $value) if (($row[$key] ?? null) !== $value) return false;
        return true;
    }

    /** @param list<array<string, mixed>> $rows @return list<object> */
    private function objects(array $rows): array
    {
        return array_map(static fn (array $row): object => (object) $row, $rows);
    }
}

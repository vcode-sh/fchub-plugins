<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\Domain\Transfer\Execution;

use CartShift\Domain\Transfer\Execution\LoadedTargetTransferPipeline;
use CartShift\Domain\Transfer\Execution\PreparedTransfer;
use CartShift\Domain\Transfer\Execution\PreparedTransferRepository;
use CartShift\Domain\Transfer\Execution\TargetStateFingerprint;
use CartShift\Domain\Transfer\Execution\TransferJournalRepository;
use CartShift\Domain\Transfer\Execution\TransferReceipt;
use CartShift\Domain\Transfer\Execution\TransferRunState;
use CartShift\Domain\Transfer\Subscription\SubscriptionCutoverEvidence;
use CartShift\Domain\Transfer\Subscription\SubscriptionCutoverEvidenceRepository;
use CartShift\Support\DatabaseTransaction;
use CartShift\Tests\Unit\PluginTestCase;

final class LoadedTargetTransferPipelineRollbackTest extends PluginTestCase
{
    private string $workspace;
    private object $originalDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->workspace = sys_get_temp_dir() . '/cartshift-loaded-rollback-' . bin2hex(random_bytes(8));
        mkdir($this->workspace, 0700);
        $this->originalDatabase = $GLOBALS['wpdb'];
    }

    protected function tearDown(): void
    {
        foreach (glob($this->workspace . '/*') ?: [] as $path) {
            unlink($path);
        }
        rmdir($this->workspace);
        $GLOBALS['wpdb'] = $this->originalDatabase;
        DatabaseTransaction::reset();
        parent::tearDown();
    }

    public function testPreReleasePromotedGuidedFailureBecomesRollbackableByTheLoadedPipeline(): void
    {
        [$prepared, $journal] = $this->promotedSubscriptionRun();
        (new SubscriptionCutoverEvidenceRepository($this->workspace))->create($this->cutoverEvidence(
            SubscriptionCutoverEvidence::PREPARED,
            'pending',
        ));

        LoadedTargetTransferPipeline::create()->prepareGuidedRollback($prepared, $journal, $this->workspace);

        self::assertSame(TransferRunState::Failed, $journal->state($prepared->runId));
        self::assertSame(TransferRunState::Promoted, $journal->failedFrom($prepared->runId));
    }

    public function testLoadedPipelineLeavesTheForwardOnlyPromotedStateUntouchedAfterReleaseStarts(): void
    {
        [$prepared, $journal] = $this->promotedSubscriptionRun();
        (new SubscriptionCutoverEvidenceRepository($this->workspace))->create($this->cutoverEvidence(
            SubscriptionCutoverEvidence::SOURCE_RELEASED,
            'released',
        ));

        try {
            LoadedTargetTransferPipeline::create()->prepareGuidedRollback($prepared, $journal, $this->workspace);
            self::fail('A post-release subscription run was made rollbackable.');
        } catch (\RuntimeException $exception) {
            self::assertStringStartsWith('rollback_blocked_after_subscription_source_release:', $exception->getMessage());
        }

        self::assertSame(TransferRunState::Promoted, $journal->state($prepared->runId));
    }

    /** @return array{PreparedTransfer,TransferJournalRepository} */
    private function promotedSubscriptionRun(): array
    {
        $database = new LoadedRollbackJournalDatabase();
        $GLOBALS['wpdb'] = $database;
        $prepared = new PreparedTransfer(
            'run-loaded-rollback-22',
            '/srv/private/package',
            str_repeat('1', 64),
            new TargetStateFingerprint(...array_map(
                static fn (string $digit): string => str_repeat($digit, 64),
                ['1', '2', '3', '4', '5', '6', '7'],
            )),
            'guided',
            [],
            false,
            '2026-08-13T10:00:00Z',
            'shop-alpha',
        );
        $descriptors = new PreparedTransferRepository($this->workspace);
        $descriptors->save($prepared);
        $journal = new TransferJournalRepository($descriptors, $database);
        $journal->start($prepared);
        foreach ([
            [TransferRunState::Prepared, TransferRunState::Staging],
            [TransferRunState::Staging, TransferRunState::Staged],
            [TransferRunState::Staged, TransferRunState::Reconciling],
            [TransferRunState::Reconciling, TransferRunState::Reconciled],
            [TransferRunState::Reconciled, TransferRunState::Promoted],
        ] as [$from, $to]) {
            $journal->transition($prepared->runId, $from, $to);
        }
        DatabaseTransaction::begin();
        $journal->commitReceipt(new TransferReceipt(
            $prepared->runId,
            'subscription',
            'shop-alpha:subscription:31',
            1,
            str_repeat('8', 64),
            'created',
            ['primary' => 9031, 'shop-alpha:subscription:31' => 9031],
            null,
            str_repeat('9', 64),
            1,
            '2026-08-13T10:00:00Z',
            '2026-08-13T10:00:01Z',
        ));
        DatabaseTransaction::commit();

        return [$prepared, $journal];
    }

    private function cutoverEvidence(string $state, string $releaseState): SubscriptionCutoverEvidence
    {
        $entry = [
            'source_identity' => 'shop-alpha:subscription:31',
            'source_fingerprint' => str_repeat('8', 64),
            'target_id' => 9031,
            'staged_target_fingerprint' => str_repeat('9', 64),
            'source_release_required' => true,
            'intended_status' => 'active',
            'release_state' => $releaseState,
            'activation_state' => 'paused',
        ];
        if ($releaseState === 'released') {
            $entry += [
                'pre_renewal_fingerprint' => str_repeat('a', 64),
                'pre_release_comparison_fingerprint' => str_repeat('b', 64),
                'previous_requires_manual_renewal' => false,
                'post_source_fingerprint' => str_repeat('c', 64),
                'post_renewal_fingerprint' => str_repeat('a', 64),
            ];
        }

        return new SubscriptionCutoverEvidence(
            'run-loaded-rollback-22',
            'shop-alpha',
            str_repeat('1', 64),
            str_repeat('2', 64),
            str_repeat('3', 64),
            str_repeat('4', 64),
            str_repeat('5', 64),
            'guided',
            $state,
            [$entry],
            '2026-08-13T10:00:02Z',
        );
    }
}

final class LoadedRollbackJournalDatabase extends \wpdb
{
    /** @var array<string,array<string,mixed>> */
    public array $runs = [];
    /** @var array<string,array<string,mixed>> */
    public array $records = [];
    /** @var array<string,array<string,mixed>> */
    public array $outbox = [];

    public function insert(string $table, array $data, ?array $format = null): int|false
    {
        if (str_ends_with($table, 'cartshift_transfer_runs')) {
            if (isset($this->runs[$data['run_id']])) return false;
            $this->runs[$data['run_id']] = $data;
            return 1;
        }
        $key = $data['run_id'] . '|' . $data['record_kind'] . '|' . $data['source_identity'] . '|' . $data['generation'];
        if (str_ends_with($table, 'cartshift_transfer_records')) {
            $this->records[$key] = $data;
            return 1;
        }
        if (str_ends_with($table, 'cartshift_transfer_outbox')) {
            $data['id'] = count($this->outbox) + 1;
            $this->outbox[$key] = $data;
            return 1;
        }
        return false;
    }

    public function update(string $table, array $data, array $where, ?array $format = null, ?array $whereFormat = null): int|false
    {
        if (!str_ends_with($table, 'cartshift_transfer_runs')) return 0;
        foreach ($this->runs as &$row) {
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
            return $this->objects(array_values(array_filter(
                $this->runs,
                static fn (array $row): bool => str_contains($query, "'" . $row['run_id'] . "'"),
            )));
        }
        if (str_contains($query, 'cartshift_transfer_outbox')) {
            return $this->objects(array_values(array_filter(
                $this->outbox,
                static fn (array $row): bool => str_contains($query, "'" . $row['run_id'] . "'"),
            )));
        }
        return [];
    }

    /** @param array<string,mixed> $row @param array<string,mixed> $where */
    private function matches(array $row, array $where): bool
    {
        foreach ($where as $key => $value) {
            if (($row[$key] ?? null) !== $value) return false;
        }
        return true;
    }

    /** @param list<array<string,mixed>> $rows @return list<object> */
    private function objects(array $rows): array
    {
        return array_map(static fn (array $row): object => (object) $row, $rows);
    }
}

<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\Domain\Transfer\SameSite;

use CartShift\Domain\Transfer\SameSite\GuidedRunState;
use CartShift\Domain\Transfer\SameSite\GuidedRunStateRepository;
use CartShift\Support\CanonicalJson;
use CartShift\Tests\Unit\PluginTestCase;

final class GuidedRunStateRepositoryTest extends PluginTestCase
{
    private string $workspace;

    protected function setUp(): void
    {
        parent::setUp();
        $this->workspace = sys_get_temp_dir() . '/cartshift-guided-run-' . bin2hex(random_bytes(8));
        mkdir($this->workspace, 0700);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->workspace . '/*') ?: [] as $path) {
            unlink($path);
        }
        rmdir($this->workspace);
        parent::tearDown();
    }

    public function testACompletedPrepareIsReadBackWithTheExactDescriptor(): void
    {
        $repository = new GuidedRunStateRepository($this->workspace, 'site-0123456789abcdef');
        $state = GuidedRunState::start(
            'site-0123456789abcdef',
            'wp-user:7',
            '2026-08-12T12:00:00Z',
        )
            ->afterStep('audit', ['selection_fingerprint' => str_repeat('a', 64)], 12)
            ->afterStep('export', ['path' => $this->workspace . '/package'], 12)
            ->afterStep('prepare', ['descriptor' => 'tr-491f7178d619ae327139ae2e'], 12);

        $repository->transaction(static fn (?GuidedRunState $current): GuidedRunState => $current ?? $state);

        $loaded = $repository->get();

        self::assertNotNull($loaded);
        self::assertSame('tr-491f7178d619ae327139ae2e', $loaded->evidence->descriptor);
        self::assertSame('2026-08-12T12:00:00Z', $loaded->decidedAtUtc);
        self::assertSame(3, $loaded->nextStep);
        self::assertSame(0600, fileperms($repository->path()) & 0777);
    }

    public function testATransactionReadsTheLatestStateBeforeAdvancing(): void
    {
        $repository = new GuidedRunStateRepository($this->workspace, 'site-0123456789abcdef');
        $initial = GuidedRunState::start('site-0123456789abcdef', 'wp-user:7', '2026-08-12T12:00:00Z');
        $repository->transaction(static fn (): GuidedRunState => $initial);

        $repository->transaction(static function (?GuidedRunState $current): GuidedRunState {
            self::assertSame(0, $current?->nextStep);

            return $current->afterStep('compatibility', ['ready' => true], 12);
        });

        self::assertSame(1, $repository->get()?->nextStep);
    }

    public function testStateForAnotherSourceCannotBeWrittenIntoThisWorkspaceSlot(): void
    {
        $repository = new GuidedRunStateRepository($this->workspace, 'site-0123456789abcdef');

        $this->expectExceptionMessage('guided_run_source_mismatch');
        $repository->transaction(static fn (): GuidedRunState => GuidedRunState::start(
            'site-fedcba9876543210',
            'wp-user:7',
            '2026-08-12T12:00:00Z',
        ));
    }

    public function testRollbackIntentIsDurableBeforeTargetMutationBegins(): void
    {
        $repository = new GuidedRunStateRepository($this->workspace, 'site-0123456789abcdef');
        $failed = GuidedRunState::start('site-0123456789abcdef', 'wp-user:7', '2026-08-12T12:00:00Z')
            ->afterStep('audit', ['selection_fingerprint' => str_repeat('a', 64)], 12)
            ->afterStep('export', ['path' => $this->workspace . '/package'], 12)
            ->afterStep('prepare', ['descriptor' => 'tr-491f7178d619ae327139ae2e'], 12)
            ->afterFailure('stage', new \RuntimeException('stage_failed'));
        $sealed = [
            'rollback_plan' => $this->workspace . '/rollback-plan.json',
            'rollback_plan_fingerprint' => str_repeat('b', 64),
            'lease_recovery' => str_repeat('c', 64),
            'deletion_count' => 2,
        ];

        $repository->transaction(static fn (): GuidedRunState => $failed->beginRollback($sealed));
        $loaded = $repository->get();

        self::assertSame(GuidedRunState::ROLLING_BACK, $loaded?->phase);
        self::assertEquals($sealed, $loaded?->lastResult);
        self::assertTrue($loaded?->isTerminal());
    }

    public function testRunModeAndMigrationExceptionsSurviveARepositoryRoundTrip(): void
    {
        $repository = new GuidedRunStateRepository($this->workspace, 'site-0123456789abcdef');
        $exception = [
            'kind' => 'shared_parent_stock',
            'product_name' => 'Harness',
            'variation_name' => 'Large',
            'source_quantity' => 11,
        ];
        $state = GuidedRunState::start(
            'site-0123456789abcdef',
            'wp-user:7',
            '2026-08-12T12:00:00Z',
            true,
        )->afterFailure(
            'validate-package',
            new \CartShift\Domain\Transfer\SameSite\GuidedRunFailure(
                'guided_completed_rehearsal_rollback_unavailable',
                ['migration_exceptions' => [$exception]],
            ),
        );

        $repository->transaction(static fn (): GuidedRunState => $state);
        $loaded = $repository->get();

        self::assertTrue($loaded?->includesSubscriptions);
        self::assertEquals([$exception], $loaded?->migrationExceptions);
    }

    public function testACompletedSubscriptionMigrationKeepsAllFifteenSteps(): void
    {
        $repository = new GuidedRunStateRepository($this->workspace, 'site-0123456789abcdef');
        $state = GuidedRunState::fromArray([
            'decided_at_utc' => '2026-08-12T12:00:00Z',
            'evidence' => [
                'descriptor' => 'descriptor-0001',
                'package_path' => $this->workspace . '/package',
                'selection_fingerprint' => str_repeat('a', 64),
            ],
            'failure' => null,
            'includes_subscriptions' => true,
            'last_result' => ['state' => 'completed'],
            'last_verb' => 'complete',
            'migration_exceptions' => [],
            'next_step' => 15,
            'operator' => 'wp-user:7',
            'phase' => GuidedRunState::COMPLETED,
            'source_key' => 'site-0123456789abcdef',
        ]);

        $repository->transaction(static fn (): GuidedRunState => $state);

        self::assertSame(15, $repository->get()?->nextStep);
        self::assertTrue($repository->get()?->includesSubscriptions);
    }

    public function testLegacyCanonicalStateRemainsReadableAndUpgradesOnTheNextWrite(): void
    {
        $repository = new GuidedRunStateRepository($this->workspace, 'site-0123456789abcdef');
        $legacy = GuidedRunState::start(
            'site-0123456789abcdef',
            'wp-user:7',
            '2026-08-12T12:00:00Z',
        )->toArray();
        unset($legacy['includes_subscriptions'], $legacy['migration_exceptions']);
        file_put_contents($repository->path(), CanonicalJson::encode($legacy) . "\n");
        chmod($repository->path(), 0600);

        $loaded = $repository->get();

        self::assertNotNull($loaded);
        self::assertFalse($loaded->includesSubscriptions);
        self::assertSame([], $loaded->migrationExceptions);

        $repository->transaction(static fn (?GuidedRunState $state): GuidedRunState =>
            $state?->afterStep('compatibility', ['ready' => true], 12)
                ?? throw new \LogicException('Legacy state disappeared.'));
        $upgraded = json_decode((string) file_get_contents($repository->path()), true, flags: JSON_THROW_ON_ERROR);

        self::assertArrayHasKey('includes_subscriptions', $upgraded);
        self::assertArrayHasKey('migration_exceptions', $upgraded);
    }
}

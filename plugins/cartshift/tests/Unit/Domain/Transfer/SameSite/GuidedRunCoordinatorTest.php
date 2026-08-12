<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\Domain\Transfer\SameSite;

use CartShift\Domain\Transfer\SameSite\GuidedRunCoordinator;
use CartShift\Domain\Transfer\SameSite\GuidedRunPlan;
use CartShift\Domain\Transfer\SameSite\GuidedRunState;
use CartShift\Domain\Transfer\SameSite\GuidedRunStateRepository;
use CartShift\Domain\Transfer\SameSite\GuidedStep;
use CartShift\Tests\Unit\PluginTestCase;

final class GuidedRunCoordinatorTest extends PluginTestCase
{
    private const string SOURCE_KEY = 'site-0123456789abcdef';

    private string $workspace;

    protected function setUp(): void
    {
        parent::setUp();
        $this->workspace = sys_get_temp_dir() . '/cartshift-guided-coordinator-' . bin2hex(random_bytes(8));
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

    public function testEachRequestAdvancesOneDurableStepAndResumeNeverRepeatsCompletedWork(): void
    {
        $calls = [];
        $workspace = $this->workspace;
        $coordinator = $this->coordinator(static function (GuidedStep $step) use (&$calls, $workspace): array {
            $calls[] = $step->verb;

            return match ($step->verb) {
                'compatibility' => ['ready' => true],
                'audit' => ['selection_fingerprint' => str_repeat('a', 64)],
                'propose-decisions' => [
                    'status' => 'owner_review_required',
                    'blockers' => [],
                    'proposal_decisions' => [['identity' => 'site-0123456789abcdef:order:1']],
                    'decision_set' => ['decisions' => []],
                ],
                'export' => ['path' => $workspace . '/package'],
                'validate-package' => ['status' => 'validated'],
                'prepare' => ['descriptor' => 'tr-491f7178d619ae327139ae2e'],
                default => ['state' => $step->verb],
            };
        });

        $first = $coordinator->start();
        self::assertSame(GuidedRunState::RUNNING, $first->phase);
        self::assertSame(1, $first->nextStep);
        self::assertSame(['compatibility'], $calls);

        $coordinator->start();
        $coordinator->start();
        $review = $coordinator->start();

        self::assertSame(GuidedRunState::AWAITING_DECISIONS, $review->phase);
        self::assertSame(3, $review->nextStep);
        self::assertSame(['compatibility', 'compatibility', 'audit', 'propose-decisions'], $calls);

        $coordinator->recordDecisionAcceptance(['accepted' => 0, 'fingerprint' => str_repeat('b', 64)]);
        do {
            $finished = $coordinator->start();
        } while ($finished->phase === GuidedRunState::RUNNING);

        self::assertNull($finished->failure);
        self::assertSame(GuidedRunState::COMPLETED, $finished->phase);
        self::assertSame(12, $finished->nextStep);
        self::assertSame(2, count(array_filter($calls, static fn (string $verb): bool => $verb === 'compatibility')));
        self::assertSame(1, count(array_filter($calls, static fn (string $verb): bool => $verb === 'prepare')));
        self::assertSame('tr-491f7178d619ae327139ae2e', $finished->evidence->descriptor);
    }

    public function testAFailedStepWithTargetEvidenceIsPersistedAndNeverBlindlyRetried(): void
    {
        $calls = 0;
        $workspace = $this->workspace;
        $coordinator = $this->coordinator(static function (GuidedStep $step) use (&$calls, $workspace): array {
            ++$calls;

            return match ($step->verb) {
                'compatibility' => ['ready' => true],
                'audit' => ['selection_fingerprint' => str_repeat('a', 64)],
                'propose-decisions' => [
                    'status' => 'owner_review_required',
                    'blockers' => [],
                    'proposal_decisions' => [['identity' => 'site-0123456789abcdef:order:1']],
                    'decision_set' => ['decisions' => []],
                ],
                'export' => ['path' => $workspace . '/package'],
                'validate-package' => ['status' => 'validated'],
                'prepare' => ['descriptor' => 'tr-491f7178d619ae327139ae2e'],
                default => throw new \RuntimeException('target_product_assessment_blocked'),
            };
        });

        for ($step = 0; $step < 4; ++$step) {
            $coordinator->start();
        }
        $coordinator->recordDecisionAcceptance(['accepted' => 0, 'fingerprint' => str_repeat('b', 64)]);
        do {
            $failed = $coordinator->start();
        } while ($failed->phase === GuidedRunState::RUNNING);
        $callsBeforeRetry = $calls;
        $same = $coordinator->start();

        self::assertSame(GuidedRunState::FAILED, $failed->phase);
        self::assertSame('target_product_assessment_blocked', $failed->failure);
        self::assertSame($failed->toArray(), $same->toArray());
        self::assertSame($callsBeforeRetry, $calls);
    }

    public function testAFailedStepBeforeTargetEvidenceCanStartAFreshRun(): void
    {
        $calls = 0;
        $coordinator = $this->coordinator(static function () use (&$calls): array {
            ++$calls;
            if ($calls === 1) {
                throw new \RuntimeException('temporary_source_read_failed');
            }

            return ['ready' => true];
        });

        $failed = $coordinator->start();
        $restarted = $coordinator->start();

        self::assertSame(GuidedRunState::FAILED, $failed->phase);
        self::assertNull($failed->evidence->descriptor);
        self::assertSame(GuidedRunState::RUNNING, $restarted->phase);
        self::assertSame(1, $restarted->nextStep);
        self::assertSame(2, $calls);
    }

    public function testAPermanentCapabilityStopCannotBeRestartedByDirectRequest(): void
    {
        $calls = 0;
        $coordinator = $this->coordinator(static function () use (&$calls): array {
            ++$calls;
            throw new \RuntimeException('guided_completed_rehearsal_rollback_unavailable');
        });

        $failed = $coordinator->start();
        $same = $coordinator->start();

        self::assertSame(GuidedRunState::FAILED, $failed->phase);
        self::assertFalse($failed->canRestart());
        self::assertSame($failed->toArray(), $same->toArray());
        self::assertSame(1, $calls);
    }

    public function testAnUnreadyCompatibilityReportStopsBeforeTheNextStep(): void
    {
        $calls = 0;
        $coordinator = $this->coordinator(static function () use (&$calls): array {
            ++$calls;

            return ['ready' => false, 'errors' => [['code' => 'schema_upgrade_required']]];
        });

        $failed = $coordinator->start();

        self::assertSame(GuidedRunState::FAILED, $failed->phase);
        self::assertSame('guided_compatibility_blocked', $failed->failure);
        self::assertSame(0, $failed->nextStep);
        self::assertSame(1, $calls);
    }

    public function testACancelledReviewCanStartANewRunInsteadOfBecomingAPermanentDeadEnd(): void
    {
        $calls = [];
        $coordinator = $this->coordinator(static function (GuidedStep $step) use (&$calls): array {
            $calls[] = $step->verb;

            return match ($step->verb) {
                'compatibility' => ['ready' => true],
                'audit' => ['selection_fingerprint' => str_repeat('a', 64)],
                default => [
                    'status' => 'owner_review_required',
                    'blockers' => [],
                    'proposal_decisions' => [['identity' => 'site-0123456789abcdef:order:1']],
                    'decision_set' => ['decisions' => []],
                ],
            };
        });
        for ($step = 0; $step < 4; ++$step) {
            $coordinator->start();
        }
        $coordinator->cancel();

        for ($step = 0; $step < 4; ++$step) {
            $restarted = $coordinator->start();
        }

        self::assertSame(GuidedRunState::AWAITING_DECISIONS, $restarted->phase);
        self::assertSame(3, $restarted->nextStep);
        self::assertSame([
            'compatibility', 'compatibility', 'audit', 'propose-decisions',
            'compatibility', 'compatibility', 'audit', 'propose-decisions',
        ], $calls);
    }

    public function testAFailedRunCannotBeCancelledPastItsRollbackEvidence(): void
    {
        $coordinator = $this->coordinator(static fn (): array => throw new \RuntimeException('stage_failed'));
        $coordinator->start();

        $this->expectExceptionMessage('guided_run_cannot_cancel');
        $coordinator->cancel();
    }

    public function testNoNewDecisionsAdvanceWithoutAnEmptyOwnerReview(): void
    {
        $coordinator = $this->coordinator(static fn (GuidedStep $step): array => match ($step->verb) {
            'compatibility' => ['ready' => true],
            'audit' => ['selection_fingerprint' => str_repeat('a', 64)],
            default => [
                'status' => 'owner_review_required',
                'blockers' => [],
                'proposal_decisions' => [],
                'decision_set' => ['decisions' => []],
            ],
        });

        for ($step = 0; $step < 4; ++$step) {
            $state = $coordinator->start();
        }

        self::assertSame(GuidedRunState::RUNNING, $state->phase);
        self::assertSame(4, $state->nextStep);
    }

    private function coordinator(\Closure $runStep): GuidedRunCoordinator
    {
        $repository = new GuidedRunStateRepository($this->workspace, self::SOURCE_KEY);
        $workspace = $this->workspace;

        return new GuidedRunCoordinator(
            $repository,
            static fn (GuidedRunState $state): GuidedRunPlan => GuidedRunPlan::rehearsal(
                sourceKey: $state->sourceKey,
                workspace: $workspace,
                operator: $state->operator,
                decidedAtUtc: $state->decidedAtUtc,
                evidence: $state->evidence,
                includesSubscriptions: false,
            ),
            $runStep,
            static fn (): GuidedRunState => GuidedRunState::start(
                self::SOURCE_KEY,
                'wp-user:7',
                '2026-08-12T12:00:00Z',
            ),
        );
    }
}

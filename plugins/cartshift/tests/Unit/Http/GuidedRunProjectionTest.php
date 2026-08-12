<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\Http;

use CartShift\Domain\Transfer\SameSite\GuidedRunFailure;
use CartShift\Domain\Transfer\SameSite\GuidedRunState;
use CartShift\Http\GuidedRunProjection;
use CartShift\Tests\Unit\PluginTestCase;

final class GuidedRunProjectionTest extends PluginTestCase
{
    public function testPlanReturnsTheExactFriendlyTwelveStepListWithoutLoadingRunState(): void
    {
        $payload = (new GuidedRunProjection())->plan(
            'site-0123456789abcdef',
            subscriptionsActive: false,
            loadRun: false,
        );

        self::assertSame(['plan', 'plan_blocked', 'plan_message', 'run'], array_keys($payload));
        self::assertNull($payload['plan_blocked']);
        self::assertNull($payload['plan_message']);
        self::assertNull($payload['run']);
        self::assertTrue(array_is_list($payload['plan']));
        self::assertSame([
            'Check compatibility',
            'Check compatibility',
            'Inspect source records',
            'Review migration decisions',
            'Create the private rehearsal package',
            'Validate the rehearsal package',
            'Prepare target records',
            'Stage target records',
            'Verify staged records',
            'Promote staged records',
            'Activate the FluentCart catalogue',
            'Finish the rehearsal',
        ], array_column($payload['plan'], 'label'));
        foreach ($payload['plan'] as $step) {
            self::assertSame(['label', 'completed'], array_keys($step));
            self::assertFalse($step['completed']);
        }
    }

    public function testRunPresentsFailureAndStockExceptionsWithoutInternalEvidence(): void
    {
        $state = GuidedRunState::start(
            'site-0123456789abcdef',
            'wp-user:1',
            '2026-08-12T12:00:00Z',
        )->afterFailure('prepare', new GuidedRunFailure(
            'guided_completed_rehearsal_rollback_unavailable',
            ['migration_exceptions' => [[
                'kind' => 'shared_parent_stock',
                'product_name' => 'Trail harness',
                'variation_name' => 'Harness size: Large',
                'sku' => 'HARNESS-L',
                'source_owner' => 'site-0123456789abcdef:product:42',
                'source_quantity' => 11,
            ]]],
        ));

        $payload = (new GuidedRunProjection())->run($state);

        self::assertSame([
            'phase',
            'completed_steps',
            'total_steps',
            'last_step',
            'failure',
            'review',
            'migration_exceptions',
            'rollback',
        ], array_keys($payload));
        self::assertSame(GuidedRunState::FAILED, $payload['phase']);
        self::assertSame('Prepare target records', $payload['last_step']);
        self::assertSame(
            'This CartShift core cannot yet roll back a completed rehearsal, so the guided run stopped '
                . 'before preparing any target records.',
            $payload['failure']['message'],
        );
        self::assertFalse($payload['failure']['can_restart']);
        self::assertSame('Trail harness', $payload['migration_exceptions'][0]['title']);
        self::assertSame('planned', $payload['migration_exceptions'][0]['target_state']);
        self::assertArrayNotHasKey('kind', $payload['migration_exceptions'][0]);
        self::assertStringNotContainsString(
            'site-0123456789abcdef',
            json_encode($payload, JSON_THROW_ON_ERROR),
        );
    }
}

<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\Http;

use CartShift\Domain\Transfer\SameSite\GuidedRunFailure;
use CartShift\Domain\Transfer\SameSite\GuidedRunState;
use CartShift\Http\GuidedRunProjection;
use CartShift\Tests\Unit\PluginTestCase;

final class GuidedRunProjectionTest extends PluginTestCase
{
    public function testPlanReturnsTheExactFriendlyMigrationStepsWithoutLoadingRunState(): void
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
            'Create the private migration package',
            'Validate the migration package',
            'Prepare target records',
            'Stage target records',
            'Verify staged records',
            'Promote staged records',
            'Activate the FluentCart catalogue',
            'Finish the migration',
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
            'temporary_source_read_failed',
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
            'renewal_pause',
            'migration_exceptions',
            'rollback',
        ], array_keys($payload));
        self::assertSame(GuidedRunState::FAILED, $payload['phase']);
        self::assertSame('Prepare target records', $payload['last_step']);
        self::assertSame(
            'The migration stopped before any target records were prepared. You can safely try again.',
            $payload['failure']['message'],
        );
        self::assertTrue($payload['failure']['can_restart']);
        self::assertSame('Trail harness', $payload['migration_exceptions'][0]['title']);
        self::assertSame('planned', $payload['migration_exceptions'][0]['target_state']);
        self::assertArrayNotHasKey('kind', $payload['migration_exceptions'][0]);
        self::assertStringNotContainsString(
            'site-0123456789abcdef',
            json_encode($payload, JSON_THROW_ON_ERROR),
        );
    }

    public function testVerifiedStockFollowUpUsesPastTenseAfterMigration(): void
    {
        $state = GuidedRunState::start(
            'site-0123456789abcdef',
            'wp-user:1',
            '2026-08-12T12:00:00Z',
        )->afterFailure('complete', new GuidedRunFailure('target_failed', [
            'migration_exceptions' => [[
                'kind' => 'shared_parent_stock',
                'product_name' => 'Trail harness',
                'variation_name' => 'Large',
                'source_owner' => 'site-0123456789abcdef:product:42',
                'source_quantity' => 11,
                'target_verified' => true,
            ]],
        ]));

        $report = (new GuidedRunProjection())->run($state)['migration_exceptions'][0];

        self::assertSame('confirmed', $report['target_state']);
        self::assertStringContainsString('CartShift migrated the affected variations', $report['message']);
        self::assertStringNotContainsString('will migrate', $report['message']);
    }

    public function testCompletedMigrationIsPresentedAsFinishedInsteadOfUnsafeRehearsal(): void
    {
        $state = GuidedRunState::fromArray([
            'decided_at_utc' => '2026-08-12T12:00:00Z',
            'evidence' => [
                'descriptor' => 'tr-491f7178d619ae327139ae2e',
                'package_path' => '/srv/private/package',
                'selection_fingerprint' => str_repeat('a', 64),
            ],
            'failure' => null,
            'includes_subscriptions' => false,
            'last_result' => ['state' => 'completed'],
            'last_verb' => 'complete',
            'migration_exceptions' => [],
            'next_step' => 12,
            'operator' => 'wp-user:1',
            'phase' => GuidedRunState::COMPLETED,
            'source_key' => 'site-0123456789abcdef',
        ]);

        $payload = (new GuidedRunProjection())->run($state);

        self::assertSame(GuidedRunState::COMPLETED, $payload['phase']);
        self::assertNull($payload['failure']);
        self::assertSame('Finish the migration', $payload['last_step']);
    }

    public function testSubscriptionMigrationReportsItsThreeAdditionalSteps(): void
    {
        $state = GuidedRunState::start(
            'site-0123456789abcdef',
            'wp-user:1',
            '2026-08-12T12:00:00Z',
            true,
        );

        $payload = (new GuidedRunProjection())->run($state);

        self::assertSame(15, $payload['total_steps']);
    }

    public function testRenewalPauseIsOnePlainOwnerActionWithoutTechnicalEvidence(): void
    {
        $state = GuidedRunState::fromArray([
            'decided_at_utc' => '2026-08-12T12:00:00Z',
            'evidence' => [
                'descriptor' => 'tr-491f7178d619ae327139ae2e',
                'package_path' => '/srv/private/package',
                'selection_fingerprint' => str_repeat('a', 64),
            ],
            'failure' => null,
            'includes_subscriptions' => true,
            'last_result' => ['subscription_release_required' => true],
            'last_verb' => 'prepare-subscription-cutover',
            'migration_exceptions' => [],
            'next_step' => 11,
            'operator' => 'wp-user:1',
            'phase' => 'awaiting_renewal_pause',
            'source_key' => 'site-0123456789abcdef',
        ]);

        $payload = (new GuidedRunProjection())->run($state);

        self::assertSame([
            'title' => 'Pause WooCommerce renewals',
            'message' => 'Pause checkout, subscription changes and scheduled renewal jobs, then continue. CartShift will immediately hand renewal ownership to FluentCart.',
            'action' => 'I have paused renewals — continue',
        ], $payload['renewal_pause']);
        self::assertStringNotContainsString('descriptor', json_encode($payload['renewal_pause'], JSON_THROW_ON_ERROR));
    }

    public function testSubscriptionStepsHaveSpecificMemberFacingLabels(): void
    {
        $labels = [
            'prepare-subscription-cutover' => 'Prepare subscription transfer',
            'release-subscription-source' => 'Stop WooCommerce subscription renewals',
            'activate-subscriptions' => 'Activate FluentCart subscriptions',
        ];

        foreach ($labels as $verb => $label) {
            $state = GuidedRunState::fromArray([
                'decided_at_utc' => '2026-08-12T12:00:00Z',
                'evidence' => [
                    'descriptor' => 'tr-491f7178d619ae327139ae2e',
                    'package_path' => '/srv/private/package',
                    'selection_fingerprint' => str_repeat('a', 64),
                ],
                'failure' => null,
                'includes_subscriptions' => true,
                'last_result' => [],
                'last_verb' => $verb,
                'migration_exceptions' => [],
                'next_step' => 12,
                'operator' => 'wp-user:1',
                'phase' => GuidedRunState::RUNNING,
                'source_key' => 'site-0123456789abcdef',
            ]);

            self::assertSame($label, (new GuidedRunProjection())->run($state)['last_step']);
        }
    }

    public function testSkippedRecordsBecomeAPlainFollowUpReportWithoutSourceIdentity(): void
    {
        $state = GuidedRunState::start(
            'site-0123456789abcdef',
            'wp-user:1',
            '2026-08-12T12:00:00Z',
        )->afterFailure('stage', new GuidedRunFailure('target_failed', [
            'migration_exceptions' => [[
                'kind' => 'skipped_product',
                'title' => 'Store membership',
                'dependent_orders' => 1,
                'dependent_subscriptions' => 2,
                'identity' => 'site-0123456789abcdef:product:10',
            ]],
        ]));

        $report = (new GuidedRunProjection())->run($state)['migration_exceptions'][0];

        self::assertSame('skipped_record', $report['type']);
        self::assertSame('Store membership', $report['title']);
        self::assertStringContainsString('1 related order and 2 related subscriptions', $report['message']);
        self::assertStringNotContainsString('site-0123456789abcdef', json_encode($report, JSON_THROW_ON_ERROR));
    }

    public function testDeliberatelyOmittedHistoryBecomesOnePlainScopeReport(): void
    {
        $state = GuidedRunState::start(
            'site-0123456789abcdef',
            'wp-user:1',
            '2026-08-12T12:00:00Z',
        )->afterFailure('propose-decisions', new GuidedRunFailure('review_failed', [
            'migration_exceptions' => [[
                'kind' => 'source_scope',
                'included_subscriptions' => 13,
                'omitted_subscriptions' => 17,
                'included_registered_customers' => 361,
                'omitted_wordpress_accounts' => 322,
                'guest_order_profiles' => 7,
                'unique_guest_emails' => 2,
                'source_key' => 'site-0123456789abcdef',
            ]],
        ]));

        $report = (new GuidedRunProjection())->run($state)['migration_exceptions'][0];

        self::assertSame('source_scope', $report['type']);
        self::assertSame('What stays in WooCommerce', $report['title']);
        self::assertSame(17, $report['omitted_subscriptions']);
        self::assertSame(322, $report['omitted_wordpress_accounts']);
        self::assertSame(7, $report['guest_order_profiles']);
        self::assertSame(2, $report['unique_guest_emails']);
        self::assertStringNotContainsString('site-0123456789abcdef', json_encode($report, JSON_THROW_ON_ERROR));
    }

    public function testDuplicateSkuChangesBecomeOnePlainProductFollowUp(): void
    {
        $state = GuidedRunState::start(
            'site-0123456789abcdef',
            'wp-user:1',
            '2026-08-12T12:00:00Z',
        )->afterFailure('stage', new GuidedRunFailure('target_failed', [
            'migration_exceptions' => [
                [
                    'kind' => 'duplicate_variation_sku',
                    'product_name' => 'Trail harness',
                    'variation_name' => 'Size: Small',
                    'source_variation' => 'site-0123456789abcdef:product:42:variation:101',
                    'source_sku' => 'HARNESS',
                    'target_sku' => 'CS-11111111111111111111',
                ],
                [
                    'kind' => 'duplicate_variation_sku',
                    'product_name' => 'Trail harness',
                    'variation_name' => 'Size: Large',
                    'source_variation' => 'site-0123456789abcdef:product:42:variation:102',
                    'source_sku' => 'HARNESS',
                    'target_sku' => 'CS-22222222222222222222',
                ],
            ],
        ]));

        $report = (new GuidedRunProjection())->run($state)['migration_exceptions'][0];

        self::assertSame('sku_change', $report['type']);
        self::assertSame('Trail harness', $report['title']);
        self::assertCount(2, $report['variations']);
        self::assertStringContainsString('unique FluentCart SKU', $report['message']);
        self::assertStringNotContainsString('site-0123456789abcdef', json_encode($report, JSON_THROW_ON_ERROR));
    }

    public function testPhysicalOrderFulfilmentBecomesOneSummaryInsteadOfAnOrderByOrderList(): void
    {
        $state = GuidedRunState::start(
            'site-0123456789abcdef',
            'wp-user:1',
            '2026-08-12T12:00:00Z',
        )->afterFailure('stage', new GuidedRunFailure('target_failed', [
            'migration_exceptions' => [
                [
                    'kind' => 'physical_order_fulfilment',
                    'source_order' => 'site-0123456789abcdef:order:116',
                    'projection' => 'delivered',
                    'mixed' => false,
                ],
                [
                    'kind' => 'physical_order_fulfilment',
                    'source_order' => 'site-0123456789abcdef:order:118',
                    'projection' => 'unshipped',
                    'mixed' => true,
                ],
            ],
        ]));

        $report = (new GuidedRunProjection())->run($state)['migration_exceptions'][0];

        self::assertSame('fulfilment_summary', $report['type']);
        self::assertSame(2, $report['order_count']);
        self::assertSame(1, $report['delivered_count']);
        self::assertSame(1, $report['unshipped_count']);
        self::assertSame(1, $report['mixed_count']);
        self::assertStringNotContainsString('Order 116', json_encode($report, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('site-0123456789abcdef', json_encode($report, JSON_THROW_ON_ERROR));
    }

    public function testMissingHistoricalVariationsBecomeOnePlainFollowUp(): void
    {
        $state = GuidedRunState::start(
            'site-0123456789abcdef',
            'wp-user:1',
            '2026-08-12T12:00:00Z',
        )->afterFailure('stage', new GuidedRunFailure('target_failed', [
            'migration_exceptions' => [
                [
                    'kind' => 'historical_order_variation_unlinked',
                    'source_order' => 'site-0123456789abcdef:order:119',
                    'source_line' => 'site-0123456789abcdef:order:119:item:11',
                    'line_name' => 'Historical shirt',
                ],
                [
                    'kind' => 'historical_order_variation_unlinked',
                    'source_order' => 'site-0123456789abcdef:order:119',
                    'source_line' => 'site-0123456789abcdef:order:119:item:12',
                    'line_name' => 'Historical shirt',
                ],
            ],
        ]));

        $report = (new GuidedRunProjection())->run($state)['migration_exceptions'][0];

        self::assertSame('historical_line_summary', $report['type']);
        self::assertSame(2, $report['line_count']);
        self::assertStringContainsString('product history', $report['message']);
        self::assertStringNotContainsString('site-0123456789abcdef', json_encode($report, JSON_THROW_ON_ERROR));
    }
}

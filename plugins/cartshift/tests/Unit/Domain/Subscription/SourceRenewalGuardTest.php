<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\Domain\Subscription;

use CartShift\Domain\Subscription\SourceRenewalGuard;
use CartShift\Tests\Unit\PluginTestCase;

require_once dirname(__DIR__, 3) . '/stubs/WcSourceReleaseDoubles.php';

/**
 * Plan section 11 Phase C, step by step.
 *
 * The invariant the whole file exists for: no source subscription is disabled
 * before its destination row is ready, and nothing is released while the source
 * still holds an open renewal that something could pay. An `is_manual()` re-read
 * is necessary and is not sufficient, and the tests below say why in the source's
 * own terms rather than in ours.
 */
final class SourceRenewalGuardTest extends PluginTestCase
{
    // ──────────────────────────────────────────────
    // Characterisation: what WCS 8.7.1 actually does
    // ──────────────────────────────────────────────

    /**
     * `WC_Subscriptions_Manager::process_renewal()` creates the order without a
     * gateway. So an open order with no payment method is a real state, and the
     * gateway-neutral refusal is not a hypothetical.
     */
    public function testManualProcessRenewalLeavesAnOrderWithNoGateway(): void
    {
        $order = \CartShiftWcsRenewalMechanics::processRenewal(880_777);

        $this->assertSame('', $order->get_payment_method());
        $this->assertFalse($order->is_paid());
        $this->assertGreaterThan(0, (float) $order->get_total());
    }

    /**
     * The normal scheduled-payment wrapper checks `is_manual()`. That is the
     * guard everyone remembers, and it is the reason a naive release stops here.
     */
    public function testTheScheduledPaymentWrapperChecksIsManual(): void
    {
        $order = \CartShiftSourceOrderDouble::openWithGateway(880_778);

        $this->assertTrue(\CartShiftWcsRenewalMechanics::scheduledPaymentWrapperWouldCharge(
            new \CartShiftSourceSubscriptionDouble(manual: false),
            $order,
        ));

        $this->assertFalse(\CartShiftWcsRenewalMechanics::scheduledPaymentWrapperWouldCharge(
            new \CartShiftSourceSubscriptionDouble(manual: true),
            $order,
        ));
    }

    /**
     * The low-level trigger does not. Order present, positive total, non-empty
     * payment method — and it fires, on a subscription that is manual. Retry,
     * admin and early-renewal paths reach it by their own routes.
     *
     * This single assertion is the whole argument for the open-order scan.
     */
    public function testTheLowLevelTriggerIgnoresIsManualEntirely(): void
    {
        $this->assertTrue(\CartShiftWcsRenewalMechanics::triggerGatewayRenewalPaymentHook(
            \CartShiftSourceOrderDouble::openWithGateway(880_779),
        ));

        $this->assertFalse(\CartShiftWcsRenewalMechanics::triggerGatewayRenewalPaymentHook(
            \CartShiftSourceOrderDouble::openWithoutGateway(880_780),
        ));

        $this->assertFalse(\CartShiftWcsRenewalMechanics::triggerGatewayRenewalPaymentHook(null));
    }

    // ──────────────────────────────────────────────
    // The scan
    // ──────────────────────────────────────────────

    public function testACleanHistoryPassesTheScan(): void
    {
        $report = (new SourceRenewalGuard())->inspect($this->subscription([
            'parent'  => [\CartShiftSourceOrderDouble::paid(880_001)],
            'renewal' => [\CartShiftSourceOrderDouble::paid(880_501)],
        ]));

        $this->assertSame([], $report['failures']);
        $this->assertSame([], $report['open']);
    }

    public function testTheScanAsksForRenewalOrdersByTypeThroughThePublicApi(): void
    {
        $subscription = $this->subscription(['renewal' => [\CartShiftSourceOrderDouble::paid(880_501)]]);

        (new SourceRenewalGuard())->inspect($subscription);

        $this->assertContains('get_related_orders:all:renewal', $subscription->calls);
    }

    public function testAnOpenOrderCarryingAGatewayBlocksWithTheGatewayCode(): void
    {
        $report = (new SourceRenewalGuard())->inspect($this->subscription([
            'renewal' => [\CartShiftSourceOrderDouble::openWithGateway(880_502)],
        ]));

        $this->assertSame(
            [SourceRenewalGuard::REASON_OPEN_RENEWAL_GATEWAY],
            $this->codes($report['failures']),
        );
    }

    public function testAnOpenGatewayNeutralOrderBlocksWithTheOrderCode(): void
    {
        $report = (new SourceRenewalGuard())->inspect($this->subscription([
            'renewal' => [\CartShiftSourceOrderDouble::openWithoutGateway(880_503)],
        ]));

        $this->assertSame(
            [SourceRenewalGuard::REASON_OPEN_RENEWAL_ORDER],
            $this->codes($report['failures']),
        );
    }

    public function testATerminalOrderIsNotOpen(): void
    {
        $report = (new SourceRenewalGuard())->inspect($this->subscription([
            'renewal' => [\CartShiftSourceOrderDouble::cancelled(880_504)],
        ]));

        $this->assertSame([], $report['failures']);
    }

    public function testAZeroTotalOrderIsNotOpen(): void
    {
        $report = (new SourceRenewalGuard())->inspect($this->subscription([
            'renewal' => [\CartShiftSourceOrderDouble::zeroTotal(880_505)],
        ]));

        $this->assertSame([], $report['failures']);
    }

    /**
     * `needs_payment()` is recorded and is not the definition. An order that
     * answers false to it and is still unpaid and non-terminal is open.
     */
    public function testNeedsPaymentIsEvidenceRatherThanTheWholeDefinition(): void
    {
        $order = new \CartShiftSourceOrderDouble(
            880_506,
            'pending',
            '29.00',
            paid: false,
            needsPayment: false,
            paymentMethod: 'stripe',
            datePaid: null,
        );

        $report = (new SourceRenewalGuard())->inspect($this->subscription(['renewal' => [$order]]));

        $this->assertSame(
            [SourceRenewalGuard::REASON_OPEN_RENEWAL_GATEWAY],
            $this->codes($report['failures']),
        );
        $this->assertFalse($report['orders'][0]['needs_payment']);
    }

    public function testAPendingRetryBlocksReleaseEvenWhenEveryOrderIsPaid(): void
    {
        $report = (new SourceRenewalGuard())->inspect($this->subscription(
            ['renewal' => [\CartShiftSourceOrderDouble::paid(880_507)]],
            retry: '2099-01-01 00:00:00',
        ));

        $this->assertNotSame([], $report['failures']);
        $this->assertSame('2099-01-01 00:00:00', $report['payment_retry']);
    }

    public function testTheFingerprintMovesWhenAnOrderPaymentMethodChanges(): void
    {
        $one = (new SourceRenewalGuard())->inspect($this->subscription([
            'renewal' => [\CartShiftSourceOrderDouble::paid(880_508)],
        ]));

        $two = (new SourceRenewalGuard())->inspect($this->subscription([
            'renewal' => [\CartShiftSourceOrderDouble::paid(880_508, 'paypal')],
        ]));

        $this->assertNotSame($one['fingerprint'], $two['fingerprint']);
    }

    // ──────────────────────────────────────────────
    // The release
    // ──────────────────────────────────────────────

    public function testReleaseSetsRereadsAndRescansInThatOrder(): void
    {
        $subscription = $this->subscription(['renewal' => [\CartShiftSourceOrderDouble::paid(880_509)]]);

        $result = (new SourceRenewalGuard())->release($subscription);

        $this->assertSame(SourceRenewalGuard::STATE_RELEASED, $result['state']);
        $this->assertSame([], $result['failures']);
        $this->assertFalse($result['previous_requires_manual_renewal']);
        $this->assertTrue($subscription->is_manual());

        $setIndex  = array_search('set_requires_manual_renewal:true', $subscription->calls, true);
        $saveIndex = array_search('save', $subscription->calls, true);

        $this->assertIsInt($setIndex);
        $this->assertIsInt($saveIndex);
        $this->assertGreaterThan($setIndex, $saveIndex);

        // At least one typed scan before the save and one after it.
        $scans = array_keys(array_filter(
            $subscription->calls,
            static fn (string $call): bool => $call === 'get_related_orders:all:renewal',
        ));

        $this->assertGreaterThanOrEqual(2, count($scans));
        $this->assertLessThan($saveIndex, $scans[0]);
        $this->assertGreaterThan($saveIndex, $scans[count($scans) - 1]);
    }

    /**
     * WCS makes `is_manual()` true on a duplicate/staging site even when the
     * persisted renewal flag is false. That runtime safety net disappears when
     * the source returns to production, so it must never be mistaken for an
     * already-released subscription.
     */
    public function testEffectiveManualModeDoesNotReplaceThePersistedRenewalFlag(): void
    {
        $subscription = new \CartShiftSourceSubscriptionDouble(
            related: ['renewal' => [\CartShiftSourceOrderDouble::paid(880_509)]],
            effectiveManual: true,
        );

        $result = (new SourceRenewalGuard())->release($subscription);

        $this->assertSame(SourceRenewalGuard::STATE_RELEASED, $result['state']);
        $this->assertFalse($result['previous_requires_manual_renewal']);
        $this->assertTrue($result['source_mutated']);
        $this->assertTrue($subscription->get_requires_manual_renewal());
        $this->assertTrue($subscription->is_manual());
    }

    public function testRestorationVerifiesThePersistedFlagWhenRuntimeModeStaysManual(): void
    {
        $subscription = new \CartShiftSourceSubscriptionDouble(
            manual: true,
            related: ['renewal' => [\CartShiftSourceOrderDouble::paid(880_510)]],
            effectiveManual: true,
        );

        $report = (new SourceRenewalGuard())->inspect($subscription);
        $result = (new SourceRenewalGuard())->restore(
            $subscription,
            false,
            (string) $report['fingerprint'],
        );

        $this->assertSame(SourceRenewalGuard::STATE_RESTORED, $result['state']);
        $this->assertFalse($subscription->get_requires_manual_renewal());
        $this->assertTrue($subscription->is_manual(), 'The staging environment still forces effective manual mode.');
    }

    public function testReleaseRefusesBeforeAnyMutationWhenAnOpenOrderExists(): void
    {
        $subscription = $this->subscription([
            'renewal' => [\CartShiftSourceOrderDouble::openWithGateway(880_510)],
        ]);

        $result = (new SourceRenewalGuard())->release($subscription);

        $this->assertSame(SourceRenewalGuard::STATE_BLOCKED, $result['state']);
        $this->assertSame(
            [SourceRenewalGuard::REASON_OPEN_RENEWAL_GATEWAY],
            $this->codes($result['failures']),
        );
        $this->assertNotContains('set_requires_manual_renewal:true', $subscription->calls);
        $this->assertNotContains('save', $subscription->calls);
        $this->assertFalse($subscription->is_manual());
    }

    /**
     * An already-manual source still gets the full scan. A historical flag is
     * not proof that no open billing artefact exists.
     */
    public function testAnAlreadyManualSourceIsStillScanned(): void
    {
        $subscription = $this->subscription(
            ['renewal' => [\CartShiftSourceOrderDouble::openWithGateway(880_511)]],
            manual: true,
        );

        $result = (new SourceRenewalGuard())->release($subscription);

        $this->assertSame(SourceRenewalGuard::STATE_BLOCKED, $result['state']);
        $this->assertContains('get_related_orders:all:renewal', $subscription->calls);
    }

    public function testAnAlreadyManualSourceIsRecordedAsSuchRatherThanReSaved(): void
    {
        $subscription = $this->subscription(
            ['renewal' => [\CartShiftSourceOrderDouble::paid(880_512)]],
            manual: true,
        );

        $result = (new SourceRenewalGuard())->release($subscription);

        $this->assertSame(SourceRenewalGuard::STATE_ALREADY_MANUAL, $result['state']);
        $this->assertTrue($result['previous_requires_manual_renewal']);
        $this->assertSame([], $result['failures']);
    }

    /**
     * The one the whole sequence exists for. A queued source action creates a
     * renewal order between the save and the re-read; the post-scan sees it,
     * the fingerprints differ, and the release STOPS with the source left manual.
     */
    public function testDriftBetweenTheSaveAndTheRereadIsAHardStop(): void
    {
        $subscription = $this->subscription(
            ['renewal' => [\CartShiftSourceOrderDouble::paid(880_513)]],
            onSave: static function (\CartShiftSourceSubscriptionDouble $subscription): void {
                $subscription->addRenewalOrder(\CartShiftSourceOrderDouble::openWithoutGateway(880_514));
            },
        );

        $result = (new SourceRenewalGuard())->release($subscription);

        $this->assertSame(SourceRenewalGuard::STATE_BLOCKED, $result['state']);
        $this->assertContains(SourceRenewalGuard::REASON_FINGERPRINT_CHANGED, $this->codes($result['failures']));
        $this->assertNotSame($result['pre']['fingerprint'], $result['post']['fingerprint']);

        // Left manual. The source is the safe side to be stuck on.
        $this->assertTrue($subscription->is_manual());
    }

    public function testANewRetryScheduledDuringTheSaveIsAlsoDrift(): void
    {
        $subscription = $this->subscription(
            ['renewal' => [\CartShiftSourceOrderDouble::paid(880_515)]],
            onSave: static function (\CartShiftSourceSubscriptionDouble $subscription): void {
                $subscription->scheduleRetry('2099-02-02 00:00:00');
            },
        );

        $result = (new SourceRenewalGuard())->release($subscription);

        $this->assertSame(SourceRenewalGuard::STATE_BLOCKED, $result['state']);
        $this->assertContains(SourceRenewalGuard::REASON_FINGERPRINT_CHANGED, $this->codes($result['failures']));
    }

    /**
     * `is_manual() === true` on re-read is necessary and not sufficient. A
     * gateway-bearing open order that predates the release is still refused,
     * and the manual flag never becomes an excuse for waving it through.
     */
    public function testAPreExistingGatewayBearingOpenOrderIsRefusedEvenWhenTheFlagWouldTake(): void
    {
        $subscription = $this->subscription([
            'renewal' => [
                \CartShiftSourceOrderDouble::paid(880_516),
                \CartShiftSourceOrderDouble::openWithGateway(880_517),
            ],
        ]);

        $result = (new SourceRenewalGuard())->release($subscription);

        $this->assertSame(SourceRenewalGuard::STATE_BLOCKED, $result['state']);
        $this->assertFalse($subscription->is_manual(), 'The flag must not have been set at all.');
        $this->assertSame(
            [SourceRenewalGuard::REASON_OPEN_RENEWAL_GATEWAY],
            $this->codes($result['failures']),
        );
    }

    public function testASaveThatDoesNotTakeIsRefusedRatherThanAssumed(): void
    {
        $subscription = $this->subscription(
            ['renewal' => [\CartShiftSourceOrderDouble::paid(880_518)]],
            saveSucceeds: false,
        );

        $result = (new SourceRenewalGuard())->release($subscription);

        $this->assertSame(SourceRenewalGuard::STATE_BLOCKED, $result['state']);
        $this->assertContains(SourceRenewalGuard::REASON_RELEASE_UNVERIFIED, $this->codes($result['failures']));
    }

    // ──────────────────────────────────────────────
    // Restoration
    // ──────────────────────────────────────────────

    public function testRestorationPutsTheFlagBackWhenNothingMoved(): void
    {
        $subscription = $this->subscription(
            ['renewal' => [\CartShiftSourceOrderDouble::paid(880_519)]],
            manual: true,
        );

        $released = (new SourceRenewalGuard())->release($subscription);

        $result = (new SourceRenewalGuard())->restore($subscription, false, (string) $released['post']['fingerprint']);

        $this->assertSame(SourceRenewalGuard::STATE_RESTORED, $result['state']);
        $this->assertFalse($subscription->is_manual());
    }

    public function testRestorationIsRefusedWhenAPendingManualInvoiceAppeared(): void
    {
        $subscription = $this->subscription(['renewal' => [\CartShiftSourceOrderDouble::paid(880_520)]]);

        $released = (new SourceRenewalGuard())->release($subscription);

        // A queued source action raised a manual renewal invoice after release.
        $subscription->addRenewalOrder(\CartShiftSourceOrderDouble::openWithoutGateway(880_521));

        $result = (new SourceRenewalGuard())->restore($subscription, false, (string) $released['post']['fingerprint']);

        $this->assertSame(SourceRenewalGuard::STATE_BLOCKED, $result['state']);
        $this->assertContains(SourceRenewalGuard::REASON_FINGERPRINT_CHANGED, $this->codes($result['failures']));

        // Kept manual. Deleting billing history to make rollback look tidy is
        // how audits become folklore.
        $this->assertTrue($subscription->is_manual());
    }

    // ──────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────

    /**
     * @param array<string, list<object>> $related
     */
    private function subscription(
        array $related = [],
        bool $manual = false,
        string $retry = '',
        ?callable $onSave = null,
        bool $saveSucceeds = true,
    ): \CartShiftSourceSubscriptionDouble {
        return new \CartShiftSourceSubscriptionDouble(
            910_001,
            $manual,
            $retry,
            $related,
            $onSave,
            $saveSucceeds,
        );
    }

    /**
     * @param list<array{code: string, message: string}> $failures
     * @return list<string>
     */
    private function codes(array $failures): array
    {
        return array_values(array_unique(array_column($failures, 'code')));
    }
}

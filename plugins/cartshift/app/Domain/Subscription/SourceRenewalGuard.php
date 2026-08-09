<?php

declare(strict_types=1);

namespace CartShift\Domain\Subscription;

defined('ABSPATH') || exit;

/**
 * Whether a WooCommerce Subscriptions record may stop owning its own billing —
 * plan section 11 Phase C.
 *
 * WHY `is_manual()` IS NOT THE ANSWER. WooCommerce Subscriptions 8.7.1 is
 * layered, and only the middle layer asks the question everybody quotes:
 *
 *  - `WC_Subscriptions_Manager::process_renewal()` creates a manual renewal
 *    order and sets NO gateway on it;
 *  - `WC_Subscriptions_Payment_Gateways::gateway_scheduled_subscription_payment()`
 *    checks `!$subscription->is_manual()` before reaching the helper;
 *  - `trigger_gateway_renewal_payment_hook()` itself checks only order presence,
 *    a positive total and a non-empty payment method — and retry, admin and
 *    early-renewal paths reach the gateway-specific hook by their own routes.
 *
 * So a successful `is_manual()` re-read is NECESSARY and is not the release
 * invariant. An old unpaid renewal order carrying a gateway is a charge waiting
 * for any of those other routes, and this class refuses to release around one.
 * `SourceRenewalGuardTest` characterises all three layers so the argument lives
 * in an executable form rather than in this paragraph.
 *
 * WHAT IT NEVER DOES. It does not clear a payment method, cancel an order, pay
 * one, or delete one. Every one of those would make the release succeed and the
 * shop's billing history a work of fiction; the operator reconciles the invoice
 * and runs the command again.
 *
 * Every WCS/WooCommerce call below is one the plan's context pack names, or one
 * `WC_Order` declares in the WooCommerce 11.0.0 checkout in this repository.
 * WooCommerce Subscriptions is not on this machine and nothing here guesses at
 * its API.
 */
final class SourceRenewalGuard
{
    /** Section 9.4's History/cutover row. */
    public const string REASON_MAINTENANCE_UNCONFIRMED = 'source_renewal_maintenance_unconfirmed';
    public const string REASON_OPEN_RENEWAL_ORDER = 'source_open_renewal_order';
    public const string REASON_OPEN_RENEWAL_GATEWAY = 'source_open_renewal_gateway';
    public const string REASON_RELEASE_UNVERIFIED = 'source_release_unverified';
    public const string REASON_FINGERPRINT_CHANGED = 'source_fingerprint_changed';

    public const string STATE_RELEASED = CutoverReceipt::RELEASE_RELEASED;
    public const string STATE_ALREADY_MANUAL = CutoverReceipt::RELEASE_ALREADY_MANUAL;
    public const string STATE_RESTORED = CutoverReceipt::RELEASE_RESTORED;
    public const string STATE_BLOCKED = CutoverReceipt::RELEASE_BLOCKED;

    /**
     * Statuses in which a positive-total order can no longer be paid.
     *
     * Exactly the two the plan names. `failed`, `pending` and `on-hold` are all
     * payable in WooCommerce and are therefore all open, which is the entire
     * point — a failed renewal is the most chargeable order on the list.
     *
     * @var list<string>
     */
    private const array TERMINAL_ORDER_STATUSES = ['cancelled', 'refunded'];

    /** The relationship whose orders can still bill this subscription. */
    private const string RELATIONSHIP = 'renewal';

    /**
     * Every renewal order and payment fact, plus the verdict on them.
     *
     * @return array{
     *     fingerprint: string,
     *     is_manual: bool,
     *     payment_retry: string,
     *     orders: list<array<string, mixed>>,
     *     open: list<array<string, mixed>>,
     *     failures: list<array{code: string, message: string}>,
     * }
     */
    public function inspect(object $subscription): array
    {
        $subscriptionId = self::intOf($subscription, 'get_id');
        $retry          = self::stringOf($subscription, 'get_date', 'payment_retry');
        $retryPending   = self::isPendingRetry($retry);

        $orders = [];
        $open   = [];

        // A SEPARATE typed call, as section 6.2 requires everywhere else. One
        // `get_related_orders('all')` flattens the grouped result and discards
        // the label, and "which of these is a renewal" is the whole question.
        foreach (self::relatedRenewalOrders($subscription) as $order) {
            $snapshot = self::snapshot($order);

            $orders[] = $snapshot;

            if (self::isOpen($snapshot, $retryPending)) {
                $open[] = $snapshot;
            }
        }

        usort($orders, static fn (array $a, array $b): int => $a['order_id'] <=> $b['order_id']);
        usort($open, static fn (array $a, array $b): int => $a['order_id'] <=> $b['order_id']);

        return [
            // The plan's list exactly: order ID, relationship type, status,
            // total, payment method, paid date, transaction ID, and the
            // subscription's own `payment_retry` date.
            //
            // `is_manual` is deliberately NOT in it. The release changes that
            // flag by design, so including it would make every successful
            // release look like drift and every drift check vacuous. The flag
            // is verified on its own, immediately after the save.
            'fingerprint'   => SubscriptionRecordFactory::digest([
                'orders'        => $orders,
                'payment_retry' => $retry,
                'subscription'  => $subscriptionId,
            ]),
            'is_manual'     => self::boolOf($subscription, 'is_manual'),
            'payment_retry' => $retry,
            'orders'        => $orders,
            'open'          => $open,
            'failures'      => self::openFailures(
                $subscriptionId,
                self::stringOf($subscription, 'get_status'),
                $open,
                $retry,
                $retryPending,
            ),
        ];
    }

    /**
     * Disable the source's automatic renewal, and prove it.
     *
     * Scan, refuse or set, save, re-read, scan again, compare. The second scan
     * is not defensive programming: `WC_Subscriptions_Manager::process_renewal()`
     * can create a renewal order from a queued action while this method is
     * running, and an order that appeared during the save is precisely the one
     * nobody has looked at.
     *
     * `source_mutated` is the one fact only this class can establish, and every
     * later command inherits it through the receipt rather than trying to infer
     * it from a flag it cannot interpret. It is true from the moment `save()` is
     * called, INCLUDING on the drift refusal below — that branch leaves the
     * source manual on purpose, and an entry that records otherwise is how a
     * later `stage` came to rebuild a subscription CartShift had already
     * disabled.
     *
     * @return array{
     *     state: string,
     *     previous_requires_manual_renewal: bool|null,
     *     source_mutated: bool,
     *     pre: array<string, mixed>,
     *     post: array<string, mixed>,
     *     failures: list<array{code: string, message: string}>,
     * }
     */
    public function release(object $subscription): array
    {
        $subscriptionId = self::intOf($subscription, 'get_id');
        $previous       = self::boolOf($subscription, 'is_manual');

        $pre = $this->inspect($subscription);

        // BEFORE any mutation. Nothing below this line runs on a subscription
        // with an open renewal order, whatever its manual flag says.
        if ($pre['failures'] !== []) {
            return self::blocked($previous, $pre, $pre, $pre['failures'], false);
        }

        if ($previous) {
            // Already manual: record the confirmation, change nothing. The scan
            // above still ran, because a historical flag does not prove the
            // absence of open billing artefacts.
            return [
                'state'                            => self::STATE_ALREADY_MANUAL,
                'previous_requires_manual_renewal' => true,
                // Nothing was written: the source arrived manual and stays
                // manual, so there is nothing for a restoration to put back.
                'source_mutated'                   => false,
                'pre'                              => $pre,
                'post'                             => $pre,
                'failures'                         => [],
            ];
        }

        if (!method_exists($subscription, 'set_requires_manual_renewal') || !method_exists($subscription, 'save')) {
            return self::blocked($previous, $pre, $pre, [[
                'code'    => self::REASON_RELEASE_UNVERIFIED,
                'message' => sprintf(
                    'Subscription WC-#%d does not expose set_requires_manual_renewal()/save(), so its '
                    . 'source ownership cannot be released. Nothing was changed.',
                    $subscriptionId,
                ),
            ]], false);
        }

        $subscription->set_requires_manual_renewal(true);
        $subscription->save();

        $post = $this->inspect($subscription);

        // Necessary, and asserted first because everything after it is about
        // what the flag does NOT cover.
        if (!$post['is_manual']) {
            // `true` even though the flag did not take. `save()` was called, so
            // nothing here can prove the source is untouched, and the safe
            // direction for a fact that gates a rebuild is to assume it is.
            return self::blocked($previous, $pre, $post, [[
                'code'    => self::REASON_RELEASE_UNVERIFIED,
                'message' => sprintf(
                    'Subscription WC-#%d still reports is_manual() === false after the save, so WooCommerce '
                    . 'Subscriptions did not take the change. The source still owns this billing.',
                    $subscriptionId,
                ),
            ]], true);
        }

        // Both, not whichever came first. A queued action that raised a manual
        // renewal invoice during the save produces an open order AND a moved
        // fingerprint, and an operator reading one of those without the other
        // is missing half the reason the release stopped.
        $drift = $post['failures'];

        if (!hash_equals((string) $pre['fingerprint'], (string) $post['fingerprint'])) {
            $drift[] = [
                'code'    => self::REASON_FINGERPRINT_CHANGED,
                'message' => sprintf(
                    'Subscription WC-#%d changed while its source was being released: the renewal/payment '
                    . 'fingerprint was %s and is now %s. The source has been left MANUAL and the workers '
                    . 'must stay paused. Reconcile what appeared before going any further.',
                    $subscriptionId,
                    (string) $pre['fingerprint'],
                    (string) $post['fingerprint'],
                ),
            ];
        }

        if ($drift !== []) {
            return self::blocked($previous, $pre, $post, $drift, true);
        }

        return [
            'state'                            => self::STATE_RELEASED,
            'previous_requires_manual_renewal' => $previous,
            'source_mutated'                   => true,
            'pre'                              => $pre,
            'post'                             => $post,
            'failures'                         => [],
        ];
    }

    /**
     * Put the previous manual flag back, if and only if nothing moved.
     *
     * A new renewal order, a new payment, a new retry — including an uncharged
     * pending manual invoice a queued source action raised — means the source
     * stays manual and the operator reviews the invoice. Automatically deleting
     * billing history to make a rollback look tidy is how audits become
     * folklore.
     *
     * `source_mutated` runs the other way here: false once the previous flag is
     * genuinely back, true on every refusal, because a refusal leaves the source
     * exactly as released as it was.
     *
     * @return array{
     *     state: string,
     *     source_mutated: bool,
     *     pre: array<string, mixed>,
     *     post: array<string, mixed>,
     *     failures: list<array{code: string, message: string}>,
     * }
     */
    public function restore(object $subscription, bool $previousManual, string $releasedFingerprint): array
    {
        $subscriptionId = self::intOf($subscription, 'get_id');
        $current        = $this->inspect($subscription);

        if ($releasedFingerprint !== '' && !hash_equals($releasedFingerprint, (string) $current['fingerprint'])) {
            return [
                'state'          => self::STATE_BLOCKED,
                'source_mutated' => true,
                'pre'            => $current,
                'post'           => $current,
                'failures' => [[
                    'code'    => self::REASON_FINGERPRINT_CHANGED,
                    'message' => sprintf(
                        'Subscription WC-#%d has a renewal/payment fingerprint of %s, and it was %s when the '
                        . 'source was released. Something billed, retried or raised an invoice in between. '
                        . 'The source stays MANUAL: review that invoice by hand.',
                        $subscriptionId,
                        (string) $current['fingerprint'],
                        $releasedFingerprint,
                    ),
                ]],
            ];
        }

        if (!method_exists($subscription, 'set_requires_manual_renewal') || !method_exists($subscription, 'save')) {
            return [
                'state'          => self::STATE_BLOCKED,
                'source_mutated' => true,
                'pre'            => $current,
                'post'           => $current,
                'failures' => [[
                    'code'    => self::REASON_RELEASE_UNVERIFIED,
                    'message' => sprintf(
                        'Subscription WC-#%d does not expose set_requires_manual_renewal()/save(). Nothing '
                        . 'was restored.',
                        $subscriptionId,
                    ),
                ]],
            ];
        }

        $subscription->set_requires_manual_renewal($previousManual);
        $subscription->save();

        $post = $this->inspect($subscription);

        if ($post['is_manual'] !== $previousManual) {
            return [
                'state'          => self::STATE_BLOCKED,
                'source_mutated' => true,
                'pre'            => $current,
                'post'           => $post,
                'failures' => [[
                    'code'    => self::REASON_RELEASE_UNVERIFIED,
                    'message' => sprintf(
                        'Subscription WC-#%d did not take the restored manual flag. Its current state is '
                        . 'is_manual() === %s; check the source before letting anything renew.',
                        $subscriptionId,
                        $post['is_manual'] ? 'true' : 'false',
                    ),
                ]],
            ];
        }

        return [
            'state'          => self::STATE_RESTORED,
            'source_mutated' => false,
            'pre'            => $current,
            'post'           => $post,
            'failures'       => [],
        ];
    }

    // ──────────────────────────────────────────────
    // The scan
    // ──────────────────────────────────────────────

    /**
     * @return list<object>
     */
    private static function relatedRenewalOrders(object $subscription): array
    {
        if (!method_exists($subscription, 'get_related_orders')) {
            return [];
        }

        $orders = $subscription->get_related_orders('all', self::RELATIONSHIP);

        return array_values(array_filter((array) $orders, is_object(...)));
    }

    /**
     * One order's payment facts, in the plan's own list.
     *
     * `needs_payment` is recorded and is NOT the definition of open — an order
     * can answer false to it and still be an unpaid, non-terminal, positive
     * invoice that any of WCS's other routes could charge.
     *
     * @return array<string, mixed>
     */
    private static function snapshot(object $order): array
    {
        return [
            'order_id'       => self::intOf($order, 'get_id'),
            'relationship'   => self::RELATIONSHIP,
            'status'         => self::normaliseStatus(self::stringOf($order, 'get_status')),
            'total'          => self::stringOf($order, 'get_total'),
            'payment_method' => trim(self::stringOf($order, 'get_payment_method')),
            'paid_date'      => self::stringOf($order, 'get_date_paid'),
            'transaction_id' => self::stringOf($order, 'get_transaction_id'),
            'is_paid'        => self::boolOf($order, 'is_paid'),
            'needs_payment'  => self::boolOf($order, 'needs_payment'),
        ];
    }

    /**
     * @param array<string, mixed> $snapshot
     */
    private static function isOpen(array $snapshot, bool $retryPending): bool
    {
        if ((float) $snapshot['total'] <= 0) {
            return false;
        }

        if (in_array((string) $snapshot['status'], self::TERMINAL_ORDER_STATUSES, true)) {
            return false;
        }

        // The plan's definition, then its two extra signals. A pending WCS retry
        // makes a non-terminal order open whatever its paid flag says, because
        // the retry is the source announcing it intends to charge again.
        return !$snapshot['is_paid'] || $snapshot['needs_payment'] === true || $retryPending;
    }

    /**
     * @param list<array<string, mixed>> $open
     * @return list<array{code: string, message: string}>
     */
    private static function openFailures(
        int $subscriptionId,
        string $status,
        array $open,
        string $retry,
        bool $retryPending,
    ): array {
        if ($open === []) {
            if (!$retryPending) {
                return [];
            }

            // A retry with nothing yet to retry. WCS is about to create the
            // order; releasing into that window is exactly the double-charge
            // this command exists to prevent.
            // The subscription's live status is in the message deliberately.
            // Plan section 4.9 records two Lapka retry values that exist in the
            // HPOS mirror and not in the authoritative posts table, and a retry
            // date on a `cancelled` subscription with no open order is almost
            // certainly one of those — a stale row rather than an imminent
            // charge. Without the status the operator cannot tell that from a
            // real pending retry, and the two need opposite responses.
            return [[
                'code'    => self::REASON_OPEN_RENEWAL_ORDER,
                'message' => sprintf(
                    'Subscription WC-#%d (status: %s) has a payment retry scheduled for %s and no open '
                    . 'renewal order to retry. Nothing was changed. If the subscription is not active, this '
                    . 'is most likely a stale retry date left in one storage backend; clear or resolve it in '
                    . 'WooCommerce, then run this again.',
                    $subscriptionId,
                    $status === '' ? 'unknown' : self::normaliseStatus($status),
                    $retry,
                ),
            ]];
        }

        $withGateway = array_values(array_filter(
            $open,
            static fn (array $order): bool => (string) $order['payment_method'] !== '',
        ));

        if ($withGateway !== []) {
            return [[
                'code'    => self::REASON_OPEN_RENEWAL_GATEWAY,
                'message' => sprintf(
                    'Subscription WC-#%d has %d open renewal order(s) carrying a payment method (%s). A '
                    . 'manual flag does not stop WooCommerce Subscriptions charging those — the low-level '
                    . 'trigger checks only the order, the total and the method. Nothing was changed and no '
                    . 'payment method was cleared: settle or cancel the invoice in WooCommerce yourself.',
                    $subscriptionId,
                    count($withGateway),
                    implode(', ', array_map(
                        static fn (array $order): string => sprintf(
                            '#%d %s/%s',
                            (int) $order['order_id'],
                            (string) $order['status'],
                            (string) $order['payment_method'],
                        ),
                        $withGateway,
                    )),
                ),
            ]];
        }

        return [[
            'code'    => self::REASON_OPEN_RENEWAL_ORDER,
            'message' => sprintf(
                'Subscription WC-#%d has %d open renewal order(s) with no payment method (%s). That is what '
                . 'a manual renewal invoice looks like, and it is still an outstanding invoice. Nothing was '
                . 'changed: reconcile it in WooCommerce, then run this again.',
                $subscriptionId,
                count($open),
                implode(', ', array_map(
                    static fn (array $order): string => sprintf(
                        '#%d %s',
                        (int) $order['order_id'],
                        (string) $order['status'],
                    ),
                    $open,
                )),
            ),
        ]];
    }

    /**
     * A WooCommerce status without its storage prefix.
     *
     * `wc-` is a PREFIX, and `ltrim()` takes a character set — `ltrim(
     * 'cancelled', 'wc-')` is `ancelled`, which matches no terminal status and
     * quietly makes every cancelled order look open.
     */
    private static function normaliseStatus(string $status): string
    {
        $status = strtolower(trim($status));

        return str_starts_with($status, 'wc-') ? substr($status, 3) : $status;
    }

    /**
     * Whether WCS currently has a retry scheduled.
     *
     * WooCommerce Subscriptions answers `''` for a date that is not set, and
     * clearing a resolved retry writes zero — which `get_date()` renders as the
     * all-zero MySQL datetime on some code paths and as `''` on others. Both
     * mean "no retry"; anything else means one is on the books.
     */
    private static function isPendingRetry(string $retry): bool
    {
        $retry = trim($retry);

        return $retry !== '' && $retry !== '0' && $retry !== '0000-00-00 00:00:00';
    }

    // ──────────────────────────────────────────────
    // Results
    // ──────────────────────────────────────────────

    /**
     * @param array<string, mixed>                       $pre
     * @param array<string, mixed>                       $post
     * @param list<array{code: string, message: string}> $failures
     * @return array{
     *     state: string,
     *     previous_requires_manual_renewal: bool|null,
     *     source_mutated: bool,
     *     pre: array<string, mixed>,
     *     post: array<string, mixed>,
     *     failures: list<array{code: string, message: string}>,
     * }
     */
    private static function blocked(
        bool $previous,
        array $pre,
        array $post,
        array $failures,
        bool $mutated,
    ): array {
        return [
            'state'                            => self::STATE_BLOCKED,
            'previous_requires_manual_renewal' => $previous,
            'source_mutated'                   => $mutated,
            'pre'                              => $pre,
            'post'                             => $post,
            'failures'                         => $failures,
        ];
    }

    // ──────────────────────────────────────────────
    // Reading an object that may not have the method
    // ──────────────────────────────────────────────

    private static function intOf(object $subject, string $method): int
    {
        return method_exists($subject, $method) ? (int) $subject->{$method}() : 0;
    }

    private static function boolOf(object $subject, string $method): bool
    {
        return method_exists($subject, $method) && (bool) $subject->{$method}();
    }

    private static function stringOf(object $subject, string $method, mixed ...$arguments): string
    {
        if (!method_exists($subject, $method)) {
            return '';
        }

        $value = $subject->{$method}(...$arguments);

        if ($value === null || is_bool($value)) {
            return '';
        }

        if (is_object($value)) {
            // WC_Order::get_date_paid() answers a WC_DateTime, which stringifies
            // to an ISO-8601 instant. Deterministic and comparable, which is all
            // a fingerprint field has to be.
            return method_exists($value, '__toString') ? (string) $value : '';
        }

        return is_scalar($value) ? (string) $value : '';
    }
}

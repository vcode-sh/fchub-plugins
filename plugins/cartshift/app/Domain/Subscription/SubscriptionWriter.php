<?php

declare(strict_types=1);

namespace CartShift\Domain\Subscription;

defined('ABSPATH') || exit;

use CartShift\Domain\Mapping\SubscriptionMapper;
use CartShift\Domain\Subscription\Payment\PaymentMigrationDecision;
use CartShift\Storage\IdMapRepository;
use CartShift\Support\Constants;
use CartShift\Support\DatabaseTransaction;
use CartShift\Support\Enums\FcBillingInterval;
use FluentCart\App\Models\Subscription;

/**
 * The only place a `fct_subscriptions` row is created.
 *
 * Three things are true of it, and each one replaces a way the old path went
 * wrong.
 *
 * IT REFUSES ANYTHING THAT IS NOT `ready`. Not "writes it paused", not "writes
 * it with the missing reference nulled". CartShift used to answer an unresolved
 * product by flipping the status to `paused` and calling `Subscription
 * ::create()` regardless — which produced a row FluentCart hands no downloads
 * (`Subscription::getDownloads()`), hides the upgrade path for
 * (`canUpgrade()`), and stamps every renewal invoice from with a null
 * `object_id` and a blank line title (`RenewalService::createRenewalOrders()`).
 * A `LogicException` here is deliberate: reaching this method with a blocked
 * assessment is a programming error in the caller, not a data condition to
 * report.
 *
 * IT SETS `created_at` ON THE INSTANCE. FluentCart 1.6.0 excludes the column
 * from `Subscription::$fillable` (Subscription.php:47-76), so mass assignment
 * discards it without a word and every migrated subscription is stamped with
 * the day the migration ran. A subscriber who joined in 2021 then appears to
 * have joined this afternoon, in every report the shop owner will ever look at.
 *
 * IT IS IDEMPOTENT. A second run finds the ID map row and hands back the same
 * destination ID rather than creating a twin, and the ID map write happens
 * inside the same transaction as the row it describes — a committed
 * subscription with no mapping is a subscription the next run duplicates.
 */
final class SubscriptionWriter
{
    /** The meta key FluentCart's charge path reads at fire time. */
    public const string META_ACTIVE_PAYMENT_METHOD = 'active_payment_method';

    public function __construct(
        private readonly IdMapRepository $idMap,
        private readonly SubscriptionMapper $mapper = new SubscriptionMapper(),
    ) {
    }

    /**
     * Create the destination subscription, or hand back the one that exists.
     *
     * @throws \LogicException when the assessment is not stageable.
     */
    public function stage(
        SubscriptionRecord $record,
        SubscriptionAssessment $assessment,
        string $migrationId = '',
    ): int {
        $existing = $this->idMap->getFcId(
            Constants::ENTITY_SUBSCRIPTION,
            (string) $record->sourceSubscriptionId,
        );

        if ($existing) {
            return $existing;
        }

        if (!$assessment->isStageable()) {
            throw new \LogicException(sprintf(
                'Subscription #%d was assessed "%s" and may not be written. Codes: [%s].',
                $record->sourceSubscriptionId,
                $assessment->outcome,
                implode(', ', array_merge($assessment->errorCodes(), $assessment->warningCodes())),
            ));
        }

        $attributes = $this->mapper->map($record, $assessment);

        $this->guardPayload($record, $assessment, $attributes);

        // Lifted out rather than left in: passing it to the constructor would
        // hand it to fill(), which drops it. See the class docblock.
        $createdAt = $attributes['created_at'] ?? null;
        unset($attributes['created_at']);

        // JOINS the caller's transaction rather than opening a second one. A
        // second `START TRANSACTION` implicitly commits the first in MySQL, and
        // this method used to be called from inside `MigrationOrchestrator`'s
        // per-record transaction — so the writer's own COMMIT ended the only
        // transaction there was, and `SubscriptionMigrator::linkHistory()` then
        // created orders, items and transactions with nothing wrapping them.
        // See `DatabaseTransaction`.
        DatabaseTransaction::begin();

        try {
            $subscription = new Subscription($attributes);

            if (is_string($createdAt) && $createdAt !== '') {
                $subscription->created_at = $createdAt;
            }

            $subscription->save();

            $id = (int) $subscription->id;

            if ($id <= 0) {
                throw new \RuntimeException(sprintf(
                    'FluentCart returned no ID for subscription #%d, so nothing can reference it.',
                    $record->sourceSubscriptionId,
                ));
            }

            // Only now. FluentCart reads `active_payment_method` from
            // subscription meta at charge time — `Stripe::chargeRenewal()`
            // (Stripe.php:215-216) and `Processor::chargeVaultedRenewal()`
            // (Processor.php:817-818) — and meta needs a subscription ID,
            // which does not exist until the row does.
            $this->stampVerifiedPaymentMethod($subscription, $assessment->payment);

            $this->idMap->store(
                Constants::ENTITY_SUBSCRIPTION,
                (string) $record->sourceSubscriptionId,
                $id,
                $migrationId,
                true,
            );

            DatabaseTransaction::commit();

            return $id;
        } catch (\Throwable $exception) {
            DatabaseTransaction::rollback($exception);

            throw $exception;
        }
    }

    /**
     * The last thing between the payload and the INSERT.
     *
     * The assessor validated the *references*; this validates the *payload*,
     * and they are not the same array. `cartshift/mapper/subscription` sits
     * between them and is a public filter — free to null a reference, zero a
     * quantity, or blank an item name after every gate has passed. A third-party
     * callback should not be able to do by accident what section 9.3 exists to
     * prevent.
     *
     * A throw rather than a returned verdict, deliberately: the record was
     * assessed `ready` and something changed it afterwards, which is a fault in
     * code rather than in data, and a writer that quietly returned "no" would
     * let a caller treat it as an ordinary blocked record.
     * `SubscriptionMigrator` catches it and files the usual coded log row, so
     * the owner still gets a sentence rather than a stack trace.
     *
     * @param array<string, mixed> $attributes
     */
    private function guardPayload(
        SubscriptionRecord $record,
        SubscriptionAssessment $assessment,
        array $attributes,
    ): void {
        $missing = [];

        // Equality with what the gate resolved, not merely "greater than zero".
        // A filter that swapped `variation_id` for a different positive integer
        // would pass a value check while invalidating every section 9.3 answer
        // taken about it — including the ownership check, which asked whether
        // *that* variation sits on *that* product. The writer has no catalogue
        // lookup of its own and should not grow one; requiring the payload to
        // still be the assessed one is the same rule as `collection_method`
        // below, and it is what makes the assessment binding rather than
        // advisory.
        foreach (['customer_id', 'parent_order_id', 'product_id', 'variation_id'] as $field) {
            $resolved = (int) ($assessment->resolvedReferences[$field] ?? 0);

            if ((int) ($attributes[$field] ?? 0) !== $resolved || $resolved <= 0) {
                $missing[] = sprintf('the assessed %s', $field);
            }
        }

        if ((int) ($attributes['quantity'] ?? 0) <= 0) {
            $missing[] = 'quantity';
        }

        if (trim((string) ($attributes['item_name'] ?? '')) === '') {
            $missing[] = 'item_name';
        }

        // Against the enum, not merely against null. `billing_interval` is
        // VARCHAR, so the column accepts "fortnightly" quite happily; FluentCart
        // then fails to match it in `Subscription.php`'s interval switch and the
        // subscription never schedules anything.
        if (FcBillingInterval::tryFrom((string) ($attributes['billing_interval'] ?? '')) === null) {
            $missing[] = 'a billing_interval FluentCart recognises';
        }

        // Every money column on `fct_subscriptions` is BIGINT UNSIGNED
        // (SubscriptionsMigrator.php:24-28). A negative is not a small
        // discrepancy: MySQL either refuses the row or, in a permissive mode,
        // stores the two's complement and the customer's next invoice is
        // astronomical. The assessor checks the *contract*; this checks the
        // array that is actually about to be written, and the filter runs
        // between them.
        foreach (['recurring_amount', 'recurring_tax_total', 'recurring_total', 'signup_fee'] as $field) {
            if (!is_int($attributes[$field] ?? null) || $attributes[$field] < 0) {
                $missing[] = sprintf('a non-negative integer %s', $field);
            }
        }

        // THE LIFECYCLE, AGAINST THE PROJECTION — the same argument the money
        // loop makes, applied to the five fields it stopped short of.
        //
        // `bill_times`, `bill_count` and `trial_days` are UNSIGNED on
        // `fct_subscriptions`, so a filter that decremented one past zero either
        // gets the row refused or, in a permissive mode, stores the two's
        // complement. `status` decides whether FluentCart considers the row
        // billable at all. And `next_billing_date` is the field this entire
        // slice exists to keep null: `SubscriptionLifecycleProjector` writes the
        // source's date or nothing, never a guess, and a callback that filled it
        // in would schedule a charge nobody agreed to.
        foreach (['bill_times', 'bill_count', 'trial_days'] as $field) {
            $projected = (int) ($assessment->lifecycle[$field] ?? 0);

            if ((int) ($attributes[$field] ?? 0) !== $projected || $projected < 0) {
                $missing[] = sprintf('the projected %s', $field);
            }
        }

        if ((string) ($attributes['status'] ?? '') !== (string) ($assessment->lifecycle['status'] ?? '')) {
            $missing[] = 'the projected status';
        }

        $projectedNextBilling = $assessment->lifecycle['next_billing_date'] ?? null;

        if (($attributes['next_billing_date'] ?? null) !== $projectedNextBilling) {
            $missing[] = 'the projected next_billing_date';
        }

        if ($missing !== []) {
            throw new \LogicException(sprintf(
                'Subscription #%d passed assessment and then arrived at the writer without %s. Those '
                . 'columns are NOT NULL or UNSIGNED; nothing was written. Check any callback on '
                . '"cartshift/mapper/subscription".',
                $record->sourceSubscriptionId,
                implode(', ', $missing),
            ));
        }

        // The payload's collection method has to be the decision's, because
        // the verified token below is written from the decision. A filter that
        // promoted a manual row to `system` would produce a subscription
        // FluentCart believes it may charge and no `active_payment_method` to
        // charge with — `missing_token` at the first renewal, months later.
        if (($attributes['collection_method'] ?? null) !== $assessment->payment->collectionMethod) {
            throw new \LogicException(sprintf(
                'Subscription #%d was decided "%s" and reached the writer as "%s". Who charges a '
                . 'customer next is not a payload field; nothing was written.',
                $record->sourceSubscriptionId,
                $assessment->payment->collectionMethod,
                (string) ($attributes['collection_method'] ?? ''),
            ));
        }
    }

    /**
     * The verified token, for a `system` decision and for nothing else.
     *
     * `PaymentMigrationDecision`'s constructor already guarantees a `system`
     * decision carries a non-empty `vendor_method_id` and that a `manual` or
     * `automatic` one carries no vault metadata at all, so this is a copy
     * rather than a second opinion. Writing it for any other collection method
     * would leave a chargeable token on a subscription nothing is supposed to
     * charge.
     */
    private function stampVerifiedPaymentMethod(
        Subscription $subscription,
        PaymentMigrationDecision $payment,
    ): void {
        if ($payment->collectionMethod !== PaymentMigrationDecision::COLLECTION_SYSTEM) {
            return;
        }

        $subscription->updateMeta(self::META_ACTIVE_PAYMENT_METHOD, $payment->activePaymentMethod);
    }
}

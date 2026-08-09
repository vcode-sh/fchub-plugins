<?php

declare(strict_types=1);

namespace CartShift\Domain\Subscription;

defined('ABSPATH') || exit;

use CartShift\Domain\Subscription\Payment\PaymentEnvironment;
use CartShift\Domain\Subscription\Payment\PaymentMigrationDecision;
use CartShift\Domain\Subscription\Payment\PaymentStrategyRegistry;
use CartShift\Storage\IdMapRepository;
use CartShift\Support\Constants;

/**
 * Plan section 9.3's required-reference gate, and nothing else.
 *
 * It reads. It resolves destination references out of the ID map, asks the
 * payment registry who owns the next charge, asks the lifecycle projector what
 * status and dates the row would carry, and returns a verdict. It creates no
 * customer, no order, and no subscription, which is what makes it usable for
 * both the read-only audit of section 11 Phase A and the stage of Phase B —
 * one set of rules, so a preview cannot promise what a run refuses.
 *
 * FluentCart 1.6.0 declares `customer_id`, `parent_order_id`, `product_id`,
 * `item_name`, `quantity` and `variation_id` NOT NULL on `fct_subscriptions`
 * (database/Migrations/SubscriptionsMigrator.php:17-23). A record short of any
 * of them is not a compromised subscription to be nursed along in a safe
 * status; it is a row the destination schema will not hold.
 *
 * Two of the gates are worth reading twice.
 *
 * THE CADENCE GATE is applied here as well as at decode time. Task 2's factory
 * already refuses `month/2`, so a record reaching this class normally has a
 * representable interval — but `cartshift/mapper/subscription` is a public
 * filter and a package file is written by somebody else, and a gate that runs
 * only where the data is known to be clean is decoration.
 *
 * THE SCHEDULE GATE consumes rather than recomputes. The Stripe and PayPal
 * strategies already evaluate whether the next billing date is future and
 * plausible, and attach `active_next_date_missing` / `active_next_date_past`;
 * emitting the block for those is section 9.3's job, which is this one. The
 * manual strategy attaches no such reason — it has no opinion about schedules —
 * so the projector additionally asks `PaymentEnvironment::liveScheduleFault()`,
 * the same helper those strategies ask. One definition of "future and
 * plausible" in the plugin, consulted twice, deduplicated once.
 */
final class SubscriptionAssessor
{
    /**
     * @param null|callable(int): (int|null) $variationOwner
     *        A destination variation ID to the product post it belongs to, or
     *        null when no such variation exists. Defaults to a read-only
     *        `fct_product_variations` lookup; injectable so a test can state
     *        the target catalogue rather than stage one.
     */
    public function __construct(
        private readonly IdMapRepository $idMap,
        private readonly PaymentStrategyRegistry $strategies,
        private readonly PaymentEnvironment $environment,
        private readonly SubscriptionLifecycleProjector $lifecycle = new SubscriptionLifecycleProjector(),
        private $variationOwner = null,
    ) {
    }

    public function assess(SubscriptionRecord $record): SubscriptionAssessment
    {
        $payment   = $this->strategies->assess($record, $this->environment);
        $lifecycle = $this->lifecycle->project($record, $this->environment);

        $references = $this->resolve($record);

        $errors   = [];
        $warnings = [];

        $this->gateItems($record, $errors);
        $this->gateReferences($record, $references, $errors);
        $this->gateVariationOwnership($record, $references, $errors);
        $this->gateContract($record, $errors);
        $this->gatePayment($record, $payment, $errors, $warnings);
        $this->gateLifecycle($record, $lifecycle, $errors, $warnings);

        $errors   = $this->deduplicate($errors);
        $warnings = $this->deduplicate($warnings);

        return new SubscriptionAssessment(
            $this->outcome($errors, $payment),
            $errors,
            $warnings,
            $references,
            $payment,
            $lifecycle,
        );
    }

    // ──────────────────────────────────────────────
    // Reference resolution — reads only
    // ──────────────────────────────────────────────

    /**
     * The destination IDs this subscription would be written with.
     *
     * A null means "this migration has not produced one", which is a block
     * rather than a value to improvise around. The customer is resolved through
     * the same ID map every other entity uses, and additionally through the
     * guest namespace: 349 of the 564 Lapka subscriptions carry
     * `_customer_user = 0`, all of them have a billing email, and
     * `CustomerMigrator` files exactly those under `guest_customer` keyed by
     * that email. Looking there is a read; it creates nobody.
     *
     * @return array<string, mixed>
     */
    private function resolve(SubscriptionRecord $record): array
    {
        $item = $record->items[0] ?? null;

        $productId   = $item !== null ? (int) $item['source_product_id'] : 0;
        $variationId = $item !== null ? (int) $item['source_variation_id'] : 0;

        return [
            'customer_id'         => $this->resolveCustomer($record),
            'parent_order_id'     => $this->idMap->getFcId(
                Constants::ENTITY_ORDER,
                (string) $record->parentOrderId,
            ),
            'product_id'          => $productId > 0
                ? $this->idMap->getFcId(Constants::ENTITY_PRODUCT, (string) $productId)
                : null,
            'variation_id'        => $this->resolveVariation($productId, $variationId),
            'item_name'           => $item !== null ? trim((string) $item['name']) : '',
            'quantity'            => $item !== null ? (int) $item['quantity'] : 0,
            'source_customer_ref' => $record->sourceCustomerRef,
        ];
    }

    /**
     * The destination customer, resolved read-only from the ID map.
     *
     * NOT plan section 9.1's resolution order, and deliberately so.
     * `CustomerResolver` implements 9.1 in full — normalise the email, reuse a
     * unique FluentCart customer, otherwise attach a unique target WordPress
     * user, otherwise create a guest, block on blank or ambiguous — and it
     * *creates* customers, which would make `assess()` a writing operation and
     * break section 11 Phase A's zero-write guarantee.
     *
     * SO THE RESOLVER RUNS SOMEWHERE ELSE, AND IT IS WIRED. `SubscriptionCutover
     * ::resolveCustomer()` calls it as section 6.2's step 1, a whole pass before
     * any order is imported, and writes the ID-map row the two reuse arms do not
     * write themselves. `SubscriptionAuditController` calls `preview()`, which
     * reads the same three lookups and stops where `resolve()` would write. By
     * the time this method runs, the customer it is looking for exists.
     *
     * What this method does is read: the registered customer by source user ID,
     * then the guest namespace by normalised email and by the deterministic
     * guest ref — 349 of the 564 Lapka subscriptions are guests. It never copies
     * a source WordPress user ID into the destination; the value it returns is
     * always a FluentCart customer ID this migration recorded.
     *
     * @see CustomerResolver              Plan section 9.1.
     * @see SubscriptionCutover           Section 6.2 step 1 — the resolving caller.
     * @see SubscriptionAuditController   The read-only forecast.
     */
    private function resolveCustomer(SubscriptionRecord $record): ?int
    {
        if ($record->sourceCustomerId !== null && $record->sourceCustomerId > 0) {
            $registered = $this->idMap->getFcId(
                Constants::ENTITY_CUSTOMER,
                (string) $record->sourceCustomerId,
            );

            if ($registered) {
                return $registered;
            }
        }

        if ($record->billingEmail === '') {
            return null;
        }

        return $this->idMap->getFcId(Constants::ENTITY_GUEST_CUSTOMER, $record->billingEmail)
            ?? $this->idMap->getFcId(
                Constants::ENTITY_GUEST_CUSTOMER,
                SubscriptionRecordFactory::guestRef($record->billingEmail),
            );
    }

    /**
     * A simple WooCommerce subscription product has no variation of its own, so
     * its FluentCart variant is keyed by the product ID — which is what
     * `ProductMigrator` and `MappingPromoter` both write. The order matters:
     * a real variation ID wins, and the product-keyed fallback covers the
     * simple case that both Lapka source products are.
     */
    private function resolveVariation(int $productId, int $variationId): ?int
    {
        if ($variationId > 0) {
            $resolved = $this->idMap->getFcId(Constants::ENTITY_VARIATION, (string) $variationId);

            if ($resolved) {
                return $resolved;
            }
        }

        return $productId > 0
            ? $this->idMap->getFcId(Constants::ENTITY_VARIATION, (string) $productId)
            : null;
    }

    // ──────────────────────────────────────────────
    // The gates
    // ──────────────────────────────────────────────

    /**
     * @param list<array{code: string, message: string}> $errors
     */
    private function gateItems(SubscriptionRecord $record, array &$errors): void
    {
        $count = count($record->items);

        if ($count === 1) {
            return;
        }

        if ($count === 0) {
            $errors[] = $this->error(
                SubscriptionAssessment::REASON_REQUIRED_REFERENCE_MISSING,
                sprintf(
                    'Subscription #%d has no line item, so there is no product, variant or item name to '
                    . 'give FluentCart — all three are required. Nothing was migrated. Repair the '
                    . 'subscription in WooCommerce, or decide by hand which contract it should become.',
                    $record->sourceSubscriptionId,
                ),
            );

            return;
        }

        // Every item is named, not only the ones a truncating mapper would have
        // dropped: the operator has to split the source subscription, and for
        // that they need the whole list rather than the tail of it.
        $names = [];
        $position = 0;

        foreach ($record->items as $item) {
            $position++;
            $name = trim((string) $item['name']);
            $names[] = $name !== '' ? sprintf('#%d "%s"', $position, $name) : sprintf('#%d', $position);
        }

        $errors[] = $this->error(
            SubscriptionAssessment::REASON_MULTI_ITEM,
            sprintf(
                'Subscription #%d has %d line items and a FluentCart subscription holds one contract. '
                . 'Nothing was migrated: keeping the first item and dropping the rest would leave the '
                . 'customer paying the same and receiving less. Items: [%s]. Split it in WooCommerce, '
                . 'then re-run.',
                $record->sourceSubscriptionId,
                $count,
                implode(', ', $names),
            ),
        );
    }

    /**
     * @param array<string, mixed>                       $references
     * @param list<array{code: string, message: string}> $errors
     */
    private function gateReferences(SubscriptionRecord $record, array $references, array &$errors): void
    {
        if (($references['customer_id'] ?? null) === null) {
            $errors[] = $this->error(
                SubscriptionAssessment::REASON_CUSTOMER_NOT_FOUND,
                $record->sourceCustomerId !== null
                    ? sprintf(
                        'Customer ID %d was not migrated, and FluentCart requires a customer on every '
                        . 'subscription. Skipping: migrate customers before subscriptions, then re-run.',
                        $record->sourceCustomerId,
                    )
                    : sprintf(
                        'No FluentCart customer has been migrated for "%s", and FluentCart requires one on '
                        . 'every subscription. Migrate customers before subscriptions, then re-run.',
                        $record->billingEmail,
                    ),
            );
        }

        $missing = $this->describeMissingReferences($record, $references);

        if ($missing === []) {
            return;
        }

        $errors[] = $this->error(
            SubscriptionAssessment::REASON_REQUIRED_REFERENCE_MISSING,
            sprintf(
                'Subscription #%d is missing what FluentCart requires on every subscription: %s. '
                . 'Nothing was migrated — those columns are NOT NULL, and a paused status would not '
                . 'change that. Migrate or map the missing piece, then re-run.',
                $record->sourceSubscriptionId,
                implode('; ', $missing),
            ),
        );
    }

    /**
     * Which references are missing, named the way an owner can act on.
     *
     * "product_id is missing" is a riddle; "Product ID 202 for item #1 'Monthly
     * Tea' was not migrated" is an instruction. The item position is 1-based
     * because WooCommerce keys line items by order-item ID and nobody wants to
     * be told about item #41013.
     *
     * The variation clause is gated on the *product* rather than on the source
     * variation ID. A simple subscription product's line carries no variation
     * ID at all, and its FluentCart variant is keyed by the product ID — so a
     * check that only ran when a variation ID was present skipped every
     * subscription to a simple product, which is every Lapka subscription.
     *
     * @param array<string, mixed> $references
     * @return list<string>
     */
    private function describeMissingReferences(SubscriptionRecord $record, array $references): array
    {
        $missing = [];

        if ((int) ($references['parent_order_id'] ?? 0) <= 0) {
            $missing[] = sprintf(
                'its parent order (WooCommerce order #%d) was not migrated',
                $record->parentOrderId,
            );
        }

        $item        = $record->items[0] ?? null;
        $productId   = $item !== null ? (int) $item['source_product_id'] : 0;
        $variationId = $item !== null ? (int) $item['source_variation_id'] : 0;
        $label       = $this->describeItem($item, 1);

        if ((int) ($references['product_id'] ?? 0) <= 0) {
            $missing[] = sprintf('Product ID %d for item %s was not migrated', $productId, $label);
        } elseif ((int) ($references['variation_id'] ?? 0) <= 0) {
            $missing[] = $variationId > 0
                ? sprintf('Variation ID %d for item %s was not migrated', $variationId, $label)
                : sprintf(
                    'Product ID %d for item %s migrated, but nothing was migrated for the variant it '
                    . 'bills against',
                    $productId,
                    $label,
                );
        }

        if (trim((string) ($references['item_name'] ?? '')) === '') {
            $missing[] = sprintf('item %s has no name', $label);
        }

        if ((int) ($references['quantity'] ?? 0) <= 0) {
            $missing[] = sprintf('item %s has no positive quantity', $label);
        }

        return $missing;
    }

    /**
     * Section 9.3's "variation ID belonging to that product", asked of the
     * target catalogue rather than assumed of the ID map.
     *
     * Only reached when both IDs resolved, so a missing reference is reported
     * once and under its own code. A variation row that is not there at all is
     * as much a refusal as one on another product: either way the subscription
     * would bill against a line FluentCart cannot resolve.
     *
     * @param array<string, mixed>                       $references
     * @param list<array{code: string, message: string}> $errors
     */
    private function gateVariationOwnership(
        SubscriptionRecord $record,
        array $references,
        array &$errors,
    ): void {
        $productId   = (int) ($references['product_id'] ?? 0);
        $variationId = (int) ($references['variation_id'] ?? 0);

        if ($productId <= 0 || $variationId <= 0) {
            return;
        }

        // A dry run has no catalogue to ask.
        //
        // `MigrationOrchestrator` populates the ID map with SIMULATED
        // destination IDs — numbers standing in for rows that will exist if the
        // owner runs for real — so a `fct_product_variations` lookup finds
        // nothing and every subscription would refuse under
        // `target_variation_not_on_product`. The preview would then contradict
        // the run it is previewing, which is the exact fault this class was
        // rewritten to remove.
        //
        // What a dry run CAN check is the mapping, and it already has: the
        // product and the variation both resolved through it, which is the
        // whole of what a simulated realm knows. So the gate is skipped rather
        // than answered from a source that cannot tell a real variant from an
        // imagined one, and the real run — where the rows exist — asks properly.
        if ($this->idMap->isSimulating()) {
            return;
        }

        $owner = $this->ownerOf($variationId);

        if ($owner === $productId) {
            return;
        }

        $errors[] = $this->error(
            SubscriptionAssessment::REASON_VARIATION_NOT_ON_PRODUCT,
            $owner === null
                ? sprintf(
                    'Subscription #%d resolves to FluentCart variation %d, which is not in the target '
                    . 'catalogue at all. Nothing was migrated: re-run the mapping screen and pick the '
                    . 'variant again.',
                    $record->sourceSubscriptionId,
                    $variationId,
                )
                : sprintf(
                    'Subscription #%d resolves to FluentCart variation %d, which belongs to product %d '
                    . 'and not to product %d. Nothing was migrated: billing it would attach this '
                    . 'subscriber to somebody else\'s product. Re-pick the variant on the mapping '
                    . 'screen, then re-run.',
                    $record->sourceSubscriptionId,
                    $variationId,
                    $owner,
                    $productId,
                ),
        );
    }

    /**
     * Which FluentCart product a variation sits on, or null when there is no
     * such variation.
     *
     * Raw SQL rather than the `ProductVariation` model, for the reason
     * `MigrationOrchestratorFactory::fcVariantIdsFor()` gives: it has to work on
     * a request where FluentCart's classes are not loaded. Read-only, so
     * section 11 Phase A stays a zero-write phase.
     */
    private function ownerOf(int $variationId): ?int
    {
        if (is_callable($this->variationOwner)) {
            $owner = ($this->variationOwner)($variationId);

            return $owner === null ? null : (int) $owner;
        }

        global $wpdb;

        $owner = $wpdb->get_var($wpdb->prepare(
            "SELECT post_id FROM {$wpdb->prefix}fct_product_variations WHERE id = %d",
            $variationId,
        ));

        return $owner === null || (int) $owner <= 0 ? null : (int) $owner;
    }

    /**
     * @param array<string, mixed>|null $item
     */
    private function describeItem(?array $item, int $position): string
    {
        $name = $item === null ? '' : trim((string) $item['name']);

        return $name !== ''
            ? sprintf('#%d "%s"', $position, $name)
            : sprintf('#%d', $position);
    }

    /**
     * @param list<array{code: string, message: string}> $errors
     */
    private function gateContract(SubscriptionRecord $record, array &$errors): void
    {
        $contract = $record->contract;

        if (!$contract->isRepresentable()) {
            $errors[] = $this->error(
                SubscriptionAssessment::REASON_UNSUPPORTED_CADENCE,
                sprintf(
                    'Subscription #%d bills every %d %s, and FluentCart has no equivalent. Nothing was '
                    . 'migrated: collapsing it to the nearest interval would charge this customer on a '
                    . 'schedule they never agreed to.',
                    $record->sourceSubscriptionId,
                    $contract->multiplier,
                    $contract->period === '' ? '(no period)' : $contract->period,
                ),
            );
        }

        // `fct_subscriptions` declares every money column BIGINT UNSIGNED, so a
        // negative is not a small discrepancy — MySQL either refuses it or, in
        // a permissive mode, stores its two's complement as a very large
        // positive number and the customer's next invoice is astronomical.
        $negative = array_filter([
            'recurring_amount'    => $contract->recurringAmount,
            'recurring_tax_total' => $contract->recurringTax,
            'recurring_total'     => $contract->recurringTotal,
            'signup_fee'          => $contract->setupFee,
        ], static fn (int $value): bool => $value < 0);

        if ($negative !== []) {
            $errors[] = $this->error(
                SubscriptionAssessment::REASON_REQUIRED_REFERENCE_MISSING,
                sprintf(
                    'Subscription #%d carries a negative amount in %s, and FluentCart declares those '
                    . 'columns BIGINT UNSIGNED. Nothing was migrated.',
                    $record->sourceSubscriptionId,
                    implode(', ', array_keys($negative)),
                ),
            );
        }

        if (preg_match('/^[A-Z]{3}$/', $record->currency) !== 1) {
            $errors[] = $this->error(
                SubscriptionAssessment::REASON_REQUIRED_REFERENCE_MISSING,
                sprintf(
                    'Subscription #%d has no usable currency ("%s"), so its amounts mean nothing. '
                    . 'Nothing was migrated.',
                    $record->sourceSubscriptionId,
                    $record->currency,
                ),
            );
        }
    }

    /**
     * @param list<array{code: string, message: string}> $errors
     * @param list<array{code: string, message: string}> $warnings
     */
    private function gatePayment(
        SubscriptionRecord $record,
        PaymentMigrationDecision $payment,
        array &$errors,
        array &$warnings,
    ): void {
        // A blocked decision with nothing to say still blocks. The registry
        // always attaches `unsupported_gateway`, but a fourth strategy is one
        // class away and "blocked, no reasons" must not read as ready merely
        // because the loop below had nothing to iterate.
        if ($payment->isBlocked() && $payment->reasonCodes === []) {
            $errors[] = $this->error(
                PaymentStrategyRegistry::REASON_UNSUPPORTED_GATEWAY,
                sprintf(
                    'Subscription #%d: the %s payment strategy refused it without saying why. Nothing '
                    . 'was migrated.',
                    $record->sourceSubscriptionId,
                    $payment->strategy,
                ),
            );
        }

        foreach ($payment->reasonCodes as $code) {
            $entry = $this->error($code, $this->paymentMessage($record, $payment, $code));

            if ($payment->isBlocked() || $this->isLifecycleCode($code)) {
                $errors[] = $entry;

                continue;
            }

            $warnings[] = $entry;
        }

        // A record WooCommerce Subscriptions was charging automatically becomes
        // one FluentCart raises an invoice for. That is a change the customer
        // notices, so it is counted rather than absorbed — but it reads very
        // differently depending on whether the operator has accepted it, and
        // the two used to share one sentence.
        //
        // Unaccepted, it is the reason nothing was written, and a note ending
        // "nothing charges this customer off-session" made the record that
        // stops an entire cohort read like a success. Accepted, it is the
        // ordinary informational note it always was.
        if (
            $payment->collectionMethod !== PaymentMigrationDecision::COLLECTION_MANUAL
            || $record->requiresManualRenewal
            || $this->isTerminal($record)
        ) {
            return;
        }

        // Unaccepted: the decision already carries `manual_confirmation_required`
        // and paymentMessage() has said, in those words, that nothing was
        // migrated and why. A second, cheerier note beside it is what made the
        // refusal read like a result.
        if ($payment->outcome === PaymentMigrationDecision::OUTCOME_CONFIRMATION_REQUIRED) {
            return;
        }

        $warnings[] = $this->error(
            SubscriptionAssessment::REASON_MANUAL_RENEWAL_ADOPTED,
            sprintf(
                'Subscription #%d was renewing automatically on "%s" and will now be invoiced by '
                . 'FluentCart instead. Nothing charges this customer off-session.',
                $record->sourceSubscriptionId,
                $record->gateway === '' ? '(no gateway)' : $record->gateway,
            ),
        );
    }

    /**
     * @param array<string, mixed>                       $lifecycle
     * @param list<array{code: string, message: string}> $errors
     * @param list<array{code: string, message: string}> $warnings
     */
    private function gateLifecycle(
        SubscriptionRecord $record,
        array $lifecycle,
        array &$errors,
        array &$warnings,
    ): void {
        foreach ((array) ($lifecycle['errors'] ?? []) as $code) {
            $errors[] = $this->error((string) $code, $this->lifecycleMessage($record, (string) $code));
        }

        foreach ((array) ($lifecycle['warnings'] ?? []) as $code) {
            $warnings[] = $this->error((string) $code, $this->lifecycleMessage($record, (string) $code));
        }
    }

    // ──────────────────────────────────────────────
    // Verdict
    // ──────────────────────────────────────────────

    /**
     * @param list<array{code: string, message: string}> $errors
     */
    private function outcome(array $errors, PaymentMigrationDecision $payment): string
    {
        if ($errors !== []) {
            return SubscriptionAssessment::OUTCOME_BLOCKED;
        }

        if ($payment->outcome === PaymentMigrationDecision::OUTCOME_CONFIRMATION_REQUIRED) {
            return SubscriptionAssessment::OUTCOME_CONFIRMATION_REQUIRED;
        }

        return SubscriptionAssessment::OUTCOME_READY;
    }

    private function isTerminal(SubscriptionRecord $record): bool
    {
        return in_array(
            strtolower($record->status),
            ['cancelled', 'canceled', 'expired', 'switched'],
            true,
        );
    }

    /**
     * The two payment reasons section 9.3 turns into a block rather than a note.
     *
     * Everything else a strategy reports — an unverified vault, an unapproved
     * store mode — describes a mandate the record does not have, which the
     * manual outcome already handles safely. These two describe a schedule
     * nobody owns.
     */
    private function isLifecycleCode(string $code): bool
    {
        return in_array(
            $code,
            [
                SubscriptionAssessment::REASON_ACTIVE_NEXT_DATE_MISSING,
                SubscriptionAssessment::REASON_ACTIVE_NEXT_DATE_PAST,
            ],
            true,
        );
    }

    /**
     * What one payment reason means for this subscription, in a sentence.
     *
     * `manual_confirmation_required` earns its own because it is not a
     * diagnostic: it is the reason nothing was written, and on a target with no
     * provider credentials it is the reason nothing was written for the entire
     * live cohort. Every other refusal in this class ends "Nothing was
     * migrated"; this one used to end "Nothing charges this customer
     * off-session", which reads like a result.
     */
    private function paymentMessage(
        SubscriptionRecord $record,
        PaymentMigrationDecision $payment,
        string $code,
    ): string {
        if ($code === SubscriptionAssessment::REASON_MANUAL_NOT_ACCEPTED) {
            return sprintf(
                'Subscription #%d was renewing automatically on "%s". Nothing was migrated: bringing it '
                . 'across makes FluentCart raise an invoice instead of charging the customer silently, '
                . 'and that change has to be accepted before the subscription is created. Accept it for '
                . 'this cohort, then re-run.',
                $record->sourceSubscriptionId,
                $record->gateway === '' ? '(no gateway)' : $record->gateway,
            );
        }

        return sprintf(
            'Subscription #%d: the %s payment strategy reports "%s".',
            $record->sourceSubscriptionId,
            $payment->strategy,
            $code,
        );
    }

    private function lifecycleMessage(SubscriptionRecord $record, string $code): string
    {
        return match ($code) {
            SubscriptionAssessment::REASON_ACTIVE_NEXT_DATE_MISSING => sprintf(
                'Subscription #%d is active and has no next payment date, so nothing would own its next '
                . 'charge. Nothing was migrated: correct the date at the source and re-export, or record '
                . 'a deliberate next-action decision.',
                $record->sourceSubscriptionId,
            ),
            SubscriptionAssessment::REASON_ACTIVE_NEXT_DATE_PAST => sprintf(
                'Subscription #%d is active and its next payment date (%s) has already passed, so it is '
                . 'not activation-ready. Nothing was migrated: reconcile the outstanding charge first.',
                $record->sourceSubscriptionId,
                (string) $record->dates->nextPaymentUtc,
            ),
            SubscriptionAssessment::REASON_FINITE_TERM_STATE_CONFLICT => sprintf(
                'Subscription #%d has taken %d of its %d payments and the source still calls it "%s". '
                . 'Either the status or the count is wrong, and guessing which is how somebody is billed '
                . 'once more than they agreed to. Nothing was migrated.',
                $record->sourceSubscriptionId,
                $record->sourcePaymentCount,
                (int) ceil((int) $record->contract->finiteCycles / max(1, $record->contract->multiplier)),
                $record->status,
            ),
            SubscriptionAssessment::REASON_FINITE_TERM_FROM_PRODUCT => sprintf(
                'Subscription #%d records no term of its own, so its length was taken from the product '
                . 'it sells: %s. That describes today\'s catalogue rather than what this subscriber '
                . 'agreed to — check it if the plan has changed since they signed up.',
                $record->sourceSubscriptionId,
                self::describeTerm(
                    $record->contract->sourcePlan[SubscriptionRecordFactory::PLAN_PRODUCT_LENGTH] ?? null,
                ),
            ),
            SubscriptionAssessment::REASON_FINITE_TERM_UNDECLARED => $this->undeclaredTermMessage($record),
            default => sprintf('Subscription #%d: %s.', $record->sourceSubscriptionId, $code),
        };
    }

    /**
     * A WooCommerce Subscriptions length, in words.
     *
     * `0` is WCS's encoding of "unlimited", and rendering it as a bare `0` is
     * legible only to somebody who already knows that — which is nobody the
     * message is written for, and the opposite of the reason this warning
     * exists. On the Lapka source every one of the 564 records lands here.
     */
    private static function describeTerm(mixed $length): string
    {
        $periods = (int) $length;

        if ($length === null || $length === '') {
            return 'no length recorded';
        }

        if ($periods <= 0) {
            return 'unlimited';
        }

        return sprintf(
            $periods === 1 ? '%d billing period' : '%d billing periods',
            $periods,
        );
    }

    /**
     * Why nobody could answer, which is two different situations.
     *
     * A record exported before CartShift read the product has no evidence
     * either way — telling its operator "and neither does the product it sells"
     * asserts something nothing checked, and on the Lapka source it is simply
     * untrue: both products declare a term. So the two are told apart by
     * whether the exporter left its marker, and the older one is sent to
     * re-export rather than to WooCommerce to fix a product that is fine.
     */
    private function undeclaredTermMessage(SubscriptionRecord $record): string
    {
        $read = ($record->contract->sourcePlan[SubscriptionRecordFactory::PLAN_PRODUCT_READ] ?? '')
            === SubscriptionRecordFactory::PLAN_PRODUCT_READ_YES;

        if (!$read) {
            return sprintf(
                'Subscription #%d does not record how many payments it runs for, and this export was '
                . 'made before CartShift carried the product\'s length alongside it — so there is no '
                . 'evidence here either way. Nothing was migrated: export the source again with the '
                . 'current version, which reads the product too.',
                $record->sourceSubscriptionId,
            );
        }

        return sprintf(
            'Subscription #%d does not record how many payments it runs for, and neither does the '
            . 'product it sells. CartShift will not answer that for them: writing "unlimited" would be '
            . 'a contract nobody agreed to, and it would also stop the finite-term check from ever '
            . 'examining this record. Nothing was migrated: set the subscription length in WooCommerce '
            . 'on the subscription or on its product, then re-run.',
            $record->sourceSubscriptionId,
        );
    }

    /**
     * @return array{code: string, message: string}
     */
    private function error(string $code, string $message): array
    {
        return ['code' => $code, 'message' => $message];
    }

    /**
     * One fault, one entry.
     *
     * The payment decision and the write gate can both notice a past next
     * payment date — they consult the same helper — and reporting it twice
     * would have a receipt claim two problems where there is one.
     *
     * @param list<array{code: string, message: string}> $entries
     * @return list<array{code: string, message: string}>
     */
    private function deduplicate(array $entries): array
    {
        $seen = [];
        $out  = [];

        foreach ($entries as $entry) {
            if (isset($seen[$entry['code']])) {
                continue;
            }

            $seen[$entry['code']] = true;
            $out[] = $entry;
        }

        return $out;
    }
}

<?php

declare(strict_types=1);

namespace CartShift\Migrator;

defined('ABSPATH') || exit;

use CartShift\Domain\Mapping\SubscriptionMapper;
use CartShift\Domain\Scope\MigrationScope;
use CartShift\Domain\Subscription\InvalidSourceRecord;
use CartShift\Domain\Subscription\Payment\PaymentCapabilityProbe;
use CartShift\Domain\Subscription\Payment\PaymentEnvironment;
use CartShift\Domain\Subscription\Payment\PaymentStrategyRegistry;
use CartShift\Domain\Subscription\Source\WooDatasetRecordFactory;
use CartShift\Domain\Subscription\Source\WooSubscriptionDatasetSource;
use CartShift\Domain\Subscription\SubscriptionAssessment;
use CartShift\Domain\Subscription\SubscriptionAssessor;
use CartShift\Domain\Subscription\SubscriptionHistoryIndex;
use CartShift\Domain\Subscription\SubscriptionHistoryLinker;
use CartShift\Domain\Subscription\SubscriptionOrderImporter;
use CartShift\Domain\Subscription\SubscriptionReconciler;
use CartShift\Domain\Subscription\SubscriptionRecord;
use CartShift\Domain\Subscription\SubscriptionRecordFactory;
use CartShift\Domain\Subscription\SubscriptionSelection;
use CartShift\Domain\Subscription\SubscriptionWriter;
use CartShift\State\MigrationState;
use CartShift\Storage\IdMapRepository;
use CartShift\Storage\MigrationLogRepository;
use CartShift\Support\Constants;
use CartShift\Support\Enums\MigrationErrorCode;

final class SubscriptionMigrator extends AbstractMigrator
{
    private readonly SubscriptionRecordFactory $records;

    private readonly SubscriptionWriter $writer;

    /**
     * The one thing that decides which subscriptions this run is about.
     *
     * Injected, and shared by `countTotal()` and `fetchBatch()`. Those two used
     * to answer from different places entirely — a `COUNT(*)` against
     * `{prefix}wc_orders` and a page from `wcs_get_subscriptions()` — which on
     * a store using legacy CPT storage means the count is zero and the fetch is
     * not. Lapka is exactly that store. Two sources of truth for one question
     * is not a performance optimisation; it is a disagreement waiting for a
     * progress bar to expose it.
     */
    private readonly WooSubscriptionDatasetSource $source;

    /** @var int Offset the next page starts at — see cursorFor(). */
    private int $nextOffset = 0;

    /**
     * This run's order history, when the caller has one.
     *
     * Absent by default, and that absence is a statement rather than an
     * oversight. Section 6.2 fixes the import order — customers, products,
     * parent and renewal orders, paused subscriptions, transaction links,
     * then activation — and the phase that owns that order is the staging
     * command, not a per-record batch tick. A migrator that built the whole
     * dataset for itself would own it twice, and the two owners would
     * eventually disagree about which orders were in.
     *
     * So the history phase runs when, and only when, something has handed this
     * migrator the closure to run it against. Without one the behaviour is
     * exactly what it was: assess, stage, log.
     */
    private readonly ?SubscriptionHistoryIndex $history;

    public function __construct(
        IdMapRepository $idMap,
        MigrationLogRepository $log,
        MigrationState $migrationState,
        int $batchSize = Constants::DEFAULT_BATCH_SIZE,
        ?WooSubscriptionDatasetSource $source = null,
        ?SubscriptionHistoryIndex $history = null,
    ) {
        parent::__construct($idMap, $log, $migrationState, $batchSize);

        $this->records = new SubscriptionRecordFactory();
        $this->writer  = new SubscriptionWriter($idMap, new SubscriptionMapper());
        $this->history = $history;

        // Same-site migration, so the source key is `local` — the same value
        // schema v7 backfilled every existing mapping row to.
        $this->source = $source ?? new WooSubscriptionDatasetSource(Constants::DEFAULT_SOURCE_KEY);
    }

    #[\Override]
    protected function getEntityType(): string
    {
        return Constants::ENTITY_SUBSCRIPTION;
    }

    /**
     * How many subscriptions this run is about.
     *
     * Through the dataset source, which reads WooCommerce's public data-store
     * APIs and therefore gets whichever backend WooCommerce considers
     * authoritative. This used to be a `COUNT(*)` against `{prefix}wc_orders`
     * while `fetchBatch()` hydrated through WCS — on a store keeping orders in
     * the posts table, as Lapka does, that table is empty, `COUNT(*)` returns
     * zero, and the migration reports a total of nothing while cheerfully
     * migrating 564 subscriptions. Two answers to one question, and the wrong
     * one on the screen.
     *
     * The scope filter is the same callable `fetchBatch()` applies, so the two
     * cannot disagree about which rows are in.
     */
    #[\Override]
    protected function countTotal(): int
    {
        return $this->source->countSelected($this->selection(), $this->scopeFilter());
    }

    /**
     * Subscriptions keep OFFSET pagination. Deliberately.
     *
     * Every other migrator moved to a keyset cursor because its source is
     * either our own SQL or a query layer whose capabilities can be read off
     * the WooCommerce source in this repository. wcs_get_subscriptions() is not
     * one of those: WooCommerce Subscriptions is a paid add-on, it is not
     * installed here, and its argument vocabulary cannot be verified. Inventing
     * an `id > x` argument that may not exist would either be silently ignored
     * — re-reading the same page for ever — or throw.
     *
     * The offset is a position in the SELECTED sequence rather than in the raw
     * one, because the source applies the scope filter before slicing. The
     * scope can therefore no longer empty a page while the source still has
     * rows — the failure that used to end the entity early, losing every later
     * subscriber with nothing in the counters to show for it.
     *
     * Two things the source now owns rather than this method. The cursor
     * advances by rows CONSUMED, not by objects returned: a page of fifty where
     * one row will not hydrate hands back forty-nine, and advancing by
     * forty-nine would start the next page one row early and re-process a
     * subscription already handed over. And a page in which nothing at all
     * hydrates keeps consuming inside `page()` rather than returning empty,
     * because an empty batch is the orchestrator's only end-of-entity signal
     * and that is the same early finish arriving through a different door.
     *
     * Rows that could not be hydrated are logged. `countTotal()` counts them —
     * a selected subscription WooCommerce cannot hand back is still selected,
     * and hiding it would make the run look complete — so the total sits above
     * the processed count, and the log is where that difference gets a name.
     */
    #[\Override]
    public function fetchBatch(string|int|null $cursor, int $limit): array
    {
        $offset = max(0, (int) $cursor);

        $page = $this->source->page($this->selection(), $offset, $limit, $this->scopeFilter());

        $this->nextOffset = $offset + $page['consumed'];

        // Coded now. This used to be logged with a message and no code,
        // because attaching one meant editing MigrationErrorCode while another
        // task owned it — a row WooCommerce cannot hand back is a source row
        // that cannot be read, which is exactly what `invalid_source_record`
        // means, so it groups with the records the decoder refuses rather than
        // sitting in the log as prose nothing can count.
        foreach ($page['unhydratable'] as $subscriptionId) {
            $this->writeLog(
                $subscriptionId,
                'warning',
                sprintf(
                    'Subscription WC-#%d was selected but WooCommerce could not hydrate it, so it cannot be '
                    . 'migrated. It stays in the total: check whether the source row is intact.',
                    $subscriptionId,
                ),
                MigrationErrorCode::SubscriptionInvalidSourceRecord,
            );
        }

        return $page['records'];
    }

    /**
     * Every subscription in the source. The scope narrows it, not this.
     *
     * Selection and scope are different things and are kept apart on purpose.
     * The selection is what a cross-runtime export freezes and fingerprints;
     * the scope is what the owner ticked in the wizard. Folding the wizard's
     * choices into the selection would make the freeze marker depend on a UI
     * state that has nothing to do with the source.
     */
    private function selection(): SubscriptionSelection
    {
        return SubscriptionSelection::all(Constants::DEFAULT_SOURCE_KEY);
    }

    /**
     * The scope, as one predicate over the source's own index rows.
     *
     * This is the whole of what used to be `inScope()`, moved to where both the
     * count and the fetch can apply it. Null means "everything", which is what
     * an empty predicate has always meant.
     *
     * @return null|callable(array{id: int, status: string, customer_id: int, billing_email: string, created_ts: int|null}): bool
     */
    private function scopeFilter(): ?callable
    {
        $resolver = $this->scopeResolver();

        if ($resolver->subscriptionPredicate()->isEmpty()) {
            return null;
        }

        $scope = $resolver->scope();

        if ($scope->mode() === MigrationScope::MODE_SINCE) {
            // MigrationScope::since() is GMT. WC_DateTime carries the site
            // timezone, so date('Y-m-d H:i:s') on it renders site-local time and
            // comparing that string against a GMT bound shifts the boundary by
            // the site's offset — silently, and in whichever direction the site
            // happens to be configured. The index holds epochs, which are the
            // same instant on both sides regardless.
            $bound = (int) strtotime((string) $scope->since() . ' UTC');

            return static fn (array $row): bool => $row['created_ts'] !== null && $row['created_ts'] >= $bound;
        }

        $closed     = $resolver->closedCustomers();
        $registered = array_flip($closed['registered']);
        $guests     = array_flip($closed['guests']);

        // A disjunction, not a branch, because ScopeResolver::
        // seedSubscriptionPredicate() is a disjunction: `customer_id IN (…) OR
        // billing_email IN (…)`, with no `customer_id = 0` guard on the email
        // side. Count and fetch now run this same callable, so the two cannot
        // read the scope differently — which is what used to leave a
        // subscription counted and never migrated, total stuck above processed,
        // silently and for ever.
        //
        // The gap the disjunction covers is real rather than theoretical:
        // closedCustomers() keeps its *derived* sets disjoint, but an owner is
        // free to type the email of a registered account into guest_emails, and
        // nothing filters it out. The resolver's reading is also the one the
        // order side already applies — seedOrderPredicate() ORs the same two
        // sets — so mirroring it here keeps subscriptions consistent with the
        // orders they belong to. If such a subscription's customer was not
        // selected, processRecord() skips it with a logged warning: visible,
        // counted, and nothing like a number that never moves.
        return static function (array $row) use ($registered, $guests): bool {
            if ($row['customer_id'] > 0 && isset($registered[$row['customer_id']])) {
                return true;
            }

            // Lower-cased on both sides: MigrationScope normalises the picked
            // emails the same way, and a case mismatch here drops a subscriber.
            return isset($guests[strtolower(trim($row['billing_email']))]);
        };
    }

    /**
     * Hydrate exactly these subscription IDs, for a retry run.
     *
     * This is the one migrator whose retry does not go through the same door as
     * its batch fetch, and it is an improvement rather than a compromise:
     * fetchBatch() has to page blind through wcs_get_subscriptions() because an
     * `id > x` argument cannot be verified against an add-on that is not
     * installed, whereas hydrating a known ID needs only wcs_get_subscription(),
     * whose single-argument signature is stable and documented.
     *
     * Both function_exists() guards stay. Without WooCommerce Subscriptions
     * there is nothing to hydrate, and a retry that quietly returned nothing
     * would look like success — so the reason is written to the log against the
     * retry run rather than left to inference.
     *
     * The offset cursor is deliberately not moved: a retry paginates an ID list.
     *
     * @param array<int, string|int> $wcIds
     *
     * @return list<object>
     */
    #[\Override]
    public function fetchByIds(array $wcIds): array
    {
        $ids = self::normalizeIntIds($wcIds);

        if ($ids === []) {
            return [];
        }

        if (!function_exists('wcs_get_subscriptions') || !function_exists('wcs_get_subscription')) {
            $this->writeLog(
                0,
                'warning',
                sprintf(
                    'Cannot retry %d subscription(s): WooCommerce Subscriptions is not active, '
                    . 'so there is nothing to hydrate them from.',
                    count($ids),
                ),
            );

            return [];
        }

        $subscriptions = [];

        foreach ($ids as $id) {
            $subscription = wcs_get_subscription($id);

            if (is_object($subscription)) {
                $subscriptions[] = $subscription;
            }
        }

        return $subscriptions;
    }

    /**
     * The "cursor" here is the offset the next page starts at.
     *
     * It is computed in fetchBatch() rather than derived from the record,
     * because an offset is a property of the page, not of any row in it. The
     * orchestrator calls this immediately after fetchBatch() with a record from
     * that same batch, so the pairing holds. It is monotonic whenever a batch
     * is non-empty, which is the only case the orchestrator asks about.
     */
    #[\Override]
    public function cursorFor(mixed $record): string|int
    {
        return $this->nextOffset;
    }

    /**
     * Validate a subscription without creating any FluentCart records.
     *
     * The preview and the run ask the same assessor the same question and get
     * the same answer with the same reason code, because they are the same call
     * — a preview that promises what the run refuses is worse than no preview
     * at all. This used to check "already migrated" and "has a customer" and
     * nothing else, so a scope that selected subscriptions without their parent
     * orders previewed as "would create" for every record and then migrated
     * none of them.
     *
     * The dry run populates the ID map with simulated rows
     * (MigrationOrchestrator::run(), the `$isDryRun` branch) and entities are
     * validated in dependency order, so by the time subscriptions are reached
     * the references this resolves are exactly the ones the real run will find.
     *
     * @param \WC_Subscription $subscription
     */
    #[\Override]
    public function validateRecord(mixed $subscription): bool
    {
        $wcId = (int) $subscription->get_id();

        if ($this->idMap->getFcId(Constants::ENTITY_SUBSCRIPTION, (string) $wcId)) {
            $this->writeLog($wcId, 'dry-run', 'dry-run: already migrated, would skip.', MigrationErrorCode::AlreadyMigrated);

            return false;
        }

        $record = $this->hydrate($subscription);

        if ($record instanceof InvalidSourceRecord) {
            $this->reportInvalid($wcId, $record, 'dry-run');

            return false;
        }

        $assessment = $this->assessor()->assess($record);

        $this->report($wcId, $assessment, 'dry-run');

        if (!$assessment->isStageable()) {
            return false;
        }

        $this->writeLog($wcId, 'dry-run', sprintf('dry-run: would create subscription WC-#%d.', $wcId));

        return true;
    }

    /**
     * Assess, then — and only then — write.
     *
     * The whole of plan section 9.3 sits between those two clauses. There is no
     * arm of this method that reaches the writer with a failed gate: a blocked
     * assessment returns false here, and `SubscriptionWriter::stage()` throws
     * on one anyway, because "block before write" is worth stating twice when
     * the alternative is a row FluentCart bills against a blank line.
     *
     * @param \WC_Subscription $subscription
     */
    #[\Override]
    public function processRecord(mixed $subscription): int|false
    {
        $wcId = (int) $subscription->get_id();

        $staged = $this->idMap->getFcId(Constants::ENTITY_SUBSCRIPTION, (string) $wcId);

        $record = $this->hydrate($subscription);

        if ($record instanceof InvalidSourceRecord) {
            $this->reportInvalid($wcId, $record, 'warning');

            return false;
        }

        // Already staged is not already finished. A record whose history phase
        // reported `dataset_missing_parent_order` or `history_count_mismatch` in
        // an earlier run has a `fct_subscriptions` row and no linked charges,
        // and returning here would mean the operator's remedy — export the
        // missing order, re-run — could never take effect: the subscription is
        // skipped for ever and its bill count is never revisited. Section 10
        // requires the reconciliation to be rerunnable, and rerunnable has to
        // mean through the door a re-run actually comes in by.
        //
        // Everything the history phase does is idempotent — orders are found in
        // the ID map, transactions are only written when the column differs, and
        // the reconciler creates nothing — so a record that was already correct
        // costs a few reads and changes nothing.
        if ($staged) {
            $this->writeLog($wcId, 'skipped', 'Already migrated.', MigrationErrorCode::AlreadyMigrated);

            $this->linkHistory($wcId, $record, $staged);

            return false;
        }

        $assessment = $this->assessor()->assess($record);

        $this->report($wcId, $assessment, 'warning');

        if (!$assessment->isStageable()) {
            return false;
        }

        try {
            $id = $this->writer->stage($record, $assessment, $this->migrationId());
        } catch (\LogicException $exception) {
            // The writer's own last-line guard: the payload arrived without
            // something `fct_subscriptions` declares NOT NULL, which after a
            // `ready` assessment means a callback on
            // `cartshift/mapper/subscription` removed it. Nothing was written.
            // Reported under the usual code so it groups with every other
            // missing-reference refusal rather than surfacing as a stack trace.
            $this->writeLog(
                $wcId,
                'warning',
                $exception->getMessage(),
                MigrationErrorCode::SubscriptionRequiredReferenceMissing,
            );

            return false;
        }

        $this->writeLog($wcId, 'success', sprintf(
            'Migrated subscription #%d (FC ID: %d) - Status: %s, intended: %s.',
            $wcId,
            $id,
            (string) ($assessment->lifecycle['status'] ?? ''),
            (string) ($assessment->lifecycle['intended_status'] ?? ''),
        ));

        $this->linkHistory($wcId, $record, $id);

        return $id;
    }

    /**
     * Plan section 10, once the destination row exists.
     *
     * Import the parent and renewal orders, attach their succeeded positive
     * charges to this subscription, then reconcile three counts. Never
     * `SubscriptionService::syncSubscriptionStates()`: it completes finite
     * terms, clears dates, and manufactures one with `guessNextBillingDate()`
     * for any subscription whose next date is empty — which is 360 of the 564
     * preserved Lapka records.
     *
     * A mismatch is a warning rather than a failure, and the difference is the
     * subscriber: the row is written, staged paused, and honest about a payment
     * count nobody may guess. A missing order payload is the same shape of
     * problem one step earlier, and reported with the dataset code that names
     * it rather than with a number invented to fill the hole.
     */
    private function linkHistory(int $wcId, SubscriptionRecord $record, int $subscriptionId): void
    {
        if ($this->history === null) {
            return;
        }

        $imported = (new SubscriptionOrderImporter($this->idMap))
            ->import($record, $this->history, $this->migrationId());

        foreach ($imported['failures'] as $failure) {
            $this->writeLog(
                $wcId,
                'warning',
                sprintf(
                    'Subscription #%d names %s order %d and the dataset %s, so no history was imported and '
                    . 'its payment count was left alone.',
                    $wcId,
                    (string) $failure['relationship'],
                    (int) $failure['source_order_id'],
                    ((string) $failure['reason']) === SubscriptionHistoryIndex::REASON_NO_LINE_ITEMS
                        ? 'carries it with no line items'
                        : 'does not carry it',
                ),
                self::codeFor((string) $failure['code']),
            );
        }

        if ($imported['failures'] !== []) {
            return;
        }

        $linked = (new SubscriptionHistoryLinker($this->idMap))
            ->link($record, $this->history, $subscriptionId, $imported['orders']);

        $result = (new SubscriptionReconciler($this->history, $this->idMap))
            ->reconcile($record, $subscriptionId);

        if ($result->reconciled) {
            return;
        }

        // How many charges were actually attached, said out loud. It is the
        // first number an operator wants on a mismatch, and it is the one that
        // separates the two causes: a history that never came across, and a
        // history that came across and did not link — an order adopted through
        // the `invoice_no` probe whose transaction was never in the ID map, so
        // FluentCart counts a cycle nobody can see is missing.
        $detail = sprintf(
            ' %d of %d succeeded charge(s) in the imported history are linked to this subscription.',
            count($linked['linked']),
            $result->includedPaidOrderCount,
        );

        foreach ($result->reasonCodes as $code) {
            $this->writeLog($wcId, 'warning', $result->message . $detail, self::codeFor($code));
        }
    }

    /**
     * One source subscription as a record, with its typed relationships when —
     * and only when — there is a history to match them against.
     *
     * The preview and the run share this method for the same reason they share
     * the assessor: a preview that reads the source differently from the run is
     * a preview of something else.
     *
     * The typed relationship read is gated on `$this->history` rather than
     * always-on, and the gate is doing real work. Reading it costs four
     * `get_related_orders()` calls per subscription — 2,256 extra WooCommerce
     * queries across the Lapka cohort — which buys nothing at all when no
     * history phase is going to run, and changes the record's fingerprint for
     * every caller that has never asked for one.
     *
     * When a history IS present the four typed calls are mandatory. Section 6.2
     * requires one call per relationship because `get_related_orders()` flattens
     * its grouped result and discards the type, and section 4.8 records that WCS
     * renewal orders carry no useful `post_parent` — so without them a record
     * knows about its parent order and about none of its 4,702 renewals.
     */
    private function hydrate(object $subscription): SubscriptionRecord|InvalidSourceRecord
    {
        if ($this->history === null) {
            return $this->records->subscriptionFromWoo(Constants::DEFAULT_SOURCE_KEY, $subscription);
        }

        return $this->records->subscriptionFromWoo(
            Constants::DEFAULT_SOURCE_KEY,
            $subscription,
            (new WooDatasetRecordFactory($this->records))->relatedOrdersByType($subscription),
        );
    }

    /**
     * The gate, built fresh per record so a batch tick reads current settings.
     *
     * `manualFallbackConfirmed` is left at `PaymentEnvironment`'s own `false`,
     * and that is the whole point of the default living there rather than here.
     * `ManualPaymentStrategy::assess()` reads
     * `$record->requiresManualRenewal || $environment->manualFallbackConfirmed`,
     * so overriding it to `true` would turn every previously-automatic live
     * record from `confirmation_required` — which this writer refuses — into
     * `ready`. On a target with no provider credentials and no approved
     * settings hash, which is every target until an operator supplies both,
     * that is the entire live cohort.
     *
     * Section 8.4 is explicit that manual output "remains
     * `confirmation_required` until the operator accepts the behaviour change
     * AND the cutover receipt proves source auto-renewal was disabled". A
     * warning written on every record is a notification, not consent, and a
     * default cannot assert the second half at all. Section 11 Phase A puts
     * accepting a deliberate manual fallback in a separate, explicit
     * configuration write that is never part of a run.
     *
     * So this migrates zero live subscriptions until somebody says so, which is
     * the requirement rather than a gap. `cartshift/subscription/manual_fallback_confirmed`
     * is where that acceptance is expressed, and it is opt-in in the direction
     * that costs nothing to get wrong.
     */
    private function assessor(): SubscriptionAssessor
    {
        $environment = new PaymentEnvironment(
            capabilities: new PaymentCapabilityProbe(),
            settingsFingerprint: '',
            approvedSettingsFingerprint: null,
            verifiers: [],
            verifiedWebhookOwners: [],
            /** @see 'cartshift/subscription/manual_fallback_confirmed' */
            manualFallbackConfirmed: (bool) apply_filters(
                'cartshift/subscription/manual_fallback_confirmed',
                false,
            ),
        );

        /** @see 'cartshift/subscription/payment_environment' */
        $environment = apply_filters('cartshift/subscription/payment_environment', $environment);

        return new SubscriptionAssessor(
            $this->idMap,
            PaymentStrategyRegistry::withDefaults(),
            $environment instanceof PaymentEnvironment ? $environment : new PaymentEnvironment(
                capabilities: new PaymentCapabilityProbe(),
                settingsFingerprint: '',
            ),
        );
    }

    /**
     * Write one log row per distinct reason, errors first.
     *
     * One event, one row: the assessor already deduplicates by code, so a fault
     * both the payment decision and the write gate noticed is reported once.
     * Warnings are written whether or not the record was staged — an
     * undeclared term matters just as much on a subscription that migrated as
     * on one that did not.
     */
    private function report(int $wcId, SubscriptionAssessment $assessment, string $status): void
    {
        foreach ($assessment->errors as $error) {
            $this->writeLog(
                $wcId,
                $status,
                $this->prefix($status, (string) $error['message']),
                self::codeFor((string) $error['code']),
            );
        }

        foreach ($assessment->warnings as $warning) {
            $this->writeLog(
                $wcId,
                $status === 'dry-run' ? 'dry-run' : 'warning',
                $this->prefix($status, (string) $warning['message']),
                self::codeFor((string) $warning['code']),
            );
        }
    }

    /**
     * A source row that never became a record at all.
     *
     * One row per reason code, each saying what that code means for this
     * subscription rather than repeating the code back. The malformed Lapka
     * record — no line item, no parent order — is the shape this exists for,
     * and "required_reference_missing" on its own would send an owner looking
     * for a mapping problem that is not there.
     */
    private function reportInvalid(int $wcId, InvalidSourceRecord $record, string $status): void
    {
        foreach ($record->reasonCodes as $code) {
            $this->writeLog(
                $wcId,
                $status,
                $this->prefix($status, $this->describeInvalid($wcId, $record, $code)),
                self::codeFor($code),
            );
        }
    }

    private function describeInvalid(int $wcId, InvalidSourceRecord $record, string $code): string
    {
        $snapshot = $record->safeSnapshot;

        return match ($code) {
            SubscriptionRecordFactory::REASON_REQUIRED_REFERENCE_MISSING => sprintf(
                'Subscription #%d is missing what FluentCart requires on every subscription: %s. '
                . 'Nothing was migrated. Repair the subscription in WooCommerce, or decide by hand '
                . 'which contract it should become.',
                $wcId,
                implode('; ', self::missingSourceFacts($snapshot)),
            ),
            SubscriptionRecordFactory::REASON_CUSTOMER_EMAIL_MISSING => sprintf(
                'Subscription #%d carries no billing email, which is the only thing that identifies its '
                . 'owner. Nothing was migrated.',
                $wcId,
            ),
            SubscriptionRecordFactory::REASON_UNSUPPORTED_CADENCE => sprintf(
                'Subscription #%d bills every %s %s, and FluentCart has no equivalent. Nothing was '
                . 'migrated: collapsing it to the nearest interval would charge this customer on a '
                . 'schedule they never agreed to.',
                $wcId,
                (string) ($snapshot['billing_interval'] ?? '?'),
                (string) ($snapshot['billing_period'] ?? '(no period)'),
            ),
            default => sprintf(
                'Subscription #%d could not be read as a subscription at all (%s). Nothing was '
                . 'migrated. Repair the source record, then re-run.',
                $wcId,
                $code,
            ),
        };
    }

    /**
     * The source facts behind a decode refusal, in words.
     *
     * Read off the record's own `safeSnapshot`, which exists for exactly this:
     * enough non-secret detail to go and repair the row, and nothing more.
     *
     * @param array<string, mixed> $snapshot
     * @return list<string>
     */
    private static function missingSourceFacts(array $snapshot): array
    {
        $facts = [];

        if ((int) ($snapshot['item_count'] ?? 0) === 0) {
            $facts[] = 'it has no line item, so there is no product, variant or item name to give '
                . 'FluentCart';
        }

        if ((int) ($snapshot['parent_order_id'] ?? 0) <= 0) {
            $facts[] = 'it has no parent order';
        }

        return $facts === [] ? ['a required reference is missing or unreadable'] : $facts;
    }

    private function prefix(string $status, string $message): string
    {
        return $status === 'dry-run' ? 'dry-run: ' . $message : $message;
    }

    /**
     * A section 9.4 reason code as the log's own vocabulary.
     *
     * The two vocabularies are deliberately not the same size. Section 9.4 has
     * one code per distinct provider fault so that retry logic can key off
     * them, and the log groups those into one heading because every one of them
     * sends the operator to the same screen. The exact code is never lost — it
     * is in the message.
     */
    private static function codeFor(string $reasonCode): MigrationErrorCode
    {
        return MigrationErrorCode::tryFrom($reasonCode) ?? MigrationErrorCode::SubscriptionPaymentNotReady;
    }
}

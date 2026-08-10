<?php

declare(strict_types=1);

namespace CartShift\Domain\Subscription;

defined('ABSPATH') || exit;

use CartShift\Domain\Mapping\MappingSetValidator;
use CartShift\Domain\Mapping\ProductMapDecision;
use CartShift\Domain\Migration\MappingPromoter;
use CartShift\Domain\Migration\MigrationOrchestratorFactory;
use CartShift\Domain\Scope\MigrationScope;
use CartShift\Domain\Scope\ScopeResolver;
use CartShift\Domain\Subscription\Payment\PaymentCapabilityProbe;
use CartShift\Domain\Subscription\Payment\PaymentEnvironment;
use CartShift\Domain\Subscription\Payment\PaymentMigrationDecision;
use CartShift\Domain\Subscription\Payment\PaymentStrategyRegistry;
use CartShift\Domain\Subscription\Source\WooDatasetRecordFactory;
use CartShift\Storage\IdMapRepository;
use CartShift\Storage\ProductMapRepository;
use CartShift\Support\Constants;
use CartShift\Support\DatabaseTransaction;
use FluentCart\App\Models\Subscription;

/**
 * The staged handoff — plan section 11's phases B through E, as five commands
 * and one receipt.
 *
 * THE RULE THE WHOLE CLASS EXISTS FOR. No source subscription is disabled before
 * its destination row and history are ready, and no target subscription is
 * activated before its source automatic owner is disabled or explicitly
 * transferred. Every ordering decision below follows from that sentence, and an
 * interruption at any command boundary leaves source billing authoritative or
 * the destination paused — never both eligible to charge.
 *
 * WHY THE RECEIPT IS WRITTEN BEFORE THE FIRST ROW. `stage()` persists an
 * `assessed` receipt before it creates a customer, an order or a subscription.
 * A run that dies halfway therefore leaves a receipt that says "assessed", a
 * source that still bills, and a destination that is paused; the next run reads
 * that receipt, revalidates every fingerprint, and finishes. A receipt written
 * at the end would have left the same half-imported destination with nothing at
 * all to describe it.
 *
 * WHAT `stage()` OWNS. Section 6.2's import order, in one place, because it is
 * the phase that has the whole dataset: customers, then mapping validation, then
 * parent and renewal orders with their items and transactions, then paused
 * subscriptions, then transaction-to-subscription links, then reconciliation.
 * Lifecycle activation is deliberately NOT here — it is `activate()`, on the
 * far side of the source release.
 *
 * WHAT IT NEVER DOES. It never writes a FluentCart setting to make an approval
 * fit, never clears a source payment method to make a release succeed, and never
 * deletes a source invoice to make a rollback look tidy.
 */
final class SubscriptionCutover
{
    /** Section 9.4, History/cutover. */
    public const string REASON_MAINTENANCE_UNCONFIRMED = SourceRenewalGuard::REASON_MAINTENANCE_UNCONFIRMED;
    public const string REASON_RELEASE_UNVERIFIED = SourceRenewalGuard::REASON_RELEASE_UNVERIFIED;
    public const string REASON_SOURCE_FINGERPRINT_CHANGED = SourceRenewalGuard::REASON_FINGERPRINT_CHANGED;

    /** Section 9.4, Payment. */
    public const string REASON_SETTINGS_NOT_APPROVED = CutoverReceipt::REASON_SETTINGS_NOT_APPROVED;

    /** Section 9.4, Contract/mapping. */
    public const string REASON_VARIATION_COLLISION = MappingSetValidator::ERROR_COLLISION;

    /** An unexpected destination write failed inside a per-subscription transaction. */
    public const string REASON_DATABASE_WRITE_FAILED = 'database_write_failed';

    /** A SHA-256, written the one way this plugin writes them. */
    private const string SHA256 = '/^[0-9a-f]{64}$/';

    private readonly RuntimeCompatibilityProbe $probe;

    /**
     * Injected, or built per call against the source key being staged.
     *
     * Null is the ordinary case rather than an oversight: mapping decisions are
     * scoped by source key (schema v7), and a repository fixed to `local` at
     * construction time would read somebody else's mappings on a cross-site run
     * — the exact confusion the column was added to prevent.
     */
    private readonly ?ProductMapRepository $productMap;

    /**
     * Injected only by tests, built per call for the same reason $productMap is.
     *
     * A promoter carries its ProductMapRepository, so one fixed at construction
     * time would promote somebody else's decisions on a cross-site run — and
     * it also carries the IdMapRepository, which `stage()` does not build until
     * it knows the source key.
     */
    private readonly ?MappingPromoter $promoter;

    /** @var (\Closure(int): ?object)|null */
    private readonly ?\Closure $sourceLoader;

    /** @var (\Closure(object, string): ?string)|null */
    private readonly ?\Closure $sourceFingerprint;

    private readonly SourceRenewalGuard $guard;

    /**
     * @param callable(int): ?object          $sourceLoader      How to find a WCS subscription by ID.
     * @param callable(object, string): ?string $sourceFingerprint How to re-derive a source record's fingerprint.
     */
    public function __construct(
        ?RuntimeCompatibilityProbe $probe = null,
        ?ProductMapRepository $productMap = null,
        ?callable $sourceLoader = null,
        ?callable $sourceFingerprint = null,
        ?SourceRenewalGuard $guard = null,
        ?MappingPromoter $promoter = null,
    ) {
        $this->probe             = $probe ?? new RuntimeCompatibilityProbe();
        $this->productMap        = $productMap;
        $this->sourceLoader      = $sourceLoader === null ? null : $sourceLoader(...);
        $this->sourceFingerprint = $sourceFingerprint === null ? null : $sourceFingerprint(...);
        $this->guard             = $guard ?? new SourceRenewalGuard();
        $this->promoter          = $promoter;
    }

    // ──────────────────────────────────────────────
    // Phase B: stage
    // ──────────────────────────────────────────────

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function stage(SubscriptionDatasetSource $dataset, array $options): array
    {
        $sourceKey   = (string) ($options['source_key'] ?? Constants::DEFAULT_SOURCE_KEY);
        $receiptPath = (string) ($options['receipt_path'] ?? '');
        $migrationId = (string) ($options['migration_id'] ?? '');
        $checksum    = (string) ($options['package_checksum'] ?? '');
        $approval    = $options['approve_system_settings'] ?? null;

        $target              = $this->probe->inspect(RuntimeCompatibilityProbe::ROLE_TARGET);
        $settingsFingerprint = $target->fingerprint();

        // Refused before the dataset is even opened. A malformed or stale
        // approval is an operator error about the target, and reading 564
        // subscriptions to tell them so would only delay the sentence.
        $approvalFailures = self::approvalFailures($approval, $settingsFingerprint);

        if ($approvalFailures !== []) {
            return self::refusal($approvalFailures);
        }

        $selectionOption = $options['selection'] ?? null;
        $selection = $selectionOption instanceof SubscriptionSelection
            ? $selectionOption
            : (is_array($selectionOption)
                ? SubscriptionSelection::fromArray($selectionOption, $sourceKey)
                : SubscriptionSelection::all($sourceKey));

        if (!hash_equals($sourceKey, $selection->sourceKey)) {
            return self::refusal([[
                'code'    => CutoverReceipt::REASON_SOURCE_FINGERPRINT_CHANGED,
                'message' => sprintf(
                    'The stage source key is %s but the selection belongs to %s. Nothing was written.',
                    $sourceKey,
                    $selection->sourceKey,
                ),
            ]]);
        }

        $records   = self::materialise($dataset->records($selection));

        // Section 6.2, step 2, taken across the WHOLE decision set rather than
        // one product at a time — and with the contracts read off the DATASET,
        // because this runtime has no WooCommerce to ask. Without the contracts
        // every claim keys as one-time and a monthly/yearly collision on one
        // target variation validates clean, which is the 188-subscriber defect
        // `MappingSetValidator` exists to catch.
        $mapping = (new MappingSetValidator(self::contractIndex($records)))
            ->validate($this->decisions($sourceKey));

        if (!$mapping->isValid()) {
            return self::refusal(array_map(
                static fn (array $error): array => [
                    'code'    => (string) ($error['code'] ?? self::REASON_VARIATION_COLLISION),
                    'message' => (string) ($error['message'] ?? 'A mapping decision is not valid.'),
                ],
                $mapping->errors,
            ));
        }

        $context = [
            'package_checksum'            => $checksum,
            'selection_fingerprint'       => $selection->fingerprint(),
            'mapping_fingerprint'         => $mapping->fingerprint(),
            'target_settings_fingerprint' => $settingsFingerprint,
        ];

        $loaded = self::loadOrBegin($receiptPath, $sourceKey, $context, $selection);

        if ($loaded['receipt'] === null) {
            return self::refusal($loaded['failures']);
        }

        $receipt = $loaded['receipt'];

        // The same comparison the other four commands make, so `stage` is not
        // the one door where a wrong key surfaces as `source_fingerprint_changed`
        // and sends the operator off to re-export. `stage` always has a key —
        // from the package manifest, or from the flag, or from the default — so
        // it is compared unconditionally rather than only when it was typed.
        $mismatch = self::sourceKeyMismatch(['source_key' => $sourceKey], $receipt);

        if ($mismatch !== []) {
            return self::refusal($mismatch, $receipt);
        }

        if (is_string($approval) && $approval !== '') {
            $receipt = $receipt->withApprovedSettingsFingerprint($approval);
        }

        $failures = $receipt->transitionFailures(CutoverReceipt::STATE_STAGED, $context);

        if ($failures !== []) {
            return self::refusal($failures, $receipt);
        }

        // THE CLOSURE GATE, ON THE WRITE PATH AT LAST.
        //
        // `DatasetClosureValidator` was built for section 6.2 and `stage()`
        // called none of its three construction sites: for `--source=package` it
        // checked `$package['ok']` and threw the closure report away, and for
        // `--source=live` it went straight in. Most closure rules survived only
        // because `SubscriptionAssessor` reproduces them per record.
        //
        // Two cannot be reproduced that way, because they are not about any one
        // record. Two subscriptions sharing a parent order both assess ready —
        // the importer adopts the already-mapped FluentCart order for the second
        // — and two `fct_subscriptions` rows land on one `parent_order_id`,
        // which FluentCart's renewal service assumes never happens.
        // `SubscriptionHistoryIndex::claim()`'s own docblock says the validator
        // blocks it "in the meantime", and the validator was not wired. A
        // duplicated reference is last-write-wins, silently.
        //
        // Refused on SET-level faults only. A malformed subscription blocks its
        // own entry further down and always did; the reference cohort migrates
        // 563 of 564 and must keep doing so.
        $closure = self::closureFailures($sourceKey, $records);

        if ($closure !== []) {
            return self::refusal($closure, $receipt);
        }

        // THE RECEIPT COMES FIRST. Everything below this line writes to the
        // destination, and an interruption in the middle of it must leave
        // something behind that says what was being attempted.
        $written = $receipt->write($receiptPath);

        if ($written['path'] === null) {
            return self::refusal(self::pathFailures($written['failures'], $receiptPath));
        }

        $idMap       = new IdMapRepository($sourceKey);
        $history     = SubscriptionHistoryIndex::fromRecords($sourceKey, $records);
        $environment = self::environment(
            $settingsFingerprint,
            is_string($approval) ? $approval : null,
            ($options['accept_manual_fallback'] ?? false) === true,
        );
        $assessor    = new SubscriptionAssessor($idMap, PaymentStrategyRegistry::withDefaults(), $environment);

        /** @var list<SubscriptionRecord> $subscriptions */
        $subscriptions = array_values(array_filter(
            $records,
            static fn (object $record): bool => $record instanceof SubscriptionRecord,
        ));

        // Section 6.2, step 1. A whole pass, before any order is imported: the
        // order importer resolves its own `customer_id` from the ID map, so a
        // customer resolved lazily beside its subscription would leave every
        // earlier order attached to nobody.
        $identities = [];

        foreach ($subscriptions as $record) {
            $identities[$record->sourceRef] = $this->resolveCustomer($idMap, $record, $migrationId);
        }

        // SECTION 6.2, STEP 2 — THE HALF THAT WRITES, AND THE ONE `stage` USED
        // TO SKIP ENTIRELY.
        //
        // The validation above is step 2's READ half: it refuses a decision set
        // that cannot be honoured. Nothing turned the surviving decisions into
        // the ID map rows every reader downstream resolves through, and in the
        // same-site route nothing here had to — `MigrationOrchestratorFactory
        // ::forRun()` promotes at run start, and `ProductMigrator` writes the
        // rest. The cross-runtime route has neither: its whole operator flow is
        // export → prepare-package → map → stage, and `stage` was the only
        // command left that could do it.
        //
        // So a rehearsal on 564 real subscriptions blocked all 564 on
        // `required_reference_missing`, with the operator's mapping decisions
        // sitting correct and complete in `cartshift_product_map`, because
        // `SubscriptionAssessor::resolve()` asks the ID map for the destination
        // product and variation and the ID map had never been told. The gate was
        // right; the step before it was missing.
        //
        // AFTER THE RECEIPT AND AFTER THE CUSTOMERS. Promotion writes — ID map
        // rows always, and an orphan variant into a hand-built product in the
        // one case that arises — so it belongs on the far side of "the receipt
        // comes first". BEFORE THE ORDER IMPORT, and that ordering is the whole
        // reason this is a separate step rather than something the assessor
        // could do lazily: `SubscriptionOrderImporter::createItems()` resolves
        // `post_id` and `object_id` through this same map, so a promotion that
        // ran after it would leave every line item pointing at nothing.
        //
        // The scope is `everything` and not a run's `MigrationState`. There is
        // no migration state in this runtime — the package IS the cohort, and it
        // was narrowed at export time. Promoting the decisions for this source
        // key is therefore exactly what a narrowed run would promote.
        $promotion = $this->promote($sourceKey, $idMap, $migrationId);

        // Section 6.2, step 3.
        $importer = new SubscriptionOrderImporter($idMap);
        $imports  = [];

        foreach ($subscriptions as $record) {
            if (($identities[$record->sourceRef]['status'] ?? '') === CustomerResolver::STATUS_BLOCKED) {
                continue;
            }

            $imports[$record->sourceRef] = $importer->import($record, $history, $migrationId);
        }

        // Sections 6.2 steps 4 to 6, per record, in that order.
        $entries = [];

        foreach ($subscriptions as $record) {
            $entries[] = $this->stageOne(
                $record,
                $assessor,
                $idMap,
                $history,
                $identities[$record->sourceRef] ?? [],
                $imports[$record->sourceRef] ?? null,
                $migrationId,
            );
        }

        foreach ($records as $record) {
            if ($record instanceof InvalidSourceRecord && $record->entityKind === SubscriptionRecord::KIND) {
                $entries[] = CutoverReceipt::entry([
                    'source_ref'   => $record->sourceRef,
                    'outcome'      => CutoverReceipt::OUTCOME_BLOCKED,
                    'state'        => CutoverReceipt::STATE_ASSESSED,
                    'reason_codes' => $record->reasonCodes,
                ]);
            }
        }

        $receipt = $receipt->withEntries($entries)->withState(CutoverReceipt::STATE_STAGED);

        return self::persist($receipt, $receiptPath, self::summarise($receipt) + self::mappingSummary($promotion));
    }

    // ──────────────────────────────────────────────
    // Phase C: cutover-source
    // ──────────────────────────────────────────────

    /**
     * Disable the source's automatic renewal, one subscription at a time.
     *
     * Runs in the WooCommerce runtime, which has no FluentCart at all — so the
     * only fingerprints revalidated here are the ones this side can compute: the
     * selection, and every subscription's own source fingerprint.
     *
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function cutoverSource(array $options): array
    {
        $receiptPath = (string) ($options['receipt_path'] ?? '');

        // BEFORE the receipt is even read, let alone before any mutation. The
        // acknowledgement is the operator stating that storefront and admin
        // renewal actions and the source cron/Action Scheduler worker are
        // paused. CartShift does not pause them and this flag does not either;
        // it records a statement, timestamped, in the receipt.
        if (($options['renewals_paused'] ?? false) !== true) {
            return self::refusal([[
                'code'    => self::REASON_MAINTENANCE_UNCONFIRMED,
                'message' => 'Pass --renewals-paused to state that storefront/admin renewal actions and the '
                    . 'source cron/Action Scheduler worker are paused. The flag records your statement; it '
                    . 'does not pause anything. Nothing was read and nothing was changed.',
            ]]);
        }

        $read = CutoverReceipt::read($receiptPath);

        if ($read['receipt'] === null) {
            return self::refusal(self::pathFailures($read['failures'], $receiptPath));
        }

        $receipt   = $read['receipt'];
        $sourceKey = $receipt->sourceKey ?: Constants::DEFAULT_SOURCE_KEY;

        $mismatch = self::sourceKeyMismatch($options, $receipt);

        if ($mismatch !== []) {
            return self::refusal($mismatch, $receipt);
        }

        $context = ['selection_fingerprint' => $receipt->selection()->fingerprint()];

        $failures = $receipt->transitionFailures(CutoverReceipt::STATE_SOURCE_RELEASED, $context);

        if ($failures !== []) {
            return self::refusal($failures, $receipt);
        }

        $receipt  = $receipt->withRenewalMaintenanceAcknowledged();
        $byRef    = self::byRef($receipt);
        $problems = [];

        foreach ($receipt->entries as $entry) {
            [$entry, $entryFailures] = $this->releaseOne($entry, $sourceKey);

            $byRef[(string) $entry['source_ref']] = $entry;
            $problems = array_merge($problems, $entryFailures);

            // AFTER EVERY SINGLE ONE. A crash halfway through the cohort must
            // not lose `previous_requires_manual_renewal`: a source that was
            // automatic and is now manual, described by a receipt that still
            // says "pending", is a subscription a later restore would put back
            // as manual — silently ending the auto-renewal of somebody who
            // never asked to stop paying. One file rewrite per subscription is
            // a cheap price for the flag being durable.
            //
            // AND THE WRITE IS CHECKED. The read above proves the file was
            // readable a moment ago and proves nothing about writing it: a
            // remount, a full disk or a changed mode all land here. Carrying on
            // would disable more sources whose previous state nothing records.
            $receipt = $receipt->withEntries(array_values($byRef));
            $lost    = self::writeFailures($receipt, $receiptPath, 'cutover-source');

            if ($lost !== []) {
                return self::refusal(array_merge($problems, $lost), $receipt);
            }
        }

        if ($problems !== []) {
            $written = $receipt->write($receiptPath);

            return self::refusal($problems, $receipt, $written['path']);
        }

        $receipt = $receipt->withState(CutoverReceipt::STATE_SOURCE_RELEASED);

        return self::persist($receipt, $receiptPath, self::summarise($receipt));
    }

    // ──────────────────────────────────────────────
    // Phase D: activate
    // ──────────────────────────────────────────────

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function activate(array $options): array
    {
        $receiptPath = (string) ($options['receipt_path'] ?? '');

        $read = CutoverReceipt::read($receiptPath);

        if ($read['receipt'] === null) {
            return self::refusal(self::pathFailures($read['failures'], $receiptPath));
        }

        $receipt   = $read['receipt'];
        $sourceKey = $receipt->sourceKey ?: Constants::DEFAULT_SOURCE_KEY;

        $mismatch = self::sourceKeyMismatch($options, $receipt);

        if ($mismatch !== []) {
            return self::refusal($mismatch, $receipt);
        }

        $context = [
            'selection_fingerprint'       => $receipt->selection()->fingerprint(),
            'mapping_fingerprint'         => (new MappingSetValidator())
                ->validate($this->decisions($sourceKey))
                ->fingerprint(),
            'target_settings_fingerprint' => $this->probe
                ->inspect(RuntimeCompatibilityProbe::ROLE_TARGET)
                ->fingerprint(),
        ];

        $failures = $receipt->transitionFailures(CutoverReceipt::STATE_ACTIVATED, $context);

        if ($failures !== []) {
            return self::refusal($failures, $receipt);
        }

        $byRef    = self::byRef($receipt);
        $problems = [];

        foreach ($receipt->entries as $entry) {
            // Never. A blocked record has no destination row, and inventing one
            // at activation time would be the P0 this plan opens with. A record
            // whose history did not reconcile has a row and an unverified bill
            // count, and section 10 step 7 says it stays paused.
            if (CutoverReceipt::isHeld($entry)) {
                continue;
            }

            // MARKED BEFORE IT IS ACTED ON, and the order is the whole point.
            // `restore-source` refuses once any entry reads `activated`, so a
            // crash between these two lines must leave the receipt over-stating
            // rather than under-stating what happened: an entry recorded as
            // activated that is in fact still paused costs one re-run of
            // `activate`, whereas an entry that is live and recorded as merely
            // released would let a restoration hand the source back while
            // FluentCart is already billing.
            $ref         = (string) $entry['source_ref'];
            $byRef[$ref] = ['state' => CutoverReceipt::STATE_ACTIVATED] + $entry;
            $receipt     = $receipt->withEntries(array_values($byRef));

            // AND THE MARK HAS TO LAND. The whole ordering argument above rests
            // on this write having happened; if it did not, the receipt still
            // says `source_released`, a later `restore-source` would be
            // permitted, and setting the status now would leave the source
            // restorable while FluentCart bills — both sides able to charge,
            // which is the one outcome this command exists to make impossible.
            $lost = self::writeFailures($receipt, $receiptPath, 'activate');

            if ($lost !== []) {
                return self::refusal(array_merge($problems, $lost), $receipt);
            }

            [$activated, $entryFailures] = $this->activateOne($entry);

            // The MARK is kept when activation failed, not rolled back. Nothing
            // here can tell "the row was missing so nothing happened" from "the
            // status was written and something after it threw", and the two
            // want opposite records. Keeping the mark is the safe direction: it
            // leaves `restore-source` refusing, which costs a re-run of
            // `activate`, where rolling it back could hand a source back while
            // FluentCart bills.
            $byRef[$ref] = $entryFailures === [] ? $activated : $byRef[$ref];
            $problems    = array_merge($problems, $entryFailures);
        }

        $receipt = $receipt->withEntries(array_values($byRef));

        if ($problems !== []) {
            $written = $receipt->write($receiptPath);

            return self::refusal($problems, $receipt, $written['path']);
        }

        $receipt = $receipt->withState(CutoverReceipt::STATE_ACTIVATED);

        return self::persist($receipt, $receiptPath, self::summarise($receipt));
    }

    // ──────────────────────────────────────────────
    // Phase E: reconcile
    // ──────────────────────────────────────────────

    /**
     * Compare what the source selected with what the target now holds.
     *
     * SECTION 11 PHASE E, WITH ONE DELIBERATE DEPARTURE FROM ITS WORDING. The
     * plan says "a successful run has zero unexplained or blocked records"; this
     * closes over BLOCKED ones and refuses over HELD ones, and the asymmetry is
     * the point. A blocked record was never migrated, is reported with its
     * reason codes, and is an expected outcome of a cohort containing one
     * malformed subscription — refusing over it would mean the reference cohort
     * could never be closed at all. A held record is a subscriber PAUSED IN
     * FLUENTCART AND STILL AUTO-BILLING IN WOOCOMMERCE: safe, because only one
     * side charges, and not finished.
     *
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function reconcile(array $options): array
    {
        $receiptPath = (string) ($options['receipt_path'] ?? '');

        $read = CutoverReceipt::read($receiptPath);

        if ($read['receipt'] === null) {
            return self::refusal(self::pathFailures($read['failures'], $receiptPath));
        }

        $receipt   = $read['receipt'];
        $sourceKey = $receipt->sourceKey ?: Constants::DEFAULT_SOURCE_KEY;

        $mismatch = self::sourceKeyMismatch($options, $receipt);

        if ($mismatch !== []) {
            return self::refusal($mismatch, $receipt);
        }

        $failures = $receipt->transitionFailures(CutoverReceipt::STATE_RECONCILED, [
            'selection_fingerprint' => $receipt->selection()->fingerprint(),
        ]);

        if ($failures !== []) {
            return self::refusal($failures, $receipt);
        }

        $summary = self::summarise($receipt);

        // Stamping a held entry `reconciled` closed the cohort with `ok: true`
        // over a subscriber nobody had resolved, and left the entry's own state
        // saying something false.
        $held = array_values(array_filter(
            $receipt->entries,
            static fn (array $entry): bool => CutoverEntryState::of($entry)->isHeldForHistory(),
        ));

        if ($held !== []) {
            return self::refusal([[
                'code'    => ReconciliationResult::REASON_HISTORY_COUNT_MISMATCH,
                'message' => sprintf(
                    '%d subscription(s) are staged and paused on the target while their source still bills, '
                    . 'because their history did not reconcile: %s. The cohort is not finished, so nothing '
                    . 'was closed. %s',
                    count($held),
                    implode(', ', array_slice(array_column($held, 'source_ref'), 0, 20))
                        . (count($held) > 20 ? ', …' : ''),
                    self::remedy($receipt, $sourceKey),
                ),
            ]], $receipt);
        }

        $entries = array_map(
            static fn (array $entry): array => ($entry['outcome'] ?? '') === CutoverReceipt::OUTCOME_BLOCKED
                ? $entry
                : ['state' => CutoverReceipt::STATE_RECONCILED] + $entry,
            $receipt->entries,
        );

        $receipt = $receipt->withEntries($entries)->withState(CutoverReceipt::STATE_RECONCILED);

        return self::persist($receipt, $receiptPath, $summary);
    }

    // ──────────────────────────────────────────────
    // restore-source
    // ──────────────────────────────────────────────

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function restoreSource(array $options): array
    {
        $receiptPath = (string) ($options['receipt_path'] ?? '');

        $read = CutoverReceipt::read($receiptPath);

        if ($read['receipt'] === null) {
            return self::refusal(self::pathFailures($read['failures'], $receiptPath));
        }

        $receipt   = $read['receipt'];
        $sourceKey = $receipt->sourceKey ?: Constants::DEFAULT_SOURCE_KEY;

        $mismatch = self::sourceKeyMismatch($options, $receipt);

        if ($mismatch !== []) {
            return self::refusal($mismatch, $receipt);
        }

        $failures = $receipt->transitionFailures(CutoverReceipt::STATE_SOURCE_RESTORED, [
            'selection_fingerprint' => $receipt->selection()->fingerprint(),
        ]);

        if ($failures !== []) {
            return self::refusal($failures, $receipt);
        }

        $byRef    = self::byRef($receipt);
        $problems = [];

        foreach ($receipt->entries as $entry) {
            $state = CutoverEntryState::of($entry);
            $ref   = $state->sourceRef;

            if (!$state->isRestorable()) {
                // Nothing to undo. A terminal record and an already-manual
                // source both land here, and both travel with the cohort so the
                // receipt stays internally consistent.
                $byRef[$ref] = $state->participates() && $state->state !== CutoverReceipt::STATE_ASSESSED
                    ? ['state' => CutoverReceipt::STATE_SOURCE_RESTORED] + $entry
                    : $entry;

                continue;
            }

            // INTENT FIRST, OUTCOME AFTER — and this is the one loop in the
            // class where the ordering is NOT the same as `activate`'s.
            //
            // `activate` marks before acting because over-stating an activation
            // is safe. For a restoration the direction inverts: if the outcome
            // write failed after the source had been handed back, the on-disk
            // receipt would still read `released`, `releaseSatisfied()` would
            // accept it, and `activate` from that receipt is structurally legal
            // — an automatic source and a live FluentCart subscription on the
            // same customer.
            //
            // The naive symmetric fix — marking `restored` before the guard —
            // trades that for a subscriber silently left on manual renewal for
            // ever, because the retry would skip the entry and `stage` would be
            // free to rebuild it. So neither answer is written first. What is
            // written first is the INTENT, and intent without an outcome reads
            // as unknown: not restorable-and-done, not stageable, not
            // activatable, and loud about needing a look.
            $byRef[$ref] = self::withRestoreIntent($entry);
            $receipt     = $receipt->withEntries(array_values($byRef));
            $lost        = self::writeFailures($receipt, $receiptPath, 'restore-source');

            if ($lost !== []) {
                return self::refusal(array_merge($problems, $lost), $receipt);
            }

            [$restored, $entryFailures] = $this->restoreOne($entry);

            $byRef[$ref] = $restored;
            $problems    = array_merge($problems, $entryFailures);

            $receipt = $receipt->withEntries(array_values($byRef));
            $lost    = self::writeFailures($receipt, $receiptPath, 'restore-source');

            if ($lost !== []) {
                return self::refusal(array_merge($problems, $lost), $receipt);
            }
        }

        $receipt = $receipt->withEntries(array_values($byRef));

        if ($problems !== []) {
            $written = $receipt->write($receiptPath);

            return self::refusal($problems, $receipt, $written['path']);
        }

        $receipt = $receipt->withState(CutoverReceipt::STATE_SOURCE_RESTORED);

        return self::persist($receipt, $receiptPath, self::summarise($receipt));
    }

    // ──────────────────────────────────────────────
    // One record at a time
    // ──────────────────────────────────────────────

    /**
     * @param array<string, mixed>      $identity
     * @param array<string, mixed>|null $import
     * @return array<string, mixed>
     */
    private function stageOne(
        SubscriptionRecord $record,
        SubscriptionAssessor $assessor,
        IdMapRepository $idMap,
        SubscriptionHistoryIndex $history,
        array $identity,
        ?array $import,
        string $migrationId,
    ): array {
        $base = [
            'source_ref'             => $record->sourceRef,
            'source_subscription_id' => $record->sourceSubscriptionId,
            'source_fingerprint'     => $record->fingerprint,
            'source_status'          => $record->status,
            'target_customer_id'     => $identity['customer_id'] ?? null,
        ];

        if (($identity['status'] ?? '') === CustomerResolver::STATUS_BLOCKED) {
            return CutoverReceipt::entry($base + [
                'outcome'      => CutoverReceipt::OUTCOME_BLOCKED,
                'state'        => CutoverReceipt::STATE_ASSESSED,
                'reason_codes' => [(string) $identity['reason_code']],
            ]);
        }

        if ($import !== null && $import['failures'] !== []) {
            // The parent order is recorded even here. The import refused before
            // writing anything for THIS subscription, but an earlier record may
            // already have brought the same order across, and an operator
            // reconciling a blocked record needs to know whether one is sitting
            // in FluentCart with no subscription pointing at it.
            return CutoverReceipt::entry($base + [
                'outcome'                => CutoverReceipt::OUTCOME_BLOCKED,
                'state'                  => CutoverReceipt::STATE_ASSESSED,
                'target_parent_order_id' => $idMap->getFcId(
                    Constants::ENTITY_ORDER,
                    (string) $record->parentOrderId,
                ),
                'reason_codes'           => array_column($import['failures'], 'code'),
            ]);
        }

        $assessment = $assessor->assess($record);
        $payment    = $assessment->payment;

        $base += [
            'payment_strategy'       => $payment->strategy,
            'collection_method'      => $payment->collectionMethod,
            'next_action_owner'      => $payment->nextActionOwner,
            'intended_status'        => (string) ($assessment->lifecycle['intended_status'] ?? ''),
            'terminal'               => (bool) ($assessment->lifecycle['terminal'] ?? false),
            'target_parent_order_id' => $idMap->getFcId(Constants::ENTITY_ORDER, (string) $record->parentOrderId),
        ];

        if (!$assessment->isStageable()) {
            // `confirmation_required` and `blocked` are different business
            // verdicts and the SAME receipt outcome, because the receipt is
            // about execution: neither produced a destination row, so neither
            // has a source to release or a subscription to activate, and
            // counting a record awaiting confirmation as "participating" would
            // make `activate` demand a source release for a row that does not
            // exist. The distinction is not lost — the reason codes carry it.
            return CutoverReceipt::entry($base + [
                'outcome'      => CutoverReceipt::OUTCOME_BLOCKED,
                'state'        => CutoverReceipt::STATE_ASSESSED,
                'reason_codes' => array_merge($assessment->errorCodes(), $assessment->warningCodes()),
            ]);
        }

        // Steps 4 to 6 are one subscription, not three vaguely related writes.
        // `SubscriptionWriter` opens its own nested boundary, while the linker
        // updates transactions and the reconciler may update `bill_count` after
        // the writer returns. Without this outer boundary the writer commits
        // first and a later exception leaves a subscription with partial
        // history. `DatabaseTransaction` depth-counts, so the inner commit does
        // not reach MySQL until all three steps have succeeded.
        DatabaseTransaction::begin();

        try {
            $subscriptionId = (new SubscriptionWriter($idMap))->stage($record, $assessment, $migrationId);

            // Section 6.2, steps 5 and 6. Only now: a transaction cannot name a
            // subscription that had no ID a moment ago.
            $linked = (new SubscriptionHistoryLinker($idMap))
                ->link($record, $history, $subscriptionId, $import['orders'] ?? []);

            $reconciliation = (new SubscriptionReconciler($history, $idMap))
                ->reconcile($record, $subscriptionId);

            DatabaseTransaction::commit();
        } catch (\Throwable $exception) {
            DatabaseTransaction::rollback();

            return CutoverReceipt::entry($base + [
                'outcome'      => CutoverReceipt::OUTCOME_BLOCKED,
                'state'        => CutoverReceipt::STATE_ASSESSED,
                'reason_codes' => [self::REASON_DATABASE_WRITE_FAILED],
            ]);
        }

        return CutoverReceipt::entry($base + [
            'outcome'                => $assessment->warningCodes() === []
                ? CutoverReceipt::OUTCOME_READY
                : CutoverReceipt::OUTCOME_CONFIRMED,
            'state'                  => CutoverReceipt::STATE_STAGED,
            'staged_status'          => (string) ($assessment->lifecycle['status'] ?? ''),
            'target_subscription_id' => $subscriptionId,
            'reason_codes'           => $assessment->warningCodes(),
            'source_release'         => [
                'required' => !(bool) ($assessment->lifecycle['terminal'] ?? false),
                'state'    => (bool) ($assessment->lifecycle['terminal'] ?? false)
                    ? CutoverReceipt::RELEASE_NOT_REQUIRED
                    : CutoverReceipt::RELEASE_PENDING,
            ],
            'history'                => [
                'related_orders'      => count($reconciliation->relatedOrderIds),
                'paid_orders'         => count($reconciliation->paidOrderIds),
                'linked_transactions' => count($linked['linked']),
                'reconciled'          => $reconciliation->reconciled,
                'reason_codes'        => $reconciliation->reasonCodes,
            ],
        ]);
    }

    /**
     * @param array<string, mixed> $entry
     * @return array{0: array<string, mixed>, 1: list<array{code: string, message: string}>}
     */
    private function releaseOne(array $entry, string $sourceKey): array
    {
        $state     = CutoverEntryState::of($entry);
        $sourceRef = $state->sourceRef;

        // Blocked, or staged with a history that did not reconcile. The second
        // is the one worth spelling out: `SubscriptionReconciler` refused to
        // write `bill_count` because the source payment count, the imported
        // paid orders and FluentCart's own arithmetic disagreed, so the
        // destination row carries a cycle count nobody has verified. Disabling
        // the source's billing against that is exactly what "no source is
        // disabled before its destination row AND HISTORY are ready" forbids.
        // The source keeps charging; the operator repairs the history and
        // re-runs `stage`.
        if ($state->isHeld()) {
            return [$entry, []];
        }

        // Idempotent: a release that already satisfies the invariant is not
        // performed again, because re-running the setter would rewrite
        // `released` as `already_manual` and lose which run made the change.
        if (!$state->needsRelease()) {
            return [['state' => CutoverReceipt::STATE_SOURCE_RELEASED] + $entry, []];
        }

        // Section 11 Phase C's PayPal/automatic arm. The remote schedule stays
        // authoritative and its webhook ownership has to move "according to the
        // verified source plugin contract" — and the PPCP plugin is not in the
        // restore, so there IS no verified contract. Guessing one would either
        // cancel a live PayPal agreement or leave two systems charging. Refused,
        // by name, until somebody produces the real adapter.
        if ((string) ($entry['next_action_owner'] ?? '') === PaymentMigrationDecision::OWNER_REMOTE_SCHEDULE) {
            return [
                self::withRelease($entry, CutoverReceipt::RELEASE_BLOCKED, [self::REASON_RELEASE_UNVERIFIED]),
                [[
                    'code'    => self::REASON_RELEASE_UNVERIFIED,
                    'message' => sprintf(
                        'Subscription %s keeps a provider-owned schedule, so its webhook ownership must be '
                        . 'transferred through the verified source plugin adapter. CartShift has no verified '
                        . 'adapter for it and will not cancel a live provider agreement to make the command '
                        . 'succeed. Nothing was changed. Two ways forward: install the source PayPal plugin '
                        . 'in this runtime so its metadata contract can be identified and re-run the audit, '
                        . 'or take this subscription out of the selection, migrate the rest, and move its '
                        . 'schedule by hand.',
                        $sourceRef,
                    ),
                ]],
            ];
        }

        $subscription = $this->loadSource((int) ($entry['source_subscription_id'] ?? 0));

        if ($subscription === null) {
            return [
                self::withRelease($entry, CutoverReceipt::RELEASE_BLOCKED, [self::REASON_RELEASE_UNVERIFIED]),
                [[
                    'code'    => self::REASON_RELEASE_UNVERIFIED,
                    'message' => sprintf(
                        'Subscription %s is in the receipt and WooCommerce Subscriptions cannot hand it back. '
                        . 'Nothing was changed: run this in the source runtime.',
                        $sourceRef,
                    ),
                ]],
            ];
        }

        $drift = $this->driftFailure($entry, $state, $subscription, $sourceKey);

        if ($drift !== null) {
            return [
                self::withRelease($entry, CutoverReceipt::RELEASE_BLOCKED, [self::REASON_SOURCE_FINGERPRINT_CHANGED]),
                [$drift],
            ];
        }

        $result = $this->guard->release($subscription);

        // THE PREVIOUS FLAG IS WRITTEN ONCE, BY THE RUN THAT MUTATED THE SOURCE.
        //
        // Resuming an earlier run that set the flag and then stopped on drift,
        // the guard now finds a manual subscription and reports
        // `already_manual` with `previous = true` — which would record "this
        // subscriber was always on manual renewal" over the truth, and there is
        // no second copy anywhere to correct it from. So on a resume the
        // recorded value wins, and the guard's fresh reading is discarded.
        $previous = $state->awaitsReleaseAfterMutation()
            && $state->previousRequiresManualRenewal !== null
                ? $state->previousRequiresManualRenewal
                : $result['previous_requires_manual_renewal'];

        $blocked = $result['state'] === SourceRenewalGuard::STATE_BLOCKED;

        $updated = CutoverReceipt::entry([
            'state'          => $blocked
                ? $state->state
                : CutoverReceipt::STATE_SOURCE_RELEASED,
            'source_release' => [
                'required'                         => true,
                'state'                            => $result['state'],
                'previous_requires_manual_renewal' => $previous,
                // Latched. A resume must not report the source untouched just
                // because THIS call did not reach `save()`.
                'source_mutated'                   => $result['source_mutated'] || $state->sourceWasMutated(),
                'pre_fingerprint'                  => (string) $result['pre']['fingerprint'],
                'post_fingerprint'                 => (string) $result['post']['fingerprint'],
                'released_at_utc'                  => $result['failures'] === [] ? gmdate('Y-m-d H:i:s') : null,
                'reason_codes'                     => array_column($result['failures'], 'code'),
            ],
        ] + $entry);

        $failures = $result['failures'];

        // WHICH ROUTE OUT, NAMED, WHEN THE SOURCE HAS ALREADY BEEN WRITTEN TO.
        //
        // A blocked release on a source CartShift has already set to manual is
        // the one situation where the obvious instinct — re-export and start
        // again — is destructive. A fresh export records
        // `requires_manual_renewal = true`, `PaymentStrategyRegistry` routes the
        // subscriber as manual by definition with no confirmation asked, and
        // `previous_requires_manual_renewal` is left orphaned in the old
        // receipt. `restore-source` is the route, and until now nothing said so.
        if ($failures !== [] && ($result['source_mutated'] || $state->sourceWasMutated())) {
            $failures[] = [
                'code'    => self::REASON_RELEASE_UNVERIFIED,
                'message' => sprintf(
                    'Subscription %s has already been set to manual renewal by CartShift, so do NOT re-export '
                    . 'this cohort: a fresh export would record this subscriber as having always been on '
                    . 'manual renewal and lose the only copy of what they were before. Reconcile what is '
                    . 'reported above and run `cutover-source` again, which resumes; or run `restore-source`, '
                    . 'which hands the source back to %s.',
                    $sourceRef,
                    ($state->previousRequiresManualRenewal ?? $previous ?? false)
                        ? 'manual renewal'
                        : 'automatic renewal',
                ),
            ];
        }

        return [$updated, $failures];
    }

    /**
     * @param array<string, mixed> $entry
     * @return array{0: array<string, mixed>, 1: list<array{code: string, message: string}>}
     */
    private function activateOne(array $entry): array
    {
        $subscriptionId = (int) ($entry['target_subscription_id'] ?? 0);
        $intended       = (string) ($entry['intended_status'] ?? '');

        if ($subscriptionId <= 0) {
            // A participating entry with no destination row is an inconsistency
            // in the receipt, not a record to skip quietly. Held and blocked
            // entries never reach here — the caller filters them — so silence
            // would hide a receipt that has been edited or a stage that half
            // ran.
            return [$entry, [[
                'code'    => SubscriptionAssessment::REASON_REQUIRED_REFERENCE_MISSING,
                'message' => sprintf(
                    'Subscription %s is due for activation and the receipt records no destination '
                    . 'subscription for it. Nothing was activated: re-run `stage`, which is idempotent, and '
                    . 'check that entry.',
                    (string) ($entry['source_ref'] ?? '?'),
                ),
            ]]];
        }

        $subscription = Subscription::query()->find($subscriptionId);

        if ($subscription === null) {
            return [$entry, [[
                'code'    => SubscriptionAssessment::REASON_REQUIRED_REFERENCE_MISSING,
                'message' => sprintf(
                    'Subscription %s was staged as FluentCart #%d and that row is gone. Nothing was '
                    . 'activated.',
                    (string) ($entry['source_ref'] ?? '?'),
                    $subscriptionId,
                ),
            ]]];
        }

        // A terminal historical record is already what it is: it cannot bill and
        // it has no mandate to hand anybody. Writing a status onto it would be
        // the one change nobody asked for.
        if (($entry['terminal'] ?? false) !== true && $intended !== '') {
            $subscription->status = $intended;
            $subscription->save();
        }

        return [['state' => CutoverReceipt::STATE_ACTIVATED] + $entry, []];
    }

    /**
     * @param array<string, mixed> $entry
     * @return array{0: array<string, mixed>, 1: list<array{code: string, message: string}>}
     */
    private function restoreOne(array $entry): array
    {
        $release = (array) ($entry['source_release'] ?? []);
        $state   = CutoverEntryState::of($entry);

        $subscription = $this->loadSource((int) ($entry['source_subscription_id'] ?? 0));

        if ($subscription === null) {
            // Nothing was touched, so the intent is cleared: the source is
            // exactly as it was, which is a known state rather than an unknown
            // one.
            return [self::withoutRestoreIntent($entry), [[
                'code'    => self::REASON_RELEASE_UNVERIFIED,
                'message' => sprintf(
                    'Subscription %s cannot be read back from WooCommerce Subscriptions, so its previous '
                    . 'renewal flag cannot be restored. Nothing was changed.',
                    $state->sourceRef,
                ),
            ]]];
        }

        $result = $this->guard->restore(
            $subscription,
            (bool) ($release['previous_requires_manual_renewal'] ?? false),
            (string) ($release['post_fingerprint'] ?? ''),
        );

        if ($result['failures'] !== []) {
            // THE RELEASE STATE IS LEFT ALONE. It used to be overwritten with
            // `blocked`, which was false twice over: the guard refuses BEFORE it
            // mutates, so the source is still exactly as released as it was, and
            // a second `restore-source` then skipped the entry because it only
            // acts on `released` — making the rollback un-retryable at the one
            // moment it is needed. The failure is recorded as reason codes
            // beside the state rather than instead of it.
            return [
                CutoverReceipt::entry([
                    'source_release' => [
                        'reason_codes'          => array_column($result['failures'], 'code'),
                        // THE GUARD'S ANSWER, not a literal. It is `true` on
                        // every refusal today, and writing that constant here
                        // would be a second independent derivation of the one
                        // fact this whole design exists to have exactly one of.
                        'source_mutated'        => $result['source_mutated'],
                        'restore_intent_at_utc' => null,
                    ] + $release,
                ] + $entry),
                $result['failures'],
            ];
        }

        return [
            [
                'state'          => CutoverReceipt::STATE_SOURCE_RESTORED,
                'source_release' => [
                    'state'                 => CutoverReceipt::RELEASE_RESTORED,
                    // Put back, so the source is CartShift-untouched again and
                    // the cohort may be staged afresh. This is the write that
                    // makes section 11's restore-then-stage loop real — and it
                    // is the guard's answer rather than the `false` that used to
                    // sit here, which agreed with it only through a coincidence
                    // of three separate facts.
                    'source_mutated'        => $result['source_mutated'],
                    'restore_intent_at_utc' => null,
                ] + $release,
            ] + $entry,
            [],
        ];
    }

    // ──────────────────────────────────────────────
    // Identity
    // ──────────────────────────────────────────────

    /**
     * Plan section 9.1, and the ID map row that makes it visible to the writer.
     *
     * `CustomerResolver` is the only implementation of the cross-site order —
     * normalise the email, reuse a unique FluentCart customer, otherwise attach
     * a unique target WordPress user, otherwise create a guest, block on blank
     * or ambiguous — and it CREATES, which is why it belongs here and not in the
     * zero-write audit. Its two reuse arms write no mapping of their own, so the
     * row is written here: without it `SubscriptionAssessor::resolveCustomer()`
     * finds nothing and every record blocks on a customer that exists.
     *
     * The registered arm is keyed by the SOURCE user ID, which is what an ID map
     * is for. That is not the P0 this class was built against: the defect was
     * copying a source `$user->ID` into FluentCart's `user_id` column, and
     * nothing here does that — the resolver never reads the source ID at all.
     *
     * @return array<string, mixed>
     */
    private function resolveCustomer(
        IdMapRepository $idMap,
        SubscriptionRecord $record,
        string $migrationId,
    ): array {
        // Asked of the ID map FIRST, and this is not an optimisation. The
        // resolver's fourth step creates a guest, so calling it for a customer
        // this migration already recorded would produce a second FluentCart
        // customer for one person on every re-run — which is exactly the
        // duplication section 11 Phase E requires a second run not to cause.
        $mapped = self::mappedCustomerId($idMap, $record);

        if ($mapped !== null) {
            return [
                'status'      => CustomerResolver::STATUS_RESOLVED,
                'customer_id' => $mapped,
                'user_id'     => null,
                'outcome'     => CustomerResolver::OUTCOME_REUSED_CUSTOMER,
                'email'       => SubscriptionRecordFactory::normaliseEmail($record->billingEmail),
                'reason_code' => null,
            ];
        }

        $resolution = (new CustomerResolver($idMap))->resolveForSubscription($record, $migrationId);

        if ($resolution['status'] !== CustomerResolver::STATUS_RESOLVED) {
            return $resolution;
        }

        $customerId = (int) $resolution['customer_id'];

        // The guest arm files its own mapping under the deterministic guest ref,
        // so re-storing it would be a duplicate row for one person.
        $adopted = in_array($resolution['outcome'], [
            CustomerResolver::OUTCOME_REUSED_CUSTOMER,
            CustomerResolver::OUTCOME_ADOPTED_TARGET_USER,
        ], true);

        if ($record->sourceCustomerId !== null && $record->sourceCustomerId > 0) {
            if ($idMap->getFcId(Constants::ENTITY_CUSTOMER, (string) $record->sourceCustomerId) === null) {
                // `created_by_migration` is false for a customer that already
                // existed: a rollback must never delete somebody the target knew
                // before this migration ran.
                $idMap->store(
                    Constants::ENTITY_CUSTOMER,
                    (string) $record->sourceCustomerId,
                    $customerId,
                    $migrationId,
                    !$adopted,
                );
            }

            return $resolution;
        }

        $guestRef = SubscriptionRecordFactory::guestRef((string) $resolution['email']);

        if ($idMap->getFcId(Constants::ENTITY_GUEST_CUSTOMER, $guestRef) === null) {
            $idMap->store(Constants::ENTITY_GUEST_CUSTOMER, $guestRef, $customerId, $migrationId, !$adopted);
        }

        return $resolution;
    }

    /**
     * The customer this migration already recorded for this record, if any.
     *
     * The same three doors `SubscriptionAssessor::resolveCustomer()` reads, in
     * the same order, so "already resolved" means the same thing on both sides.
     */
    private static function mappedCustomerId(IdMapRepository $idMap, SubscriptionRecord $record): ?int
    {
        if ($record->sourceCustomerId !== null && $record->sourceCustomerId > 0) {
            $registered = $idMap->getFcId(Constants::ENTITY_CUSTOMER, (string) $record->sourceCustomerId);

            if ($registered) {
                return $registered;
            }
        }

        if ($record->billingEmail === '') {
            return null;
        }

        return $idMap->getFcId(Constants::ENTITY_GUEST_CUSTOMER, $record->billingEmail)
            ?? $idMap->getFcId(
                Constants::ENTITY_GUEST_CUSTOMER,
                SubscriptionRecordFactory::guestRef($record->billingEmail),
            );
    }

    // ──────────────────────────────────────────────
    // Source access
    // ──────────────────────────────────────────────

    private function loadSource(int $sourceSubscriptionId): ?object
    {
        if ($sourceSubscriptionId <= 0) {
            return null;
        }

        if ($this->sourceLoader !== null) {
            $loaded = ($this->sourceLoader)($sourceSubscriptionId);

            return is_object($loaded) ? $loaded : null;
        }

        if (!function_exists('wcs_get_subscription')) {
            return null;
        }

        $subscription = wcs_get_subscription($sourceSubscriptionId);

        return is_object($subscription) ? $subscription : null;
    }

    /**
     * Whether the source has moved since CartShift last looked at it — asked of
     * whichever comparator is still valid.
     *
     * TWO COMPARATORS, AND WHICH ONE APPLIES DEPENDS ON WHETHER CARTSHIFT HAS
     * ALREADY WRITTEN TO THE SOURCE.
     *
     * On an untouched source the RECORD fingerprint is right: it covers the whole
     * exported business fact, and any change to it means the export is stale.
     *
     * On a resumed run it is not merely imprecise, it is guaranteed wrong.
     * `SubscriptionRecord::fingerprintPayload()` includes
     * `requires_manual_renewal`, and the branch that gets here — a run that
     * called `SourceRenewalGuard::release()`, reached `save()`, and then blocked
     * on drift — has already flipped that flag. Re-deriving the record
     * fingerprint from that source therefore never matches the pre-mutation value
     * in the receipt, so `cutover-source` answered `source_fingerprint_changed`
     * for ever and sent the operator to re-export.
     *
     * RE-EXPORTING IS THE ONE ROUTE THAT DESTROYS THE EVIDENCE. The new export
     * records `requires_manual_renewal = true`, `PaymentStrategyRegistry` reads
     * that as manual by definition and asks for no confirmation, and
     * `previous_requires_manual_renewal` — the only surviving record that this
     * subscriber was ever on automatic billing — stays orphaned in the old
     * receipt. `restore-source` is the working route, and it works precisely
     * because it compares the GUARD's fingerprint, which excludes `is_manual` by
     * design (see `SourceRenewalGuard::inspect()`).
     *
     * So on a resume the record comparison is SKIPPED, not weakened. The
     * comparison that still means something is the guard's own `pre`/`post`
     * pair, and `SourceRenewalGuard::release()` re-runs the whole scan on the
     * next line anyway: an open renewal order — the thing that can actually
     * charge — refuses there, before any mutation, exactly as it did on the run
     * that blocked. Nothing is let through that the guard would not let through
     * on a first run.
     *
     * @param array<string, mixed> $entry
     * @return array{code: string, message: string}|null
     */
    private function driftFailure(
        array $entry,
        CutoverEntryState $state,
        object $subscription,
        string $sourceKey,
    ): ?array {
        if ($state->sourceWasMutated()) {
            return null;
        }

        $current = $this->fingerprintOf($subscription, $sourceKey);

        if ($current !== null && hash_equals((string) ($entry['source_fingerprint'] ?? ''), $current)) {
            return null;
        }

        return [
            'code'    => self::REASON_SOURCE_FINGERPRINT_CHANGED,
            'message' => sprintf(
                'Subscription %s no longer matches the record that was staged (%s, now %s). The '
                . 'source moved after the export. Nothing was changed: re-export and start again.',
                $state->sourceRef,
                (string) ($entry['source_fingerprint'] ?? ''),
                $current ?? 'unreadable',
            ),
        ];
    }

    /**
     * The source record's fingerprint, re-derived from the live source.
     *
     * The same four typed `get_related_orders()` calls the export made, through
     * the same factory, so the two answers are comparable byte for byte — which
     * is the whole point of `source_fingerprint_changed`. A record that can no
     * longer be decoded at all answers null, and the caller refuses rather than
     * treating "unreadable" as "unchanged".
     */
    private function fingerprintOf(object $subscription, string $sourceKey): ?string
    {
        if ($this->sourceFingerprint !== null) {
            return ($this->sourceFingerprint)($subscription, $sourceKey);
        }

        $factory = new SubscriptionRecordFactory();

        try {
            $record = $factory->subscriptionFromWoo(
                $sourceKey,
                $subscription,
                (new WooDatasetRecordFactory($factory))->relatedOrdersByType($subscription),
            );
        } catch (\Throwable) {
            return null;
        }

        return $record instanceof SubscriptionRecord ? $record->fingerprint : null;
    }

    // ──────────────────────────────────────────────
    // Inputs
    // ──────────────────────────────────────────────

    /**
     * Section 6.2 step 2's write half, through the ONE promoter this plugin has.
     *
     * `MappingPromoter` is not re-implemented here and must not be: it owns the
     * ordering that makes a half-finished promotion resumable (product row last),
     * the `created_by_migration = false` flag that stops a rollback deleting a
     * product the owner built by hand, the membership check that keeps a variant
     * belonging to another product out of the map, and the simulated realm. A
     * second copy of any one of those is a second thing to get wrong.
     *
     * The four FluentCart touchpoints are `MigrationOrchestratorFactory`'s own
     * statics rather than closures written here, for the reason its
     * `standalone()` docblock gives: the CLI, the REST layer and now `stage`
     * promote through identical code instead of through lookalikes that drift.
     * Three of the four are target-runtime reads and work as they stand.
     * `linkLosesDownloads()` is the exception and needs none: it opens with a
     * `function_exists('wc_get_product')` guard and answers false in a runtime
     * with no WooCommerce, which is the honest answer — this runtime cannot see
     * the source's files, and a report it cannot substantiate is worse than
     * silence.
     *
     * @return array{linked: int, variants: int, added: int, skipped: list<int>, outOfScope: list<int>, dead: list<int>, failed: list<int>, foreign: list<int>, fileless: list<int>}
     */
    private function promote(string $sourceKey, IdMapRepository $idMap, string $migrationId): array
    {
        $promoter = $this->promoter ?? new MappingPromoter(
            $this->productMap ?? new ProductMapRepository($sourceKey),
            $idMap,
            MigrationOrchestratorFactory::fcProductStillExists(...),
            MigrationOrchestratorFactory::createOrphanVariant(...),
            MigrationOrchestratorFactory::fcVariantIdsFor(...),
            MigrationOrchestratorFactory::linkLosesDownloads(...),
        );

        return $promoter->promote($migrationId, new ScopeResolver(MigrationScope::everything()));
    }

    /**
     * Promotion, as summary lines an operator reads before the block counts.
     *
     * FLAT AND SCALAR, deliberately: `SubscriptionCommand::report()` prints a
     * summary value with `implode()` when it is an array, which a nested map
     * would turn into an "array to string conversion" notice in the middle of a
     * cutover.
     *
     * And REPORTED RATHER THAN REFUSED OVER. A dead link or a foreign variant
     * blocks every subscription for that product under
     * `required_reference_missing`, named product by product, by the gate that
     * exists for it — refusing the whole cohort instead would be a harsher
     * policy than section 6.2 asks for, and would strand 561 sound subscribers
     * over one stale decision. What was missing was never the refusal; it was
     * any line at all saying how many decisions became references.
     *
     * @param array{linked: int, variants: int, added: int, skipped: list<int>, outOfScope: list<int>, dead: list<int>, failed: list<int>, foreign: list<int>, fileless: list<int>} $promotion
     * @return array<string, mixed>
     */
    private static function mappingSummary(array $promotion): array
    {
        return [
            'mapped_products'          => $promotion['linked'],
            'mapped_variants'          => $promotion['variants'],
            'mapped_variants_created'  => $promotion['added'],
            'mapping_dead_targets'     => $promotion['dead'],
            'mapping_foreign_variants' => $promotion['foreign'],
            'mapping_orphans_failed'   => $promotion['failed'],
        ];
    }

    /**
     * @return list<ProductMapDecision>
     */
    private function decisions(string $sourceKey): array
    {
        $repository = $this->productMap ?? new ProductMapRepository($sourceKey);

        return array_values($repository->all());
    }

    /**
     * The source contracts a collision check needs, read off the dataset.
     *
     * `MappingController` builds this from `wc_get_product()`, which exists only
     * in the WooCommerce runtime. Staging happens in the FluentCart one, so the
     * contracts come from the records themselves — the same normalised
     * cadence/trial/term key, derived from what the subscriber actually agreed
     * to rather than from today's catalogue.
     *
     * @param list<object> $records
     * @return array<int, NormalizedSubscriptionContract>
     */
    private static function contractIndex(array $records): array
    {
        $contracts = [];
        $projector = new SubscriptionLifecycleProjector();

        foreach ($records as $record) {
            if (!$record instanceof SubscriptionRecord) {
                continue;
            }

            $item = $record->items[0] ?? null;

            if ($item === null) {
                continue;
            }

            $key = (int) ($item['pseudo_variation_key'] ?? 0) ?: (int) ($item['source_variation_id'] ?? 0);

            if ($key <= 0) {
                continue;
            }

            $contracts[$key] = NormalizedSubscriptionContract::fromWooCommerce(
                $record->contract->period,
                $record->contract->multiplier,
                (int) $projector->project($record, null)['trial_days'],
                (int) ($record->contract->finiteCycles ?? 0),
            );
        }

        return $contracts;
    }

    /**
     * The same environment the audit and the run build, plus the approval.
     *
     * The two filters are the ones `SubscriptionMigrator` and
     * `SubscriptionAuditController` already read, in the same order, because a
     * stage that judged a record differently from the audit that previewed it
     * would make the audit decorative.
     */
    private static function environment(
        string $settingsFingerprint,
        ?string $approval,
        bool $manualFallbackAccepted = false,
    ): PaymentEnvironment
    {
        $environment = new PaymentEnvironment(
            capabilities: new PaymentCapabilityProbe(),
            settingsFingerprint: $settingsFingerprint,
            approvedSettingsFingerprint: $approval,
            verifiers: [],
            verifiedWebhookOwners: [],
            /** @see 'cartshift/subscription/manual_fallback_confirmed' */
            manualFallbackConfirmed: $manualFallbackAccepted || (bool) apply_filters(
                'cartshift/subscription/manual_fallback_confirmed',
                false,
            ),
        );

        /** @see 'cartshift/subscription/payment_environment' */
        $filtered = apply_filters('cartshift/subscription/payment_environment', $environment);

        return $filtered instanceof PaymentEnvironment ? $filtered : $environment;
    }

    /**
     * @return list<array{code: string, message: string}>
     */
    private static function approvalFailures(mixed $approval, string $settingsFingerprint): array
    {
        if ($approval === null || $approval === '') {
            // Absent is legitimate: a cohort with no `system` decision needs no
            // approval, and the strategies refuse a `system` record that has
            // none. What is not legitimate is a hash that does not fit.
            return [];
        }

        if (!is_string($approval) || preg_match(self::SHA256, $approval) !== 1) {
            return [[
                'code'    => self::REASON_SETTINGS_NOT_APPROVED,
                'message' => '--approve-system-settings must be the 64-character lower-case SHA-256 the '
                    . 'audit printed. Nothing was read and nothing was written.',
            ]];
        }

        if (!hash_equals($settingsFingerprint, $approval)) {
            return [[
                'code'    => self::REASON_SETTINGS_NOT_APPROVED,
                'message' => sprintf(
                    'The approved settings fingerprint is %s and this target currently fingerprints %s. '
                    . 'Approval is bound to the configuration it was given for. CartShift changes no '
                    . 'FluentCart setting: re-run the audit and approve the hash it prints.',
                    $approval,
                    $settingsFingerprint,
                ),
            ]];
        }

        return [];
    }

    // ──────────────────────────────────────────────
    // Receipt plumbing
    // ──────────────────────────────────────────────

    /**
     * @param array<string, string> $context
     * @return array{receipt: CutoverReceipt|null, failures: list<array{code: string, message: string}>}
     */
    private static function loadOrBegin(
        string $path,
        string $sourceKey,
        array $context,
        SubscriptionSelection $selection,
    ): array
    {
        $resolved = \CartShift\Domain\Subscription\Package\PackagePath::resolveForRead($path);

        if ($resolved['path'] === null) {
            // No file yet is the normal first run. Any other refusal — a public
            // directory, a Git working tree, a symlink — is not.
            $missing = $resolved['failures'] === [\CartShift\Domain\Subscription\Package\PackagePath::REASON_NOT_A_FILE];

            if (!$missing) {
                return ['receipt' => null, 'failures' => self::pathFailures($resolved['failures'], $path)];
            }

            return [
                'receipt'  => CutoverReceipt::begin(
                    $sourceKey,
                    $context['package_checksum'] ?? '',
                    $context['selection_fingerprint'] ?? '',
                    $context['mapping_fingerprint'] ?? '',
                    $context['target_settings_fingerprint'] ?? '',
                    '',
                    $selection->toArray(),
                ),
                'failures' => [],
            ];
        }

        $read = CutoverReceipt::read($path);

        if ($read['receipt'] === null) {
            return ['receipt' => null, 'failures' => self::pathFailures($read['failures'], $path)];
        }

        return ['receipt' => $read['receipt'], 'failures' => []];
    }

    /**
     * @param array<string, mixed> $summary
     * @return array<string, mixed>
     */
    private static function persist(CutoverReceipt $receipt, string $path, array $summary): array
    {
        $written = $receipt->write($path);

        if ($written['path'] === null) {
            return self::refusal(self::pathFailures($written['failures'], $path), $receipt);
        }

        return [
            'ok'           => true,
            'state'        => $receipt->state,
            'receipt'      => $receipt,
            'receipt_path' => $written['path'],
            'failures'     => [],
            'summary'      => $summary,
        ];
    }

    /**
     * @param list<array{code: string, message: string}> $failures
     * @return array<string, mixed>
     */
    private static function refusal(array $failures, ?CutoverReceipt $receipt = null, ?string $path = null): array
    {
        return [
            'ok'           => false,
            'state'        => $receipt?->state ?? '',
            'receipt'      => $receipt,
            'receipt_path' => $path,
            'failures'     => array_values($failures),
            'summary'      => $receipt === null ? [] : self::summarise($receipt),
        ];
    }

    /**
     * @param list<string> $codes
     * @return list<array{code: string, message: string}>
     */
    private static function pathFailures(array $codes, string $path): array
    {
        return array_map(
            static fn (string $code): array => [
                'code'    => $code,
                'message' => sprintf('The receipt at %s could not be used: %s.', $path, $code),
            ],
            $codes,
        );
    }

    /**
     * Section 11 Phase E's comparison, as counts.
     *
     * @return array<string, mixed>
     */
    private static function summarise(CutoverReceipt $receipt): array
    {
        $summary = [
            'selected'          => count($receipt->entries),
            'staged'            => 0,
            'released'          => 0,
            'activated'         => 0,
            'reconciled'        => 0,
            'restored'          => 0,
            'historical'        => 0,
            'manual'            => 0,
            'blocked'           => 0,
            // Staged, paused, and going no further until somebody repairs the
            // history. Counted separately from `blocked` because these DO have
            // a destination row, and at cohort level the two need different
            // remedies — and because without a number here the operator sees a
            // clean-looking run with subscribers quietly left behind.
            'history_mismatch'  => 0,
            // Orders imported for a subscription that then blocked. Real
            // history, correctly in FluentCart, with nothing pointing at it.
            'orphaned_orders'   => 0,
            // Restorations that started and never recorded an outcome. Nothing
            // knows whether these sources are manual or automatic.
            'source_unverified' => 0,
            'reason_codes'      => [],
        ];

        foreach ($receipt->entries as $entry) {
            if (($entry['outcome'] ?? '') === CutoverReceipt::OUTCOME_BLOCKED) {
                $summary['blocked']++;
                $summary['reason_codes'] = array_merge(
                    $summary['reason_codes'],
                    (array) ($entry['reason_codes'] ?? []),
                );

                if ((int) ($entry['target_parent_order_id'] ?? 0) > 0) {
                    $summary['orphaned_orders']++;
                }

                continue;
            }

            $entryState = CutoverEntryState::of($entry);

            if ($entryState->sourceStateIsUnknown()) {
                $summary['source_unverified']++;
            }

            if ($entryState->isHeld()) {
                $summary['history_mismatch']++;
                $summary['reason_codes'] = array_merge(
                    $summary['reason_codes'],
                    (array) (((array) ($entry['history'] ?? []))['reason_codes'] ?? []),
                );
            }

            $state = (string) ($entry['state'] ?? '');

            match ($state) {
                CutoverReceipt::STATE_STAGED          => $summary['staged']++,
                CutoverReceipt::STATE_SOURCE_RELEASED => $summary['released']++,
                CutoverReceipt::STATE_ACTIVATED       => $summary['activated']++,
                CutoverReceipt::STATE_RECONCILED      => $summary['reconciled']++,
                CutoverReceipt::STATE_SOURCE_RESTORED => $summary['restored']++,
                default                               => null,
            };

            if (($entry['terminal'] ?? false) === true) {
                $summary['historical']++;
            }

            if ((string) ($entry['collection_method'] ?? '') === PaymentMigrationDecision::COLLECTION_MANUAL) {
                $summary['manual']++;
            }
        }

        $summary['reason_codes'] = array_values(array_unique($summary['reason_codes']));
        sort($summary['reason_codes']);

        return $summary;
    }

    /**
     * @param array<string, mixed> $entry
     * @param list<string>         $codes
     * @return array<string, mixed>
     */
    private static function withRelease(array $entry, string $state, array $codes): array
    {
        $release = (array) ($entry['source_release'] ?? []);

        return CutoverReceipt::entry([
            'source_release' => ['state' => $state, 'reason_codes' => $codes] + $release,
        ] + $entry);
    }

    /**
     * What the operator can actually do next, ASKED OF THE STATE MACHINE.
     *
     * Not described. A previous version of this message told the operator to run
     * `restore-source` and then `stage`, and from `activated` — the only state
     * `reconcile` runs in — the machine refuses both, every time. A remedy that
     * is always refused is worse than none: it sends people to the workarounds
     * that are genuinely unsafe, which for this particular split means
     * un-pausing the FluentCart row by hand while WooCommerce is still
     * auto-billing.
     *
     * So the candidate transitions are put to `transitionFailures()` and only
     * the ones it accepts are named. When it accepts nothing, this says so, and
     * says what to do outside the tool instead.
     */
    private static function remedy(CutoverReceipt $receipt, string $sourceKey): string
    {
        $context   = ['selection_fingerprint' => $receipt->selection()->fingerprint()];
        $available = [];

        foreach ([
            CutoverReceipt::STATE_STAGED          => '`stage` (repair the history first, then re-run it)',
            CutoverReceipt::STATE_SOURCE_RESTORED => '`restore-source` (hands every source back, after which '
                . 'the cohort may be staged again)',
        ] as $state => $description) {
            if ($receipt->transitionFailures($state, $context) === []) {
                $available[] = $description;
            }
        }

        if ($available !== []) {
            return sprintf(
                'Export the renewal orders the counts are missing, then: %s.',
                implode('; or ', $available),
            );
        }

        return 'No CartShift command can move this receipt any further: staging would rewrite entries that '
            . 'are already activated, and a source cannot be restored once anything on the target is live. '
            . 'Finish these subscriptions by hand, and in this order — disable renewal at the source FIRST, '
            . 'then un-pause the FluentCart row. Never the other way round, and do not delete the receipt: '
            . 'it is the only record of which sources CartShift changed. For a clean re-run, export the '
            . 'missing renewal orders and start a NEW cutover with a selection narrowed to these '
            . 'subscriptions and its own receipt.';
    }

    /**
     * Write the receipt, and say why not.
     *
     * The three per-entry loops all call this instead of discarding the return
     * value. A receipt write that silently failed would leave the file
     * describing a state the world has already left, which is the one condition
     * every safety argument in this class assumes cannot happen.
     *
     * @return list<array{code: string, message: string}>
     */
    private static function writeFailures(CutoverReceipt $receipt, string $path, string $command): array
    {
        $written = $receipt->write($path);

        if ($written['path'] !== null) {
            return [];
        }

        return [[
            'code'    => CutoverReceipt::REASON_WRITE_FAILED,
            'message' => sprintf(
                '%s could not write the receipt to %s (%s), so it stopped rather than change anything else. '
                . 'The receipt no longer describes the world: read it, check the subscriptions it names '
                . 'against the source and the target by hand, and fix the path before running anything again.',
                $command,
                $path,
                implode(', ', $written['failures']) ?: 'no reason was given',
            ),
        ]];
    }

    /**
     * Whether the operator named a different source from the one in the receipt.
     *
     * The receipt carries the source key it was written under, so the commands
     * use that rather than whatever `--source-key` happened to be typed. Without
     * this an operator who exported as `lapka-club` and omitted the flag at
     * cutover got `source_fingerprint_changed` on every single entry, with a
     * message telling them to re-export and start again — which is the wrong
     * action, and doing it destroys the maintenance-window freeze the whole
     * cutover depends on.
     *
     * @param array<string, mixed> $options
     * @return list<array{code: string, message: string}>
     */
    private static function sourceKeyMismatch(array $options, CutoverReceipt $receipt): array
    {
        $given = $options['source_key'] ?? null;

        // Absent is the normal case: the receipt knows, so the flag is optional.
        if (!is_string($given) || $given === '' || $given === $receipt->sourceKey) {
            return [];
        }

        return [[
            'code'    => CutoverReceipt::REASON_TRANSITION_INVALID,
            'message' => sprintf(
                'This receipt was written for source key "%s" and --source-key says "%s". Nothing was done. '
                . 'Drop the flag, or pass the key the package was exported under — do NOT re-export, which '
                . 'would break the maintenance-window freeze this cutover is running inside.',
                $receipt->sourceKey,
                $given,
            ),
        ]];
    }

    /**
     * Record that a restoration is about to touch this source.
     *
     * @param array<string, mixed> $entry
     * @return array<string, mixed>
     */
    private static function withRestoreIntent(array $entry): array
    {
        $release = (array) ($entry['source_release'] ?? []);

        return CutoverReceipt::entry([
            'source_release' => ['restore_intent_at_utc' => gmdate('Y-m-d H:i:s')] + $release,
        ] + $entry);
    }

    /**
     * Clear it again, for a branch that touched nothing.
     *
     * @param array<string, mixed> $entry
     * @return array<string, mixed>
     */
    private static function withoutRestoreIntent(array $entry): array
    {
        $release = (array) ($entry['source_release'] ?? []);

        return CutoverReceipt::entry([
            'source_release' => ['restore_intent_at_utc' => null] + $release,
        ] + $entry);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function byRef(CutoverReceipt $receipt): array
    {
        $byRef = [];

        foreach ($receipt->entries as $entry) {
            $byRef[(string) ($entry['source_ref'] ?? '')] = $entry;
        }

        return $byRef;
    }

    /**
     * @param iterable<object> $records
     * @return list<object>
     */
    private static function materialise(iterable $records): array
    {
        $materialised = [];

        foreach ($records as $record) {
            $materialised[] = $record;
        }

        return $materialised;
    }

    /**
     * Section 6.2's closure rules, run over the records `stage()` already holds.
     *
     * The manifest is synthesised from the decoded counts rather than read from
     * a package header, and deliberately: `dataset_count_mismatch` and
     * `dataset_checksum_mismatch` are the package reader's questions, asked at
     * the file boundary where the declared numbers exist. What this run needs
     * from the validator is the set-level faults nothing else asks —
     * `shared_parent_order_requires_projection`, `dataset_duplicate_reference`,
     * `dataset_foreign_source_key` — and those are answered from the records
     * alone, so they are answered identically for a package and for a live
     * source.
     *
     * @param list<object> $records
     * @return list<array{code: string, message: string}>
     */
    private static function closureFailures(string $sourceKey, array $records): array
    {
        $counts = array_fill_keys(DatasetManifest::KINDS, 0);

        foreach ($records as $record) {
            $kind = match (true) {
                $record instanceof InvalidSourceRecord => $record->entityKind,
                method_exists($record, 'kind')         => (string) $record->kind(),
                default                                => '',
            };

            if (array_key_exists($kind, $counts)) {
                $counts[$kind]++;
            }
        }

        $manifest = new DatasetManifest(
            DatasetManifest::SCHEMA_VERSION,
            $sourceKey,
            '',
            [],
            '',
            [],
            '',
            $counts,
            0,
            count($records),
            '',
        );

        $report = (new DatasetClosureValidator())->validate($manifest, $records);

        return array_map(
            static fn (array $failure): array => [
                'code'    => (string) $failure['code'],
                'message' => self::closureMessage($failure),
            ],
            $report->setLevelFailures(),
        );
    }

    /**
     * Operator copy for one set-level closure failure.
     *
     * @param array{code: string, source_key: string, kind: string, source_ref: string, context: array<string, mixed>} $failure
     */
    private static function closureMessage(array $failure): string
    {
        $context = $failure['context'];

        return match ($failure['code']) {
            ClosureReport::CODE_SHARED_PARENT_ORDER => sprintf(
                'Subscription %s shares source order #%s as its parent with %s. FluentCart\'s renewal '
                . 'service assumes one subscription per parent order, so importing both would write two '
                . 'subscriptions against one `parent_order_id` and the second subscriber\'s renewals would '
                . 'be raised against the first one\'s order. Nothing was staged. Separate the parent orders '
                . 'in the source, or take one of these subscriptions out of the selection and migrate it by '
                . 'hand.',
                $failure['source_ref'],
                (string) ($context['source_order_id'] ?? '?'),
                implode(', ', array_map(strval(...), (array) ($context['claimed_by'] ?? []))),
            ),
            ClosureReport::CODE_DUPLICATE_REFERENCE => sprintf(
                '%s %s appears more than once in this dataset carrying two different payloads (%s and %s). '
                . 'Whichever line was read first would silently become the migrated one. Nothing was '
                . 'staged: re-export, and check the source for a reference that is not unique.',
                $failure['kind'],
                $failure['source_ref'],
                (string) ($context['first_fingerprint'] ?? '?'),
                (string) ($context['second_fingerprint'] ?? '?'),
            ),
            ClosureReport::CODE_FOREIGN_SOURCE_KEY => sprintf(
                '%s %s declares source key "%s" and this dataset is being staged as "%s". A dataset that '
                . 'mixes sources is not the dataset it claims to be. Nothing was staged.',
                $failure['kind'],
                $failure['source_ref'],
                $failure['source_key'],
                (string) ($context['manifest_source_key'] ?? '?'),
            ),
            default => sprintf(
                'This dataset failed the section 6.2 closure check as a whole (%s, on %s %s). Nothing was '
                . 'staged.',
                $failure['code'],
                $failure['kind'],
                $failure['source_ref'],
            ),
        };
    }
}

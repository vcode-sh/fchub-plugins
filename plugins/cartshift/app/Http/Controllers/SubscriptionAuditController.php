<?php

declare(strict_types=1);

namespace CartShift\Http\Controllers;

defined('ABSPATH') || exit;

use CartShift\Core\Container;
use CartShift\Domain\Mapping\MappingSetValidator;
use CartShift\Domain\Mapping\ProductMapDecision;
use CartShift\Domain\Subscription\ClosureReport;
use CartShift\Domain\Subscription\CustomerResolver;
use CartShift\Domain\Subscription\DatasetClosureValidator;
use CartShift\Domain\Subscription\DatasetManifest;
use CartShift\Domain\Subscription\InvalidSourceRecord;
use CartShift\Domain\Subscription\Package\PackageContextRepository;
use CartShift\Domain\Subscription\Package\SubscriptionPackageReader;
use CartShift\Domain\Subscription\Payment\PaymentCapabilityProbe;
use CartShift\Domain\Subscription\Payment\PaymentEnvironment;
use CartShift\Domain\Subscription\Payment\PaymentMigrationDecision;
use CartShift\Domain\Subscription\Payment\PaymentStrategyRegistry;
use CartShift\Domain\Subscription\Payment\StripeReferenceVerifier;
use CartShift\Domain\Subscription\ProductRecord;
use CartShift\Domain\Subscription\RuntimeCompatibilityProbe;
use CartShift\Domain\Subscription\RuntimeCompatibilityReport;
use CartShift\Domain\Subscription\Source\PackageSubscriptionDatasetSource;
use CartShift\Domain\Subscription\Source\WooSubscriptionDatasetSource;
use CartShift\Domain\Subscription\SubscriptionAssessment;
use CartShift\Domain\Subscription\SubscriptionAssessor;
use CartShift\Domain\Subscription\SubscriptionDatasetSource;
use CartShift\Domain\Subscription\SubscriptionRecord;
use CartShift\Domain\Subscription\SubscriptionRecordFactory;
use CartShift\Domain\Subscription\SubscriptionSelection;
use CartShift\Storage\IdMapRepository;
use CartShift\Storage\ProductMapRepository;
use CartShift\Support\Constants;
use CartShift\Support\Enums\MigrationErrorCode;
use CartShift\Validator\PreflightCheck;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Plan section 11 Phase A, over HTTP. It reads. That is the whole contract.
 *
 * ZERO WRITE, MEANT LITERALLY. No INSERT, UPDATE or DELETE; no option, no
 * transient, no scheduled action, no CartShift log line, and above all no
 * simulated ID-map row. The plugin's existing dry run does write simulated rows
 * — that is what it is for, and three screens now say so in those words — and
 * this endpoint exists because "rehearsal" and "read-only" are different
 * promises that were previously being made with one word.
 *
 * TWO OBJECTS HERE COULD WRITE AND DO NOT. `IdMapRepository` and
 * `ProductMapRepository` are both write-capable, and both are constructed
 * because the assessor's constructor and the mapping-set validator's input
 * demand them. Nothing on this path calls anything but `getFcId()` and `all()`.
 * That is an assertion, so it is enforced rather than believed:
 * `SubscriptionAuditControllerTest` runs every GET under a `$wpdb` that refuses
 * to write and a snapshot of the option/transient/post-meta/scheduled-action
 * globals, and proves the guard would catch a real write before trusting it to
 * report the absence of one.
 *
 * THE THINGS THAT DO WRITE ARE NAMED. Preparing a package, saving a mapping
 * decision and accepting the manual-renewal behaviour change are CartShift
 * configuration writes. They are legitimate, they are not audit, and the
 * document says so under `writes.configuration_writes` so the distinction
 * reaches the screen rather than living in a docblock.
 *
 * NOTHING HERE APPROVES ANYTHING. The target's settings/census fingerprint is
 * reported so an operator can read it; `approved` is always false. The operator
 * binds that exact hash at stage with `--approve-system-settings=<sha256>`, and
 * a fingerprint that has moved since invalidates the approval. CartShift changes
 * no FluentCart setting at any point.
 */
final class SubscriptionAuditController
{
    private const string NAMESPACE = 'cartshift/v1';

    private const string SOURCE_LIVE = 'live';
    private const string SOURCE_PACKAGE = 'package';

    /** @var list<string> */
    private const array SOURCES = [self::SOURCE_LIVE, self::SOURCE_PACKAGE];

    private const int DEFAULT_PER_PAGE = 25;
    private const int MAX_PER_PAGE = 200;

    /**
     * How many affected source refs one reason code carries in the summary.
     *
     * The summary is a summary. `finite_term_from_product` applies to every one
     * of the reference dataset's 564 records, and shipping 564 refs per code
     * would make the document mostly identifiers. The record endpoint filters
     * by code and is the authoritative list, so the cap is a display decision
     * rather than a loss — which is why `source_ref_total` and `truncated`
     * travel beside it instead of the count being quietly wrong.
     */
    private const int MAX_SOURCE_REFS = 50;

    /**
     * Warnings the reference dataset produces by design.
     *
     * §9.2 says the subscription's own term is preferred and the product's is
     * "fallback evidence only and must raise a warning". The Lapka source
     * records no term on any of its 564 subscriptions and both its products
     * declare one, so every record warns. That is the specification working.
     * Presenting 564 of them beside the blockers would report 564 problems
     * where there are none, so the flag travels with the code and the screen
     * files them under notes.
     *
     * @var list<string>
     */
    private const array EXPECTED_WARNINGS = [SubscriptionAssessment::REASON_FINITE_TERM_FROM_PRODUCT];

    private const string SEVERITY_BLOCKING = 'blocking';
    private const string SEVERITY_CONFIRMATION = 'confirmation';
    private const string SEVERITY_WARNING = 'warning';

    /** @var array<string, int> Most severe first, for sorting and for merging. */
    private const array SEVERITY_RANK = [
        self::SEVERITY_BLOCKING     => 0,
        self::SEVERITY_CONFIRMATION => 1,
        self::SEVERITY_WARNING      => 2,
    ];

    public function __construct(
        private readonly Container $container,
    ) {
    }

    public function registerRoutes(): void
    {
        register_rest_route(self::NAMESPACE, '/subscriptions/audit', [
            'methods'             => 'GET',
            'callback'            => [$this, 'audit'],
            'permission_callback' => [$this, 'checkPermission'],
            'args'                => [
                'source'     => ['type' => 'string', 'default' => self::SOURCE_LIVE],
                'source_key' => ['type' => 'string', 'default' => Constants::DEFAULT_SOURCE_KEY],
                'file'       => ['type' => 'string', 'default' => ''],
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/subscriptions/audit/records', [
            'methods'             => 'GET',
            'callback'            => [$this, 'records'],
            'permission_callback' => [$this, 'checkPermission'],
            'args'                => [
                'source'     => ['type' => 'string', 'default' => self::SOURCE_LIVE],
                'source_key' => ['type' => 'string', 'default' => Constants::DEFAULT_SOURCE_KEY],
                'file'       => ['type' => 'string', 'default' => ''],
                'page'       => ['type' => 'integer', 'default' => 1, 'sanitize_callback' => 'absint'],
                'per_page'   => [
                    'type'              => 'integer',
                    'default'           => self::DEFAULT_PER_PAGE,
                    'sanitize_callback' => 'absint',
                ],
                'outcome'    => ['type' => 'string', 'default' => ''],
                'code'       => ['type' => 'string', 'default' => ''],
            ],
        ]);
    }

    public function checkPermission(): bool
    {
        return current_user_can('manage_options');
    }

    // ──────────────────────────────────────────────
    // Endpoints
    // ──────────────────────────────────────────────

    /**
     * The whole assessment, as one document.
     *
     * Stateless: everything it needs arrives in the query string, nothing is
     * remembered between calls, and two audits of an unchanged source produce
     * the same answer. That is what makes it safe to re-run after every
     * mapping decision, which is exactly how the screen is used.
     */
    public function audit(WP_REST_Request $request): WP_REST_Response
    {
        $context = $this->context($request);

        if (isset($context['error'])) {
            return $this->refuse($context['error'], (int) $context['status']);
        }

        return new WP_REST_Response(['data' => $this->document($context, $this->analyse($context))]);
    }

    /**
     * One page of assessed records, filterable by outcome and by reason code.
     *
     * The summary caps how many source refs it carries per code; this is where
     * the rest of them live. A code in the summary is therefore always
     * actionable — click it, get every record it applies to.
     */
    public function records(WP_REST_Request $request): WP_REST_Response
    {
        $context = $this->context($request);

        if (isset($context['error'])) {
            return $this->refuse($context['error'], (int) $context['status']);
        }

        $rows    = $this->analyse($context)['rows'];
        $outcome = strtolower(trim((string) ($request->get_param('outcome') ?? '')));
        $code    = trim((string) ($request->get_param('code') ?? ''));

        $filtered = array_values(array_filter(
            $rows,
            static function (array $row) use ($outcome, $code): bool {
                if ($outcome !== '' && $row['outcome'] !== $outcome) {
                    return false;
                }

                return $code === '' || in_array($code, $row['reason_codes'], true);
            },
        ));

        $perPage = min(self::MAX_PER_PAGE, max(1, (int) $request->get_param('per_page') ?: self::DEFAULT_PER_PAGE));
        $page    = max(1, (int) $request->get_param('page') ?: 1);

        return new WP_REST_Response(['data' => [
            'records'  => array_slice($filtered, ($page - 1) * $perPage, $perPage),
            'page'     => $page,
            'per_page' => $perPage,
            'total'    => count($rows),
            'filtered' => count($filtered),
            'filters'  => ['outcome' => $outcome, 'code' => $code],
            'writes'   => ['nothing' => true],
        ]]);
    }

    // ──────────────────────────────────────────────
    // Source resolution
    // ──────────────────────────────────────────────

    /**
     * Which dataset this request is about, or why it cannot be read.
     *
     * A package with no file falls back to the descriptor `prepare-package`
     * stored for this source key, because that is the whole reason the
     * descriptor exists — the mapping UI and this screen both have to find the
     * file again across requests. No descriptor and no file is a refusal, not
     * an empty audit: "ready, 0 records" and "this runtime cannot see the
     * source" look identical to a reader and could not be more different.
     *
     * @return array<string, mixed>
     */
    private function context(WP_REST_Request $request): array
    {
        $mode = strtolower(trim((string) ($request->get_param('source') ?? self::SOURCE_LIVE)));
        $mode = $mode === '' ? self::SOURCE_LIVE : $mode;

        $sourceKey = trim((string) ($request->get_param('source_key') ?? ''));
        $sourceKey = $sourceKey === '' ? Constants::DEFAULT_SOURCE_KEY : $sourceKey;

        if (!in_array($mode, self::SOURCES, true)) {
            return [
                'status' => 400,
                'error'  => sprintf(
                    'Unknown source "%s". Use one of: %s.',
                    $mode,
                    implode(', ', self::SOURCES),
                ),
            ];
        }

        $selection = SubscriptionSelection::all($sourceKey);

        if ($mode === self::SOURCE_LIVE) {
            $missing = (new PreflightCheck())->missingSubscriptionDatasetApis();

            if ($missing !== []) {
                return [
                    'status' => 409,
                    'error'  => sprintf(
                        'This runtime cannot read subscriptions: %s missing. Reporting an empty dataset '
                        . 'here would look exactly like a shop with no subscribers, so nothing is '
                        . 'reported at all. Run the audit in the WooCommerce runtime, or audit a package.',
                        implode(', ', $missing),
                    ),
                ];
            }

            return [
                'mode'       => $mode,
                'source_key' => $sourceKey,
                'file'       => null,
                'package'    => null,
                'selection'  => $selection,
                'dataset'    => new WooSubscriptionDatasetSource($sourceKey, $selection),
            ];
        }

        $file = trim((string) ($request->get_param('file') ?? ''));

        if ($file === '') {
            $descriptor = (new PackageContextRepository())->get($sourceKey);
            $file       = (string) ($descriptor['path'] ?? '');
        }

        if ($file === '') {
            return [
                'status' => 400,
                'error'  => sprintf(
                    'Auditing a package needs a file. Nothing has been prepared for source key "%s", '
                    . 'and no path was given.',
                    $sourceKey,
                ),
            ];
        }

        $package = (new SubscriptionPackageReader())->validate($file);

        if ($package['path'] === null) {
            return [
                'status' => 422,
                'error'  => sprintf(
                    'That path is not a readable package: %s.',
                    implode(', ', array_column($package['failures'], 'code')) ?: 'it could not be opened',
                ),
            ];
        }

        return [
            'mode'       => $mode,
            'source_key' => $package['manifest']?->sourceKey ?? $sourceKey,
            'file'       => $package['path'],
            'package'    => [
                'ok'       => $package['ok'],
                'checksum' => $package['checksum'],
                'failures' => $package['failures'],
            ],
            'selection'  => $selection,
            'dataset'    => new PackageSubscriptionDatasetSource($package['path']),
        ];
    }

    // ──────────────────────────────────────────────
    // The read
    // ──────────────────────────────────────────────

    /**
     * Two passes over the dataset, then one assessment per subscription.
     *
     * Two passes because the closure validator consumes the stream and the
     * per-record assessment needs it again; both sources hand out a fresh
     * generator per call, so this costs a second read rather than a second copy
     * of every order in memory. Orders are deliberately not retained here —
     * the reference dataset has 4,702 renewal relationships and the audit needs
     * their closure verdict, not their payloads.
     *
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function analyse(array $context): array
    {
        /** @var SubscriptionDatasetSource $dataset */
        $dataset = $context['dataset'];
        /** @var SubscriptionSelection $selection */
        $selection = $context['selection'];

        $manifest = $dataset->manifest();
        $closure  = (new DatasetClosureValidator())->validate($manifest, $dataset->records($selection));

        $target      = (new RuntimeCompatibilityProbe())->inspect(RuntimeCompatibilityProbe::ROLE_TARGET);
        $environment = $this->environment($target);

        // READ-ONLY ON THIS PATH, AND THE GUARD IS WHAT ENFORCES IT.
        // `IdMapRepository` is write-capable — that is the whole reason the
        // plan's first P0 exists — and it is constructed here because
        // `SubscriptionAssessor` and `CustomerResolver` both take one. Nothing
        // below calls anything on it but `getFcId()`. The claim is not left to
        // this comment: `SubscriptionAuditControllerTest` runs every GET under
        // a `$wpdb` that throws on any write and proves the throw works first.
        // Scoped to the source key so a cross-site audit resolves its own
        // mappings rather than whatever `local` happens to hold.
        $idMap = new IdMapRepository((string) $context['source_key']);

        $assessor = new SubscriptionAssessor(
            $idMap,
            PaymentStrategyRegistry::withDefaults(),
            $environment,
        );

        // §9.1's resolution order, forecast rather than performed.
        // `resolve()` creates rows on two of its four arms; `preview()` reads
        // the same three lookups and stops where `resolve()` would write.
        $resolver = new CustomerResolver($idMap);

        /** @var array<string, array<string, mixed>> $previews Memoised per identity. */
        $previews = [];

        $rows     = [];
        $products = [];

        foreach ($dataset->records($selection) as $record) {
            if ($record instanceof ProductRecord) {
                $products[$record->sourceProductId] = $record;

                continue;
            }

            if ($record instanceof SubscriptionRecord) {
                $rows[] = $this->assessedRow(
                    $record,
                    $assessor->assess($record),
                    $this->previewFor($record, $resolver, $previews),
                );

                continue;
            }

            if ($record instanceof InvalidSourceRecord && $record->entityKind === SubscriptionRecord::KIND) {
                $rows[] = $this->invalidRow($record);
            }
        }

        usort($rows, static fn (array $a, array $b): int => $a['source_ref'] <=> $b['source_ref']);

        return [
            'closure'     => $closure,
            'environment' => $environment,
            'manifest'    => $manifest,
            'products'    => $products,
            'rows'        => $rows,
            'target'      => $target,
        ];
    }

    /**
     * The gate the audit judges by, which must be the gate the run judges by.
     *
     * Same two filters `SubscriptionMigrator` applies, in the same order, for
     * the reason its docblock gives: a preview that answers a different question
     * from the run is worse than no preview. `approvedSettingsFingerprint` stays
     * null unconditionally — an audit is where an operator READS the hash, and a
     * screen that could approve its own precondition would be a rubber stamp
     * with a progress bar.
     */
    private function environment(RuntimeCompatibilityReport $target): PaymentEnvironment
    {
        $environment = new PaymentEnvironment(
            capabilities: new PaymentCapabilityProbe(),
            settingsFingerprint: $target->fingerprint(),
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
        $filtered = apply_filters('cartshift/subscription/payment_environment', $environment);

        return $filtered instanceof PaymentEnvironment ? $filtered : $environment;
    }

    /**
     * One §9.1 forecast per identity, not per subscription.
     *
     * The reference dataset is 564 subscriptions held by 215 people, and
     * `preview()` costs three SELECTs. Keyed on the identity hash — `guest:`
     * plus SHA-256 of the normalised email, the same key
     * `GuestCustomerFactory::fromRecord()` files a guest under — so two
     * subscriptions belonging to one person are asked about once. A blank email
     * memoises under `''`, which is correct: they all get the same answer.
     *
     * @param array<string, array<string, mixed>> $previews
     * @return array<string, mixed>
     */
    private function previewFor(
        SubscriptionRecord $record,
        CustomerResolver $resolver,
        array &$previews,
    ): array {
        $hash = self::identityHash($record->billingEmail);

        $previews[$hash] ??= $resolver->previewForSubscription($record);

        return ['identity_hash' => $hash] + $previews[$hash];
    }

    /**
     * An identity, without the email.
     *
     * §4.4 measures distinct subscription *emails*, and the source customer ref
     * cannot count them: a registered buyer keys as `customer:660001` and the
     * same person buying once as a guest keys as `guest:<hash>`, so one person
     * would count twice. Hashing the normalised email puts both namespaces on
     * one axis and keeps the email itself out of a document meant to be
     * readable over somebody's shoulder.
     */
    private static function identityHash(string $email): string
    {
        return $email === '' ? '' : SubscriptionRecordFactory::guestRef($email);
    }

    /**
     * @param array<string, mixed> $preview
     * @return array<string, mixed>
     */
    private function assessedRow(
        SubscriptionRecord $record,
        SubscriptionAssessment $assessment,
        array $preview,
    ): array {
        $payment = $assessment->payment;
        $item    = $record->items[0] ?? [];

        $errorCodes   = $assessment->errorCodes();
        $warningCodes = $assessment->warningCodes();

        $productId   = (int) ($item['source_product_id'] ?? 0);
        $variationId = (int) ($item['source_variation_id'] ?? 0);

        $targetProductId   = $assessment->resolvedReferences['product_id'] ?? null;
        $targetVariationId = $assessment->resolvedReferences['variation_id'] ?? null;

        return [
            'source_ref'             => $record->sourceRef,
            'source_subscription_id' => $record->sourceSubscriptionId,
            'kind'                   => SubscriptionRecord::KIND,
            'status'                 => $record->status,
            'gateway'                => $record->gateway,
            'requires_manual_renewal' => $record->requiresManualRenewal,
            'currency'               => $record->currency,
            'cadence'                => $this->cadence($record),
            'target_interval'        => $record->contract->targetInterval,
            'recurring_total'        => $record->contract->recurringTotal,
            'next_payment_utc'       => $record->dates->nextPaymentUtc,
            'source_payment_count'   => $record->sourcePaymentCount,
            'outcome'                => $assessment->outcome,
            'strategy'               => $payment->strategy,
            'collection_method'      => $payment->collectionMethod,
            'next_action_owner'      => $payment->nextActionOwner,
            'payment_outcome'        => $payment->outcome,
            'payment_reason_codes'   => $payment->reasonCodes,
            'error_codes'            => $errorCodes,
            'warning_codes'          => $warningCodes,
            'reason_codes'           => array_values(array_unique([...$errorCodes, ...$warningCodes])),
            'messages'               => [
                ...$this->messages($assessment->errors, self::SEVERITY_BLOCKING),
                // A warning is only "awaiting a decision" when it is one of the
                // payment reasons that put the record there. Grading every
                // warning on a `confirmation_required` record as confirmation
                // swept §9.2's product-fallback note — which every one of the
                // reference dataset's 564 records carries — into the pile an
                // operator is expected to act on.
                ...$this->messages(
                    $assessment->warnings,
                    self::SEVERITY_WARNING,
                    $assessment->outcome === SubscriptionAssessment::OUTCOME_CONFIRMATION_REQUIRED
                        ? $payment->reasonCodes
                        : [],
                ),
            ],
            'mapping'                => [
                'source_product_id'   => $productId,
                'source_variation_id' => $variationId,
                'target_product_id'   => $targetProductId,
                'target_variation_id' => $targetVariationId,
                'needs_mapping'       => $targetProductId === null || $targetVariationId === null,
            ],
            'customer'               => [
                // What the ID map already holds — the thing the assessor
                // actually gates on, and not the same question as §9.1's.
                'resolved_in_id_map' => ($assessment->resolvedReferences['customer_id'] ?? null) !== null,
                'guest'              => $record->sourceCustomerId === null || $record->sourceCustomerId <= 0,
                // `customer:660001` or `guest:<sha256 of the normalised email>`.
                'source_ref'         => $record->sourceCustomerRef,
                'identity_hash'      => $preview['identity_hash'],
                // §9.1's forecast. No email, no name, no address.
                'resolution'         => [
                    'status'              => $preview['status'] ?? '',
                    'outcome'             => $preview['outcome'] ?? null,
                    'reason_code'         => $preview['reason_code'] ?? null,
                    'would_create'        => (bool) ($preview['would_create'] ?? false),
                    'matched_target_user' => (bool) ($preview['matched_target_user'] ?? false),
                ],
            ],
            'stripe_token'           => $this->stripeTokenClass($record),
            'stripe_remote_schedule' => trim(
                (string) ($record->paymentReferences[StripeReferenceVerifier::REF_SUBSCRIPTION] ?? ''),
            ) !== '',
        ];
    }

    /**
     * A source row nothing may migrate, kept in the totals rather than dropped.
     *
     * §6.2: an `InvalidSourceRecord` "remains in manifest/entity counts so audit
     * can report and block it; it is never silently dropped". A record list that
     * showed only decodable records would reconcile to a number smaller than the
     * one the operator selected, and the difference would be invisible.
     *
     * @return array<string, mixed>
     */
    private function invalidRow(InvalidSourceRecord $record): array
    {
        return [
            'source_ref'             => $record->sourceRef,
            'source_subscription_id' => (int) preg_replace('/\D+/', '', $record->sourceRef),
            'kind'                   => 'invalid',
            'status'                 => '',
            'gateway'                => '',
            'requires_manual_renewal' => false,
            'currency'               => '',
            'cadence'                => '',
            'target_interval'        => null,
            'recurring_total'        => 0,
            'next_payment_utc'       => null,
            'source_payment_count'   => 0,
            'outcome'                => SubscriptionAssessment::OUTCOME_BLOCKED,
            'strategy'               => '',
            'collection_method'      => '',
            'next_action_owner'      => '',
            'payment_outcome'        => '',
            'payment_reason_codes'   => [],
            'error_codes'            => $record->reasonCodes,
            'warning_codes'          => [],
            'reason_codes'           => $record->reasonCodes,
            'messages'               => array_map(
                fn (string $code): array => [
                    'code'     => $code,
                    'severity' => self::SEVERITY_BLOCKING,
                    'origin'   => 'dataset',
                    // These codes ARE the contents of an invalid-source-record
                    // envelope, at the record level exactly as they are at the
                    // closure level. Labelling them standalone here would
                    // cancel the nesting the summary is trying to reveal.
                    'nested_in' => ClosureReport::CODE_INVALID_SOURCE_RECORD,
                    'message'  => sprintf(
                        'Source record %s could not be decoded (%s). Nothing was migrated: repair it in '
                        . 'WooCommerce and export again.',
                        $record->sourceRef,
                        $code,
                    ),
                ],
                $record->reasonCodes,
            ),
            'mapping'                => [
                'source_product_id'   => 0,
                'source_variation_id' => 0,
                'target_product_id'   => null,
                'target_variation_id' => null,
                'needs_mapping'       => false,
            ],
            'customer'               => [
                'resolved_in_id_map' => false,
                'guest'              => false,
                'source_ref'         => '',
                'identity_hash'      => '',
                'resolution'         => [
                    'status'              => '',
                    'outcome'             => null,
                    'reason_code'         => null,
                    'would_create'        => false,
                    'matched_target_user' => false,
                ],
            ],
            'stripe_token'           => null,
            'stripe_remote_schedule' => false,
            'safe_snapshot'          => $record->safeSnapshot,
        ];
    }

    // ──────────────────────────────────────────────
    // The document
    // ──────────────────────────────────────────────

    /**
     * @param array<string, mixed> $context
     * @param array<string, mixed> $analysis
     * @return array<string, mixed>
     */
    private function document(array $context, array $analysis): array
    {
        /** @var ClosureReport $closure */
        $closure = $analysis['closure'];
        /** @var DatasetManifest $manifest */
        $manifest = $analysis['manifest'];
        /** @var RuntimeCompatibilityReport $target */
        $target = $analysis['target'];
        /** @var PaymentEnvironment $environment */
        $environment = $analysis['environment'];
        /** @var list<array<string, mixed>> $rows */
        $rows = $analysis['rows'];

        $assessed = $this->assessedRows($rows);

        return [
            'mode'         => 'subscription_audit',
            'writes'       => $this->writesStatement(),
            'source'       => [
                'mode'                  => $context['mode'],
                'source_key'            => $context['source_key'],
                'file'                  => $context['file'],
                'package'               => $context['package'],
                'selection_fingerprint' => $manifest->selectionFingerprint !== ''
                    ? $manifest->selectionFingerprint
                    : $context['selection']->fingerprint(),
                'storage_authority'     => $manifest->storageAuthority,
            ],
            'manifest'     => [
                'counts'        => $manifest->counts,
                'invalid_count' => $manifest->invalidCount,
                'total_records' => $manifest->totalRecords,
                'currencies'    => $manifest->currencies,
            ],
            // BOTH VERDICTS, and `set_level` is the one that decides anything.
            //
            // §6.2 forces an invalid record to block the affected ENTITY, not
            // the package, and the reference dataset carries exactly one — so
            // `complete` is permanently false for a cohort
            // `SubscriptionCutover::stage()` migrates 563 of 564 of. Exposing
            // only `complete` taught the operator that this screen's red is
            // advisory, which is the opposite of what a gate is for.
            // `set_level` is the same question `stage` asks.
            'closure'      => [
                'complete'        => $closure->isComplete(),
                'set_level'       => $closure->hasSetLevelFault(),
                'set_level_codes' => array_values(array_unique(
                    array_column($closure->setLevelFailures(), 'code'),
                )),
                'counts'          => $closure->counts,
                'reason_codes'    => $closure->reasonCodes(),
            ],
            'totals'       => $this->totals($rows, $manifest),
            'breakdown'    => $this->breakdown($assessed),
            'customers'    => $this->customers($assessed),
            'mapping'      => $this->mapping($assessed, $analysis['products'], (string) $context['source_key']),
            'stripe'       => $this->stripe($assessed),
            'paypal'       => $this->paypal($assessed),
            'target'       => $this->target($target),
            'schedule'     => $this->schedule($assessed),
            'history'      => $this->history($closure),
            'confirmation' => $this->confirmation($assessed, $environment),
            'reasons'      => $this->reasons($rows, $closure),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function writesStatement(): array
    {
        return [
            'nothing'   => true,
            // SCOPED TO WHAT CAN BE PROVEN, deliberately. The earlier wording
            // said "no option, no transient" flatly, which is a larger claim
            // than the evidence: the guard watches CartShift's own writes, and
            // this endpoint also calls into WooCommerce, WCS and FluentCart,
            // any of which may prime a transient of its own on a read. Making
            // a promise bigger than its proof is the exact species of
            // overstatement this whole mode exists to correct, so the sentence
            // says CartShift and names the third parties separately.
            'statement' => 'CartShift writes nothing here. No FluentCart row, no CartShift ID-map row — '
                . 'not even a simulated one — no CartShift option, transient, scheduled action or log '
                . 'line. It reads the source, the target catalogue and CartShift\'s own mappings, and '
                . 'reports what it found. Reading through WooCommerce and FluentCart may of course warm '
                . 'their own caches; nothing about your data changes.',
            // The correction the three run screens already carry, repeated here
            // in the same words rather than a fourth phrasing of it.
            'dry_run_note' => 'The dry run on the migration screens is a different promise: it writes '
                . 'CartShift simulation rows to CartShift\'s own ID-map table so the run can resolve '
                . 'references the way a real one would.',
            'configuration_writes' => [
                [
                    'action' => 'prepare-package',
                    'label'  => 'Prepare a package',
                    'writes' => 'Four strings in one CartShift option: source key, absolute private '
                        . 'path, records checksum, selection fingerprint. It does not copy the package '
                        . 'and it creates no customer, order or subscription.',
                ],
                [
                    'action' => 'mapping-decisions',
                    'label'  => 'Save mapping decisions',
                    'writes' => 'Rows in CartShift\'s mapping staging table. Nothing is promoted into '
                        . 'the ID map until a run starts.',
                ],
                [
                    'action' => 'manual-fallback-confirmation',
                    'label'  => 'Accept the manual fallback',
                    'writes' => 'Nothing yet. Acceptance is expressed through the '
                        . '`cartshift/subscription/manual_fallback_confirmed` filter and, at cutover, '
                        . 'through the stage command. It is a decision about behaviour, not an audit '
                        . 'finding.',
                ],
            ],
        ];
    }

    /**
     * Every count on this screen adds up to the number of subscriptions the
     * selection covers, or the screen says it does not.
     *
     * `reconciles` is not decoration. A readiness breakdown that quietly loses
     * two records is exactly how a migration finishes "successfully" with two
     * subscribers left behind, and §11 Phase E's acceptance test is that the
     * source count and the target counts agree.
     *
     * @param list<array<string, mixed>> $rows
     * @return array<string, int|bool>
     */
    private function totals(array $rows, DatasetManifest $manifest): array
    {
        $assessed = $this->assessedRows($rows);

        $counts = [
            SubscriptionAssessment::OUTCOME_READY                 => 0,
            SubscriptionAssessment::OUTCOME_CONFIRMATION_REQUIRED => 0,
            SubscriptionAssessment::OUTCOME_BLOCKED               => 0,
        ];

        foreach ($assessed as $row) {
            $counts[$row['outcome']] = ($counts[$row['outcome']] ?? 0) + 1;
        }

        $invalid  = count($rows) - count($assessed);
        $selected = $manifest->countFor(SubscriptionRecord::KIND);

        $totals = [
            'selected'              => $selected,
            'assessed'              => count($assessed),
            'invalid'               => $invalid,
            'ready'                 => $counts[SubscriptionAssessment::OUTCOME_READY],
            'confirmation_required' => $counts[SubscriptionAssessment::OUTCOME_CONFIRMATION_REQUIRED],
            'blocked'               => $counts[SubscriptionAssessment::OUTCOME_BLOCKED],
        ];

        $totals['reconciles'] = $selected
            === $totals['ready'] + $totals['confirmation_required'] + $totals['blocked'] + $invalid;

        return $totals;
    }

    /**
     * @param list<array<string, mixed>> $assessed
     * @return array<string, array<string, int>>
     */
    private function breakdown(array $assessed): array
    {
        return [
            'by_status'            => $this->tally($assessed, 'status'),
            'by_cadence'           => $this->tally($assessed, 'cadence'),
            'by_strategy'          => $this->tally($assessed, 'strategy'),
            'by_collection_method' => $this->tally($assessed, 'collection_method'),
        ];
    }

    /**
     * Who these subscriptions belong to, as far as a read can tell.
     *
     * DELIBERATELY NOT §9.1's RESOLUTION ORDER. `CustomerResolver` implements
     * that in full and *creates* customers on two of its four arms, which would
     * make this endpoint a writing operation. So what is reported is what the
     * CartShift ID map already knows — customers this migration has recorded —
     * plus the source-side identity facts, and the note says so rather than
     * letting an operator read "unresolved" as "will fail".
     *
     * @param list<array<string, mixed>> $assessed
     * @return array<string, mixed>
     */
    private function customers(array $assessed): array
    {
        $resolved = 0;
        $guests   = 0;

        /** @var array<string, array<string, mixed>> $identities One entry per distinct email. */
        $identities = [];
        $blankEmail = 0;

        foreach ($assessed as $row) {
            $resolved += $row['customer']['resolved_in_id_map'] ? 1 : 0;
            $guests   += $row['customer']['guest'] ? 1 : 0;

            $hash = (string) $row['customer']['identity_hash'];

            if ($hash === '') {
                // A SUBSCRIPTION WITH NO EMAIL DOES NOT NORMALLY GET THIS FAR.
                // `SubscriptionRecordFactory` refuses one at decode time with
                // §9.4's `customer_email_missing`, so it arrives as an
                // `InvalidSourceRecord`, lands in `totals.invalid`, and its code
                // reaches the reason list through the invalid-record envelope —
                // which is why `blank_email` reads 0 on a healthy dataset rather
                // than because nobody looked.
                //
                // The branch stays because `SubscriptionRecord`'s constructor is
                // public and takes any string, and it is counted as blocked
                // rather than skipped: silently dropping the one condition §9.1
                // blocks on first would be the same under-reporting as measuring
                // identities by source customer ref.
                //
                // Counted per SUBSCRIPTION here, unlike everything below it.
                // Records with no email cannot be deduplicated into identities —
                // three blank emails may be three people or one — so they are
                // not folded into `unique_identities` and the note says so.
                $blankEmail++;

                continue;
            }

            // COUNTED PER IDENTITY, NOT PER SUBSCRIPTION, and that is what §4.4
            // measures: "43 unique subscription emails match a target user",
            // against 564 subscriptions and 215 distinct emails. Tallying rows
            // would report the same person once per subscription they hold.
            $identities[$hash] ??= $row['customer']['resolution'];
        }

        $resolution = [
            CustomerResolver::OUTCOME_REUSED_CUSTOMER      => 0,
            CustomerResolver::OUTCOME_ADOPTED_TARGET_USER  => 0,
            CustomerResolver::OUTCOME_ATTACHED_TARGET_USER => 0,
            CustomerResolver::OUTCOME_REUSED_GUEST         => 0,
            CustomerResolver::OUTCOME_WOULD_CREATE_GUEST   => 0,
        ];

        // Blank-email subscriptions start the blocked tally rather than being
        // excluded from it. §9.1 step 5 blocks exactly this, `preview()`'s first
        // arm returns exactly this code, and a forecast that reported `blocked:
        // 0` beside `blank_email: 30` would be hiding its most common blocker.
        $blocked      = $blankEmail;
        $blockedCodes = $blankEmail > 0
            ? [CustomerResolver::REASON_EMAIL_MISSING => $blankEmail]
            : [];
        $matchedUser  = 0;
        $wouldCreate  = 0;

        foreach ($identities as $identity) {
            $outcome = (string) ($identity['outcome'] ?? '');

            if ($outcome !== '' && array_key_exists($outcome, $resolution)) {
                $resolution[$outcome]++;
            }

            if (($identity['status'] ?? '') === CustomerResolver::STATUS_BLOCKED) {
                $blocked++;

                $code = (string) ($identity['reason_code'] ?? '');
                $blockedCodes[$code] = ($blockedCodes[$code] ?? 0) + 1;
            }

            $matchedUser += ($identity['matched_target_user'] ?? false) ? 1 : 0;
            $wouldCreate += ($identity['would_create'] ?? false) ? 1 : 0;
        }

        ksort($blockedCodes);

        return [
            'assessed'              => count($assessed),
            // Distinct normalised-email hashes, so a person who bought once
            // registered and once as a guest is one person rather than two.
            'unique_identities'     => count($identities),
            'blank_email'           => $blankEmail,
            'guests_at_source'      => $guests,
            'registered_at_source'  => count($assessed) - $guests,
            'resolved_in_id_map'    => $resolved,
            'unresolved_in_id_map'  => count($assessed) - $resolved,
            // §9.1, forecast per identity by CustomerResolver::preview().
            'resolution'            => $resolution + [
                'blocked'              => $blocked,
                'blocked_reason_codes' => $blockedCodes,
                'matched_target_user'  => $matchedUser,
                'would_create'         => $wouldCreate,
            ],
            'note'                  => 'The resolution figures are a forecast of section 9.1 run '
                . 'read-only, counted per distinct email rather than per subscription. Nothing was '
                . 'created: the two arms that would create a customer are reported as "would create". '
                . 'A subscription with no email at all is refused before it gets this far — it is '
                . 'counted under "unreadable at source" with the code customer_email_missing — so if '
                . 'any reach here they are counted individually rather than as identities, because '
                . 'records with no email cannot be told apart from one another. The ID-map figures are '
                . 'a different question — what this migration has already recorded — and that is what a '
                . 'subscription is gated on today.',
        ];
    }

    /**
     * Which source products these subscriptions bill against, and where each
     * one currently points.
     *
     * `shared_target_variations` is an observation rather than a verdict: two
     * source variations claiming one target variation is allowed when their
     * contracts are equivalent and every decision opts in, and
     * `MappingSetValidator` is the thing that decides that at save time. Repeating
     * its contract key here from a second source of truth is how the audit and
     * the save start disagreeing, so the audit reports the claim and names the
     * screen that judges it.
     *
     * @param list<array<string, mixed>>       $assessed
     * @param array<int, ProductRecord>        $products
     * @return array<string, mixed>
     */
    private function mapping(array $assessed, array $products, string $sourceKey): array
    {
        $bySource = [];

        foreach ($assessed as $row) {
            $productId = (int) $row['mapping']['source_product_id'];

            if ($productId <= 0) {
                continue;
            }

            $bySource[$productId] ??= [
                'source_product_id'   => $productId,
                'name'                => $products[$productId]->name ?? '',
                'sku'                 => $products[$productId]->sku ?? '',
                'subscriptions'       => 0,
                'cadences'            => [],
                'target_product_id'   => $row['mapping']['target_product_id'],
                'target_variation_id' => $row['mapping']['target_variation_id'],
                'mapped'              => !$row['mapping']['needs_mapping'],
                'blocked'             => 0,
            ];

            $bySource[$productId]['subscriptions']++;
            $bySource[$productId]['blocked'] += $row['outcome'] === SubscriptionAssessment::OUTCOME_BLOCKED
                ? 1
                : 0;

            if ($row['cadence'] !== '' && !in_array($row['cadence'], $bySource[$productId]['cadences'], true)) {
                $bySource[$productId]['cadences'][] = $row['cadence'];
            }
        }

        ksort($bySource);

        $decisions = $this->decisions($sourceKey);
        $mapped    = count(array_filter(
            $bySource,
            static fn (array $product): bool => $product['mapped'],
        ));

        return [
            'source_products'          => array_values($bySource),
            'decided'                  => count($decisions),
            'mapped'                   => $mapped,
            'undecided'                => count($bySource) - $mapped,
            // FINGERPRINT ONLY. THIS VALIDATOR'S VERDICT IS INERT, BY
            // CONSTRUCTION AND ON PURPOSE.
            //
            // `MappingSetValidation::fingerprint()` hashes `canonical($decisions)`,
            // which depends on the decisions alone — so it is exact here. Its
            // `errors`/`isValid()` are not: constructed with no
            // `$sourceContracts`, `keyFor()` answers `ONE_TIME_KEY` for every
            // source variation, the all-one-time arm short-circuits, and
            // `errors` is ALWAYS empty. Calling `->isValid()` on this instance
            // would return a green light that means nothing — a false clean,
            // not merely a drifting one.
            //
            // Building a real contract index here is not the fix either: it
            // needs live WooCommerce products, and in package mode the target
            // has none. So the audit reports the raw claim index below and the
            // mapping screen — which does have the products — judges it.
            'fingerprint'              => (new MappingSetValidator())->validate($decisions)->fingerprint(),
            'shared_target_variations' => $this->sharedTargets($decisions),
            'note'                     => 'The mapping-set fingerprint is what stage revalidates against. '
                . 'A target variation claimed by more than one source is allowed only when the contracts '
                . 'match and every decision opts in — the mapping screen decides that when you save.',
        ];
    }

    /**
     * The mapping decisions for THIS cohort's source, not for `local`'s.
     *
     * The container binds one `ProductMapRepository`, pinned to
     * `Constants::DEFAULT_SOURCE_KEY` (`MigrationModule`). Every package audit
     * has a source key that is not `local` — it is whatever the exporting site
     * called itself — so the bound repository answered about a different
     * namespace entirely, while `SubscriptionCutover::decisions()` reads
     * `new ProductMapRepository($sourceKey)`. `mapping.decided`,
     * `mapping.mapped`, `mapping.undecided`, `shared_target_variations` and
     * `mapping.fingerprint` all described somebody else's decisions, under a
     * note claiming the fingerprint is what stage revalidates against.
     *
     * The binding is still honoured when it speaks for this source, which is
     * what makes the injected repository usable in tests and on the `local`
     * route.
     *
     * Read-only on this path — `all()` and nothing else; the zero-write guard is
     * what proves it.
     *
     * @return list<ProductMapDecision>
     */
    private function decisions(string $sourceKey): array
    {
        $bound = $this->container->has(ProductMapRepository::class)
            ? $this->container->get(ProductMapRepository::class)
            : null;

        $repository = $bound instanceof ProductMapRepository && $bound->sourceKey() === $sourceKey
            ? $bound
            : new ProductMapRepository($sourceKey);

        return array_values($repository->all());
    }

    /**
     * @param list<ProductMapDecision> $decisions
     * @return list<array<string, mixed>>
     */
    private function sharedTargets(array $decisions): array
    {
        $claims = [];

        foreach ($decisions as $decision) {
            if (!$decision->isLink()) {
                continue;
            }

            foreach ($decision->variantMap() as $sourceVariationId => $targetVariationId) {
                $claims[(int) $targetVariationId][] = [
                    'wc_id'               => $decision->wcId(),
                    'source_variation_id' => (int) $sourceVariationId,
                    'allow_shared_target' => $decision->allowSharedTarget(),
                ];
            }
        }

        ksort($claims);

        $shared = [];

        foreach ($claims as $targetVariationId => $claimants) {
            if (count($claimants) > 1) {
                $shared[] = [
                    'target_variation_id' => $targetVariationId,
                    'claimants'           => $claimants,
                ];
            }
        }

        return $shared;
    }

    /**
     * Which token each Stripe subscription would be charged against.
     *
     * §4.3: none of the reference dataset's 367 Stripe subscriptions has a
     * remote subscription ID, 120 carry a modern `pm_`, 246 a legacy `src_`,
     * and one has nothing usable. The three are entirely different pieces of
     * work — nothing, a customer payment-method update, and a sandbox proof —
     * so one "Stripe: 367" would tell an operator nothing they can act on.
     *
     * @param list<array<string, mixed>> $assessed
     * @return array<string, int>
     */
    private function stripe(array $assessed): array
    {
        $split = [
            'total'           => 0,
            'modern'          => 0,
            'legacy'          => 0,
            'missing'         => 0,
            'unrecognised'    => 0,
            'remote_schedule' => 0,
        ];

        foreach ($assessed as $row) {
            if ($row['stripe_token'] === null) {
                continue;
            }

            $split['total']++;
            $split[$row['stripe_token']]++;
            $split['remote_schedule'] += $row['stripe_remote_schedule'] ? 1 : 0;
        }

        return $split;
    }

    /**
     * §8.3's three ordered outcomes, counted.
     *
     * `manual_confirmation` is the expected result for a target with no PPCP
     * plugin evidence and no verified vault, which is what the restored
     * reference snapshot is. It is a safe route, not a failure, and it is
     * counted apart from `blocked` so the screen can say so.
     *
     * @param list<array<string, mixed>> $assessed
     * @return array<string, int>
     */
    private function paypal(array $assessed): array
    {
        $split = [
            'total'               => 0,
            'system'              => 0,
            'automatic'           => 0,
            'manual_confirmation' => 0,
            'manual_accepted'     => 0,
            'blocked'             => 0,
        ];

        foreach ($assessed as $row) {
            if ($row['strategy'] !== PaymentMigrationDecision::STRATEGY_PAYPAL) {
                continue;
            }

            $split['total']++;

            $split[match (true) {
                $row['payment_outcome'] === PaymentMigrationDecision::OUTCOME_BLOCKED => 'blocked',
                $row['collection_method'] === PaymentMigrationDecision::COLLECTION_SYSTEM => 'system',
                $row['collection_method'] === PaymentMigrationDecision::COLLECTION_AUTOMATIC => 'automatic',
                $row['payment_outcome'] === PaymentMigrationDecision::OUTCOME_CONFIRMATION_REQUIRED
                    => 'manual_confirmation',
                default => 'manual_accepted',
            }]++;
        }

        return $split;
    }

    /**
     * The store-wide policy, the population it applies to, the gateway
     * capability, and the hash that binds them.
     *
     * §4.7: the first two inputs are global store policy rather than gateway
     * capabilities, and changing them affects new checkouts and existing
     * renewals. CartShift reports them and changes neither. `approved` is false
     * here always — this is the screen where the fingerprint is read, not the
     * place it is bound.
     *
     * @return array<string, mixed>
     */
    private function target(RuntimeCompatibilityReport $report): array
    {
        $probe = new PaymentCapabilityProbe();

        return [
            'ready'                 => $report->isReady(),
            'errors'                => $report->errors,
            'topology'              => $report->topology->value,
            'subscription_settings' => $report->subscriptionSettings,
            'subscription_census'   => $report->subscriptionCensus,
            'capabilities'          => [
                PaymentCapabilityProbe::GATEWAY_STRIPE => $probe->diagnose(PaymentCapabilityProbe::GATEWAY_STRIPE),
                PaymentCapabilityProbe::GATEWAY_PAYPAL => $probe->diagnose(PaymentCapabilityProbe::GATEWAY_PAYPAL),
            ],
            'approval_fingerprint'  => $report->fingerprint(),
            'approved'              => false,
            'note'                  => 'CartShift never changes FluentCart\'s subscription settings. If '
                . 'system collection is unavailable, change the store settings outside CartShift and '
                . 're-run this audit — then bind the fingerprint above at stage with '
                . '--approve-system-settings. A fingerprint that has moved since invalidates the approval.',
        ];
    }

    /**
     * @param list<array<string, mixed>> $assessed
     * @return array<string, mixed>
     */
    private function schedule(array $assessed): array
    {
        $schedule = [
            'next_payment_missing'          => 0,
            'next_payment_past'             => 0,
            'next_payment_future'           => 0,
            'active_next_date_missing'      => 0,
            'active_next_date_past'         => 0,
            'active_next_date_missing_refs' => [],
            'active_next_date_past_refs'    => [],
        ];

        $now = gmdate('Y-m-d H:i:s');

        foreach ($assessed as $row) {
            $next = $row['next_payment_utc'];

            $schedule[match (true) {
                $next === null || $next === '' => 'next_payment_missing',
                $next > $now                   => 'next_payment_future',
                default                        => 'next_payment_past',
            }]++;

            foreach (
                [
                    SubscriptionAssessment::REASON_ACTIVE_NEXT_DATE_MISSING => 'active_next_date_missing',
                    SubscriptionAssessment::REASON_ACTIVE_NEXT_DATE_PAST    => 'active_next_date_past',
                ] as $code => $key
            ) {
                if (in_array($code, $row['reason_codes'], true)) {
                    $schedule[$key]++;
                    $schedule[$key . '_refs'][] = $row['source_ref'];
                }
            }
        }

        return $schedule;
    }

    /**
     * §10's reconciliation, as far as a read can take it.
     *
     * The closure validator already compares each subscription's WCS payment
     * count against the succeeded, positive charge evidence actually included,
     * and reports both numbers. Forcing them to agree is what §10 forbids, so
     * the audit reports the disagreement and the record stays paused.
     *
     * @return array<string, mixed>
     */
    private function history(ClosureReport $closure): array
    {
        $records = [];

        foreach ($closure->failuresFor(ClosureReport::CODE_HISTORY_COUNT_MISMATCH) as $failure) {
            // The correction travels with the two raw numbers, because
            // `included_paid_orders + billed_cycles_offset` is the arithmetic
            // the validator actually performed. An operator shown only the two
            // ends would reconstruct a different sum and go looking for a
            // discrepancy that is not there.
            $records[] = [
                'source_ref'           => $failure['source_ref'],
                'source_payment_count' => (int) ($failure['context']['source_payment_count'] ?? 0),
                'included_paid_orders' => (int) ($failure['context']['included_paid_orders'] ?? 0),
                'billed_cycles_offset' => (int) ($failure['context']['billed_cycles_offset'] ?? 0),
            ];
        }

        return [
            'mismatches' => count($records),
            'records'    => $records,
            'note'       => 'FluentCart recomputes bill_count from succeeded positive charges linked to '
                . 'the subscription. A count that disagrees with the history included is reported, never '
                . 'forced: the record stays paused until the difference is explained.',
        ];
    }

    /**
     * The expected first-run state, stated so it cannot be mistaken for a bug
     * or for a success.
     *
     * §8.4: manual output "remains `confirmation_required` until the operator
     * accepts the behaviour change and the cutover receipt proves source
     * auto-renewal was disabled". On a target with no provider credentials and
     * no approved settings hash — which is every target until an operator
     * supplies both — that is the entire live cohort, and it migrates nothing.
     *
     * @param list<array<string, mixed>> $assessed
     * @return array<string, mixed>
     */
    private function confirmation(array $assessed, PaymentEnvironment $environment): array
    {
        $awaiting = count(array_filter(
            $assessed,
            static fn (array $row): bool
                => $row['outcome'] === SubscriptionAssessment::OUTCOME_CONFIRMATION_REQUIRED,
        ));

        return [
            'manual_fallback_confirmed' => $environment->manualFallbackConfirmed,
            'awaiting'                  => $awaiting,
            'remedy'                    => 'These records were renewing automatically and would be '
                . 'invoiced by FluentCart instead. Nothing is written until that change is accepted for '
                . 'the cohort — accepting it is a CartShift configuration decision, not an audit finding, '
                . 'and it is expressed through the cartshift/subscription/manual_fallback_confirmed '
                . 'filter and the stage command. Until then this is the expected result, not a failure.',
        ];
    }

    // ──────────────────────────────────────────────
    // Reason codes
    // ──────────────────────────────────────────────

    /**
     * Every reason code the dataset produced, with the records it applies to.
     *
     * THE NESTED CODES ARE THE POINT. `source_encoding_invalid` has no
     * first-class failure of its own: it arrives only inside
     * `context.reason_codes` of an `invalid_source_record` failure, unlike
     * `dataset_foreign_source_key`, which is a failure code in its own right. A
     * list built from the outer `code` alone therefore shows a mangled source
     * row as the generic "invalid record" and hides the single line that says
     * how to repair it — on the one screen built to reveal exactly that. So any
     * failure whose context carries `reason_codes` contributes those too,
     * labelled with what they were nested in, and the outer code stays where it
     * was rather than being replaced by them.
     *
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private function reasons(array $rows, ClosureReport $closure): array
    {
        $reasons = [];

        foreach ($closure->failures as $failure) {
            $this->recordReason(
                $reasons,
                (string) $failure['code'],
                self::SEVERITY_BLOCKING,
                'closure',
                (string) $failure['source_ref'],
                null,
            );

            foreach ((array) ($failure['context']['reason_codes'] ?? []) as $nested) {
                $this->recordReason(
                    $reasons,
                    (string) $nested,
                    self::SEVERITY_BLOCKING,
                    'closure',
                    (string) $failure['source_ref'],
                    (string) $failure['code'],
                );
            }
        }

        foreach ($rows as $row) {
            foreach ($row['messages'] as $message) {
                $this->recordReason(
                    $reasons,
                    (string) $message['code'],
                    (string) $message['severity'],
                    (string) ($message['origin'] ?? 'assessment'),
                    (string) $row['source_ref'],
                    $message['nested_in'] ?? null,
                );
            }
        }

        $reasons = array_values($reasons);

        usort(
            $reasons,
            static fn (array $a, array $b): int
                => [self::SEVERITY_RANK[$a['severity']], $a['code']]
                <=> [self::SEVERITY_RANK[$b['severity']], $b['code']],
        );

        return array_map($this->describeReason(...), $reasons);
    }

    /**
     * @param array<string, array<string, mixed>> $reasons
     */
    private function recordReason(
        array &$reasons,
        string $code,
        string $severity,
        string $origin,
        string $sourceRef,
        ?string $nestedIn,
    ): void {
        if ($code === '') {
            return;
        }

        $reasons[$code] ??= [
            'code'        => $code,
            'severity'    => $severity,
            'origins'     => [],
            'nested_in'   => null,
            'standalone'  => false,
            'source_refs' => [],
        ];

        // THREE FIELDS, THREE MERGE RULES, ALL OF THEM EXPLICIT. `??=` on the
        // whole entry was doing the merging for two of them, which meant a code
        // that turned up standalone first and nested later — or the other way
        // round — was labelled by whichever occurrence happened to be iterated
        // first. Severity was already merged properly; these two now are too.

        // Most severe wins. One code can be a block on one record and a note on
        // another — `manual_confirmation_required` is exactly that — and
        // reporting the gentler reading would let a cohort-stopping refusal
        // read as a footnote.
        if (self::SEVERITY_RANK[$severity] < self::SEVERITY_RANK[$reasons[$code]['severity']]) {
            $reasons[$code]['severity'] = $severity;
        }

        // Every origin it was seen from, so `closure` and `assessment` can both
        // be true rather than one silently winning.
        $reasons[$code]['origins'][$origin] = true;

        // `nested_in` describes a code that has NO reporting of its own — which
        // is the entire reason `source_encoding_invalid` needs surfacing. One
        // standalone occurrence disproves that, permanently.
        if ($nestedIn === null) {
            $reasons[$code]['standalone'] = true;
            $reasons[$code]['nested_in']  = null;
        } elseif (!$reasons[$code]['standalone'] && $reasons[$code]['nested_in'] === null) {
            $reasons[$code]['nested_in'] = $nestedIn;
        }

        $reasons[$code]['source_refs'][$sourceRef] = true;
    }

    /**
     * @param array<string, mixed> $reason
     * @return array<string, mixed>
     */
    private function describeReason(array $reason): array
    {
        $descriptor = MigrationErrorCode::coerce($reason['code'])?->toArray();
        $refs       = array_keys($reason['source_refs']);
        $origins    = array_keys($reason['origins']);

        sort($refs);
        sort($origins);

        return [
            'code'             => $reason['code'],
            // The raw code is always present, whether or not the enum knows it.
            // §9.4's table is wider than the enum today — `source_encoding_invalid`
            // and `dataset_foreign_source_key` are both awaiting ratification —
            // and a screen that showed only the codes it had copy for would hide
            // precisely the newest ones.
            'known'            => $descriptor !== null,
            'label'            => $descriptor['label'] ?? $reason['code'],
            'hint'             => $descriptor['hint'] ?? '',
            'category'         => $descriptor['category'] ?? 'unknown',
            'severity'         => $reason['severity'],
            'origin'           => count($origins) === 1 ? $origins[0] : 'multiple',
            'origins'          => $origins,
            'nested_in'        => $reason['nested_in'],
            'expected'         => in_array($reason['code'], self::EXPECTED_WARNINGS, true),
            'count'            => count($refs),
            'source_refs'      => array_slice($refs, 0, self::MAX_SOURCE_REFS),
            'source_ref_total' => count($refs),
            'truncated'        => count($refs) > self::MAX_SOURCE_REFS,
        ];
    }

    // ──────────────────────────────────────────────
    // Small shared pieces
    // ──────────────────────────────────────────────

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private function assessedRows(array $rows): array
    {
        return array_values(array_filter(
            $rows,
            static fn (array $row): bool => $row['kind'] === SubscriptionRecord::KIND,
        ));
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array<string, int>
     */
    private function tally(array $rows, string $key): array
    {
        $tally = [];

        foreach ($rows as $row) {
            $value = (string) ($row[$key] ?? '');
            $value = $value === '' ? '(none)' : $value;

            $tally[$value] = ($tally[$value] ?? 0) + 1;
        }

        ksort($tally);

        return $tally;
    }

    /**
     * The FluentCart interval, or the exact source pair that has no equivalent.
     *
     * §7.2's cadence table has no fallback arm, so an unrepresentable contract
     * has to read as itself — `month/2` — rather than being filed under
     * "monthly" on a summary screen and quietly billing somebody twice as often
     * as they agreed to.
     */
    private function cadence(SubscriptionRecord $record): string
    {
        return $record->contract->targetInterval
            ?? sprintf(
                'unsupported:%s/%d',
                $record->contract->period === '' ? '(none)' : $record->contract->period,
                $record->contract->multiplier,
            );
    }

    /**
     * `pm_`, `src_`/`card_`, nothing, or something nobody recognises.
     *
     * Null for a record that is not on the Stripe gateway at all, so the split
     * counts Stripe subscriptions rather than every record that happens to have
     * no token.
     */
    private function stripeTokenClass(SubscriptionRecord $record): ?string
    {
        if ($record->gateway !== PaymentCapabilityProbe::GATEWAY_STRIPE) {
            return null;
        }

        $token = trim((string) ($record->paymentReferences[StripeReferenceVerifier::REF_METHOD] ?? ''));

        return match (true) {
            $token === ''                     => 'missing',
            str_starts_with($token, 'pm_')    => 'modern',
            str_starts_with($token, 'src_')   => 'legacy',
            str_starts_with($token, 'card_')  => 'legacy',
            default                           => 'unrecognised',
        };
    }

    /**
     * @param list<array{code: string, message: string}> $entries
     * @param list<string> $promoteToConfirmation Codes that are the reason this
     *        record is awaiting a decision, rather than merely notes about it.
     * @return list<array{code: string, message: string, severity: string}>
     */
    private function messages(array $entries, string $severity, array $promoteToConfirmation = []): array
    {
        return array_map(
            static function (array $entry) use ($severity, $promoteToConfirmation): array {
                $code = (string) ($entry['code'] ?? '');

                return [
                    'code'     => $code,
                    'message'  => (string) ($entry['message'] ?? ''),
                    'severity' => in_array($code, $promoteToConfirmation, true)
                        ? self::SEVERITY_CONFIRMATION
                        : $severity,
                ];
            },
            $entries,
        );
    }

    private function refuse(string $message, int $status): WP_REST_Response
    {
        return new WP_REST_Response(['data' => ['message' => $message, 'writes' => ['nothing' => true]]], $status);
    }
}

<?php

declare(strict_types=1);

namespace CartShift\Domain\Subscription;

use CartShift\Support\Constants;
use CartShift\Support\MoneyHelper;
use DateTimeImmutable;
use DateTimeZone;

defined('ABSPATH') || exit;

/**
 * The only way a source row becomes a record, from either source mode.
 *
 * Two responsibilities, and they are the same responsibility seen twice.
 *
 * DECODING. A live WooCommerce object and a package payload both funnel through
 * one `*FromPayload()` method per kind, so the two modes cannot drift into
 * disagreeing about what a record is. A row that cannot satisfy the constructor
 * contracts in plan section 6.1 comes back as an `InvalidSourceRecord` with
 * stable reason codes — never as a valid record with nulls where the required
 * references should be. The type signatures do that enforcing, not a convention.
 *
 * CANONICALISATION. The fingerprint is SHA-256 over a canonical field set:
 * object keys sorted all the way down, money as integer minor units, dates as
 * explicit UTC strings, no floats and no locale-dependent formatting anywhere
 * near it. Two structurally identical records from a live source and from a
 * package file must produce the same 64 characters, because the cutover treats
 * a changed fingerprint as a changed source and refuses to proceed. This is the
 * same idiom `RuntimeCompatibilityReport` established — one deterministic-JSON
 * convention in this plugin, not two.
 */
final class SubscriptionRecordFactory
{
    // Decode-time reason codes, from the plan's section 9.4 table. The three
    // the closure validator also reports are aliased to its constants rather
    // than spelled out twice — one literal per code, or the two halves of the
    // dataset layer drift apart and a retry stops matching its own blocker.
    public const string REASON_REQUIRED_REFERENCE_MISSING = 'required_reference_missing';
    public const string REASON_CUSTOMER_EMAIL_MISSING = 'customer_email_missing';
    public const string REASON_UNSUPPORTED_CADENCE = 'unsupported_billing_cadence';
    public const string REASON_AMBIGUOUS_RELATIONSHIP = ClosureReport::CODE_AMBIGUOUS_ORDER_RELATIONSHIP;
    public const string REASON_CHECKSUM_MISMATCH = ClosureReport::CODE_CHECKSUM_MISMATCH;
    public const string REASON_INVALID_SOURCE_RECORD = ClosureReport::CODE_INVALID_SOURCE_RECORD;

    /**
     * Section 7.2's cadence table, entire. There is deliberately no default arm:
     * every other period/multiplier pair blocks until somebody adds it with
     * WCS-versus-FluentCart schedule parity tests around month ends and leap
     * years. `FcBillingInterval` collapsing every year multiplier to `yearly` is
     * exactly the behaviour this replaces.
     *
     * @var array<string, string>
     */
    private const array CADENCE = [
        'day:1'   => 'daily',
        'week:1'  => 'weekly',
        'month:1' => 'monthly',
        'month:3' => 'quarterly',
        'month:6' => 'half_yearly',
        'year:1'  => 'yearly',
    ];

    /**
     * The WCS subscription meta this implementation reads, and no more.
     *
     * WooCommerce Subscriptions is not installed on this machine, so nothing
     * beyond the contracts the plan pins is invented here. The PayPal side is
     * absent on purpose: the PPCP plugin source is missing from the restore, a
     * customer ID is not a billing mandate, and guessing which meta key holds a
     * vault ID is how a subscription ends up charging the wrong thing.
     *
     * @var array<string, string>
     */
    private const array PAYMENT_REFERENCE_META = [
        '_stripe_customer_id'     => 'stripe_customer_id',
        '_stripe_source_id'       => 'stripe_source_id',
        '_stripe_subscription_id' => 'stripe_subscription_id',
    ];

    /** @var array<string, string> WCS plan meta, kept verbatim for audit. */
    private const array PLAN_META = [
        '_subscription_length'       => 'length',
        '_subscription_trial_length' => 'trial_length',
        '_subscription_trial_period' => 'trial_period',
        '_subscription_sign_up_fee'  => 'sign_up_fee',
    ];

    /**
     * The `sourcePlan` key carrying the PRODUCT's declared term.
     *
     * Section 9.2 allows the current product's metadata as fallback evidence
     * for a subscription that records no term of its own, provided it raises a
     * warning. That fallback is only possible if the value travels with the
     * record — the writer never touches a live `WC_Product`, and a package is
     * decoded on a site where the WooCommerce product does not exist at all.
     *
     * It is worth knowing why this is the ordinary case rather than a corner.
     * In the preserved Lapka source `_subscription_length` occurs four times
     * across the whole dump, against 1,128 occurrences of
     * `_schedule_next_payment` for 564 subscriptions: WooCommerce Subscriptions
     * writes the term on the two source PRODUCTS and on none of the
     * subscriptions. A reading that only consulted the subscription would
     * therefore find every one of the 564 silent.
     */
    public const string PLAN_PRODUCT_LENGTH = 'product_length';

    /**
     * Marks a record built by an exporter that CONSULTED the product.
     *
     * Without it, "the product declares no term" and "this package predates
     * CartShift reading the product at all" are the same absence, and an
     * operator gets told the product said nothing when nobody ever asked it.
     * On the Lapka source that message would be flatly wrong: both products
     * declare a term.
     *
     * Set whether or not a length was found, because the fact being recorded is
     * that the question was put — not what came back.
     */
    public const string PLAN_PRODUCT_READ = 'product_term_read';
    public const string PLAN_PRODUCT_READ_YES = 'yes';

    /**
     * Where a record's finite term came from: the subscription's own meta, the
     * current product's, or nowhere.
     */
    public const string FINITE_CYCLES_SOURCE = 'finite_cycles_source';
    public const string FINITE_FROM_SUBSCRIPTION = 'declared';
    public const string FINITE_FROM_PRODUCT = 'product';
    public const string FINITE_FROM_NOWHERE = 'undeclared';

    /** @var list<string> The billing fields a WC_Order exposes, if it does. */
    private const array BILLING_FIELDS = [
        'first_name',
        'last_name',
        'company',
        'address_1',
        'address_2',
        'city',
        'state',
        'postcode',
        'country',
        'phone',
    ];

    // ──────────────────────────────────────────────
    // References
    // ──────────────────────────────────────────────

    public static function ref(string $kind, int|string $id): string
    {
        return $kind . ':' . $id;
    }

    /**
     * A guest reference: `guest:` plus SHA-256 of the normalised email.
     *
     * 349 Lapka subscriptions carry `_customer_user = 0`. Keyed on that number
     * they are one mythical customer zero; keyed on the email they are as many
     * people as there are addresses, which is the point.
     */
    public static function guestRef(string $email): string
    {
        return 'guest:' . hash('sha256', self::normaliseEmail($email));
    }

    public static function customerRef(int|null $sourceUserId, string $email): string
    {
        return $sourceUserId !== null && $sourceUserId > 0
            ? self::ref(CustomerRecord::KIND, $sourceUserId)
            : self::guestRef($email);
    }

    public static function normaliseEmail(string $email): string
    {
        // mb_strtolower where available: an address may carry non-ASCII in its
        // local part, and strtolower() is byte-wise and locale-dependent.
        $trimmed = trim($email);

        return function_exists('mb_strtolower') ? mb_strtolower($trimmed, 'UTF-8') : strtolower($trimmed);
    }

    /**
     * A `WC_Subscription::get_date()` answer as a date string, or null.
     *
     * WooCommerce Subscriptions returns the INTEGER `0` for a date that is not
     * set — `WC_Subscription::get_date()` is documented `@return string|int`,
     * and `WC_Subscription::get_date_or_zero()` is what its own callers reach
     * for. It does NOT return `''`. Cast that sentinel straight to a string and
     * the decoder is handed `'0'`, which is present, unparseable, and therefore
     * `required_reference_missing` — on a subscription whose only sin is having
     * no trial.
     *
     * That is not a decoder problem and it is not fixed by loosening the
     * decoder: `'0'` genuinely is not a date, and a package that carries one
     * should still be refused. It is a translation problem, and this is the
     * translation. Every live `get_date()` read goes through here.
     *
     * `'0000-00-00 00:00:00'` is folded in for the same reason: legacy MySQL
     * zero dates are "not set" spelled differently, and CPT-authority sites
     * still have them.
     *
     * @return string|null Null when the source says "not set".
     */
    public static function wcsDate(mixed $value): string|null
    {
        // Anything that is not a scalar cannot be stringified safely, and no
        // WCS date type answers with one. Absent is the honest reading.
        if (!is_string($value) && !is_int($value) && !is_float($value)) {
            return null;
        }

        $value = trim((string) $value);

        return in_array($value, ['', '0', '0000-00-00 00:00:00'], true) ? null : $value;
    }

    // ──────────────────────────────────────────────
    // Canonicalisation
    // ──────────────────────────────────────────────

    /**
     * SHA-256 over the canonical JSON of a value. The one hashing entry point.
     *
     * @param array<array-key, mixed> $value
     */
    public static function digest(array $value): string
    {
        return hash('sha256', self::canonicalJson($value));
    }

    /**
     * The canonical JSON of a value. Total, injective, and never silently empty.
     *
     * This used to be `(string) json_encode(...)`, which is a trapdoor.
     * `json_encode()` returns `false` on malformed UTF-8, the cast turns `false`
     * into `''`, and every such record then fingerprints as SHA-256 of the empty
     * string — the same 64 characters for all of them. Nothing surfaces:
     * `dataset_duplicate_reference` cannot fire because the two fingerprints are
     * equal, a tampered payload passes `verifyDeclaredFingerprint()`, and the
     * cutover's `source_fingerprint_changed` marker — the one mechanism section
     * 11 Phase 0 has for aborting a run whose source moved underneath it — stops
     * moving. A restored Polish WooCommerce database full of Latin-1 mangled
     * billing names is exactly the input that triggers it.
     *
     * So the canonical form is built by `canonicalise()`, which replaces any
     * string that is not valid UTF-8 with a `sha256:` marker over its raw bytes.
     * That is deterministic, always encodable, and injective — two different
     * mangled byte sequences produce two different markers and therefore two
     * different fingerprints, which is the property the collision destroyed.
     *
     * `JSON_THROW_ON_ERROR` covers what is left: INF, NAN, recursion and depth
     * are programmer errors rather than source data, and must be loud rather
     * than quietly hashing to a constant.
     *
     * @param array<array-key, mixed> $value
     */
    public static function canonicalJson(array $value): string
    {
        return json_encode(
            self::canonicalise($value),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR,
        );
    }

    /**
     * Sorted, and with every unencodable string replaced by a stable marker.
     *
     * @param array<array-key, mixed> $value
     * @return array<array-key, mixed>
     */
    public static function canonicalise(array $value): array
    {
        /** @var array<array-key, mixed> $escaped */
        $escaped = self::escapeMalformedText($value);

        return self::sortDeep($escaped);
    }

    /**
     * Whether a string is valid UTF-8 and can therefore survive JSON encoding.
     *
     * `preg_match('//u', ...)` is the fallback rather than an afterthought: this
     * plugin already has a test harness for running without mbstring
     * (tests/stubs/MbstringAbsence.php), and a canonicalisation that silently
     * changed answer depending on an installed extension would be worse than the
     * bug it replaces.
     */
    public static function isText(string $value): bool
    {
        return function_exists('mb_check_encoding')
            ? mb_check_encoding($value, 'UTF-8')
            : preg_match('//u', $value) === 1;
    }

    /**
     * A string as itself, or a stable injective marker when it is not text.
     *
     * The marker hashes the raw bytes rather than showing them: a malformed
     * value may be a mangled payment reference, and the plan's Global
     * Constraints keep those out of fingerprint inputs, logs and reports. A
     * SHA-256 keeps two different byte sequences apart without disclosing
     * either.
     */
    public static function textOrMarker(string $value): string
    {
        return self::isText($value) ? $value : 'sha256:' . hash('sha256', $value);
    }

    private static function escapeMalformedText(mixed $value): mixed
    {
        if (is_string($value)) {
            return self::textOrMarker($value);
        }

        // AN OBJECT IS A CONTAINER TOO, and one whose properties this used to
        // hand straight back — so a mangled byte sequence one level inside a
        // `stdClass` walked past the marker substitution and took
        // `json_encode()` down with it. That was unreachable only because no
        // current caller passes an object, and three `digest()`/`canonicalJson()`
        // call sites take one from outside this class. Reachability is a poor
        // thing for a fingerprint's totality to rest on.
        //
        // Rebuilt as a `stdClass` rather than as an array: an empty object must
        // stay `{}` and not become `[]`. Properties are sorted here because
        // `sortDeep()` cannot see inside an object, and an order-dependent
        // fingerprint is the same class of bug one step along.
        if (is_object($value)) {
            $properties = [];

            foreach (get_object_vars($value) as $key => $item) {
                $properties[self::textOrMarker((string) $key)] = self::escapeMalformedText($item);
            }

            ksort($properties);

            $escaped = new \stdClass();

            foreach ($properties as $key => $item) {
                $escaped->{$key} = $item;
            }

            return $escaped;
        }

        if (!is_array($value)) {
            return $value;
        }

        $escaped = [];

        // Keys too. A malformed array key is just as unencodable as a value,
        // and json_encode() fails on the whole document either way.
        foreach ($value as $key => $item) {
            $escaped[is_string($key) ? self::textOrMarker($key) : $key] = self::escapeMalformedText($item);
        }

        return $escaped;
    }

    /**
     * Every field path whose value (or key) is not valid UTF-8, with a marker.
     *
     * The markers are what make the resulting `InvalidSourceRecord` injective:
     * two source rows mangled differently produce different snapshots and so
     * different fingerprints, instead of colliding the way the raw cast did.
     *
     * @return array<string, string> path => marker, sorted by path
     */
    private static function malformedTextPaths(mixed $value, string $prefix = ''): array
    {
        $found = [];

        if (is_string($value)) {
            if (!self::isText($value)) {
                $found[$prefix === '' ? '(root)' : $prefix] = self::textOrMarker($value);
            }

            return $found;
        }

        if (!is_array($value)) {
            return $found;
        }

        foreach ($value as $key => $item) {
            $label = is_string($key) ? self::textOrMarker($key) : (string) $key;
            $path  = $prefix === '' ? $label : $prefix . '.' . $label;

            if (is_string($key) && !self::isText($key)) {
                $found[$path] = $label;
            }

            $found += self::malformedTextPaths($item, $path);
        }

        ksort($found);

        return $found;
    }

    /**
     * Sort every associative array by key, all the way down.
     *
     * Lists keep their order — a related-order sequence is meaningful and is
     * sorted where it is built. Key order is an accident of assembly and must
     * never reach the hash, or a re-export in a different code path reads as a
     * source change and aborts a cutover for nothing.
     *
     * @param array<array-key, mixed> $value
     * @return array<array-key, mixed>
     */
    public static function sortDeep(array $value): array
    {
        $isList = array_is_list($value);

        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = self::sortDeep($item);
            }
        }

        if (!$isList) {
            ksort($value);
        }

        return $value;
    }

    /**
     * The FluentCart interval for a source cadence, or null when there is none.
     */
    public static function targetInterval(string $period, int $multiplier): string|null
    {
        return self::CADENCE[strtolower(trim($period)) . ':' . $multiplier] ?? null;
    }

    // ──────────────────────────────────────────────
    // Envelopes
    // ──────────────────────────────────────────────

    /**
     * @return array{kind: string, payload: array<string, mixed>}
     */
    public static function envelope(
        CustomerRecord|ProductRecord|OrderRecord|SubscriptionRecord|InvalidSourceRecord $record,
    ): array {
        return ['kind' => $record->kind(), 'payload' => $record->toArray()];
    }

    /**
     * @param array<string, mixed> $envelope
     */
    public function fromEnvelope(
        array $envelope,
    ): CustomerRecord|ProductRecord|OrderRecord|SubscriptionRecord|InvalidSourceRecord {
        $kind      = (string) ($envelope['kind'] ?? '');
        $payload   = (array) ($envelope['payload'] ?? []);
        $sourceKey = (string) ($payload['source_key'] ?? Constants::DEFAULT_SOURCE_KEY);

        $decoded = match ($kind) {
            CustomerRecord::KIND     => $this->customerFromPayload($sourceKey, $payload),
            ProductRecord::KIND      => $this->productFromPayload($sourceKey, $payload),
            OrderRecord::KIND        => $this->orderFromPayload($sourceKey, $payload),
            SubscriptionRecord::KIND => $this->subscriptionFromPayload($sourceKey, $payload),
            InvalidSourceRecord::KIND => $this->invalidFromPayload($sourceKey, $payload),
            default                  => $this->invalid(
                $sourceKey,
                $kind === '' ? 'unknown' : $kind,
                (string) ($payload['source_ref'] ?? 'unknown'),
                [self::REASON_INVALID_SOURCE_RECORD],
                ['declared_kind' => $kind],
            ),
        };

        return $this->verifyDeclaredFingerprint($decoded, $payload);
    }

    // ──────────────────────────────────────────────
    // Customers
    // ──────────────────────────────────────────────

    /**
     * @param array<string, mixed> $payload
     */
    public function customerFromPayload(string $sourceKey, array $payload): CustomerRecord|InvalidSourceRecord
    {
        $sourceUserId = self::positiveIntOrNull($payload['source_user_id'] ?? null);

        $guard = $this->encodingFailure(
            $sourceKey,
            CustomerRecord::KIND,
            $sourceUserId !== null ? self::ref(CustomerRecord::KIND, $sourceUserId) : 'customer:unknown',
            $payload,
        );

        if ($guard !== null) {
            return $guard;
        }

        $email        = self::normaliseEmail((string) ($payload['email'] ?? ''));
        $sourceRef    = self::customerRef($sourceUserId, $email);

        if ($email === '') {
            return $this->invalid(
                $sourceKey,
                CustomerRecord::KIND,
                $sourceUserId !== null ? self::ref(CustomerRecord::KIND, $sourceUserId) : 'customer:unknown',
                [self::REASON_CUSTOMER_EMAIL_MISSING],
                ['source_user_id' => $sourceUserId],
            );
        }

        $record = new CustomerRecord(
            $sourceKey,
            $sourceRef,
            $sourceUserId,
            $email,
            self::sortDeep((array) ($payload['billing_identity'] ?? [])),
            '',
        );

        return $record->withFingerprint(self::digest($record->fingerprintPayload()));
    }

    // ──────────────────────────────────────────────
    // Products
    // ──────────────────────────────────────────────

    /**
     * @param array<string, mixed> $payload
     */
    public function productFromPayload(string $sourceKey, array $payload): ProductRecord|InvalidSourceRecord
    {
        $productId = (int) ($payload['source_product_id'] ?? 0);

        $guard = $this->encodingFailure(
            $sourceKey,
            ProductRecord::KIND,
            $productId > 0 ? self::ref(ProductRecord::KIND, $productId) : 'product:unknown',
            $payload,
        );

        if ($guard !== null) {
            return $guard;
        }

        if ($productId <= 0) {
            return $this->invalid(
                $sourceKey,
                ProductRecord::KIND,
                'product:unknown',
                [self::REASON_REQUIRED_REFERENCE_MISSING],
                ['field' => 'source_product_id'],
            );
        }

        $variations = [];

        foreach ((array) ($payload['variations'] ?? []) as $variation) {
            $variation = (array) $variation;
            $variationId = (int) ($variation['source_variation_id'] ?? 0);

            $variations[] = self::sortDeep(array_merge($variation, [
                'source_variation_id'  => $variationId,
                'pseudo_variation_key' => (string) ($variation['pseudo_variation_key']
                    ?? ($variationId > 0 ? $variationId : $productId)),
            ]));
        }

        $record = new ProductRecord(
            $sourceKey,
            self::ref(ProductRecord::KIND, $productId),
            $productId,
            (string) ($payload['type'] ?? ''),
            (string) ($payload['name'] ?? ''),
            (string) ($payload['sku'] ?? ''),
            $variations,
            '',
        );

        return $record->withFingerprint(self::digest($record->fingerprintPayload()));
    }

    // ──────────────────────────────────────────────
    // Orders
    // ──────────────────────────────────────────────

    /**
     * @param array<string, mixed> $payload
     */
    public function orderFromPayload(string $sourceKey, array $payload): OrderRecord|InvalidSourceRecord
    {
        $orderId = (int) ($payload['source_order_id'] ?? 0);

        $guard = $this->encodingFailure(
            $sourceKey,
            OrderRecord::KIND,
            $orderId > 0 ? self::ref(OrderRecord::KIND, $orderId) : 'order:unknown',
            $payload,
        );

        if ($guard !== null) {
            return $guard;
        }

        $email = self::normaliseEmail((string) ($payload['billing_email'] ?? ''));

        if ($orderId <= 0) {
            return $this->invalid(
                $sourceKey,
                OrderRecord::KIND,
                'order:unknown',
                [self::REASON_REQUIRED_REFERENCE_MISSING],
                ['field' => 'source_order_id'],
            );
        }

        $dates        = self::utcDates($payload['dates'] ?? [], ['created_utc', 'paid_utc', 'completed_utc']);
        $transactions = self::transactions($payload['transactions'] ?? []);

        if ($dates === null || $transactions === null) {
            return $this->invalid(
                $sourceKey,
                OrderRecord::KIND,
                self::ref(OrderRecord::KIND, $orderId),
                [self::REASON_REQUIRED_REFERENCE_MISSING],
                ['field' => $dates === null ? 'dates' : 'transactions', 'source_order_id' => $orderId],
            );
        }

        // An order may legitimately carry no customer reference of its own —
        // then it is whoever the billing email says, which is what a guest ref
        // means. What it may not do is carry a reference in a shape nothing
        // else in the dataset uses.
        $customerRef = (string) ($payload['source_customer_ref'] ?? '');

        if (!str_starts_with($customerRef, 'customer:') && !str_starts_with($customerRef, 'guest:')) {
            $customerRef = self::customerRef(
                self::positiveIntOrNull($payload['source_customer_id'] ?? null),
                $email,
            );
        }

        $record = new OrderRecord(
            $sourceKey,
            self::ref(OrderRecord::KIND, $orderId),
            $orderId,
            (string) ($payload['status'] ?? ''),
            strtoupper((string) ($payload['currency'] ?? '')),
            $customerRef,
            $email,
            self::sortDeep((array) ($payload['addresses'] ?? [])),
            self::lineItems($payload['items'] ?? []),
            $transactions,
            self::money((array) ($payload['totals'] ?? [])),
            $dates,
            '',
        );

        return $record->withFingerprint(self::digest($record->fingerprintPayload()));
    }

    // ──────────────────────────────────────────────
    // Subscriptions
    // ──────────────────────────────────────────────

    /**
     * @param array<string, mixed> $payload
     */
    public function subscriptionFromPayload(
        string $sourceKey,
        array $payload,
    ): SubscriptionRecord|InvalidSourceRecord {
        $subscriptionId = (int) ($payload['source_subscription_id'] ?? 0);
        $sourceRef      = $subscriptionId > 0
            ? self::ref(SubscriptionRecord::KIND, $subscriptionId)
            : 'subscription:unknown';

        $guard = $this->encodingFailure($sourceKey, SubscriptionRecord::KIND, $sourceRef, $payload);

        if ($guard !== null) {
            return $guard;
        }

        $email        = self::normaliseEmail((string) ($payload['billing_email'] ?? ''));
        $customerId   = self::positiveIntOrNull($payload['source_customer_id'] ?? null);
        $parentId     = (int) ($payload['parent_order_id'] ?? 0);
        $items        = self::lineItems($payload['items'] ?? []);
        $contractSpec = (array) ($payload['contract'] ?? []);
        $period       = strtolower(trim((string) ($contractSpec['period'] ?? '')));
        $multiplier   = (int) ($contractSpec['multiplier'] ?? 0);

        $reasons  = [];
        $snapshot = [
            'source_subscription_id' => $subscriptionId,
            'status'                 => (string) ($payload['status'] ?? ''),
            'currency'               => strtoupper((string) ($payload['currency'] ?? '')),
            'gateway'                => (string) ($payload['gateway'] ?? ''),
            'parent_order_id'        => $parentId,
            'item_count'             => count($items),
            'billing_period'         => $period,
            'billing_interval'       => $multiplier,
        ];

        if ($subscriptionId <= 0) {
            $reasons[] = self::REASON_REQUIRED_REFERENCE_MISSING;
        }

        if ($email === '') {
            $reasons[] = self::REASON_CUSTOMER_EMAIL_MISSING;
        }

        // The malformed Lapka record, twice over: no parent order and no line
        // item. FluentCart's subscriptions table declares both NOT NULL, so
        // there is no valid record to build and none will be built.
        if ($parentId <= 0) {
            $reasons[] = self::REASON_REQUIRED_REFERENCE_MISSING;
        }

        if ($items === [] || !self::itemsAreComplete($items)) {
            $reasons[] = self::REASON_REQUIRED_REFERENCE_MISSING;
        }

        $targetInterval = self::targetInterval($period, $multiplier);

        if ($targetInterval === null) {
            $reasons[] = self::REASON_UNSUPPORTED_CADENCE;
        }

        $dates = self::utcDates(
            $payload['dates'] ?? [],
            ['start_utc', 'trial_end_utc', 'next_payment_utc', 'cancelled_utc', 'end_utc'],
        );

        if ($dates === null || $dates['start_utc'] === null) {
            $reasons[] = self::REASON_REQUIRED_REFERENCE_MISSING;
        }

        $relatedOrderFault = self::relatedOrderFault($payload['related_orders'] ?? []);

        if ($relatedOrderFault !== null) {
            $reasons[] = $relatedOrderFault;
        }

        if ($reasons !== []) {
            return $this->invalid($sourceKey, SubscriptionRecord::KIND, $sourceRef, $reasons, $snapshot);
        }

        /** @var array<string, string|null> $dates */
        $recurringTotal = (int) ($contractSpec['recurring_total'] ?? 0);
        $recurringTax   = (int) ($contractSpec['recurring_tax'] ?? 0);

        $record = new SubscriptionRecord(
            $sourceKey,
            $sourceRef,
            $subscriptionId,
            (string) ($payload['status'] ?? ''),
            strtoupper((string) ($payload['currency'] ?? '')),
            self::customerRef($customerId, $email),
            $customerId,
            $email,
            self::sortDeep((array) ($payload['billing_identity'] ?? [])),
            $parentId,
            $items,
            new SubscriptionContract(
                $period,
                $multiplier,
                $targetInterval,
                (int) ($contractSpec['recurring_amount'] ?? ($recurringTotal - $recurringTax)),
                $recurringTax,
                $recurringTotal,
                self::positiveIntOrNull($contractSpec['finite_cycles'] ?? null),
                (int) ($contractSpec['trial_length'] ?? 0),
                (string) ($contractSpec['trial_period'] ?? ''),
                (int) ($contractSpec['setup_fee'] ?? 0),
                self::finiteCyclesProvenance(
                    (array) ($contractSpec['source_plan'] ?? []),
                    $contractSpec['finite_cycles'] ?? null,
                ),
            ),
            (string) ($payload['gateway'] ?? ''),
            (bool) ($payload['requires_manual_renewal'] ?? false),
            self::paymentReferences($payload['payment_references'] ?? []),
            new SubscriptionDates(
                $dates['start_utc'],
                $dates['trial_end_utc'],
                $dates['next_payment_utc'],
                $dates['cancelled_utc'],
                $dates['end_utc'],
            ),
            self::relatedOrders($payload['related_orders'] ?? []),
            max(0, (int) ($payload['source_payment_count'] ?? 0)),
            '',
        );

        return $record->withFingerprint(self::digest($record->fingerprintPayload()));
    }

    // ──────────────────────────────────────────────
    // Invalid records
    // ──────────────────────────────────────────────

    /**
     * @param array<string, mixed> $payload
     */
    public function invalidFromPayload(string $sourceKey, array $payload): InvalidSourceRecord
    {
        $entityKind = (string) ($payload['entity_kind'] ?? 'unknown');
        $sourceRef  = (string) ($payload['source_ref'] ?? '');

        $guard = $this->encodingFailure($sourceKey, $entityKind, $sourceRef, $payload);

        if ($guard !== null) {
            return $guard;
        }

        $declared = array_map(strval(...), (array) ($payload['reason_codes'] ?? []));
        $known    = array_values(array_intersect($declared, ClosureReport::REASON_CODES));
        $rejected = count($declared) - count($known);

        // Section 9.4: free-form strings do not control cutover. These codes
        // arrive from a file somebody else wrote and then drive retry logic and
        // operator copy, so they are checked rather than believed. Anything
        // unrecognised is counted into the snapshot rather than dropped in
        // silence — a package inventing codes is itself worth knowing about.
        $snapshot = (array) ($payload['safe_snapshot'] ?? []);

        if ($rejected > 0) {
            $snapshot['unrecognised_reason_codes'] = $rejected;
        }

        return $this->invalid(
            $sourceKey,
            $entityKind,
            $sourceRef,
            $known === [] ? [self::REASON_INVALID_SOURCE_RECORD] : $known,
            $snapshot,
        );
    }

    /**
     * A record whose text is not text cannot be canonicalised, so it is not a
     * record.
     *
     * Refusing it here is what keeps `canonicalJson()`'s marker substitution a
     * safety net rather than the primary behaviour: an operator gets a counted,
     * blocked record naming the exact field paths to go and repair in
     * WooCommerce, instead of a fingerprint that quietly means nothing. The
     * markers in the snapshot are SHA-256 over the offending bytes — injective,
     * so two differently mangled rows do not collide, and non-disclosing, so a
     * mangled payment reference does not end up in a report.
     *
     * @param array<string, mixed> $payload
     */
    private function encodingFailure(
        string $sourceKey,
        string $entityKind,
        string $sourceRef,
        array $payload,
    ): InvalidSourceRecord|null {
        $malformed = self::malformedTextPaths($payload);

        if ($malformed === []) {
            return null;
        }

        return $this->invalid(
            $sourceKey,
            $entityKind,
            self::textOrMarker($sourceRef),
            [ClosureReport::CODE_SOURCE_ENCODING_INVALID],
            ['malformed_fields' => $malformed],
        );
    }

    /**
     * @param list<string>         $reasonCodes
     * @param array<string, mixed> $safeSnapshot
     */
    public function invalid(
        string $sourceKey,
        string $entityKind,
        string $sourceRef,
        array $reasonCodes,
        array $safeSnapshot,
    ): InvalidSourceRecord {
        $codes = array_values(array_unique($reasonCodes));
        sort($codes);

        $record = new InvalidSourceRecord(
            $sourceKey,
            $entityKind,
            $sourceRef,
            $codes,
            self::sortDeep($safeSnapshot),
            '',
        );

        return $record->withFingerprint(self::digest($record->fingerprintPayload()));
    }

    // ──────────────────────────────────────────────
    // Live WooCommerce reads
    // ──────────────────────────────────────────────

    /**
     * Read a live WCS subscription into the same payload a package carries.
     *
     * The typed related orders are supplied by the caller rather than read here,
     * because the plan requires four separate
     * `get_related_orders('ids', $type)` calls and the source adapter is what
     * owns the WooCommerce runtime. Absent them, only the parent relationship is
     * known — which is honest, and is what the closure validator will say.
     *
     * @param array<string, list<int>> $relatedOrdersByType
     */
    public function subscriptionFromWoo(
        string $sourceKey,
        object $subscription,
        array $relatedOrdersByType = [],
    ): SubscriptionRecord|InvalidSourceRecord {
        return $this->subscriptionFromPayload(
            $sourceKey,
            $this->wooSubscriptionPayload($subscription, $relatedOrdersByType),
        );
    }

    /**
     * The customer behind a live subscription, from the subscription itself.
     *
     * `SubscriptionMigrator` currently claims a subscription carries no billing
     * details. It is not true for this dataset: all 349 guest subscriptions have
     * a billing email, and every one of the 564 has a resolvable one.
     */
    public function customerFromWoo(string $sourceKey, object $subscription): CustomerRecord|InvalidSourceRecord
    {
        $userId = self::positiveIntOrNull(self::call($subscription, 'get_customer_id'));

        return $this->customerFromPayload($sourceKey, [
            'source_user_id'   => $userId,
            'email'            => (string) (self::call($subscription, 'get_billing_email') ?? ''),
            'billing_identity' => self::billingIdentity($subscription),
        ]);
    }

    /**
     * @param array<string, list<int>> $relatedOrdersByType
     * @return array<string, mixed>
     */
    private function wooSubscriptionPayload(object $subscription, array $relatedOrdersByType): array
    {
        $parentId = (int) (self::call($subscription, 'get_parent_id') ?? 0);

        $items = [];

        // WooCommerce keys line items by order-item ID, and that key is the only
        // place the item's own ID appears — WC_Order_Item_Product has no
        // get_id() in the pinned stub, and inventing one would be a WCS API this
        // plan has not verified.
        foreach ((array) (self::call($subscription, 'get_items') ?? []) as $itemId => $item) {
            $productId   = (int) $item->get_product_id();
            $variationId = (int) $item->get_variation_id();

            $items[] = [
                'source_item_id'       => (int) $itemId,
                'source_product_id'    => $productId,
                'source_variation_id'  => $variationId,
                'pseudo_variation_key' => (string) ($variationId > 0 ? $variationId : $productId),
                'name'                 => (string) $item->get_name(),
                'quantity'             => (int) $item->get_quantity(),
                'line_total'           => MoneyHelper::toCents($item->get_total()),
                'line_tax'             => MoneyHelper::toCents($item->get_total_tax()),
            ];
        }

        $total = MoneyHelper::toCents((string) (self::call($subscription, 'get_total') ?? '0'));
        $tax   = MoneyHelper::toCents((string) (self::call($subscription, 'get_total_tax') ?? '0'));

        $related = $relatedOrdersByType === [] && $parentId > 0
            ? [SubscriptionOrderReference::PARENT => [$parentId]]
            : $relatedOrdersByType;

        $relatedOrders = [];

        foreach ($related as $relationship => $orderIds) {
            foreach ((array) $orderIds as $orderId) {
                $relatedOrders[] = [
                    'source_order_id' => (int) $orderId,
                    'relationship'    => (string) $relationship,
                ];
            }
        }

        return [
            'source_subscription_id'  => (int) (self::call($subscription, 'get_id') ?? 0),
            'status'                  => (string) (self::call($subscription, 'get_status') ?? ''),
            'currency'                => (string) (self::call($subscription, 'get_currency') ?? ''),
            'source_customer_id'      => self::positiveIntOrNull(self::call($subscription, 'get_customer_id')),
            'billing_email'           => (string) (self::call($subscription, 'get_billing_email') ?? ''),
            'billing_identity'        => self::billingIdentity($subscription),
            'parent_order_id'         => $parentId,
            'items'                   => $items,
            'contract'                => [
                'period'           => (string) (self::call($subscription, 'get_billing_period') ?? ''),
                'multiplier'       => (int) (self::call($subscription, 'get_billing_interval') ?? 0),
                'recurring_amount' => $total - $tax,
                'recurring_tax'    => $tax,
                'recurring_total'  => $total,
                // The subscription's own contract, never the current product's,
                // and never parent-total-minus-recurring-total: both Lapka
                // source plans have a zero setup fee and that subtraction is
                // what manufactured the phantom PLN 50 the plan reports.
                'finite_cycles'    => self::positiveIntOrNull(self::meta($subscription, '_subscription_length')),
                'trial_length'     => (int) self::meta($subscription, '_subscription_trial_length'),
                'trial_period'     => (string) self::meta($subscription, '_subscription_trial_period'),
                'setup_fee'        => MoneyHelper::toCents(
                    (string) (self::meta($subscription, '_subscription_sign_up_fee') ?: '0'),
                ),
                'source_plan'      => self::planMeta($subscription),
            ],
            'gateway'                 => (string) (self::call($subscription, 'get_payment_method') ?? ''),
            'requires_manual_renewal' => (bool) (
                self::call($subscription, 'get_requires_manual_renewal')
                ?? self::call($subscription, 'is_manual')
                ?? false
            ),
            'payment_references'      => self::wooPaymentReferences($subscription),
            // Through `wcsDate()`, never raw: WCS answers `0` for a date that
            // is not set, and 563 of Lapka's 564 subscriptions have at least
            // one of these unset.
            'dates'                   => [
                'start_utc'        => self::wcsDate(self::call($subscription, 'get_date', 'start')),
                'trial_end_utc'    => self::wcsDate(self::call($subscription, 'get_date', 'trial_end')),
                'next_payment_utc' => self::wcsDate(self::call($subscription, 'get_date', 'next_payment')),
                'cancelled_utc'    => self::wcsDate(self::call($subscription, 'get_date', 'cancelled')),
                'end_utc'          => self::wcsDate(self::call($subscription, 'get_date', 'end')),
            ],
            'related_orders'          => $relatedOrders,
            'source_payment_count'    => (int) (self::call($subscription, 'get_payment_count') ?? 0),
        ];
    }

    // ──────────────────────────────────────────────
    // Normalisation helpers
    // ──────────────────────────────────────────────

    /**
     * @param mixed $items
     * @return list<array<string, mixed>>
     */
    private static function lineItems(mixed $items): array
    {
        $normalised = [];

        foreach ((array) $items as $item) {
            $item        = (array) $item;
            $productId   = (int) ($item['source_product_id'] ?? 0);
            $variationId = (int) ($item['source_variation_id'] ?? 0);

            $normalised[] = [
                'line_tax'             => (int) ($item['line_tax'] ?? 0),
                'line_total'           => (int) ($item['line_total'] ?? 0),
                'name'                 => (string) ($item['name'] ?? ''),
                'pseudo_variation_key' => (string) ($item['pseudo_variation_key']
                    ?? ($variationId > 0 ? $variationId : $productId)),
                'quantity'             => (int) ($item['quantity'] ?? 0),
                'source_item_id'       => (int) ($item['source_item_id'] ?? 0),
                'source_product_id'    => $productId,
                'source_variation_id'  => $variationId,
            ];
        }

        return $normalised;
    }

    /**
     * FluentCart's subscriptions table declares `item_name` NOT NULL and needs a
     * product to point at. An item missing either is not a record with a gap in
     * it; it is a source row somebody has to go and fix.
     *
     * @param list<array<string, mixed>> $items
     */
    private static function itemsAreComplete(array $items): bool
    {
        foreach ($items as $item) {
            if ((int) $item['source_product_id'] <= 0) {
                return false;
            }

            if (trim((string) $item['name']) === '') {
                return false;
            }

            if ((int) $item['quantity'] <= 0) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return list<array<string, mixed>>|null Null when a charge date is present but unparseable.
     */
    private static function transactions(mixed $transactions): array|null
    {
        $normalised = [];

        foreach ((array) $transactions as $transaction) {
            $transaction = (array) $transaction;
            $paidAt      = self::utcDate($transaction['paid_at_utc'] ?? null);

            if ($paidAt === false) {
                return null;
            }

            $normalised[] = [
                'currency'              => strtoupper((string) ($transaction['currency'] ?? '')),
                'gateway'               => (string) ($transaction['gateway'] ?? ''),
                'paid_at_utc'           => $paidAt,
                'source_transaction_id' => (string) ($transaction['source_transaction_id'] ?? ''),
                'status'                => (string) ($transaction['status'] ?? ''),
                'total'                 => (int) ($transaction['total'] ?? 0),
                'type'                  => (string) ($transaction['type'] ?? 'charge'),
            ];
        }

        return $normalised;
    }

    /**
     * @param array<string, mixed> $totals
     * @return array<string, int>
     */
    private static function money(array $totals): array
    {
        $normalised = [];

        foreach ($totals as $key => $value) {
            $normalised[(string) $key] = (int) $value;
        }

        ksort($normalised);

        return $normalised;
    }

    /**
     * @return array<string, string>
     */
    private static function paymentReferences(mixed $references): array
    {
        $normalised = [];

        foreach ((array) $references as $key => $value) {
            $value = trim((string) $value);

            if ($value !== '') {
                $normalised[(string) $key] = $value;
            }
        }

        ksort($normalised);

        return $normalised;
    }

    /**
     * Which fault a related-order list has, if any.
     *
     * Two different faults used to share one `null` return and therefore one
     * reason code. They are not the same thing: a relationship type nobody
     * recognises is an ambiguity about what the order *is*, while an order ID of
     * zero is a reference that is simply missing. Codes are contract — section
     * 9.4 has retry logic keying off them — so a caller told
     * `dataset_ambiguous_order_relationship` would go looking for a typed
     * relationship problem that is not there.
     *
     * @return string|null The reason code, or null when every reference is sound.
     */
    private static function relatedOrderFault(mixed $references): string|null
    {
        foreach ((array) $references as $reference) {
            $reference = (array) $reference;

            if ((int) ($reference['source_order_id'] ?? 0) <= 0) {
                return self::REASON_REQUIRED_REFERENCE_MISSING;
            }

            if (!in_array(
                (string) ($reference['relationship'] ?? ''),
                SubscriptionOrderReference::RELATIONSHIPS,
                true,
            )) {
                return self::REASON_AMBIGUOUS_RELATIONSHIP;
            }
        }

        return null;
    }

    /**
     * @return list<SubscriptionOrderReference> Assumes relatedOrderFault() passed.
     */
    private static function relatedOrders(mixed $references): array
    {
        $normalised = [];

        foreach ((array) $references as $reference) {
            $reference = (array) $reference;

            $normalised[] = [
                (string) ($reference['relationship'] ?? ''),
                (int) ($reference['source_order_id'] ?? 0),
            ];
        }

        // Sorted so two exports of the same subscription cannot differ merely
        // because WooCommerce returned the renewal orders in another order.
        // Duplicates survive on purpose: one order under two relationship types
        // is a question for the closure validator, not something to quietly
        // deduplicate here.
        sort($normalised);

        return array_map(
            static fn (array $pair): SubscriptionOrderReference => new SubscriptionOrderReference($pair[1], $pair[0]),
            $normalised,
        );
    }

    /**
     * @param list<string> $keys
     * @return array<string, string|null>|null Null when any value is present but unparseable.
     */
    private static function utcDates(mixed $dates, array $keys): array|null
    {
        $dates      = (array) $dates;
        $normalised = [];

        foreach ($keys as $key) {
            $value = self::utcDate($dates[$key] ?? null);

            if ($value === false) {
                return null;
            }

            $normalised[$key] = $value;
        }

        ksort($normalised);

        return $normalised;
    }

    /**
     * A source date as an explicit UTC `Y-m-d H:i:s` string.
     *
     * Strict parsing on purpose. `strtotime()` would cheerfully accept most of
     * what it is given and answer with something, and a date this migration
     * cannot read is a fact about the source, not an invitation to improvise.
     *
     * @return string|null|false Null for absent, false for unparseable.
     */
    private static function utcDate(mixed $value): string|null|false
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        if ($value === '' || $value === '0000-00-00 00:00:00') {
            return null;
        }

        $parsed = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $value, new DateTimeZone('UTC'));

        if ($parsed === false || $parsed->format('Y-m-d H:i:s') !== $value) {
            return false;
        }

        return $parsed->format('Y-m-d H:i:s');
    }

    private static function positiveIntOrNull(mixed $value): int|null
    {
        $int = (int) $value;

        return $int > 0 ? $int : null;
    }

    /**
     * A package record must declare a fingerprint, and it must be the right one.
     *
     * The absent case is not a lenient default, it is the tamper check made
     * opt-out: delete the `fingerprint` field from a package line and the record
     * used to decode clean with a freshly computed one, which is precisely the
     * edit an attacker — or a well-meaning hand-edit — would make. An absent
     * fingerprint on a package record is a malformed record.
     *
     * When the decoded record is already invalid the codes are merged rather
     * than replaced: "this row is malformed AND its checksum does not match" is
     * two facts, and an operator reading the receipt needs both.
     *
     * @param array<string, mixed> $payload
     */
    private function verifyDeclaredFingerprint(
        CustomerRecord|ProductRecord|OrderRecord|SubscriptionRecord|InvalidSourceRecord $record,
        array $payload,
    ): CustomerRecord|ProductRecord|OrderRecord|SubscriptionRecord|InvalidSourceRecord {
        $declared = is_string($payload['fingerprint'] ?? null) ? $payload['fingerprint'] : '';

        if ($declared !== '' && hash_equals($record->fingerprint, $declared)) {
            return $record;
        }

        $codes = $record instanceof InvalidSourceRecord ? $record->reasonCodes : [];
        $codes[] = self::REASON_CHECKSUM_MISMATCH;

        return $this->invalid(
            $record->sourceKey,
            $record instanceof InvalidSourceRecord ? $record->entityKind : $record->kind(),
            $record->sourceRef,
            $codes,
            [
                'declared_fingerprint' => $declared === '' ? '(absent)' : $declared,
                'computed_fingerprint' => $record->fingerprint,
            ],
        );
    }

    // ──────────────────────────────────────────────
    // Duck-typed WooCommerce access
    // ──────────────────────────────────────────────

    /**
     * Call a getter if the object has one, otherwise answer null.
     *
     * WooCommerce Subscriptions is not installed on this machine, so the source
     * object is whatever the runtime hands over. Guarding each call keeps a
     * missing optional getter — a billing field, say — from turning into a fatal
     * error halfway through an export.
     */
    private static function call(object $subject, string $method, mixed ...$arguments): mixed
    {
        return method_exists($subject, $method) ? $subject->{$method}(...$arguments) : null;
    }

    private static function meta(object $subscription, string $key): mixed
    {
        return self::call($subscription, 'get_meta', $key) ?? '';
    }

    /**
     * @return array<string, string>
     */
    private static function wooPaymentReferences(object $subscription): array
    {
        $references = [];

        foreach (self::PAYMENT_REFERENCE_META as $metaKey => $name) {
            $value = trim((string) self::meta($subscription, $metaKey));

            if ($value !== '') {
                $references[$name] = $value;
            }
        }

        return self::paymentReferences($references);
    }

    /**
     * The WCS plan meta this record carries, plus the product's declared term.
     *
     * The product's `_subscription_length` is read here rather than left to the
     * writer because the writer has no live WooCommerce object — by design, so
     * the live and package paths cannot disagree — and because a package is
     * decoded on a site where that product does not exist. Section 9.2's
     * fallback is only available if the evidence travels with the record.
     *
     * Read from the FIRST line item's product. A subscription with more than
     * one item is blocked before any term matters, so there is no second
     * product whose opinion could be lost.
     *
     * @return array<string, string>
     */
    private static function planMeta(object $subscription): array
    {
        $plan = [];

        foreach (self::PLAN_META as $metaKey => $name) {
            $value = trim((string) self::meta($subscription, $metaKey));

            if ($value !== '') {
                $plan[$name] = $value;
            }
        }

        $plan[self::PLAN_PRODUCT_READ] = self::PLAN_PRODUCT_READ_YES;

        $productLength = self::productDeclaredLength($subscription);

        if ($productLength !== null) {
            $plan[self::PLAN_PRODUCT_LENGTH] = $productLength;
        }

        ksort($plan);

        return self::finiteCyclesProvenance($plan, null);
    }

    /**
     * The `_subscription_length` the subscription's own product declares, as
     * the source wrote it, or null when there is no product to ask.
     *
     * `'0'` is a real answer — WooCommerce Subscriptions' encoding of
     * "unlimited" — and is deliberately not collapsed to null, which means
     * "nobody said". Those two are the whole reason this method exists.
     */
    private static function productDeclaredLength(object $subscription): ?string
    {
        $items = (array) (self::call($subscription, 'get_items') ?? []);

        // One item, or no answer.
        //
        // "The first item's product" is only a safe reading because a
        // multi-item subscription cannot be written — but that gate lives in
        // `SubscriptionAssessor`, in another class, and a fallback whose
        // correctness depends on a different class staying blocking for ever is
        // not a guarantee. The record IS built for a multi-item source (the
        // refusal is an assessment error, not a decode refusal), so it would
        // otherwise carry one product's term for a contract that has two.
        if (count($items) !== 1) {
            return null;
        }

        foreach ($items as $item) {
            if (!is_object($item) || !method_exists($item, 'get_product')) {
                return null;
            }

            $product = $item->get_product();

            if (!is_object($product) || !method_exists($product, 'get_meta')) {
                return null;
            }

            $length = trim((string) $product->get_meta('_subscription_length'));

            return $length === '' ? null : $length;
        }

        return null;
    }

    /**
     * Say whether the source answered the "how many cycles" question at all.
     *
     * `SubscriptionContract::$finiteCycles === null` means two different things
     * and Task 8 has to tell them apart: WCS writes `_subscription_length = 0`
     * for a genuinely unlimited plan, and writes nothing at all when the
     * subscription's own meta is silent — in which case section 9.2 says the
     * current product's metadata is fallback evidence that MUST raise a warning.
     * A bare `null` gives the writer no signal that it is looking at an
     * unanswered question rather than an answer of "forever".
     *
     * Recorded inside `sourcePlan` on purpose: the section 6.1 constructor
     * signature is fixed and Task 3 is building against it right now, so this is
     * additive. `sourcePlan` round-trips verbatim through the package, so a
     * provenance decided on the live side survives to the target side, and a
     * package that already carries one is left alone.
     *
     * @param array<string, mixed> $plan
     * @return array<string, mixed>
     */
    private static function finiteCyclesProvenance(array $plan, mixed $finiteCycles): array
    {
        $plan = self::sortDeep($plan);

        if (isset($plan[self::FINITE_CYCLES_SOURCE])) {
            return $plan;
        }

        $plan[self::FINITE_CYCLES_SOURCE] = match (true) {
            array_key_exists('length', $plan),
            self::positiveIntOrNull($finiteCycles) !== null => self::FINITE_FROM_SUBSCRIPTION,

            // Section 9.2's fallback, recorded as a fallback. The writer uses
            // it and warns; it never passes for the subscriber's own answer.
            array_key_exists(self::PLAN_PRODUCT_LENGTH, $plan) => self::FINITE_FROM_PRODUCT,

            default => self::FINITE_FROM_NOWHERE,
        };

        ksort($plan);

        return $plan;
    }

    /**
     * @return array<string, string>
     */
    private static function billingIdentity(object $subject): array
    {
        $identity = [];

        foreach (self::BILLING_FIELDS as $field) {
            $value = trim((string) (self::call($subject, 'get_billing_' . $field) ?? ''));

            if ($value !== '') {
                $identity[$field] = $value;
            }
        }

        ksort($identity);

        return $identity;
    }
}

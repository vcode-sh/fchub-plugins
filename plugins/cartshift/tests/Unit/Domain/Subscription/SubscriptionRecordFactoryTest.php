<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\Domain\Subscription;

use CartShift\Domain\Subscription\CustomerRecord;
use CartShift\Domain\Subscription\InvalidSourceRecord;
use CartShift\Domain\Subscription\OrderRecord;
use CartShift\Domain\Subscription\ProductRecord;
use CartShift\Domain\Subscription\SubscriptionRecord;
use CartShift\Domain\Subscription\SubscriptionRecordFactory;
use CartShift\Tests\Unit\PluginTestCase;

/**
 * The decoder, and the two properties everything downstream leans on.
 *
 * First: a malformed source row cannot become a valid record. The constructors
 * in plan section 6.1 declare `int $parentOrderId` rather than `?int`, so a
 * subscription with no parent order is not a record with a null in it — it is
 * an `InvalidSourceRecord`, and the type system is what enforces that rather
 * than a convention somebody remembers.
 *
 * Second: the fingerprint is the same number whether the record arrived from a
 * live WooCommerce object or from a package file. Task 3 turns that into an
 * acceptance gate; it is proved here, where the canonicalisation is written.
 */
final class SubscriptionRecordFactoryTest extends PluginTestCase
{
    /** @var array<string, callable> */
    private array $shapes;

    private SubscriptionRecordFactory $factory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shapes  = require dirname(__DIR__, 3) . '/fixtures/lapka-subscription-shapes.php';
        $this->factory = new SubscriptionRecordFactory();
    }

    // ──────────────────────────────────────────────
    // References
    // ──────────────────────────────────────────────

    public function testANumericCustomerBecomesATypedReference(): void
    {
        $this->assertSame('customer:660001', SubscriptionRecordFactory::customerRef(660_001, 'a@example.invalid'));
    }

    /**
     * 349 Lapka subscriptions carry `_customer_user = 0`. Keyed on the numeric
     * ID they would all be one mythical customer zero; keyed on the email they
     * are as many people as there are addresses.
     */
    public function testTwoGuestsWithDifferentEmailsDoNotCollide(): void
    {
        $first  = SubscriptionRecordFactory::customerRef(0, 'guest-a@example.invalid');
        $second = SubscriptionRecordFactory::customerRef(0, 'guest-b@example.invalid');

        $this->assertStringStartsWith('guest:', $first);
        $this->assertStringStartsWith('guest:', $second);
        $this->assertNotSame($first, $second);
    }

    public function testAGuestReferenceIsStableAcrossEmailCasingAndPadding(): void
    {
        $this->assertSame(
            SubscriptionRecordFactory::customerRef(0, 'guest-a@example.invalid'),
            SubscriptionRecordFactory::customerRef(null, '  Guest-A@Example.Invalid '),
        );
    }

    public function testAGuestReferenceIsASha256OfTheNormalisedEmail(): void
    {
        $this->assertSame(
            'guest:' . hash('sha256', 'guest-a@example.invalid'),
            SubscriptionRecordFactory::customerRef(0, 'guest-a@example.invalid'),
        );
    }

    // ──────────────────────────────────────────────
    // Decoding a valid subscription
    // ──────────────────────────────────────────────

    public function testAValidSubscriptionPayloadDecodesToARecord(): void
    {
        $record = $this->factory->subscriptionFromPayload('local', $this->shapes['subscriptionPayload']());

        $this->assertInstanceOf(SubscriptionRecord::class, $record);
        $this->assertSame('local', $record->sourceKey);
        $this->assertSame('subscription:910001', $record->sourceRef);
        $this->assertSame('subscription', $record->kind());
        $this->assertSame(880_001, $record->parentOrderId);
        $this->assertSame(660_001, $record->sourceCustomerId);
        $this->assertSame('customer:660001', $record->sourceCustomerRef);
        $this->assertSame(2, $record->sourcePaymentCount);
        $this->assertNotSame('', $record->fingerprint);
    }

    public function testTheContractKeepsTheSourceCadenceAndItsTargetInterval(): void
    {
        $record = $this->factory->subscriptionFromPayload('local', $this->shapes['subscriptionPayload']());

        $this->assertInstanceOf(SubscriptionRecord::class, $record);
        $this->assertSame('month', $record->contract->period);
        $this->assertSame(1, $record->contract->multiplier);
        $this->assertSame('monthly', $record->contract->targetInterval);
        $this->assertSame(2900, $record->contract->recurringTotal);
    }

    public function testRelatedOrdersKeepTheirRelationshipType(): void
    {
        $record = $this->factory->subscriptionFromPayload('local', $this->shapes['subscriptionPayload']());

        $this->assertInstanceOf(SubscriptionRecord::class, $record);
        $this->assertSame(
            [[880_001, 'parent'], [880_501, 'renewal']],
            array_map(
                static fn ($reference): array => [$reference->sourceOrderId, $reference->relationship],
                $record->relatedOrders,
            ),
        );
    }

    // ──────────────────────────────────────────────
    // Decoding refuses to invent
    // ──────────────────────────────────────────────

    /**
     * The malformed Lapka record: no parent order. `int $parentOrderId` means
     * there is no valid record to build, so the decoder must not build one.
     */
    public function testASubscriptionWithNoParentOrderCannotBecomeAValidRecord(): void
    {
        $record = $this->factory->subscriptionFromPayload('local', $this->shapes['subscriptionPayload']([
            'parent_order_id' => 0,
        ]));

        $this->assertInstanceOf(InvalidSourceRecord::class, $record);
        $this->assertSame('subscription', $record->entityKind);
        $this->assertSame('invalid', $record->kind());
        $this->assertContains('required_reference_missing', $record->reasonCodes);
    }

    public function testASubscriptionWithNoLineItemCannotBecomeAValidRecord(): void
    {
        $record = $this->factory->subscriptionFromPayload('local', $this->shapes['subscriptionPayload']([
            'items' => [],
        ]));

        $this->assertInstanceOf(InvalidSourceRecord::class, $record);
        $this->assertContains('required_reference_missing', $record->reasonCodes);
    }

    public function testASubscriptionWithNoResolvableEmailCannotBecomeAValidRecord(): void
    {
        $record = $this->factory->subscriptionFromPayload('local', $this->shapes['subscriptionPayload']([
            'source_customer_id'  => null,
            'source_customer_ref' => '',
            'billing_email'       => '   ',
        ]));

        $this->assertInstanceOf(InvalidSourceRecord::class, $record);
        $this->assertContains('customer_email_missing', $record->reasonCodes);
    }

    /**
     * Section 7.2's cadence table has no fallback arm. A 2-month contract is not
     * "roughly monthly"; FluentCart cannot say it, so nothing may pretend.
     */
    public function testAnUnrepresentableCadenceCannotBecomeAValidRecord(): void
    {
        $record = $this->factory->subscriptionFromPayload('local', $this->shapes['subscriptionPayload']([
            'contract' => ['period' => 'month', 'multiplier' => 2, 'recurring_total' => 2900],
        ]));

        $this->assertInstanceOf(InvalidSourceRecord::class, $record);
        $this->assertContains('unsupported_billing_cadence', $record->reasonCodes);
    }

    public function testEveryCadenceTheTableNamesIsRepresentable(): void
    {
        $expected = [
            ['day', 1, 'daily'],
            ['week', 1, 'weekly'],
            ['month', 1, 'monthly'],
            ['month', 3, 'quarterly'],
            ['month', 6, 'half_yearly'],
            ['year', 1, 'yearly'],
        ];

        foreach ($expected as [$period, $multiplier, $interval]) {
            $this->assertSame(
                $interval,
                SubscriptionRecordFactory::targetInterval($period, $multiplier),
                "{$period} x{$multiplier} must map to {$interval}.",
            );
        }
    }

    public function testEveryOtherCadencePairIsRefused(): void
    {
        foreach ([['month', 2], ['month', 4], ['week', 2], ['year', 2], ['day', 3], ['fortnight', 1]] as [$p, $m]) {
            $this->assertNull(
                SubscriptionRecordFactory::targetInterval($p, $m),
                "{$p} x{$m} has no FluentCart equivalent and must not be given one.",
            );
        }
    }

    /**
     * A safe snapshot exists so an operator can go and repair the source row.
     * It must therefore say what is wrong and what to look at, and must not
     * carry the payment identifiers the plan's Global Constraints keep out of
     * logs and reports.
     */
    public function testAnInvalidRecordCarriesARemediableButNonSecretSnapshot(): void
    {
        $record = $this->factory->subscriptionFromPayload('local', $this->shapes['subscriptionPayload']([
            'parent_order_id' => 0,
        ]));

        $this->assertInstanceOf(InvalidSourceRecord::class, $record);
        $this->assertSame('subscription:910001', $record->sourceRef);
        $this->assertSame(910_001, $record->safeSnapshot['source_subscription_id']);
        $this->assertSame('active', $record->safeSnapshot['status']);

        $encoded = (string) json_encode($record->safeSnapshot);
        $this->assertStringNotContainsString('cus_synthetic_fixture_0001', $encoded);
        $this->assertStringNotContainsString('pm_synthetic_fixture_0001', $encoded);
    }

    // ──────────────────────────────────────────────
    // Fingerprints
    // ──────────────────────────────────────────────

    public function testTheFingerprintIsASha256(): void
    {
        $record = $this->factory->subscriptionFromPayload('local', $this->shapes['subscriptionPayload']());

        $this->assertInstanceOf(SubscriptionRecord::class, $record);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $record->fingerprint);
    }

    /**
     * The record can reproduce its own fingerprint from its own state. That is
     * what lets a later phase re-verify a staged record without keeping the
     * payload it arrived in, and it pins the one canonicalisation to one place.
     */
    public function testARecordCanReproduceItsOwnFingerprint(): void
    {
        $record = $this->factory->subscriptionFromPayload('local', $this->shapes['subscriptionPayload']());

        $this->assertInstanceOf(SubscriptionRecord::class, $record);
        $this->assertSame(
            $record->fingerprint,
            SubscriptionRecordFactory::digest($record->fingerprintPayload()),
        );
    }

    /**
     * Key order is an accident of assembly. Two payloads that say the same thing
     * in a different order are the same record, and must hash identically or
     * every re-export looks like a source change.
     */
    public function testKeyOrderDoesNotChangeTheFingerprint(): void
    {
        $payload = $this->shapes['subscriptionPayload']();
        $shuffled = array_reverse($payload, true);
        $shuffled['contract'] = array_reverse($payload['contract'], true);
        $shuffled['dates']    = array_reverse($payload['dates'], true);

        $first  = $this->factory->subscriptionFromPayload('local', $payload);
        $second = $this->factory->subscriptionFromPayload('local', $shuffled);

        $this->assertInstanceOf(SubscriptionRecord::class, $first);
        $this->assertInstanceOf(SubscriptionRecord::class, $second);
        $this->assertSame($first->fingerprint, $second->fingerprint);
    }

    public function testADifferentSourceKeyIsADifferentRecord(): void
    {
        $payload = $this->shapes['subscriptionPayload']();

        $local = $this->factory->subscriptionFromPayload('local', $payload);
        $club  = $this->factory->subscriptionFromPayload('lapka-klub', $payload);

        $this->assertInstanceOf(SubscriptionRecord::class, $local);
        $this->assertInstanceOf(SubscriptionRecord::class, $club);
        $this->assertNotSame($local->fingerprint, $club->fingerprint);
    }

    public function testAChangedRecurringAmountChangesTheFingerprint(): void
    {
        $payload = $this->shapes['subscriptionPayload']();
        $cheaper = $this->shapes['subscriptionPayload']([
            'contract' => array_merge($payload['contract'], [
                'recurring_amount' => 2400,
                'recurring_total'  => 2400,
            ]),
        ]);

        $first  = $this->factory->subscriptionFromPayload('local', $payload);
        $second = $this->factory->subscriptionFromPayload('local', $cheaper);

        $this->assertInstanceOf(SubscriptionRecord::class, $first);
        $this->assertInstanceOf(SubscriptionRecord::class, $second);
        $this->assertNotSame($first->fingerprint, $second->fingerprint);
    }

    /**
     * A rotated payment token is a source change the cutover has to notice, but
     * the raw token is not one of the canonical fields — a digest of it is. The
     * fingerprint moves; the token stays out of the hashed field set.
     */
    public function testARotatedPaymentTokenChangesTheFingerprintWithoutBeingACanonicalField(): void
    {
        $payload = $this->shapes['subscriptionPayload']();
        $rotated = $this->shapes['subscriptionPayload']([
            'payment_references' => [
                'stripe_customer_id' => 'cus_synthetic_fixture_0001',
                'stripe_source_id'   => 'pm_synthetic_fixture_9999',
            ],
        ]);

        $first  = $this->factory->subscriptionFromPayload('local', $payload);
        $second = $this->factory->subscriptionFromPayload('local', $rotated);

        $this->assertInstanceOf(SubscriptionRecord::class, $first);
        $this->assertInstanceOf(SubscriptionRecord::class, $second);
        $this->assertNotSame($first->fingerprint, $second->fingerprint);

        $canonical = (string) json_encode($first->fingerprintPayload());
        $this->assertStringNotContainsString('pm_synthetic_fixture_0001', $canonical);
        $this->assertStringNotContainsString('cus_synthetic_fixture_0001', $canonical);
    }

    /**
     * Money is an integer count of minor units in the canonical form, never a
     * float. `0.1 + 0.2` is the reason.
     */
    public function testMoneyIsCanonicalisedAsIntegerMinorUnits(): void
    {
        $record = $this->factory->subscriptionFromPayload('local', $this->shapes['subscriptionPayload']());

        $this->assertInstanceOf(SubscriptionRecord::class, $record);

        $canonical = $record->fingerprintPayload();

        $this->assertIsInt($canonical['contract']['recurring_total']);
        $this->assertIsInt($canonical['items'][0]['line_total']);
        $this->assertStringNotContainsString(
            '29.0',
            (string) json_encode($canonical),
            'A decimal string in the canonical form is a conversion that has not happened yet.',
        );
    }

    // ──────────────────────────────────────────────
    // Text that is not text
    // ──────────────────────────────────────────────

    /**
     * The one that mattered. `json_encode()` returns false on malformed UTF-8,
     * `(string) false` is `''`, and every affected record used to fingerprint as
     * SHA-256 of the empty string — the same 64 characters for all of them.
     *
     * `"\xC3\x28"` is a truncated two-byte sequence and `"\xE2\x28\xA1"` a
     * truncated three-byte one: exactly what a Latin-1 column dumped into a
     * utf8mb4 restore produces, which a Polish WooCommerce database has rather a
     * lot of.
     */
    public function testTwoRecordsWithDifferentlyMangledBytesDoNotFingerprintAlike(): void
    {
        $first = $this->factory->subscriptionFromPayload('local', $this->shapes['subscriptionPayload']([
            'billing_identity' => ['city' => "Krak\xC3\x28w"],
        ]));

        $second = $this->factory->subscriptionFromPayload('local', $this->shapes['subscriptionPayload']([
            'billing_identity' => ['city' => "Krak\xE2\x28\xA1w"],
        ]));

        $this->assertNotSame(
            $first->fingerprint,
            $second->fingerprint,
            'Two different mangled byte sequences must not collide onto one fingerprint.',
        );
    }

    /**
     * And neither of them may be the digest of nothing at all — the value every
     * such record used to land on.
     */
    public function testAMangledRecordNeverFingerprintsAsTheEmptyString(): void
    {
        $record = $this->factory->subscriptionFromPayload('local', $this->shapes['subscriptionPayload']([
            'billing_identity' => ['city' => "Krak\xC3\x28w"],
        ]));

        $this->assertNotSame(hash('sha256', ''), $record->fingerprint);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $record->fingerprint);
    }

    /**
     * A string that cannot be canonicalised cannot be written to a utf8mb4
     * column either, so it is a source defect with a field path attached, not a
     * record. The snapshot names the path and hashes the bytes — an operator
     * needs to know which field to repair, not to have a mangled payment
     * reference quoted back at them in a report.
     */
    public function testAMangledSourceFieldIsABlockedRecordNamingTheField(): void
    {
        $record = $this->factory->subscriptionFromPayload('local', $this->shapes['subscriptionPayload']([
            'billing_identity' => ['city' => "Krak\xC3\x28w"],
        ]));

        $this->assertInstanceOf(InvalidSourceRecord::class, $record);
        $this->assertSame(['source_encoding_invalid'], $record->reasonCodes);
        $this->assertArrayHasKey('billing_identity.city', $record->safeSnapshot['malformed_fields']);
        $this->assertStringStartsWith(
            'sha256:',
            $record->safeSnapshot['malformed_fields']['billing_identity.city'],
            'The offending bytes are hashed, not quoted.',
        );
    }

    public function testAMangledRecordStillSurvivesThePackageRoundTrip(): void
    {
        $record = $this->factory->subscriptionFromPayload('local', $this->shapes['subscriptionPayload']([
            'billing_identity' => ['city' => "Krak\xC3\x28w"],
        ]));

        $roundTripped = $this->factory->fromEnvelope(SubscriptionRecordFactory::envelope($record));

        $this->assertInstanceOf(InvalidSourceRecord::class, $roundTripped);
        $this->assertSame($record->fingerprint, $roundTripped->fingerprint);
    }

    /**
     * The safety net under the guard: even if a mangled string reaches the
     * canonicaliser directly — a hand-built record, a future source adapter —
     * the digest must stay injective rather than collapsing.
     */
    public function testTheCanonicaliserItselfKeepsMangledBytesApart(): void
    {
        $first  = SubscriptionRecordFactory::digest(['x' => "\xC3\x28"]);
        $second = SubscriptionRecordFactory::digest(['x' => "\xE2\x28\xA1"]);

        $this->assertNotSame($first, $second);
        $this->assertNotSame(hash('sha256', ''), $first);
        $this->assertNotSame(hash('sha256', ''), $second);
    }

    public function testAMangledArrayKeyIsCaughtToo(): void
    {
        $record = $this->factory->customerFromPayload('local', $this->shapes['customerPayload']([
            'billing_identity' => ["cit\xC3\x28y" => 'Warszawa'],
        ]));

        $this->assertInstanceOf(InvalidSourceRecord::class, $record);
        $this->assertSame(['source_encoding_invalid'], $record->reasonCodes);
    }

    // ──────────────────────────────────────────────
    // Live and package agree
    // ──────────────────────────────────────────────

    /**
     * The acceptance gate Task 3 inherits: one record, two source modes, one
     * fingerprint. If this drifts, every cross-site re-export looks like a
     * source change and the cutover refuses to proceed for no reason at all.
     */
    public function testALiveWooSubscriptionAndItsPackagePayloadFingerprintIdentically(): void
    {
        $live = $this->factory->subscriptionFromWoo(
            'local',
            $this->shapes['monthlyPln29'](),
            ['parent' => [880_001], 'renewal' => [880_501]],
        );

        $this->assertInstanceOf(SubscriptionRecord::class, $live);

        $roundTripped = $this->factory->fromEnvelope(SubscriptionRecordFactory::envelope($live));

        $this->assertInstanceOf(SubscriptionRecord::class, $roundTripped);
        $this->assertSame($live->fingerprint, $roundTripped->fingerprint);
        $this->assertEquals($live, $roundTripped, 'The round trip must be lossless, not merely equal-ish.');
    }

    public function testALiveWooSubscriptionCarriesTheContractTheSourceActuallyHas(): void
    {
        $live = $this->factory->subscriptionFromWoo('local', $this->shapes['monthlyPln24']());

        $this->assertInstanceOf(SubscriptionRecord::class, $live);
        $this->assertSame(2400, $live->contract->recurringTotal, 'PLN 24 is this subscriber\'s contract, not a stale 29.');
        $this->assertSame(0, $live->contract->setupFee, 'Both source plans have a zero setup fee; none may be inferred.');
    }

    public function testALiveGuestSubscriptionKeepsANullCustomerIdAndAnEmailDerivedRef(): void
    {
        $live = $this->factory->subscriptionFromWoo('local', $this->shapes['guestCustomer']());

        $this->assertInstanceOf(SubscriptionRecord::class, $live);
        $this->assertNull($live->sourceCustomerId);
        $this->assertSame(
            'guest:' . hash('sha256', 'guest-910006@example.invalid'),
            $live->sourceCustomerRef,
        );
    }

    /**
     * The one malformed active Lapka record, taken the whole way round. It stays
     * exactly one invalid record with the same reason codes and the same
     * fingerprint — it does not multiply, mutate or evaporate in transit.
     */
    public function testTheMalformedLapkaRecordSurvivesTheRoundTripAsOneBlockedInvalidRecord(): void
    {
        $live = $this->factory->subscriptionFromWoo('local', $this->shapes['malformedNoItemNoParent']());

        $this->assertInstanceOf(InvalidSourceRecord::class, $live);

        $roundTripped = $this->factory->fromEnvelope(SubscriptionRecordFactory::envelope($live));

        $this->assertInstanceOf(InvalidSourceRecord::class, $roundTripped);
        $this->assertSame($live->fingerprint, $roundTripped->fingerprint);
        $this->assertSame($live->reasonCodes, $roundTripped->reasonCodes);
        $this->assertSame('subscription:910014', $roundTripped->sourceRef);
    }

    public function testAPayloadWhoseDeclaredFingerprintDisagreesWithItsContentIsInvalid(): void
    {
        $record = $this->factory->subscriptionFromPayload('local', $this->shapes['subscriptionPayload']());
        $this->assertInstanceOf(SubscriptionRecord::class, $record);

        $tampered = $record->toArray();
        $tampered['fingerprint'] = str_repeat('0', 64);

        $decoded = $this->factory->fromEnvelope(['kind' => 'subscription', 'payload' => $tampered]);

        $this->assertInstanceOf(InvalidSourceRecord::class, $decoded);
        $this->assertContains('dataset_checksum_mismatch', $decoded->reasonCodes);
    }

    // ──────────────────────────────────────────────
    // The other three kinds
    // ──────────────────────────────────────────────

    public function testACustomerPayloadDecodesAndRoundTrips(): void
    {
        $record = $this->factory->customerFromPayload('local', $this->shapes['customerPayload']());

        $this->assertInstanceOf(CustomerRecord::class, $record);
        $this->assertSame('customer', $record->kind());
        $this->assertSame(660_001, $record->sourceUserId);
        $this->assertSame('subscriber-660001@example.invalid', $record->email);
        $this->assertEquals($record, $this->factory->fromEnvelope(SubscriptionRecordFactory::envelope($record)));
    }

    public function testAGuestCustomerPayloadGetsAnEmailDerivedReference(): void
    {
        $record = $this->factory->customerFromPayload('local', $this->shapes['guestCustomerPayload']());

        $this->assertInstanceOf(CustomerRecord::class, $record);
        $this->assertNull($record->sourceUserId);
        $this->assertSame('guest:' . hash('sha256', 'guest-910006@example.invalid'), $record->sourceRef);
    }

    public function testACustomerWithNoEmailIsInvalid(): void
    {
        $record = $this->factory->customerFromPayload('local', $this->shapes['customerPayload'](['email' => '']));

        $this->assertInstanceOf(InvalidSourceRecord::class, $record);
        $this->assertContains('customer_email_missing', $record->reasonCodes);
    }

    public function testAProductPayloadDecodesAndRoundTrips(): void
    {
        $record = $this->factory->productFromPayload('local', $this->shapes['monthlyProductPayload']());

        $this->assertInstanceOf(ProductRecord::class, $record);
        $this->assertSame('product', $record->kind());
        $this->assertSame(CARTSHIFT_LAPKA_MONTHLY_PRODUCT_ID, $record->sourceProductId);
        $this->assertSame(
            (string) CARTSHIFT_LAPKA_MONTHLY_PRODUCT_ID,
            $record->variations[0]['pseudo_variation_key'],
            'A simple Woo subscription product is its own pseudo-variation.',
        );
        $this->assertEquals($record, $this->factory->fromEnvelope(SubscriptionRecordFactory::envelope($record)));
    }

    public function testAnOrderPayloadDecodesAndRoundTrips(): void
    {
        $record = $this->factory->orderFromPayload('local', $this->shapes['parentOrderPayload']());

        $this->assertInstanceOf(OrderRecord::class, $record);
        $this->assertSame('order', $record->kind());
        $this->assertSame(880_001, $record->sourceOrderId);
        $this->assertTrue($record->isPaid());
        $this->assertSame(1, $record->succeededChargeCount());
        $this->assertEquals($record, $this->factory->fromEnvelope(SubscriptionRecordFactory::envelope($record)));
    }

    /**
     * A charge date the migration cannot read is a fact about the source, and
     * nulling it quietly would let a paid renewal arrive as an unpaid one —
     * which is a bill count short, discovered later, by a customer.
     */
    public function testAnOrderWithAnUnreadableChargeDateIsInvalid(): void
    {
        $payload = $this->shapes['parentOrderPayload']();
        $payload['transactions'][0]['paid_at_utc'] = 'sometime in April';

        $record = $this->factory->orderFromPayload('local', $payload);

        $this->assertInstanceOf(InvalidSourceRecord::class, $record);
        $this->assertContains('required_reference_missing', $record->reasonCodes);
    }

    public function testAnOrderWithNoSourceIdIsInvalid(): void
    {
        $record = $this->factory->orderFromPayload('local', $this->shapes['parentOrderPayload']([
            'source_order_id' => 0,
        ]));

        $this->assertInstanceOf(InvalidSourceRecord::class, $record);
        $this->assertContains('required_reference_missing', $record->reasonCodes);
    }

    /**
     * The tamper check must not be opt-out by omission. Deleting the field is
     * the edit somebody makes precisely because it is easier than forging a
     * matching hash.
     */
    public function testAPackageRecordWithNoDeclaredFingerprintIsInvalid(): void
    {
        $record = $this->factory->subscriptionFromPayload('local', $this->shapes['subscriptionPayload']());
        $this->assertInstanceOf(SubscriptionRecord::class, $record);

        $stripped = $record->toArray();
        unset($stripped['fingerprint']);

        $decoded = $this->factory->fromEnvelope(['kind' => 'subscription', 'payload' => $stripped]);

        $this->assertInstanceOf(InvalidSourceRecord::class, $decoded);
        $this->assertContains('dataset_checksum_mismatch', $decoded->reasonCodes);
        $this->assertSame('(absent)', $decoded->safeSnapshot['declared_fingerprint']);
    }

    /**
     * "This row is malformed" and "its checksum does not match" are two facts,
     * and a receipt that reported only the second would send an operator
     * looking for a transfer problem that is not there.
     */
    public function testAnAlreadyInvalidRecordKeepsItsOwnCodesAlongsideTheChecksumFailure(): void
    {
        $invalid = $this->factory->subscriptionFromPayload('local', $this->shapes['subscriptionPayload']([
            'parent_order_id' => 0,
        ]));

        $tampered = $invalid->toArray();
        $tampered['fingerprint'] = str_repeat('0', 64);

        $decoded = $this->factory->fromEnvelope(['kind' => 'invalid', 'payload' => $tampered]);

        $this->assertInstanceOf(InvalidSourceRecord::class, $decoded);
        $this->assertContains('required_reference_missing', $decoded->reasonCodes);
        $this->assertContains('dataset_checksum_mismatch', $decoded->reasonCodes);
    }

    /**
     * Section 9.4: free-form strings do not control cutover. A package chooses
     * its own reason codes, and those codes drive retry logic and operator copy.
     */
    public function testAPackageCannotInventItsOwnReasonCodes(): void
    {
        $decoded = $this->factory->invalidFromPayload('local', [
            'entity_kind'   => 'subscription',
            'source_ref'    => 'subscription:910001',
            'reason_codes'  => ['required_reference_missing', 'please_just_migrate_it'],
            'safe_snapshot' => [],
        ]);

        $this->assertSame(['required_reference_missing'], $decoded->reasonCodes);
        $this->assertSame(
            1,
            $decoded->safeSnapshot['unrecognised_reason_codes'],
            'Codes that were thrown away must be counted, not discarded in silence.',
        );
    }

    public function testAPackageWithNothingButInventedCodesStillBlocks(): void
    {
        $decoded = $this->factory->invalidFromPayload('local', [
            'entity_kind'  => 'subscription',
            'source_ref'   => 'subscription:910001',
            'reason_codes' => ['everything_is_fine_honestly'],
        ]);

        $this->assertSame(['invalid_source_record'], $decoded->reasonCodes);
    }

    /**
     * An order ID of zero is a missing reference; an unrecognised relationship
     * type is an ambiguity. They used to share one return value and therefore
     * one code, and section 9.4 has retry logic keying off the difference.
     */
    public function testAZeroRelatedOrderIdIsAMissingReferenceNotAnAmbiguity(): void
    {
        $record = $this->factory->subscriptionFromPayload('local', $this->shapes['subscriptionPayload']([
            'related_orders' => [['source_order_id' => 0, 'relationship' => 'renewal']],
        ]));

        $this->assertInstanceOf(InvalidSourceRecord::class, $record);
        $this->assertContains('required_reference_missing', $record->reasonCodes);
        $this->assertNotContains('dataset_ambiguous_order_relationship', $record->reasonCodes);
    }

    public function testAnUnknownRelationshipTypeIsStillAnAmbiguity(): void
    {
        $record = $this->factory->subscriptionFromPayload('local', $this->shapes['subscriptionPayload']([
            'related_orders' => [['source_order_id' => 880_501, 'relationship' => 'reincarnation']],
        ]));

        $this->assertInstanceOf(InvalidSourceRecord::class, $record);
        $this->assertContains('dataset_ambiguous_order_relationship', $record->reasonCodes);
        $this->assertNotContains('required_reference_missing', $record->reasonCodes);
    }

    // ──────────────────────────────────────────────
    // Finite term provenance
    // ──────────────────────────────────────────────

    /**
     * `finiteCycles === null` means two different things, and Task 8 has to tell
     * them apart: WCS writes `_subscription_length = 0` for a genuinely
     * unlimited plan, and writes nothing when the subscription's own meta is
     * silent — in which case section 9.2 requires the product fallback to raise
     * a warning. Both Lapka source plans are the first case.
     */
    public function testAnExplicitlyUnlimitedTermIsDistinguishableFromAnUnansweredOne(): void
    {
        $declared = $this->factory->subscriptionFromWoo('local', $this->shapes['monthlyPln29']([
            'meta' => ['_subscription_length' => '0'],
        ]));

        // Three states now, not two. The ordinary Lapka shape is the middle
        // one — the subscription is silent and its product declares — because
        // `_subscription_length` occurs four times in the whole preserved
        // source, on the two products and on none of the 564 subscriptions.
        $fromProduct = $this->factory->subscriptionFromWoo('local', $this->shapes['monthlyPln29']());

        $silent = $this->factory->subscriptionFromWoo('local', $this->shapes['termDeclaredNowhere']());

        $this->assertInstanceOf(SubscriptionRecord::class, $declared);
        $this->assertInstanceOf(SubscriptionRecord::class, $silent);

        $this->assertNull($declared->contract->finiteCycles);
        $this->assertNull($silent->contract->finiteCycles);

        $this->assertInstanceOf(SubscriptionRecord::class, $fromProduct);
        $this->assertNull($fromProduct->contract->finiteCycles);

        $this->assertSame('declared', $declared->contract->sourcePlan['finite_cycles_source']);
        $this->assertSame('product', $fromProduct->contract->sourcePlan['finite_cycles_source']);
        $this->assertSame('undeclared', $silent->contract->sourcePlan['finite_cycles_source']);

        // And the product's own answer travels with the record, because the
        // writer has no live WooCommerce object and a package is decoded on a
        // site where that product does not exist.
        $this->assertSame('0', $fromProduct->contract->sourcePlan['product_length']);
        $this->assertArrayNotHasKey('product_length', $silent->contract->sourcePlan);
    }

    /**
     * A multi-item source carries no product term at all.
     *
     * "The first item's product" is only a safe reading because a multi-item
     * subscription cannot be written — but that gate is an ASSESSMENT error in
     * another class, not a decode refusal, so the record is built regardless.
     * Left ungated, it would carry one product's term as evidence for a
     * contract that has two, and the fallback's correctness would rest on a
     * different class staying blocking for ever.
     */
    public function testAMultiItemSourceCarriesNoProductTerm(): void
    {
        $record = $this->factory->subscriptionFromWoo('local', $this->shapes['multiItem']());

        $this->assertInstanceOf(SubscriptionRecord::class, $record);
        $this->assertArrayNotHasKey('product_length', $record->contract->sourcePlan);
        $this->assertSame('undeclared', $record->contract->sourcePlan['finite_cycles_source']);
        $this->assertSame(
            'yes',
            $record->contract->sourcePlan['product_term_read'],
            'The product WAS consulted; it is the multiplicity that makes its answer unusable.',
        );
    }

    /**
     * And the marker distinguishing "we asked" from "nobody asked" is on every
     * record this factory builds, whatever the answer was.
     */
    public function testEveryLiveRecordRecordsThatTheProductWasConsulted(): void
    {
        foreach (['monthlyPln29', 'termDeclaredNowhere', 'yearlyPln290'] as $shape) {
            $record = $this->factory->subscriptionFromWoo('local', $this->shapes[$shape]());

            $this->assertInstanceOf(SubscriptionRecord::class, $record, $shape);
            $this->assertSame('yes', $record->contract->sourcePlan['product_term_read'], $shape);
        }
    }

    public function testTheFiniteTermProvenanceSurvivesThePackageRoundTrip(): void
    {
        $live = $this->factory->subscriptionFromWoo('local', $this->shapes['monthlyPln29']([
            'meta' => ['_subscription_length' => '0'],
        ]));

        $roundTripped = $this->factory->fromEnvelope(SubscriptionRecordFactory::envelope($live));

        $this->assertInstanceOf(SubscriptionRecord::class, $roundTripped);
        $this->assertSame('declared', $roundTripped->contract->sourcePlan['finite_cycles_source']);
    }

    public function testAnUnknownEnvelopeKindIsInvalidRatherThanFatal(): void
    {
        $decoded = $this->factory->fromEnvelope(['kind' => 'goat', 'payload' => ['source_key' => 'local']]);

        $this->assertInstanceOf(InvalidSourceRecord::class, $decoded);
        $this->assertContains('invalid_source_record', $decoded->reasonCodes);
    }

    // ──────────────────────────────────────────────
    // Dates
    // ──────────────────────────────────────────────

    /**
     * WooCommerce Subscriptions answers the INTEGER `0` for a date that is not
     * set, and 360 of the 564 Lapka records answer exactly that for
     * `next_payment`. Zero is null; null is not "now", and it is not tomorrow
     * either.
     */
    public function testAnAbsentDateStaysNull(): void
    {
        $live = $this->factory->subscriptionFromWoo('local', $this->shapes['onHoldNoNextDate']());

        $this->assertInstanceOf(SubscriptionRecord::class, $live);
        $this->assertNull($live->dates->nextPaymentUtc);
        $this->assertSame('2023-04-11 09:15:00', $live->dates->startUtc);
    }

    public function testAnUnparseableDateIsAnInvalidRecordRatherThanASilentNull(): void
    {
        $record = $this->factory->subscriptionFromPayload('local', $this->shapes['subscriptionPayload']([
            'dates' => ['start_utc' => 'next Tuesday-ish', 'next_payment_utc' => null],
        ]));

        $this->assertInstanceOf(InvalidSourceRecord::class, $record);
        $this->assertContains('required_reference_missing', $record->reasonCodes);
    }

    // ──────────────────────────────────────────────
    // The WCS not-set sentinel
    // ──────────────────────────────────────────────

    /**
     * THE LIVE-EXPORT REGRESSION, AND WHY THE SUITE MISSED IT.
     *
     * `WC_Subscription::get_date()` is documented `@return string|int` and
     * answers the INTEGER `0` for a date that is not set. Every double in this
     * repository used to answer `''` instead, and every test therefore agreed
     * with every other test about a shape WooCommerce does not produce. Cast
     * that `0` to a string and the decoder is handed `'1'`-shaped garbage —
     * `'0'` — which is present and unparseable, so `utcDates()` returns null and
     * the record is `required_reference_missing`.
     *
     * On the real dataset that is not a corner case, it is the whole thing: 551
     * of 564 have no trial end, 360 no next payment, 204 no cancellation or end.
     * Every single record has at least one unset date, so the first live export
     * produced 564 invalid subscriptions and — because the source only emits a
     * customer for a subscription that decoded — zero customers.
     *
     * These are the assertions that would have failed.
     */
    public function testTheWcsNotSetSentinelIsAbsenceRatherThanAValue(): void
    {
        $this->assertNull(SubscriptionRecordFactory::wcsDate(0), 'WCS says "not set" with an integer zero.');
        $this->assertNull(SubscriptionRecordFactory::wcsDate('0'), 'And a string zero is no more a date.');
        $this->assertNull(SubscriptionRecordFactory::wcsDate(''));
        $this->assertNull(SubscriptionRecordFactory::wcsDate(null));
        $this->assertNull(SubscriptionRecordFactory::wcsDate('0000-00-00 00:00:00'));
        $this->assertNull(SubscriptionRecordFactory::wcsDate([]), 'A non-scalar cannot be stringified, let alone read.');
    }

    public function testARealDateSurvivesTheSentinelNormaliserUntouched(): void
    {
        $this->assertSame('2023-04-11 09:15:00', SubscriptionRecordFactory::wcsDate('2023-04-11 09:15:00'));
        $this->assertSame('2023-04-11 09:15:00', SubscriptionRecordFactory::wcsDate('  2023-04-11 09:15:00  '));
    }

    /**
     * The fixture double must keep lying the way WooCommerce lies. If this
     * assertion ever fails, every other test in this file has quietly stopped
     * exercising the live shape — which is exactly the state the suite was in
     * when the first live export failed.
     */
    public function testTheSubscriptionDoubleAnswersTheIntegerSentinelLikeWooCommerceDoes(): void
    {
        $subscription = $this->shapes['onHoldNoNextDate']();

        $this->assertSame(0, $subscription->get_date('next_payment'));
        $this->assertSame(0, $subscription->get_date('trial_end'));
        $this->assertSame('2023-04-11 09:15:00', $subscription->get_date('start'));
    }

    /**
     * The symptom, stated as the invariant: a subscription whose only
     * peculiarity is having no trial is an ordinary record.
     */
    public function testASubscriptionWithNoTrialAndNoEndDateIsValidRatherThanBlocked(): void
    {
        $subscription = $this->shapes['monthlyPln29']();

        $this->assertSame(0, $subscription->get_date('trial_end'), 'Precondition: the source really is unset here.');
        $this->assertSame(0, $subscription->get_date('cancelled'));
        $this->assertSame(0, $subscription->get_date('end'));

        $live = $this->factory->subscriptionFromWoo('local', $subscription);

        $this->assertInstanceOf(
            SubscriptionRecord::class,
            $live,
            'An unset optional date is absence, not a missing required reference.',
        );
        $this->assertNull($live->dates->trialEndUtc);
        $this->assertNull($live->dates->cancelledUtc);
        $this->assertNull($live->dates->endUtc);
        $this->assertSame('2023-04-11 09:15:00', $live->dates->startUtc);
    }

    /**
     * The fix is a translation, not a loosening. A live date that is genuinely
     * unreadable — a Polish `d/m/Y`, say, which is what a hand-edited meta row
     * looks like — still blocks, and still blocks with the same code.
     */
    public function testAnUnreadableLiveDateStillBlocksAfterTheSentinelIsHandled(): void
    {
        $live = $this->factory->subscriptionFromWoo('local', $this->shapes['monthlyPln29']([
            'dates' => ['start' => '27/05/2022'],
        ]));

        $this->assertInstanceOf(InvalidSourceRecord::class, $live);
        $this->assertContains('required_reference_missing', $live->reasonCodes);
    }

    /**
     * A start date is required, so the sentinel there is still a blocker — the
     * normaliser reports absence honestly rather than making absence passable.
     */
    public function testASubscriptionWithNoStartDateAtAllStillBlocks(): void
    {
        $live = $this->factory->subscriptionFromWoo('local', $this->shapes['monthlyPln29']([
            'dates' => ['start' => ''],
        ]));

        $this->assertInstanceOf(InvalidSourceRecord::class, $live);
        $this->assertContains('required_reference_missing', $live->reasonCodes);
    }

    /**
     * The sentinel must not survive into the package, or the two source modes
     * would disagree about the same subscription and every re-export would look
     * like a source change.
     */
    public function testTheSentinelNeverReachesThePackageAsAValue(): void
    {
        $live = $this->factory->subscriptionFromWoo('local', $this->shapes['monthlyPln29']());

        $this->assertInstanceOf(SubscriptionRecord::class, $live);

        $encoded = SubscriptionRecordFactory::envelope($live);
        $dates   = (array) ($encoded['payload']['dates'] ?? []);

        $this->assertArrayHasKey('trial_end_utc', $dates);
        $this->assertNull($dates['trial_end_utc'], 'Null in the package, never 0 and never "0".');

        $roundTripped = $this->factory->fromEnvelope($encoded);

        $this->assertInstanceOf(SubscriptionRecord::class, $roundTripped);
        $this->assertSame($live->fingerprint, $roundTripped->fingerprint);
    }
}

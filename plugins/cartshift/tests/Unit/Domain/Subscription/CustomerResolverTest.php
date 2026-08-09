<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\Domain\Subscription;

use CartShift\Domain\Migration\GuestCustomerFactory;
use CartShift\Domain\Subscription\CustomerRecord;
use CartShift\Domain\Subscription\CustomerResolver;
use CartShift\Domain\Subscription\SubscriptionRecord;
use CartShift\Domain\Subscription\SubscriptionRecordFactory;
use CartShift\Storage\IdMapRepository;
use CartShift\Support\Constants;
use CartShift\Tests\Unit\PluginTestCase;

require_once dirname(__DIR__, 3) . '/stubs/FluentCartModelStubs.php';
// preview()'s guest arm reads the ID map, which the shared get_var callback in
// EntityMigratorStubs answers. resolve() never needed it: its guest arm goes
// through GuestCustomerFactory, which the FluentCart model store already covers.
require_once dirname(__DIR__, 3) . '/stubs/EntityMigratorStubs.php';

/**
 * Plan section 9.1's five-step cross-site resolution order, proven end to end.
 *
 * The Lapka baseline this is written against (context pack section 4.4): 349 of
 * 564 subscriptions carry `_customer_user = 0`; all 564 resolve an email; 215
 * unique subscription emails; 518 target WordPress users; 43 unique subscription
 * emails match a target user; and zero source numeric user IDs point to the same
 * email at the same numeric target user ID. That last fact is the one a copied
 * `$user->ID` would violate silently, which is the defect this class exists to
 * close — email is the only bridge, and nothing here ever reads
 * `CustomerRecord::$sourceUserId` to decide who a record belongs to on the
 * target.
 */
final class CustomerResolverTest extends PluginTestCase
{
    /** @var array<string, callable> */
    private array $shapes;

    protected function setUp(): void
    {
        parent::setUp();

        \CartShiftFcModelStore::install();

        $this->shapes = require dirname(__DIR__, 3) . '/fixtures/lapka-subscription-shapes.php';
    }

    // ──────────────────────────────────────────────
    // Step 2 — reuse an existing FluentCart customer by unique email
    // ──────────────────────────────────────────────

    public function testExistingFluentCartCustomerIsReusedBeforeGuestCreation(): void
    {
        $record = $this->customerRecordFor(($this->shapes['guestCustomer'])());

        $this->installIdentityLookup([$record->email => [900]], []);

        $result = $this->makeResolver()->resolve($record, 'test-migration');

        $this->assertSame(CustomerResolver::STATUS_RESOLVED, $result['status']);
        $this->assertSame(900, $result['customer_id']);
        $this->assertNull($result['user_id']);
        $this->assertSame(CustomerResolver::OUTCOME_REUSED_CUSTOMER, $result['outcome']);

        // Nothing was created: the existing row was reused, not duplicated.
        $this->assertSame([], \CartShiftFcModelStore::all('Customer'));
    }

    public function testDuplicateFluentCartCustomerEmailBlocksAsAmbiguous(): void
    {
        $record = $this->customerRecordFor(($this->shapes['guestCustomer'])());

        $this->installIdentityLookup([$record->email => [501, 777]], []);

        $result = $this->makeResolver()->resolve($record, 'test-migration');

        $this->assertSame(CustomerResolver::STATUS_BLOCKED, $result['status']);
        $this->assertSame(CustomerResolver::REASON_IDENTITY_AMBIGUOUS, $result['reason_code']);
        $this->assertNull($result['customer_id']);
        $this->assertSame([], \CartShiftFcModelStore::all('Customer'));
    }

    // ──────────────────────────────────────────────
    // Step 3 — a unique target WordPress user by email
    // ──────────────────────────────────────────────

    public function testRegisteredEmailAttachesTheUniqueTargetUser(): void
    {
        $record = $this->customerRecordFor(($this->shapes['registeredCustomer'])());

        $this->installIdentityLookup([], [$record->email => [4242]]);

        $result = $this->makeResolver()->resolve($record, 'test-migration');

        $this->assertSame(CustomerResolver::STATUS_RESOLVED, $result['status']);
        $this->assertSame(4242, $result['user_id']);
        $this->assertSame(CustomerResolver::OUTCOME_ATTACHED_TARGET_USER, $result['outcome']);

        $created = \CartShiftFcModelStore::all('Customer');
        $this->assertCount(1, $created);
        $this->assertSame(4242, $created[0]->user_id);
        $this->assertSame($record->email, $created[0]->email);
    }

    public function testExistingCustomerAlreadyAttachedToTargetUserIsReused(): void
    {
        $record = $this->customerRecordFor(($this->shapes['registeredCustomer'])());

        // The target already has a FluentCart customer for user 4242, under
        // whatever email FluentCart has on file for them — deliberately not
        // the record's own email, to prove that row is not overwritten.
        $this->installIdentityLookup([], [$record->email => [4242]], [4242 => [55]]);

        $result = $this->makeResolver()->resolve($record, 'test-migration');

        $this->assertSame(55, $result['customer_id']);
        $this->assertSame(4242, $result['user_id']);
        $this->assertSame(CustomerResolver::OUTCOME_ADOPTED_TARGET_USER, $result['outcome']);
        $this->assertSame([], \CartShiftFcModelStore::all('Customer'));
    }

    public function testDuplicateTargetWordPressUserEmailBlocksAsAmbiguous(): void
    {
        $record = $this->customerRecordFor(($this->shapes['guestCustomer'])());

        $this->installIdentityLookup([], [$record->email => [10, 20]]);

        $result = $this->makeResolver()->resolve($record, 'test-migration');

        $this->assertSame(CustomerResolver::STATUS_BLOCKED, $result['status']);
        $this->assertSame(CustomerResolver::REASON_IDENTITY_AMBIGUOUS, $result['reason_code']);
        $this->assertSame([], \CartShiftFcModelStore::all('Customer'));
    }

    /**
     * Steps 2 and 3's own email lookups both ask for every match and block on
     * more than one; the "reuse" half of step 3's own "create/reuse" must not
     * be the one place in this class that quietly picks the first row instead.
     */
    public function testMultipleFluentCartCustomersAttachedToOneTargetUserBlocksAsAmbiguous(): void
    {
        $record = $this->customerRecordFor(($this->shapes['registeredCustomer'])());

        $this->installIdentityLookup([], [$record->email => [4242]], [4242 => [55, 56]]);

        $result = $this->makeResolver()->resolve($record, 'test-migration');

        $this->assertSame(CustomerResolver::STATUS_BLOCKED, $result['status']);
        $this->assertSame(CustomerResolver::REASON_IDENTITY_AMBIGUOUS, $result['reason_code']);
        $this->assertSame([], \CartShiftFcModelStore::all('Customer'));
    }

    /**
     * The dataset's own worked example: zero source numeric user IDs point to
     * the same email at the same numeric target user ID. A target install can
     * still happen to hand out the same integer to somebody else, and this
     * proves the resolver never notices — it is not reading
     * CustomerRecord::$sourceUserId at all, only the email.
     */
    public function testSameNumericIdWithADifferentEmailIsNeverLinked(): void
    {
        $subscription = ($this->shapes['registeredCustomer'])(['customer_id' => 660_001]);
        $record       = $this->customerRecordFor($subscription);

        // Target user 660001 exists, but belongs to somebody else's address.
        // No FluentCart customer and no target user matches the record's own
        // email at all, so the honest outcome is a guest, never user 660001.
        $this->installIdentityLookup([], ['someone-else@example.invalid' => [660_001]]);

        $result = $this->makeResolver()->resolve($record, 'test-migration');

        $this->assertSame(CustomerResolver::STATUS_RESOLVED, $result['status']);
        $this->assertNull($result['user_id']);

        $created = \CartShiftFcModelStore::all('Customer');
        $this->assertCount(1, $created);
        $this->assertNull($created[0]->user_id);
        $this->assertNotSame(660_001, $created[0]->user_id);
    }

    /**
     * The dispatch for this task asked for proof that "same-site mode may
     * keep using the source user ID, but only after proving the source key
     * is `local` and the user's email agrees." This class does not implement
     * that permission at all — it is `sourceKey`-agnostic and never reads
     * `CustomerRecord::$sourceUserId` in any mode, which is a strictly
     * stronger guarantee than the plan requires, not a gap in it. This test
     * pins that down for a `local`-keyed record specifically, so nobody later
     * "restores" the numeric-ID shortcut on the grounds that the plan permits
     * it for same-site: even here, a coinciding ID with a disagreeing email
     * must not be linked.
     */
    public function testLocalSourceKeyStillResolvesByEmailNotByACoincidingSourceUserId(): void
    {
        $record = new CustomerRecord(
            Constants::DEFAULT_SOURCE_KEY,
            'customer:660001',
            660_001,
            'genuinely-this-persons-own-email@example.invalid',
            [],
            '',
        );

        // Target user 660001 exists on the target install too — a coincidence
        // of two separate autoincrement counters — but belongs to somebody
        // else's address, and nothing matches the record's own email.
        $this->installIdentityLookup([], ['someone-else@example.invalid' => [660_001]]);

        $result = $this->makeResolver()->resolve($record, 'test-migration');

        $this->assertSame(CustomerResolver::STATUS_RESOLVED, $result['status']);
        $this->assertNull($result['user_id']);

        $created = \CartShiftFcModelStore::all('Customer');
        $this->assertCount(1, $created);
        $this->assertNull($created[0]->user_id);
        $this->assertNotSame(660_001, $created[0]->user_id);
    }

    // ──────────────────────────────────────────────
    // Step 4 — guest, keyed on the email hash
    // ──────────────────────────────────────────────

    public function testGuestEmailCreatesAGuestCustomer(): void
    {
        $subscription = ($this->shapes['guestCustomer'])();
        $record       = $this->customerRecordFor($subscription);
        $this->assertNull($record->sourceUserId);

        $this->installIdentityLookup([], []);

        $result = $this->makeResolver()->resolve($record, 'test-migration');

        $this->assertSame(CustomerResolver::STATUS_RESOLVED, $result['status']);
        $this->assertNull($result['user_id']);
        $this->assertSame(GuestCustomerFactory::OUTCOME_CREATED, $result['outcome']);

        $created = \CartShiftFcModelStore::all('Customer');
        $this->assertCount(1, $created);
        $this->assertNull($created[0]->user_id);
        $this->assertSame($record->email, $created[0]->email);
    }

    /**
     * 349 Lapka subscriptions carry `_customer_user = 0`. Keyed on that number
     * they would collapse into one mythical customer; keyed on the normalised
     * email hash — the same helper the dataset layer itself uses — two
     * different guest emails must resolve to two different customers.
     */
    public function testTwoDifferentGuestEmailsNeverCollide(): void
    {
        $first  = $this->customerRecordFor(($this->shapes['guestCustomer'])([
            'billing_email' => 'first-guest@example.invalid',
        ]));
        $second = $this->customerRecordFor(($this->shapes['guestCustomer'])([
            'billing_email' => 'second-guest@example.invalid',
        ]));

        $this->assertNotSame($first->sourceRef, $second->sourceRef);
        $this->assertSame(SubscriptionRecordFactory::guestRef('first-guest@example.invalid'), $first->sourceRef);
        $this->assertSame(SubscriptionRecordFactory::guestRef('second-guest@example.invalid'), $second->sourceRef);

        $this->installIdentityLookup([], []);
        $resolver = $this->makeResolver();

        $firstResult  = $resolver->resolve($first, 'test-migration');
        $secondResult = $resolver->resolve($second, 'test-migration');

        $this->assertNotSame($firstResult['customer_id'], $secondResult['customer_id']);
        $this->assertCount(2, \CartShiftFcModelStore::all('Customer'));
    }

    /**
     * Repeated subscriptions for one guest email must reuse the same target
     * customer, and resolving the same identity twice creates nothing new.
     */
    public function testRepeatedResolutionOfOneGuestEmailReusesTheSameCustomer(): void
    {
        $record = $this->customerRecordFor(($this->shapes['guestCustomer'])());

        $this->installIdentityLookup([], []);
        $resolver = $this->makeResolver();

        $first = $resolver->resolve($record, 'test-migration');
        $this->assertSame(CustomerResolver::STATUS_RESOLVED, $first['status']);
        $this->assertCount(1, \CartShiftFcModelStore::all('Customer'));

        // A second resolution sees exactly what the first call actually wrote
        // — the live FluentCart table now carries the created row — mirroring
        // what a real re-run of the migration finds.
        $this->installIdentityLookup([$record->email => [$first['customer_id']]], []);

        $second = $resolver->resolve($record, 'test-migration');

        $this->assertSame(CustomerResolver::STATUS_RESOLVED, $second['status']);
        $this->assertSame($first['customer_id'], $second['customer_id']);
        $this->assertCount(1, \CartShiftFcModelStore::all('Customer'));
    }

    // ──────────────────────────────────────────────
    // resolveForSubscription() — the entry point the migrator actually calls
    // ──────────────────────────────────────────────

    /**
     * Every other test above goes in through `resolve()` with a hand-built
     * `CustomerRecord`. `resolveForSubscription()` is what `SubscriptionMigrator`
     * calls in practice — it iterates `SubscriptionRecord`, not `CustomerRecord`
     * — and its field mapping (`sourceCustomerRef` -> `sourceRef`,
     * `sourceCustomerId` -> `sourceUserId`, `billingEmail` -> `email`,
     * `billingIdentity` -> `billingIdentity`) is exactly the kind of thing a
     * later constructor-order change breaks silently, in the one code path
     * that decides which human owns a subscription. Built from a real,
     * factory-decoded `SubscriptionRecord` rather than a hand-built one, so
     * this exercises the actual shape the migrator will hand over.
     */
    public function testResolveForSubscriptionDelegatesUsingTheSubscriptionsOwnIdentity(): void
    {
        $subscription = ($this->shapes['registeredCustomer'])();
        $record       = (new SubscriptionRecordFactory())->subscriptionFromWoo('lapka-klub', $subscription);
        self::assertInstanceOf(SubscriptionRecord::class, $record);

        $this->installIdentityLookup([], [$record->billingEmail => [4242]]);

        $result = $this->makeResolver()->resolveForSubscription($record, 'test-migration');

        $this->assertSame(CustomerResolver::STATUS_RESOLVED, $result['status']);
        $this->assertSame(4242, $result['user_id']);
        $this->assertSame(CustomerResolver::OUTCOME_ATTACHED_TARGET_USER, $result['outcome']);
        $this->assertSame($record->billingEmail, $result['email']);

        $created = \CartShiftFcModelStore::all('Customer');
        $this->assertCount(1, $created);
        $this->assertSame(4242, $created[0]->user_id);
        $this->assertSame($record->billingEmail, $created[0]->email);
    }

    /**
     * The guest half of the same bridge: `sourceCustomerId` is null on the
     * `SubscriptionRecord` itself (349 of the 564 Lapka records), and that
     * must reach `resolve()` as `CustomerRecord::$sourceUserId === null`
     * rather than, say, silently defaulting to 0 somewhere in the field copy.
     */
    public function testResolveForSubscriptionBuildsAGuestIdentityWhenTheSourceCustomerIdIsZero(): void
    {
        $subscription = ($this->shapes['guestCustomer'])();
        $record       = (new SubscriptionRecordFactory())->subscriptionFromWoo('lapka-klub', $subscription);
        self::assertInstanceOf(SubscriptionRecord::class, $record);
        $this->assertNull($record->sourceCustomerId);

        $this->installIdentityLookup([], []);

        $result = $this->makeResolver()->resolveForSubscription($record, 'test-migration');

        $this->assertSame(CustomerResolver::STATUS_RESOLVED, $result['status']);
        $this->assertNull($result['user_id']);
        $this->assertSame(GuestCustomerFactory::OUTCOME_CREATED, $result['outcome']);

        $created = \CartShiftFcModelStore::all('Customer');
        $this->assertCount(1, $created);
        $this->assertNull($created[0]->user_id);
        $this->assertSame($record->billingEmail, $created[0]->email);
    }

    // ──────────────────────────────────────────────
    // Step 5 — block blank or ambiguous identity
    // ──────────────────────────────────────────────

    public function testBlankEmailBlocks(): void
    {
        // Built directly, bypassing SubscriptionRecordFactory: the resolver
        // must defend this on its own rather than trust that every caller
        // already ran a record through the factory's own guard.
        $record = new CustomerRecord('lapka-klub', 'customer:1', 1, '', [], '');

        $result = $this->makeResolver()->resolve($record, 'test-migration');

        $this->assertSame(CustomerResolver::STATUS_BLOCKED, $result['status']);
        $this->assertSame(CustomerResolver::REASON_EMAIL_MISSING, $result['reason_code']);
        $this->assertNull($result['customer_id']);
        $this->assertSame([], \CartShiftFcModelStore::all('Customer'));
    }

    // ──────────────────────────────────────────────
    // preview() — the same five steps, stopping where resolve() would write
    // ──────────────────────────────────────────────

    /**
     * The whole reason `preview()` exists: §11 Phase A's audit needs the answer
     * and cannot afford the side effect. Watched at the `$wpdb` level rather
     * than by counting model rows, because "no FluentCart customer was created"
     * is a weaker claim than "nothing was written anywhere".
     */
    public function testPreviewWritesNothingOnAnyArm(): void
    {
        require_once dirname(__DIR__, 3) . '/stubs/ZeroWriteGuard.php';

        $guest      = $this->customerRecordFor(($this->shapes['guestCustomer'])());
        $registered = $this->customerRecordFor(($this->shapes['registeredCustomer'])());

        $this->installIdentityLookup([], [$registered->email => [4242]]);

        $watched = \CartShiftZeroWriteGuard::watch(function () use ($guest, $registered): array {
            $resolver = $this->makeResolver();

            return [
                'guest'      => $resolver->preview($guest),
                'registered' => $resolver->preview($registered),
            ];
        });

        $this->assertSame([], $watched['violations']);
        // Non-vacuous: both arms really were evaluated under the guard.
        $this->assertSame(
            CustomerResolver::OUTCOME_WOULD_CREATE_GUEST,
            $watched['result']['guest']['outcome'],
        );
        $this->assertSame(
            CustomerResolver::OUTCOME_ATTACHED_TARGET_USER,
            $watched['result']['registered']['outcome'],
        );
        $this->assertSame([], \CartShiftFcModelStore::all('Customer'));
    }

    public function testPreviewReportsTheReusedFluentCartCustomer(): void
    {
        $record = $this->customerRecordFor(($this->shapes['guestCustomer'])());

        $this->installIdentityLookup([$record->email => [900]], []);

        $result = $this->makeResolver()->preview($record);

        $this->assertSame(CustomerResolver::OUTCOME_REUSED_CUSTOMER, $result['outcome']);
        $this->assertSame(900, $result['customer_id']);
        $this->assertFalse($result['would_create']);
        $this->assertFalse($result['matched_target_user']);
    }

    /**
     * §4.4's load-bearing figure: 43 of the 215 distinct subscription emails
     * match a target WordPress user. `matched_target_user` is how the audit
     * counts them, and it is true for both step-3 arms — the one that would
     * create a FluentCart customer for that user and the one that adopts an
     * existing one.
     */
    public function testPreviewFlagsAMatchedTargetUserOnBothStepThreeArms(): void
    {
        $record = $this->customerRecordFor(($this->shapes['registeredCustomer'])());

        $this->installIdentityLookup([], [$record->email => [4242]]);

        $wouldAttach = $this->makeResolver()->preview($record);

        $this->assertTrue($wouldAttach['matched_target_user']);
        $this->assertTrue($wouldAttach['would_create']);
        $this->assertSame(4242, $wouldAttach['user_id']);
        $this->assertNull(
            $wouldAttach['customer_id'],
            'The row does not exist yet; a plausible ID here is one a caller would try to use.',
        );

        $this->installIdentityLookup([], [$record->email => [4242]], [4242 => [55]]);

        $wouldAdopt = $this->makeResolver()->preview($record);

        $this->assertTrue($wouldAdopt['matched_target_user']);
        $this->assertFalse($wouldAdopt['would_create']);
        $this->assertSame(55, $wouldAdopt['customer_id']);
    }

    public function testPreviewTellsAGuestThatIsAlreadyMappedFromOneThatWouldBeCreated(): void
    {
        $record = $this->customerRecordFor(($this->shapes['guestCustomer'])());

        $fresh = $this->makeResolver()->preview($record);

        $this->assertSame(CustomerResolver::OUTCOME_WOULD_CREATE_GUEST, $fresh['outcome']);
        $this->assertTrue($fresh['would_create']);

        $GLOBALS['_cartshift_test_id_map'][Constants::ENTITY_GUEST_CUSTOMER]
            [SubscriptionRecordFactory::guestRef($record->email)] = 4001;
        $GLOBALS['_cartshift_test_get_var_callback'] = cartshift_test_id_map_reader();

        $mapped = $this->makeResolver()->preview($record);

        $this->assertSame(CustomerResolver::OUTCOME_REUSED_GUEST, $mapped['outcome']);
        $this->assertSame(4001, $mapped['customer_id']);
        $this->assertFalse($mapped['would_create']);
    }

    public function testPreviewBlocksTheSameIdentitiesResolveDoes(): void
    {
        $blank = $this->makeResolver()->preview(
            new CustomerRecord('lapka-klub', 'customer:1', 1, '', [], ''),
        );

        $this->assertSame(CustomerResolver::STATUS_BLOCKED, $blank['status']);
        $this->assertSame(CustomerResolver::REASON_EMAIL_MISSING, $blank['reason_code']);

        $record = $this->customerRecordFor(($this->shapes['guestCustomer'])());
        $this->installIdentityLookup([$record->email => [501, 777]], []);

        $ambiguous = $this->makeResolver()->preview($record);

        $this->assertSame(CustomerResolver::STATUS_BLOCKED, $ambiguous['status']);
        $this->assertSame(CustomerResolver::REASON_IDENTITY_AMBIGUOUS, $ambiguous['reason_code']);
    }

    /**
     * A forecast that disagrees with the thing it forecasts is worse than none.
     * Every arm that does not write is asserted to give `resolve()`'s own
     * `status`, `outcome` and `customer_id`.
     */
    public function testPreviewAgreesWithResolveOnEveryArmThatDoesNotWrite(): void
    {
        $record = $this->customerRecordFor(($this->shapes['guestCustomer'])());

        foreach (
            [
                'reused customer' => [[$record->email => [900]], []],
                'ambiguous'       => [[$record->email => [501, 777]], []],
            ] as $label => [$byEmail, $byUser]
        ) {
            $this->installIdentityLookup($byEmail, $byUser);

            $preview  = $this->makeResolver()->preview($record);
            $resolved = $this->makeResolver()->resolve($record, 'test-migration');

            foreach (['status', 'outcome', 'customer_id'] as $field) {
                $this->assertSame(
                    $resolved[$field],
                    $preview[$field],
                    sprintf('preview() and resolve() disagree about %s on the %s arm.', $field, $label),
                );
            }
        }
    }

    public function testPreviewForSubscriptionUsesTheSubscriptionsOwnIdentity(): void
    {
        $subscription = $this->subscriptionRecordFor(($this->shapes['guestCustomer'])());

        $result = $this->makeResolver()->previewForSubscription($subscription);

        $this->assertSame($subscription->billingEmail, $result['email']);
        $this->assertSame(CustomerResolver::OUTCOME_WOULD_CREATE_GUEST, $result['outcome']);
    }

    // ──────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────

    private function subscriptionRecordFor(object $subscription): SubscriptionRecord
    {
        $record = (new SubscriptionRecordFactory())->subscriptionFromWoo('lapka-klub', $subscription);
        self::assertInstanceOf(SubscriptionRecord::class, $record);

        return $record;
    }

    private function customerRecordFor(object $subscription): CustomerRecord
    {
        $record = (new SubscriptionRecordFactory())->customerFromWoo('lapka-klub', $subscription);
        self::assertInstanceOf(CustomerRecord::class, $record);

        return $record;
    }

    private function makeResolver(): CustomerResolver
    {
        return new CustomerResolver(new IdMapRepository('lapka-klub'));
    }

    /**
     * Install a `get_col()` fake that answers CustomerResolver's three
     * identity queries: FluentCart customers by email, target WordPress users
     * by email, and FluentCart customers already attached to a target user
     * ID. Keyed by email (or, for the third map, by user ID) so a test only
     * has to say who exists, not which table the resolver happened to ask
     * first.
     *
     * @param array<string, list<int>> $fluentCartCustomerIdsByEmail
     * @param array<string, list<int>> $wordPressUserIdsByEmail
     * @param array<int, list<int>>    $fluentCartCustomerIdsByUserId
     */
    private function installIdentityLookup(
        array $fluentCartCustomerIdsByEmail,
        array $wordPressUserIdsByEmail,
        array $fluentCartCustomerIdsByUserId = [],
    ): void {
        $GLOBALS['_cartshift_test_get_col_callback'] = static function (string $query) use (
            $fluentCartCustomerIdsByEmail,
            $wordPressUserIdsByEmail,
            $fluentCartCustomerIdsByUserId,
        ): array {
            // The by-user-id query is the only one of the three with no
            // quoted string in it (`%d`, not `%s`), and it targets the same
            // table the by-email FluentCart query does — so it has to be
            // told apart by its WHERE clause, not by table name alone.
            if (str_contains($query, 'WHERE user_id')) {
                foreach ($fluentCartCustomerIdsByUserId as $userId => $ids) {
                    if (str_contains($query, "user_id = {$userId}")) {
                        return $ids;
                    }
                }

                return [];
            }

            $byEmail = str_contains($query, 'fct_customers') ? $fluentCartCustomerIdsByEmail : $wordPressUserIdsByEmail;

            foreach ($byEmail as $email => $ids) {
                if (str_contains($query, "'{$email}'")) {
                    return $ids;
                }
            }

            return [];
        };
    }
}

<?php

declare(strict_types=1);

namespace CartShift\Domain\Subscription;

defined('ABSPATH') || exit;

use CartShift\Domain\Mapping\CustomerMapper;
use CartShift\Domain\Migration\GuestCustomerFactory;
use CartShift\Storage\IdMapRepository;
use CartShift\Support\Constants;
use FluentCart\App\Models\Customer;
use FluentCart\App\Models\CustomerAddresses;

/**
 * Cross-site customer identity resolution — plan section 9.1, five steps, in
 * that order, and no sixth one:
 *
 * 1. Normalise the email.
 * 2. Reuse an existing FluentCart customer with that email, if unique.
 * 3. Otherwise find a unique target WordPress user by email and create/reuse
 *    a FluentCart customer attached to that target user ID.
 * 4. Otherwise create/reuse a guest FluentCart customer from source billing
 *    data.
 * 5. Block a blank email or an ambiguous duplicate target identity.
 *
 * `CustomerMapper::mapRegistered()` copies the source `$user->ID` straight
 * into FluentCart's `user_id`, which is correct only when the source and the
 * target are the same WordPress install. Lapka is two installs sharing one
 * MariaDB: 349 of its 564 subscriptions carry `_customer_user = 0`, all 564
 * resolve an email, and zero source numeric user IDs point to the same email
 * at the same numeric target user ID. Copying an ID across installs attaches
 * a subscription to whichever unrelated person happens to hold that number on
 * the target — so this class never reads `CustomerRecord::$sourceUserId` at
 * all. Email, resolved against the target's own tables, is the only bridge.
 * That is a deliberate omission, not an oversight: search this file for
 * `sourceUserId` and it appears exactly once, in this paragraph.
 *
 * Same-site behaviour is untouched by this class. `CustomerMapper::mapRegistered()`
 * and `GuestCustomerFactory::fromOrder()` keep doing what they always did;
 * this is new code for a new (cross-site) path, not a replacement for the old
 * one.
 */
final class CustomerResolver
{
    public const string STATUS_RESOLVED = 'resolved';
    public const string STATUS_BLOCKED = 'blocked';

    /** Step 2: an existing FluentCart customer with a uniquely matching email. */
    public const string OUTCOME_REUSED_CUSTOMER = 'reused_customer';

    /** Step 3: a new FluentCart customer attached to the resolved target user. */
    public const string OUTCOME_ATTACHED_TARGET_USER = 'attached_target_user';

    /** Step 3: the target user already had a FluentCart customer of its own. */
    public const string OUTCOME_ADOPTED_TARGET_USER = 'adopted_target_user';

    /** Section 9.4's Identity row, verbatim — aliased so the two halves of the
     * dataset layer cannot quietly drift apart over a respelled literal. */
    public const string REASON_EMAIL_MISSING = SubscriptionRecordFactory::REASON_CUSTOMER_EMAIL_MISSING;

    public const string REASON_IDENTITY_AMBIGUOUS = 'customer_identity_ambiguous';

    /** Preview only: step 4 would create a guest customer from source billing data. */
    public const string OUTCOME_WOULD_CREATE_GUEST = 'would_create_guest';

    /** Preview only: step 4's guest ref is already in the ID map, so nothing new. */
    public const string OUTCOME_REUSED_GUEST = 'reused_guest';

    private readonly CustomerMapper $customerMapper;

    private readonly GuestCustomerFactory $guestCustomers;

    public function __construct(
        private readonly IdMapRepository $idMap,
        ?CustomerMapper $customerMapper = null,
        ?GuestCustomerFactory $guestCustomers = null,
    ) {
        $this->customerMapper = $customerMapper ?? new CustomerMapper($idMap);
        $this->guestCustomers = $guestCustomers ?? new GuestCustomerFactory($idMap, $this->customerMapper);
    }

    /**
     * Resolve a `SubscriptionRecord`'s own customer identity.
     *
     * A convenience for the migrator, which iterates `SubscriptionRecord`
     * rather than `CustomerRecord`: it carries the identical identity fields
     * — `sourceCustomerRef`, `sourceCustomerId`, `billingEmail`,
     * `billingIdentity` — because a subscription's billing details are the
     * source billing data step 4 builds a guest from. Building a throwaway
     * `CustomerRecord` from them and delegating keeps exactly one resolution
     * algorithm in the plugin.
     *
     * @return array{
     *     status: string,
     *     customer_id: int|null,
     *     user_id: int|null,
     *     outcome: string|null,
     *     email: string,
     *     reason_code: string|null,
     * }
     */
    public function resolveForSubscription(SubscriptionRecord $subscription, string $migrationId = ''): array
    {
        $record = new CustomerRecord(
            $subscription->sourceKey,
            $subscription->sourceCustomerRef,
            $subscription->sourceCustomerId,
            $subscription->billingEmail,
            $subscription->billingIdentity,
            '',
        );

        return $this->resolve($record, $migrationId);
    }

    /**
     * @return array{
     *     status: string,
     *     customer_id: int|null,
     *     user_id: int|null,
     *     outcome: string|null,
     *     email: string,
     *     reason_code: string|null,
     * }
     */
    public function resolve(CustomerRecord $record, string $migrationId = ''): array
    {
        // Step 1: normalise. Reusing the dataset's own helper — rather than a
        // second trim()/strtolower() with its own opinion — is what keeps this
        // resolver and SubscriptionRecordFactory's guest refs agreeing byte for
        // byte, even though CustomerRecord::$email is already normalised by the
        // time the factory built it. A record built any other way must not get
        // a different answer here than it would there.
        $email = SubscriptionRecordFactory::normaliseEmail($record->email);

        // Step 5 (blank case): nothing to resolve against.
        if ($email === '') {
            return $this->blocked(self::REASON_EMAIL_MISSING, $email);
        }

        // Step 2: reuse an existing FluentCart customer with this email, if unique.
        $customerIds = $this->fluentCartCustomerIdsByEmail($email);

        if (count($customerIds) > 1) {
            return $this->blocked(self::REASON_IDENTITY_AMBIGUOUS, $email);
        }

        if (count($customerIds) === 1) {
            return $this->resolved($customerIds[0], null, self::OUTCOME_REUSED_CUSTOMER, $email);
        }

        // Step 3: a unique target WordPress user by email.
        $userIds = $this->targetWordPressUserIdsByEmail($email);

        if (count($userIds) > 1) {
            return $this->blocked(self::REASON_IDENTITY_AMBIGUOUS, $email);
        }

        if (count($userIds) === 1) {
            $userId = $userIds[0];

            // Step 3's "reuse" half: a FluentCart customer already attached to
            // this user ID, under whatever email FluentCart has on file for
            // them. Asked for as a full list and blocked on more than one, the
            // same way steps 2 and 3's own email lookups are — a `->first()`
            // that silently picked one of several would guess exactly where
            // this class refuses to everywhere else.
            $attachedCustomerIds = $this->fluentCartCustomerIdsByUserId($userId);

            if (count($attachedCustomerIds) > 1) {
                return $this->blocked(self::REASON_IDENTITY_AMBIGUOUS, $email);
            }

            [$customerId, $outcome] = $this->attachToTargetUser(
                $record,
                $userId,
                $attachedCustomerIds[0] ?? null,
            );

            return $this->resolved($customerId, $userId, $outcome, $email);
        }

        // Step 4: a guest, keyed on the deterministic email hash.
        $guest = $this->guestCustomers->fromRecord($record, $migrationId);

        // Only reachable if $email somehow reads non-blank here and blank by
        // the time fromRecord() re-checks it, which normaliseEmail() being
        // idempotent rules out — kept because fromRecord()'s own contract is
        // nullable and a resolver that silently swallowed that would be worse.
        if ($guest === null) {
            return $this->blocked(self::REASON_EMAIL_MISSING, $email);
        }

        return $this->resolved($guest['id'], null, $guest['outcome'], $guest['email']);
    }

    /**
     * Which arm `resolve()` would take, without taking it.
     *
     * §11 Phase A's audit has to report cross-site identity — how many of these
     * subscribers the target already knows, how many match a target WordPress
     * user by email, how many arrive as guests — and §4.4 pins the figure that
     * makes the decision: 43 of the 215 distinct subscription emails match a
     * target user. `resolve()` cannot answer that for an audit, because two of
     * its four arms *create* a row, and an audit that created 172 guest
     * customers to tell you it had not written anything would be the exact
     * defect this whole task exists to fix.
     *
     * So: the same five steps, in the same order, reading the same three
     * lookups, stopping where `resolve()` would write. The two outcomes
     * `resolve()` has and this does not are the two writes:
     * `OUTCOME_ATTACHED_TARGET_USER` and `GuestCustomerFactory::OUTCOME_CREATED`
     * become `would_create`, with `customer_id` null because the row does not
     * exist yet and inventing an ID for it would be a lie a caller could act on.
     *
     * ONE ARM IS DELIBERATELY ABSENT. `GuestCustomerFactory::fromRecord()`
     * carries an `OUTCOME_ADOPTED` branch — a FluentCart customer already
     * holding this email — and this does not reproduce it, because it cannot be
     * reached from here: step 2 above has already asked
     * `fluentCartCustomerIdsByEmail()` the same question and returned
     * `reused_customer` on a hit. That branch is defence-in-depth against a race
     * between the check and the write; a read has no write to race with.
     *
     * `resolve()` is untouched by this method and shares no state with it. The
     * only thing they share is the private lookups, which is the point — a
     * preview computed from a second set of queries would be a forecast of a
     * different function.
     *
     * @return array{
     *     status: string,
     *     customer_id: int|null,
     *     user_id: int|null,
     *     outcome: string|null,
     *     email: string,
     *     reason_code: string|null,
     *     would_create: bool,
     *     matched_target_user: bool,
     * }
     */
    public function preview(CustomerRecord $record): array
    {
        $email = SubscriptionRecordFactory::normaliseEmail($record->email);

        // Step 5, blank case.
        if ($email === '') {
            return self::previewOf($this->blocked(self::REASON_EMAIL_MISSING, $email));
        }

        // Step 2.
        $customerIds = $this->fluentCartCustomerIdsByEmail($email);

        if (count($customerIds) > 1) {
            return self::previewOf($this->blocked(self::REASON_IDENTITY_AMBIGUOUS, $email));
        }

        if (count($customerIds) === 1) {
            return self::previewOf(
                $this->resolved($customerIds[0], null, self::OUTCOME_REUSED_CUSTOMER, $email),
            );
        }

        // Step 3.
        $userIds = $this->targetWordPressUserIdsByEmail($email);

        if (count($userIds) > 1) {
            return self::previewOf($this->blocked(self::REASON_IDENTITY_AMBIGUOUS, $email));
        }

        if (count($userIds) === 1) {
            $userId              = $userIds[0];
            $attachedCustomerIds = $this->fluentCartCustomerIdsByUserId($userId);

            if (count($attachedCustomerIds) > 1) {
                return self::previewOf($this->blocked(self::REASON_IDENTITY_AMBIGUOUS, $email));
            }

            if ($attachedCustomerIds === []) {
                // resolve() would create the customer here. Reported as such,
                // and with a null ID rather than a plausible one.
                return self::previewOf(
                    $this->resolved(0, $userId, self::OUTCOME_ATTACHED_TARGET_USER, $email),
                    wouldCreate: true,
                    matchedTargetUser: true,
                );
            }

            return self::previewOf(
                $this->resolved($attachedCustomerIds[0], $userId, self::OUTCOME_ADOPTED_TARGET_USER, $email),
                matchedTargetUser: true,
            );
        }

        // Step 4, read-only: the deterministic guest ref, looked up rather than
        // filed. `GuestCustomerFactory::fromRecord()` keys on exactly this.
        $mapped = $this->idMap->getFcId(
            Constants::ENTITY_GUEST_CUSTOMER,
            SubscriptionRecordFactory::guestRef($email),
        );

        return $mapped
            ? self::previewOf($this->resolved($mapped, null, self::OUTCOME_REUSED_GUEST, $email))
            : self::previewOf(
                $this->resolved(0, null, self::OUTCOME_WOULD_CREATE_GUEST, $email),
                wouldCreate: true,
            );
    }

    /**
     * `preview()` for a `SubscriptionRecord`, mirroring
     * `resolveForSubscription()` so the audit iterates the same records the
     * migrator does.
     *
     * @return array{
     *     status: string,
     *     customer_id: int|null,
     *     user_id: int|null,
     *     outcome: string|null,
     *     email: string,
     *     reason_code: string|null,
     *     would_create: bool,
     *     matched_target_user: bool,
     * }
     */
    public function previewForSubscription(SubscriptionRecord $subscription): array
    {
        return $this->preview(new CustomerRecord(
            $subscription->sourceKey,
            $subscription->sourceCustomerRef,
            $subscription->sourceCustomerId,
            $subscription->billingEmail,
            $subscription->billingIdentity,
            '',
        ));
    }

    /**
     * A `resolve()`-shaped answer, re-labelled as a forecast.
     *
     * `customer_id` is nulled when the row would have to be created, because a
     * `0` there reads as an ID and a caller would eventually try to use it.
     *
     * @param array<string, mixed> $outcome
     * @return array<string, mixed>
     */
    private static function previewOf(
        array $outcome,
        bool $wouldCreate = false,
        bool $matchedTargetUser = false,
    ): array {
        $outcome['customer_id'] = $wouldCreate ? null : $outcome['customer_id'];
        $outcome['would_create'] = $wouldCreate;
        $outcome['matched_target_user'] = $matchedTargetUser;

        return $outcome;
    }

    /**
     * Step 3's "create/reuse" half, once the target user ID is already known
     * to be the unique match for this record's email and the caller has
     * already proven at most one existing FluentCart customer is attached to
     * it. `$existingCustomerId` is that customer, or null when there is none
     * — a target install may already know this person through a native
     * signup or an earlier migration run, under whatever email FluentCart has
     * on file for them, which is deliberately left untouched rather than
     * overwritten with the source's billing email.
     *
     * @return array{0: int, 1: string}
     */
    private function attachToTargetUser(CustomerRecord $record, int $userId, ?int $existingCustomerId): array
    {
        if ($existingCustomerId !== null) {
            return [$existingCustomerId, self::OUTCOME_ADOPTED_TARGET_USER];
        }

        $mapped   = $this->customerMapper->mapFromRecord($record, $userId);
        $customer = Customer::query()->create($mapped['customer']);

        foreach ($mapped['addresses'] as $addressData) {
            $addressData['customer_id'] = $customer->id;
            CustomerAddresses::query()->create($addressData);
        }

        return [(int) $customer->id, self::OUTCOME_ATTACHED_TARGET_USER];
    }

    /**
     * Every FluentCart customer ID already carrying this email.
     *
     * `fct_customers.email` is an ordinary index, not a unique one
     * (`database/Migrations/CustomersMigrator.php` in the installed FluentCart
     * 1.6.0 declares `INDEX ... (email ASC)` and nothing stronger) — so more
     * than one row sharing an email is a real state the target can be in, not
     * a hypothetical this query guards against for nothing.
     *
     * @return list<int>
     */
    private function fluentCartCustomerIdsByEmail(string $email): array
    {
        global $wpdb;

        $table = $wpdb->prefix . 'fct_customers';

        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT id FROM {$table} WHERE email = %s",
            $email,
        ));

        return array_values(array_map(intval(...), $ids));
    }

    /**
     * Every FluentCart customer ID already attached to this target user ID.
     *
     * `fct_customers.user_id` is likewise an ordinary index
     * (`{$indexPrefix}_user_id`), not a unique one, so the same
     * ask-for-all-then-decide shape as `fluentCartCustomerIdsByEmail()`
     * applies here too — a `->first()` would have silently picked one of
     * several instead of admitting the target already disagrees with itself
     * about who this user is.
     *
     * @return list<int>
     */
    private function fluentCartCustomerIdsByUserId(int $userId): array
    {
        global $wpdb;

        $table = $wpdb->prefix . 'fct_customers';

        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT id FROM {$table} WHERE user_id = %d",
            $userId,
        ));

        return array_values(array_map(intval(...), $ids));
    }

    /**
     * Every target WordPress user ID already carrying this email.
     *
     * `wp_insert_user()` refuses a duplicate email by default, but that is an
     * application-layer check, not a schema constraint — `wp_users.user_email`
     * has no unique index — and a restored/imported target site can carry
     * duplicates that check never saw. A plain `get_user_by('email', ...)`
     * would silently pick one of them; this asks for all of them so step 3 can
     * tell "unique" from "ambiguous" instead of guessing.
     *
     * @return list<int>
     */
    private function targetWordPressUserIdsByEmail(string $email): array
    {
        global $wpdb;

        $table = $wpdb->prefix . 'users';

        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT ID FROM {$table} WHERE user_email = %s",
            $email,
        ));

        return array_values(array_map(intval(...), $ids));
    }

    /**
     * @return array{status: string, customer_id: int, user_id: int|null, outcome: string, email: string, reason_code: null}
     */
    private function resolved(int $customerId, ?int $userId, string $outcome, string $email): array
    {
        return [
            'status'      => self::STATUS_RESOLVED,
            'customer_id' => $customerId,
            'user_id'     => $userId,
            'outcome'     => $outcome,
            'email'       => $email,
            'reason_code' => null,
        ];
    }

    /**
     * @return array{status: string, customer_id: null, user_id: null, outcome: null, email: string, reason_code: string}
     */
    private function blocked(string $reasonCode, string $email): array
    {
        return [
            'status'      => self::STATUS_BLOCKED,
            'customer_id' => null,
            'user_id'     => null,
            'outcome'     => null,
            'email'       => $email,
            'reason_code' => $reasonCode,
        ];
    }
}

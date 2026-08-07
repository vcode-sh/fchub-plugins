<?php

declare(strict_types=1);

namespace CartShift\Migrator;

defined('ABSPATH') || exit;

use CartShift\Domain\Mapping\CustomerMapper;
use CartShift\Domain\Migration\GuestCustomerFactory;
use CartShift\State\MigrationState;
use CartShift\Storage\IdMapRepository;
use CartShift\Storage\MigrationLogRepository;
use CartShift\Support\Constants;
use CartShift\Support\Enums\MigrationErrorCode;
use CartShift\Support\WooStorage;
use FluentCart\App\Models\Customer;
use FluentCart\App\Models\CustomerAddresses;

final class CustomerMigrator extends AbstractMigrator
{
    /** Cursor phase: registered WP users, keyed on wc_orders.customer_id. */
    private const string PHASE_REGISTERED = 'registered';

    /** Cursor phase: guest checkouts, keyed on wc_orders.billing_email. */
    private const string PHASE_GUEST = 'guest';

    private readonly CustomerMapper $customerMapper;

    private readonly GuestCustomerFactory $guestCustomers;

    /** @var int|null Cached registered customer count */
    private ?int $registeredCount = null;

    public function __construct(
        IdMapRepository $idMap,
        MigrationLogRepository $log,
        MigrationState $migrationState,
        int $batchSize = Constants::DEFAULT_BATCH_SIZE,
    ) {
        parent::__construct($idMap, $log, $migrationState, $batchSize);
        $this->customerMapper = new CustomerMapper($idMap);
        $this->guestCustomers = new GuestCustomerFactory($idMap, $this->customerMapper);
    }

    #[\Override]
    protected function getEntityType(): string
    {
        return Constants::ENTITY_CUSTOMER;
    }

    /**
     * FIX H4: query users by order history, not just 'customer' role.
     * FIX H5: count unique guest emails directly via SQL.
     */
    #[\Override]
    protected function countTotal(): int
    {
        return $this->countRegisteredCustomers() + $this->countGuestCustomers();
    }

    /**
     * Two sources, two cursors, one sequence.
     *
     * Customers are spliced out of registered user IDs first and guest billing
     * emails second, and a single integer cursor cannot express that: `123` is
     * both a plausible customer_id and meaningless as an email. So the cursor
     * carries an explicit phase marker — `registered:<customer_id>` or
     * `guest:<billing_email>` — and each phase keysets on its own ordering
     * column, matching the ORDER BY of the query that produced it.
     *
     * The phase transition is the subtle bit. A short registered page means the
     * registered phase is exhausted, so the batch is topped up from the very
     * start of the guest phase; the last record in that spliced batch is a
     * guest, so cursorFor() reports a `guest:` cursor and the next call never
     * looks at registered users again. A registered page that happens to fill
     * the batch exactly returns as-is, and the following call finds no more
     * registered rows and tops up from the start of the guests — same outcome,
     * one batch later.
     */
    #[\Override]
    public function fetchBatch(string|int|null $cursor, int $limit): array
    {
        [$phase, $value] = self::decodeCursor($cursor);

        $batch = [];
        $guestAfter = null;

        if ($phase === self::PHASE_REGISTERED) {
            $batch = $this->fetchRegisteredBatch(is_int($value) ? $value : 0, $limit);

            if (count($batch) >= $limit) {
                return $batch;
            }
        } else {
            $guestAfter = is_string($value) ? $value : null;
        }

        $remaining = $limit - count($batch);

        if ($remaining > 0) {
            $batch = array_merge($batch, $this->fetchGuestBatch($guestAfter, $remaining));
        }

        return $batch;
    }

    /**
     * Phase-tagged cursor for the record just handed out.
     */
    #[\Override]
    public function cursorFor(mixed $record): string|int
    {
        if (($record['type'] ?? null) === 'guest') {
            return self::PHASE_GUEST . ':' . (string) $record['data']['email'];
        }

        return self::PHASE_REGISTERED . ':' . (int) $record['data']['user_id'];
    }

    /**
     * Hydrate exactly these customers, for a retry run.
     *
     * The fiddly one, because a customer has two identities and a retry only
     * ever sees one of them. The ID map is keyed by phase — `customer` rows on
     * the user ID, `guest_customer` rows on the email — but the *log* is keyed
     * by getRecordId(), which returns the bare value with no phase attached:
     * `"42"` for a registered user, `"bob@example.com"` for a guest. That is
     * what a retry list contains, so that is what this method has to read.
     *
     * The two forms are distinguishable, and not by luck: a registered ID is
     * getRecordId()'s `(string) $userData['user_id']`, so it is always decimal
     * digits, and an email always contains an `@`, so it never is. Digits mean
     * registered, anything else means guest.
     *
     * Phase-prefixed IDs — `registered:42`, `guest:bob@example.com`, the form
     * cursorFor() emits — are accepted too. They are not what the log stores,
     * but they are what someone reading the cursor code would reasonably pass,
     * and honouring them costs two str_starts_with() calls.
     *
     * No existence check is done here on purpose. A user ID whose WP row has
     * been deleted comes back as a record, is handed to processRegistered(),
     * and is logged as `user_not_found` against the retry run — which is the
     * answer the user asked for. Dropping it silently would leave the retry
     * looking like it fixed something.
     *
     * @param array<int, string|int> $wcIds
     *
     * @return list<array{type: string, data: array<string, int|string>}>
     */
    #[\Override]
    public function fetchByIds(array $wcIds): array
    {
        $userIds = [];
        $emails = [];

        foreach ($wcIds as $raw) {
            if (!is_int($raw) && !is_string($raw)) {
                continue;
            }

            $id = trim((string) $raw);

            if ($id === '') {
                continue;
            }

            if (str_starts_with($id, self::PHASE_REGISTERED . ':')) {
                $id = substr($id, strlen(self::PHASE_REGISTERED) + 1);
                $userId = ctype_digit($id) ? (int) $id : 0;

                if ($userId > 0) {
                    $userIds[$userId] = true;
                }

                continue;
            }

            if (str_starts_with($id, self::PHASE_GUEST . ':')) {
                $email = substr($id, strlen(self::PHASE_GUEST) + 1);

                if ($email !== '') {
                    $emails[$email] = true;
                }

                continue;
            }

            if (ctype_digit($id)) {
                $userId = (int) $id;

                if ($userId > 0) {
                    $userIds[$userId] = true;
                }

                continue;
            }

            $emails[$id] = true;
        }

        $userIds = array_keys($userIds);

        // Same one-shot cache priming fetchRegisteredBatch() does, so the
        // per-record get_userdata() calls stay cache hits.
        if ($userIds !== [] && function_exists('cache_users')) {
            cache_users($userIds);
        }

        $batch = array_map(
            static fn (int $id): array => ['type' => 'registered', 'data' => ['user_id' => $id]],
            $userIds,
        );

        foreach (array_keys($emails) as $email) {
            $batch[] = ['type' => 'guest', 'data' => ['email' => (string) $email]];
        }

        return $batch;
    }

    /**
     * Customers write two kinds of ID-map row, and both count as migrated.
     *
     * @return list<string>
     */
    #[\Override]
    protected function migratedEntityTypes(): array
    {
        return [Constants::ENTITY_CUSTOMER, Constants::ENTITY_GUEST_CUSTOMER];
    }

    /**
     * Split a cursor into its phase and per-phase position.
     *
     * Anything unrecognised — null, a bare integer left over from the offset
     * era, a malformed string — starts the entity from the beginning of the
     * registered phase. Re-reads are idempotent skips, so restarting is the
     * safe reading of an ambiguous cursor.
     *
     * @return array{0: string, 1: int|string|null}
     */
    private static function decodeCursor(string|int|null $cursor): array
    {
        if (!is_string($cursor) || !str_contains($cursor, ':')) {
            return [self::PHASE_REGISTERED, null];
        }

        [$phase, $value] = explode(':', $cursor, 2);

        if ($phase === self::PHASE_GUEST) {
            return [self::PHASE_GUEST, $value];
        }

        if ($phase === self::PHASE_REGISTERED && ctype_digit($value)) {
            return [self::PHASE_REGISTERED, (int) $value];
        }

        return [self::PHASE_REGISTERED, null];
    }

    /**
     * Validate a customer record without creating any FC records.
     */
    #[\Override]
    public function validateRecord(mixed $record): bool
    {
        $type = $record['type'];
        $data = $record['data'];

        return match ($type) {
            'registered' => $this->validateRegistered($data),
            'guest'      => $this->validateGuest($data),
            default      => false,
        };
    }

    #[\Override]
    public function processRecord(mixed $record): int|false
    {
        $type = $record['type'];
        $data = $record['data'];

        return match ($type) {
            'registered' => $this->processRegistered($data),
            'guest'      => $this->processGuest($data),
            default      => false,
        };
    }

    #[\Override]
    public function getRecordId(mixed $record): string
    {
        if ($record['type'] === 'registered') {
            return (string) $record['data']['user_id'];
        }

        return $record['data']['email'];
    }

    /**
     * Validate a registered customer without creating any FC records.
     */
    private function validateRegistered(array $userData): bool
    {
        $userId = (int) $userData['user_id'];

        if ($this->idMap->getFcId(Constants::ENTITY_CUSTOMER, (string) $userId)) {
            $this->writeLog($userId, 'dry-run', 'dry-run: already migrated, would skip.', MigrationErrorCode::AlreadyMigrated);
            return false;
        }

        $user = get_userdata($userId);
        if (!$user) {
            $this->writeLog($userId, 'dry-run', 'dry-run: user not found, would fail.', MigrationErrorCode::UserNotFound);
            return false;
        }

        if (empty($user->user_email)) {
            $this->writeLog($userId, 'dry-run', 'dry-run: user has no email, would fail.', MigrationErrorCode::MissingEmail);
            return false;
        }

        $this->writeLog($userId, 'dry-run', sprintf(
            'dry-run: would create customer "%s".',
            $user->user_email,
        ));

        return true;
    }

    /**
     * Validate a guest customer without creating any FC records.
     */
    private function validateGuest(array $guestData): bool
    {
        $email = $guestData['email'];

        if (empty($email)) {
            $this->writeLog($email, 'dry-run', 'dry-run: guest email is empty, would fail.', MigrationErrorCode::MissingEmail);
            return false;
        }

        if ($this->idMap->getFcId(Constants::ENTITY_GUEST_CUSTOMER, $email)) {
            $this->writeLog($email, 'dry-run', 'dry-run: guest already migrated, would skip.', MigrationErrorCode::AlreadyMigrated);
            return false;
        }

        $this->writeLog($email, 'dry-run', sprintf(
            'dry-run: would create customer "%s".',
            $email,
        ));

        return true;
    }

    /**
     * Process a registered WP customer user.
     */
    private function processRegistered(array $userData): int|false
    {
        $userId = (int) $userData['user_id'];

        if ($this->idMap->getFcId(Constants::ENTITY_CUSTOMER, (string) $userId)) {
            $this->writeLog($userId, 'skipped', 'Already migrated.', MigrationErrorCode::AlreadyMigrated);
            return false;
        }

        $user = get_userdata($userId);
        if (!$user) {
            $this->writeLog($userId, 'error', 'User not found.', MigrationErrorCode::UserNotFound);
            return false;
        }

        // FIX C9: when mapping existing FC customer, store with created_by_migration=false.
        $existing = Customer::query()->where('email', $user->user_email)->first();
        if ($existing) {
            $this->idMap->store(
                Constants::ENTITY_CUSTOMER,
                (string) $userId,
                $existing->id,
                $this->migrationId(),
                false,
            );
            $this->writeLog($userId, 'skipped', 'Customer already exists in FluentCart.', MigrationErrorCode::AlreadyExistsInFluentCart);
            return false;
        }

        $mapped = $this->customerMapper->mapRegistered($user);

        $customer = Customer::query()->create($mapped['customer']);
        $this->idMap->store(
            Constants::ENTITY_CUSTOMER,
            (string) $userId,
            $customer->id,
            $this->migrationId(),
            true,
        );

        // FIX C7: compound keys for addresses.
        foreach ($mapped['addresses'] as $addressData) {
            $addressData['customer_id'] = $customer->id;
            $address = CustomerAddresses::query()->create($addressData);
            $addressKey = "{$userId}_{$addressData['type']}";
            $this->idMap->store(
                Constants::ENTITY_CUSTOMER_ADDRESS,
                $addressKey,
                $address->id,
                $this->migrationId(),
                true,
            );
        }

        $this->writeLog($userId, 'success', sprintf(
            'Migrated customer "%s" (FC ID: %d).',
            $user->user_email,
            $customer->id,
        ));

        return $customer->id;
    }

    /**
     * Process a guest customer.
     * FIX C6: use email string as wc_id (VARCHAR), not crc32 hash.
     *
     * The building itself lives in GuestCustomerFactory, because OrderMigrator
     * needs precisely this behaviour for an order whose registered customer was
     * never migrated. What stays here is the reporting: the factory decides what
     * happened, this decides how it reads in the log.
     */
    private function processGuest(array $guestData): int|false
    {
        $email = $guestData['email'];

        if ($this->idMap->getFcId(Constants::ENTITY_GUEST_CUSTOMER, $email)) {
            $this->writeLog($email, 'skipped', 'Guest customer already migrated.', MigrationErrorCode::AlreadyMigrated);
            return false;
        }

        // Find the first order for this guest to build mapped data.
        $order = $this->findFirstGuestOrder($email);
        if (!$order) {
            $this->writeLog($email, 'error', 'No order found for guest email.', MigrationErrorCode::NoOrderForGuest);
            return false;
        }

        $built = $this->guestCustomers->fromOrder($order, $this->migrationId());

        if ($built === null) {
            $this->writeLog($email, 'error', 'No order found for guest email.', MigrationErrorCode::NoOrderForGuest);
            return false;
        }

        if ($built['outcome'] === GuestCustomerFactory::OUTCOME_ADOPTED) {
            $this->writeLog($email, 'skipped', 'Guest customer already exists in FluentCart.', MigrationErrorCode::AlreadyExistsInFluentCart);
            return false;
        }

        if ($built['outcome'] === GuestCustomerFactory::OUTCOME_ALREADY_MAPPED) {
            $this->writeLog($email, 'skipped', 'Guest customer already migrated.', MigrationErrorCode::AlreadyMigrated);
            return false;
        }

        $this->writeLog($email, 'success', sprintf(
            'Migrated guest customer "%s" (FC ID: %d).',
            $email,
            $built['id'],
        ));

        return $built['id'];
    }

    /**
     * FIX H4: count registered customers by order history, not just 'customer' role.
     * Users who have placed orders (customer_id > 0 in wc_orders).
     *
     * Scoped to the same statuses wc_get_orders() returns — an unfiltered query
     * counts people whose only "order" is an abandoned checkout draft.
     */
    private function countRegisteredCustomers(): int
    {
        if ($this->registeredCount !== null) {
            return $this->registeredCount;
        }

        global $wpdb;

        $table = WooStorage::ordersTable();
        [$scope, $scopeValues] = WooStorage::orderScopeParts();
        $selection = $this->scopeResolver()->registeredCustomerPredicate();

        // Prepared now, where it used to be a bare string: the selection carries
        // values, and they have to bind in the same prepare() as the status
        // scope rather than in a nested one.
        $this->registeredCount = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT customer_id)
             FROM {$table}
             WHERE customer_id > 0
               AND {$scope}"
            . $selection->andSql(),
            ...[...$scopeValues, ...$selection->values()],
        ));

        return $this->registeredCount;
    }

    /**
     * FIX H5: count unique guest emails directly via SQL.
     *
     * Same status scope as everything else. Without it, every abandoned cart
     * email becomes a FluentCart customer.
     */
    private function countGuestCustomers(): int
    {
        global $wpdb;

        $table = WooStorage::ordersTable();
        [$scope, $scopeValues] = WooStorage::orderScopeParts();
        $selection = $this->scopeResolver()->guestCustomerPredicate();

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT billing_email)
             FROM {$table}
             WHERE (customer_id IS NULL OR customer_id = 0)
               AND billing_email != ''
               AND {$scope}"
            . $selection->andSql(),
            ...[...$scopeValues, ...$selection->values()],
        ));
    }

    /**
     * FIX H4: registered customers by order history, keyset on customer_id.
     *
     * wc_orders.customer_id is the WP user ID. Verified, because FluentCart's
     * migrator gets this wrong by joining wc_customer_lookup, whose customer_id
     * is that table's own AUTO_INCREMENT primary key and nothing to do with
     * users:
     *
     * @see woocommerce/src/Internal/DataStores/Orders/OrdersTableDataStore.php (v11.0.0, lines 3068, 3072 — customer_id passed to wc_update_user_last_active())
     *
     * @return list<array{type: string, data: array{user_id: int}}>
     */
    private function fetchRegisteredBatch(int $afterUserId, int $limit): array
    {
        global $wpdb;

        $table = WooStorage::ordersTable();

        // Placeholder form, so the scope and the pagination go through a single
        // prepare() rather than nesting one prepared string inside another.
        [$scope, $scopeValues] = WooStorage::orderScopeParts();
        $selection = $this->scopeResolver()->registeredCustomerPredicate();

        // Values bind positionally, and this query now interleaves three
        // sources of them plus the limit. Placeholder order in the string is
        // keyset, status scope, selection, LIMIT — and so is the value order.
        $userIds = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT customer_id
             FROM {$table}
             WHERE customer_id > %d
               AND {$scope}"
            . $selection->andSql()
            . " ORDER BY customer_id ASC
             LIMIT %d",
            ...[max(0, $afterUserId), ...$scopeValues, ...$selection->values(), $limit],
        ));

        $userIds = array_map(intval(...), $userIds);

        // Prime the user cache once for the whole page so the per-record
        // get_userdata() calls in processRegistered()/validateRegistered()
        // become cache hits instead of one query each.
        if ($userIds !== [] && function_exists('cache_users')) {
            cache_users($userIds);
        }

        return array_map(
            static fn (int $id): array => ['type' => 'registered', 'data' => ['user_id' => $id]],
            $userIds,
        );
    }

    /**
     * FIX H5: unique guest emails, keyset on billing_email.
     *
     * The `>` comparison and the ORDER BY both run under the column's own
     * collation, so the two agree by construction — which is what keyset
     * pagination on a string column needs. (Under a case-insensitive collation
     * DISTINCT folds case as well, consistently with both.)
     *
     * @return list<array{type: string, data: array{email: string}}>
     */
    private function fetchGuestBatch(?string $afterEmail, int $limit): array
    {
        global $wpdb;

        $table = WooStorage::ordersTable();

        [$scope, $scopeValues] = WooStorage::orderScopeParts();
        $selection = $this->scopeResolver()->guestCustomerPredicate();

        $after = $afterEmail !== null ? 'AND billing_email > %s' : '';
        $afterValues = $afterEmail !== null ? [$afterEmail] : [];

        // The selection is spliced after the keyset range and the status scope,
        // so the value order stays keyset, status scope, selection, LIMIT. The
        // selection compares billing_email against literals under the column's
        // own collation, exactly as the `>` and the ORDER BY do.
        $emails = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT billing_email
             FROM {$table}
             WHERE (customer_id IS NULL OR customer_id = 0)
               AND billing_email != ''
               {$after}
               AND {$scope}"
            . $selection->andSql()
            . " ORDER BY billing_email ASC
             LIMIT %d",
            ...[...$afterValues, ...$scopeValues, ...$selection->values(), $limit],
        ));

        return array_map(
            static fn (string $email): array => ['type' => 'guest', 'data' => ['email' => $email]],
            $emails,
        );
    }

    /**
     * Find the first WC order for a guest email to extract customer data.
     *
     * No 'status' arg is needed: WC_Order_Query defaults it to
     * array_keys(wc_get_order_statuses()), the same set used everywhere else.
     *
     * @see woocommerce/includes/class-wc-order-query.php::get_default_query_vars() (v11.0.0, line 26)
     */
    private function findFirstGuestOrder(string $email): ?\WC_Order
    {
        $orders = wc_get_orders([
            'billing_email' => $email,
            'customer_id'   => 0,
            'limit'         => 1,
            'orderby'       => 'ID',
            'order'         => 'ASC',
            'status'        => 'any',
            'type'          => WooStorage::TYPE_ORDER,
        ]);

        return $orders[0] ?? null;
    }
}

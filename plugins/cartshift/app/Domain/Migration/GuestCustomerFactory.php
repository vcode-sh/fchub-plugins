<?php

declare(strict_types=1);

namespace CartShift\Domain\Migration;

defined('ABSPATH') || exit;

use CartShift\Domain\Mapping\CustomerMapper;
use CartShift\Storage\IdMapRepository;
use CartShift\Support\Constants;
use FluentCart\App\Models\Customer;
use FluentCart\App\Models\CustomerAddresses;

/**
 * Build a FluentCart customer out of a WooCommerce order's own billing details.
 *
 * Two callers need exactly this, and for the same reason. CustomerMigrator has
 * always used it for guest checkouts: there is no WP user, so the order is the
 * only record of who bought the thing. OrderMigrator now uses it for a second
 * case that used to be a silent skip — an order whose registered customer was
 * never migrated. A buyer rebuilt from the order is not as good as the original
 * customer record, but it is enormously better than losing the order and the
 * revenue on it.
 *
 * Everything is keyed on the billing email under ENTITY_GUEST_CUSTOMER, which is
 * what makes the second order from the same buyer reuse the first rebuild rather
 * than creating a duplicate customer per order.
 */
final class GuestCustomerFactory
{
    /** An earlier record already put this email in the ID map. */
    public const string OUTCOME_ALREADY_MAPPED = 'already_mapped';

    /** A FluentCart customer with this email already existed and was adopted. */
    public const string OUTCOME_ADOPTED = 'adopted';

    /** A customer row was created from the order. */
    public const string OUTCOME_CREATED = 'created';

    private readonly CustomerMapper $customerMapper;

    public function __construct(
        private readonly IdMapRepository $idMap,
        ?CustomerMapper $customerMapper = null,
    ) {
        $this->customerMapper = $customerMapper ?? new CustomerMapper($idMap);
    }

    /**
     * Resolve — creating if need be — the FluentCart customer for an order's
     * billing email.
     *
     * Returns null when the order carries no billing email, which is the one
     * case where there is genuinely nothing to build a buyer from. Callers
     * decide what that means: CustomerMigrator has no record to migrate,
     * OrderMigrator writes the order with a null customer_id rather than
     * dropping it.
     *
     * @return array{id: int, outcome: string, email: string}|null
     */
    public function fromOrder(\WC_Order $order, string $migrationId): ?array
    {
        $email = trim((string) $order->get_billing_email());

        if ($email === '') {
            return null;
        }

        $existingFcId = $this->idMap->getFcId(Constants::ENTITY_GUEST_CUSTOMER, $email);
        if ($existingFcId) {
            return ['id' => $existingFcId, 'outcome' => self::OUTCOME_ALREADY_MAPPED, 'email' => $email];
        }

        // FIX C9: an adopted customer is stored with created_by_migration=false,
        // so a rollback never deletes a record CartShift did not create.
        $existing = Customer::query()->where('email', $email)->first();
        if ($existing) {
            $this->idMap->store(
                Constants::ENTITY_GUEST_CUSTOMER,
                $email,
                $existing->id,
                $migrationId,
                false,
            );

            return ['id' => (int) $existing->id, 'outcome' => self::OUTCOME_ADOPTED, 'email' => $email];
        }

        $mapped = $this->customerMapper->mapGuest($order);

        $customer = Customer::query()->create($mapped['customer']);
        $this->idMap->store(
            Constants::ENTITY_GUEST_CUSTOMER,
            $email,
            $customer->id,
            $migrationId,
            true,
        );

        // FIX C7: compound keys for addresses.
        foreach ($mapped['addresses'] as $addressData) {
            $addressData['customer_id'] = $customer->id;
            $address = CustomerAddresses::query()->create($addressData);
            $this->idMap->store(
                Constants::ENTITY_CUSTOMER_ADDRESS,
                "{$email}_{$addressData['type']}",
                $address->id,
                $migrationId,
                true,
            );
        }

        return ['id' => (int) $customer->id, 'outcome' => self::OUTCOME_CREATED, 'email' => $email];
    }
}

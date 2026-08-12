<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\Customer;

use CartShift\Domain\Transfer\RecordEnvelope;
use CartShift\Domain\Transfer\SelectionClause;
use CartShift\Domain\Transfer\SelectionMode;
use CartShift\Domain\Transfer\SourceIdentity;
use CartShift\Domain\Transfer\SourceRecordException;
use CartShift\Domain\Transfer\TransferRecordSource;
use CartShift\Domain\Transfer\TransferSelection;
use CartShift\Support\UtcDateTime;

defined('ABSPATH') || exit;

final class WooCustomerRecordSource implements TransferRecordSource
{
    private readonly ?\Closure $registeredReader;
    private readonly ?\Closure $guestOrderReader;

    public function __construct(?callable $registeredReader = null, ?callable $guestOrderReader = null)
    {
        $this->registeredReader = $registeredReader === null ? null : $registeredReader(...);
        $this->guestOrderReader = $guestOrderReader === null ? null : $guestOrderReader(...);
    }

    /** @return iterable<RecordEnvelope> */
    public function records(TransferSelection $selection): iterable
    {
        if ($selection->customers->mode === SelectionMode::None) return;
        $rows = $this->registeredReader !== null ? ($this->registeredReader)($selection->customers) : $this->loadedRegistered($selection->customers);
        $records = [];
        foreach ($rows as $row) {
            $data = is_array($row) ? $this->allowlisted($row) : $this->registeredObject($row);
            $id = (int) ($data['user_id'] ?? 0);
            if ($id <= 0 || isset($records[$id])) throw new SourceRecordException('customer_source_identity_duplicate', 'Customer source returned an invalid or duplicate identity.');
            $records[$id] = $this->fromData($selection->sourceKey, (string) $id, $id, 'registered', $data);
        }
        ksort($records, SORT_NUMERIC);
        if ($selection->customers->mode === SelectionMode::Ids && array_keys($records) !== $selection->customers->ids) {
            throw new SourceRecordException('selection_identity_missing', 'Explicit customer selection did not hydrate exactly once.');
        }
        foreach ($records as $record) yield $record;
    }

    public function record(SourceIdentity $identity): RecordEnvelope
    {
        if ($identity->entityType !== 'customer') throw new \InvalidArgumentException('Customer source can hydrate only customer identities.');
        if (preg_match('/\A([1-9][0-9]*):guest\z/D', $identity->sourceId, $match) === 1) {
            $orderId = (int) $match[1];
            $row = $this->guestOrderReader !== null ? ($this->guestOrderReader)($orderId) : $this->loadedGuestOrder($orderId);
            if ($row === null) throw new SourceRecordException('guest_customer_source_missing', 'Guest customer source order did not hydrate.');
            $data = is_array($row) ? $this->allowlisted($row, true) : $this->guestObject($row);
            if ((int) ($data['order_id'] ?? 0) !== $orderId) throw new SourceRecordException('guest_customer_source_mismatch', 'Guest customer source returned a different order identity.');
            return $this->fromData($identity->sourceKey, $identity->sourceId, null, 'guest', $data);
        }
        if (preg_match('/\A[1-9][0-9]*\z/D', $identity->sourceId) !== 1) throw new SourceRecordException('customer_source_identity_invalid', 'Customer source identity is invalid.');
        $clause = SelectionClause::ids([(int) $identity->sourceId]);
        $selection = new TransferSelection($identity->sourceKey, SelectionClause::none(), $clause, SelectionClause::none(), SelectionClause::none());
        $records = iterator_to_array($this->records($selection));
        return $records[0];
    }

    /** @param array<string, mixed> $data */
    private function fromData(string $sourceKey, string $sourceId, ?int $userId, string $classification, array $data): RecordEnvelope
    {
        $identity = new SourceIdentity($sourceKey, 'customer', $sourceId);
        $addresses = [];
        foreach (['billing', 'shipping'] as $type) {
            $address = is_array($data[$type] ?? null) ? $data[$type] : [];
            if (!$this->meaningful($address)) continue;
            $addresses[] = new CustomerAddressRecord(
                new SourceIdentity($sourceKey, 'customer', $sourceId . ':' . $type), $type, true, 'active', ucfirst($type),
                trim((string) ($address['name'] ?? '')), trim((string) ($address['company'] ?? '')),
                trim((string) ($address['address_1'] ?? '')), trim((string) ($address['address_2'] ?? '')),
                trim((string) ($address['city'] ?? '')), trim((string) ($address['state'] ?? '')),
                trim((string) ($address['postcode'] ?? '')), strtoupper(trim((string) ($address['country'] ?? ''))),
                trim((string) ($address['phone'] ?? '')), trim((string) ($address['email'] ?? '')),
            );
        }
        return CustomerRecord::create(
            $identity, $userId, $classification, trim((string) ($data['first_name'] ?? '')), trim((string) ($data['last_name'] ?? '')),
            trim((string) ($data['email'] ?? '')), (string) ($data['status'] ?? 'active'), $addresses,
            $this->canonicalDate($data['created_utc'] ?? null), $this->canonicalDate($data['updated_utc'] ?? null),
            ['origin' => $classification === 'guest' ? 'order_snapshot' : 'source_user', 'source_order_id' => $classification === 'guest' ? (int) ($data['order_id'] ?? 0) : null], [],
        )->envelope();
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function allowlisted(array $row, bool $guest = false): array
    {
        $keys = ['email', 'first_name', 'last_name', 'status', 'created_utc', 'updated_utc', 'billing', 'shipping'];
        if ($guest) $keys[] = 'order_id'; else $keys[] = 'user_id';
        return array_intersect_key($row, array_flip($keys));
    }

    private function meaningful(array $address): bool
    {
        foreach (['name', 'company', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country', 'phone', 'email'] as $field) if (trim((string) ($address[$field] ?? '')) !== '') return true;
        return false;
    }

    private function canonicalDate(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? UtcDateTime::canonical($value) : null;
    }

    /** @return iterable<object> */
    private function loadedRegistered(SelectionClause $clause): iterable
    {
        if (!function_exists('get_users')) throw new SourceRecordException('wordpress_user_api_unavailable', 'WordPress user API is unavailable.');
        $args = ['orderby' => 'ID', 'order' => 'ASC', 'fields' => 'all'];
        if ($clause->mode === SelectionMode::Ids) $args['include'] = $clause->ids;
        if ($clause->mode === SelectionMode::Since) $args['date_query'] = [['after' => $clause->since, 'inclusive' => true]];
        foreach ((array) get_users($args) as $user) yield $user;
    }

    /** @return array<string, mixed> */
    private function registeredObject(mixed $user): array
    {
        if (!is_object($user) || (int) ($user->ID ?? 0) <= 0) throw new SourceRecordException('customer_hydration_failed', 'Selected WordPress customer did not hydrate.');
        $id = (int) $user->ID;
        return ['user_id' => $id, 'email' => (string) ($user->user_email ?? ''), 'first_name' => (string) ($user->first_name ?? ''), 'last_name' => (string) ($user->last_name ?? ''), 'status' => 'active', 'created_utc' => UtcDateTime::canonical((string) ($user->user_registered ?? '')), 'updated_utc' => null, 'billing' => $this->userAddress($id, 'billing'), 'shipping' => $this->userAddress($id, 'shipping')];
    }

    /** @return array<string, string> */
    private function userAddress(int $id, string $type): array
    {
        $meta = static fn (string $field): string => trim((string) get_user_meta($id, $type . '_' . $field, true));
        return ['type' => $type, 'name' => trim($meta('first_name') . ' ' . $meta('last_name')), 'company' => $meta('company'), 'address_1' => $meta('address_1'), 'address_2' => $meta('address_2'), 'city' => $meta('city'), 'state' => $meta('state'), 'postcode' => $meta('postcode'), 'country' => $meta('country'), 'phone' => $meta('phone'), 'email' => $meta('email')];
    }

    private function loadedGuestOrder(int $orderId): mixed { return function_exists('wc_get_order') ? wc_get_order($orderId) : null; }

    /** @return array<string, mixed> */
    private function guestObject(mixed $order): array
    {
        if (!is_object($order) || !is_callable([$order, 'get_id'])) throw new SourceRecordException('guest_customer_hydration_failed', 'Guest customer source order did not hydrate.');
        $address = static function (object $order, string $type): array {
            $get = static fn (string $field): string => trim((string) $order->{'get_' . $type . '_' . $field}());
            return ['type' => $type, 'name' => trim($get('first_name') . ' ' . $get('last_name')), 'company' => $get('company'), 'address_1' => $get('address_1'), 'address_2' => $get('address_2'), 'city' => $get('city'), 'state' => $get('state'), 'postcode' => $get('postcode'), 'country' => $get('country'), 'phone' => is_callable([$order, 'get_' . $type . '_phone']) ? $get('phone') : '', 'email' => is_callable([$order, 'get_' . $type . '_email']) ? $get('email') : ''];
        };
        return ['order_id' => (int) $order->get_id(), 'email' => (string) $order->get_billing_email(), 'first_name' => (string) $order->get_billing_first_name(), 'last_name' => (string) $order->get_billing_last_name(), 'status' => 'active', 'created_utc' => UtcDateTime::canonical($order->get_date_created()), 'updated_utc' => UtcDateTime::canonical(is_callable([$order, 'get_date_modified']) ? $order->get_date_modified() : null), 'billing' => $address($order, 'billing'), 'shipping' => $address($order, 'shipping')];
    }
}

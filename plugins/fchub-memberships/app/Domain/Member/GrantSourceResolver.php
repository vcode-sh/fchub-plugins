<?php

namespace FChubMemberships\Domain\Member;

defined('ABSPATH') || exit;

/**
 * Turns a membership's provenance into something an administrator can follow.
 *
 * A link is produced only for a record that was found. An identifier without a
 * destination is still useful; a link that leads nowhere is not.
 */
final class GrantSourceResolver
{
    public function __construct(
        private ?FluentCartSourceGateway $gateway = null,
        private ?ActorNameResolver $actors = null
    ) {
        $this->gateway ??= new FluentCartSourceGateway();
        $this->actors ??= new ActorNameResolver();
    }

    /**
     * @param array<string, mixed> $membership
     * @param list<array<string, mixed>> $auditRecords audit rows for this membership's grants
     * @return array{
     *     type: string, label: string, url: ?string, actor: ?string,
     *     granted_at: ?string, subscription: ?array<string, mixed>
     * }
     */
    public function resolve(array $membership, array $auditRecords = []): array
    {
        $type = (string) ($membership['source_type'] ?? '');
        $id = (int) ($membership['source_id'] ?? 0);
        $creation = $this->creationRecord($auditRecords);

        $source = [
            'type' => $type,
            'label' => $this->label($type, $id),
            'url' => null,
            'actor' => $creation ? $this->actorName($creation) : null,
            'granted_at' => $creation['created_at'] ?? null,
            'subscription' => null,
        ];

        if ($type === 'order') {
            $source['url'] = $this->gateway->order($id) ? $this->orderUrl($id) : null;

            return $source;
        }

        if ($type === 'subscription') {
            $subscription = $this->gateway->subscription($id);
            if ($subscription === null) {
                return $source;
            }

            $source['subscription'] = $this->subscriptionFacts($subscription);
            $parentOrderId = $subscription['parent_order_id'];
            if ($parentOrderId > 0 && $this->gateway->order($parentOrderId)) {
                $source['url'] = $this->orderUrl($parentOrderId);
            }
        }

        return $source;
    }

    private function label(string $type, int $id): string
    {
        return match ($type) {
            'order' => sprintf(/* translators: %d: order id */ __('Order #%d', 'fchub-memberships'), $id),
            'subscription' => sprintf(
                /* translators: %d: subscription id */
                __('Subscription #%d', 'fchub-memberships'),
                $id
            ),
            'manual' => __('Manual grant', 'fchub-memberships'),
            'trial' => __('Trial', 'fchub-memberships'),
            'import' => __('Imported', 'fchub-memberships'),
            '' => __('Unknown', 'fchub-memberships'),
            default => $id > 0
                ? ucfirst(str_replace('_', ' ', $type)) . ' #' . $id
                : ucfirst(str_replace('_', ' ', $type)),
        };
    }

    private function orderUrl(int $orderId): string
    {
        return admin_url('admin.php?page=fluent-cart#/orders/' . $orderId . '/view');
    }

    /**
     * A cancelled subscription stops advertising a renewal it will not perform.
     *
     * @param array<string, mixed> $subscription
     * @return array<string, mixed>
     */
    private function subscriptionFacts(array $subscription): array
    {
        $cancelled = !empty($subscription['canceled_at'])
            || in_array((string) $subscription['status'], ['cancelled', 'canceled', 'failed', 'expired'], true);

        return [
            'id' => $subscription['id'],
            'status' => $subscription['status'],
            'next_billing_date' => $cancelled ? null : ($subscription['next_billing_date'] ?: null),
            'canceled_at' => $subscription['canceled_at'] ?: null,
        ];
    }

    /**
     * @param list<array<string, mixed>> $auditRecords
     * @return array<string, mixed>|null
     */
    private function creationRecord(array $auditRecords): ?array
    {
        foreach ($auditRecords as $record) {
            if (($record['action'] ?? '') === 'created') {
                return $record;
            }
        }

        return null;
    }

    /** @param array<string, mixed> $record */
    private function actorName(array $record): string
    {
        return $this->actors->name((int) ($record['actor_id'] ?? 0))
            ?? __('System', 'fchub-memberships');
    }
}

<?php

namespace FChubMemberships\Domain\Member;

defined('ABSPATH') || exit;

/**
 * Composes one readable history from audit records and drip notifications.
 *
 * Events belong to memberships, not to storage rows, so a change applied to
 * every rule of a plan reads as the single change it was. Descriptions are
 * written for the person reading them, not for the column they came from.
 */
final class MemberTimelineComposer
{
    private const ACTION_TYPES = [
        'created' => 'granted',
        'renewed' => 'renewed',
        'extended' => 'extended',
        'revoked' => 'revoked',
        'paused' => 'paused',
        'resumed' => 'resumed',
        'expired' => 'expired',
        'trial_converted' => 'trial_converted',
        'trial_expired' => 'trial_expired',
    ];

    public function __construct(private ?ActorNameResolver $actors = null)
    {
        $this->actors ??= new ActorNameResolver();
    }

    /**
     * @param list<array<string, mixed>> $memberships from MembershipGrouper
     * @param list<array<string, mixed>> $auditRecords grant audit rows
     * @param list<array<string, mixed>> $dripNotifications
     * @return list<array<string, mixed>> newest first
     */
    public function compose(array $memberships, array $auditRecords, array $dripNotifications = []): array
    {
        $owners = $this->grantOwners($memberships);
        $events = [];

        foreach ($auditRecords as $record) {
            $membership = $owners[(int) ($record['entity_id'] ?? 0)] ?? null;
            if ($membership === null) {
                continue;
            }

            $events[] = $this->auditEvent($record, $membership);
        }

        foreach ($dripNotifications as $notification) {
            $membership = $owners[(int) ($notification['grant_id'] ?? 0)] ?? null;
            if ($membership === null) {
                continue;
            }

            $events[] = $this->dripEvent($notification, $membership);
        }

        return $this->sortAndDeduplicate(array_merge($events, $this->recordedDates($memberships, $events)));
    }

    /**
     * Dates the grant rows themselves record, for memberships the audit log
     * never covered — an import, or anything created before a change was
     * audited. Only start and expiry are stored on the row; a revocation date
     * lives in the audit log alone and is never guessed at.
     *
     * @param list<array<string, mixed>> $memberships
     * @param list<array<string, mixed>> $events
     * @return list<array<string, mixed>>
     */
    private function recordedDates(array $memberships, array $events): array
    {
        $covered = [];
        foreach ($events as $event) {
            $covered[$event['membership_key'] . '|' . $event['type']] = true;
        }

        $derived = [];
        foreach ($memberships as $membership) {
            $key = $membership['key'];

            if (!empty($membership['created_at']) && !isset($covered[$key . '|granted'])) {
                $derived[] = $this->rowEvent($membership, 'granted', $membership['created_at']);
            }

            if (($membership['status'] ?? '') === 'expired'
                && !empty($membership['expires_at'])
                && !isset($covered[$key . '|expired'])
            ) {
                $derived[] = $this->rowEvent($membership, 'expired', $membership['expires_at']);
            }
        }

        return $derived;
    }

    /**
     * @param array<string, mixed> $membership
     * @return array<string, mixed>
     */
    private function rowEvent(array $membership, string $type, string $date): array
    {
        return [
            'date' => $date,
            'type' => $type,
            'membership_key' => $membership['key'],
            'plan_title' => $membership['plan_title'],
            'description' => $membership['plan_title'] . ' ' . $this->verb(
                $type === 'granted' ? 'created' : $type,
                []
            ),
            'metadata' => ['derived_from' => 'grant'],
        ];
    }

    /**
     * @param list<array<string, mixed>> $memberships
     * @return array<int, array<string, mixed>>
     */
    private function grantOwners(array $memberships): array
    {
        $owners = [];
        foreach ($memberships as $membership) {
            foreach ($membership['grant_ids'] ?? [] as $grantId) {
                $owners[(int) $grantId] = $membership;
            }
        }

        return $owners;
    }

    /**
     * @param array<string, mixed> $record
     * @param array<string, mixed> $membership
     * @return array<string, mixed>
     */
    private function auditEvent(array $record, array $membership): array
    {
        $action = (string) ($record['action'] ?? '');
        $actor = $this->actors->name((int) ($record['actor_id'] ?? 0));

        return [
            'date' => (string) ($record['created_at'] ?? ''),
            'type' => self::ACTION_TYPES[$action] ?? $action,
            'membership_key' => $membership['key'],
            'plan_title' => $membership['plan_title'],
            'description' => $this->describe($action, $record, $membership, $actor),
            'metadata' => [
                'audit_id' => (int) ($record['id'] ?? 0),
                'actor' => $actor,
                'actor_type' => (string) ($record['actor_type'] ?? ''),
                'context' => (string) ($record['context'] ?? ''),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $record
     * @param array<string, mixed> $membership
     */
    private function describe(string $action, array $record, array $membership, ?string $actor): string
    {
        $sentence = $membership['plan_title'] . ' ' . $this->verb($action, $record);

        if ($actor !== null) {
            $sentence .= sprintf(/* translators: %s: actor name */ __(' by %s', 'fchub-memberships'), $actor);
        }

        $context = trim((string) ($record['context'] ?? ''));

        return $context === '' ? $sentence : $sentence . ' — ' . $context;
    }

    /** @param array<string, mixed> $record */
    private function verb(string $action, array $record): string
    {
        if ($action === 'extended' || ($action === 'renewed' && $this->expiryOf($record) !== null)) {
            $expiry = $this->expiryOf($record);
            if ($expiry !== null) {
                return sprintf(
                    /* translators: 1: action word, 2: formatted date */
                    __('%1$s to %2$s', 'fchub-memberships'),
                    $action === 'extended' ? __('extended', 'fchub-memberships') : __('renewed', 'fchub-memberships'),
                    wp_date(get_option('date_format'), strtotime($expiry))
                );
            }
        }

        return match ($action) {
            'created' => __('granted', 'fchub-memberships'),
            'revoked' => __('revoked', 'fchub-memberships'),
            'paused' => __('paused', 'fchub-memberships'),
            'resumed' => __('resumed', 'fchub-memberships'),
            'expired' => __('expired', 'fchub-memberships'),
            'renewed' => __('renewed', 'fchub-memberships'),
            'trial_converted' => __('trial converted', 'fchub-memberships'),
            'trial_expired' => __('trial expired', 'fchub-memberships'),
            default => str_replace('_', ' ', $action),
        };
    }

    /** @param array<string, mixed> $record */
    private function expiryOf(array $record): ?string
    {
        $expiry = $record['new_value']['expires_at'] ?? null;

        return $expiry ? (string) $expiry : null;
    }

    /**
     * @param array<string, mixed> $notification
     * @param array<string, mixed> $membership
     * @return array<string, mixed>
     */
    private function dripEvent(array $notification, array $membership): array
    {
        $status = (string) ($notification['status'] ?? '');
        [$type, $verb] = match ($status) {
            'sent' => ['drip_sent', __('drip notification sent', 'fchub-memberships')],
            'pending' => ['drip_scheduled', __('drip notification scheduled', 'fchub-memberships')],
            default => ['drip_failed', __('drip notification failed', 'fchub-memberships')],
        };

        return [
            'date' => (string) ($notification['sent_at'] ?? $notification['notify_at'] ?? ''),
            'type' => $type,
            'membership_key' => $membership['key'],
            'plan_title' => $membership['plan_title'],
            'description' => $membership['plan_title'] . ' ' . $verb,
            'metadata' => ['notification_id' => (int) ($notification['id'] ?? 0)],
        ];
    }

    /**
     * @param list<array<string, mixed>> $events
     * @return list<array<string, mixed>>
     */
    private function sortAndDeduplicate(array $events): array
    {
        usort($events, static fn(array $a, array $b): int => strcmp($b['date'], $a['date']));

        $seen = [];
        $unique = [];
        foreach ($events as $event) {
            $key = $event['membership_key'] . '|' . $event['type'] . '|' . $event['date'];
            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $unique[] = $event;
        }

        return $unique;
    }
}

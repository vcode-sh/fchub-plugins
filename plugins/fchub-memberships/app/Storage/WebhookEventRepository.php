<?php

declare(strict_types=1);

namespace FChubMemberships\Storage;

defined('ABSPATH') || exit;

final class WebhookEventRepository
{
    private string $table;
    private string $deliveryTable;

    public function __construct()
    {
        global $wpdb;

        $this->table = \FChubMemberships\Support\CustomTableDatabase::identifier($wpdb->prefix . 'fchub_membership_webhook_events');
        $this->deliveryTable = \FChubMemberships\Support\CustomTableDatabase::identifier($wpdb->prefix . 'fchub_membership_webhook_deliveries');
    }

    /** @param array<string, mixed> $event */
    public function create(array $event): bool
    {
        $record = $this->record($event);

        global $wpdb;

        $previousSuppression = $wpdb->suppress_errors(true);
        try {
            $inserted = \FChubMemberships\Support\CustomTableDatabase::insert($this->table, $record);
        } finally {
            $wpdb->suppress_errors($previousSuppression);
        }
        if ($inserted !== false) {
            return true;
        }

        $existing = $this->findByEventId($record['event_id']);
        if ($existing === null || !$this->sameImmutableRecord($existing, $record)) {
            throw new \RuntimeException('Unable to persist webhook event.');
        }

        return true;
    }

    /** @return array<string, mixed>|null */
    public function findByEventId(string $eventId): ?array
    {
        if (!$this->validEventId($eventId)) {
            return null;
        }

        global $wpdb;

        $row = \FChubMemberships\Support\CustomTableDatabase::getRow(\FChubMemberships\Support\CustomTableDatabase::prepare(
            "SELECT * FROM {$this->table} WHERE event_id = %s",
            $eventId
        ), ARRAY_A);
        if ($wpdb->last_error !== '') {
            throw new \RuntimeException('Unable to read webhook event.');
        }

        if (!is_array($row)) {
            return null;
        }

        $row['id'] = (int) $row['id'];

        return $row;
    }

    public function deleteOrphansBefore(string $cutoff): int
    {
        $this->assertDate($cutoff);

        global $wpdb;

        $deleted = \FChubMemberships\Support\CustomTableDatabase::query(\FChubMemberships\Support\CustomTableDatabase::prepare(
            "DELETE event FROM {$this->table} event
             WHERE event.created_at < %s
               AND NOT EXISTS (
                    SELECT 1 FROM {$this->deliveryTable} delivery
                    WHERE delivery.event_id = event.event_id
               )",
            $cutoff
        ));
        if ($deleted === false) {
            throw new \RuntimeException('Unable to purge orphan webhook events.');
        }

        return $deleted;
    }

    /** @param array<string, mixed> $event @return array<string, string> */
    private function record(array $event): array
    {
        $record = [];
        foreach (['event_id', 'event_type', 'schema_version', 'body', 'occurred_at', 'created_at'] as $field) {
            $record[$field] = isset($event[$field]) ? (string) $event[$field] : '';
        }

        if (!$this->validEventId($record['event_id'])
            || $record['event_type'] === ''
            || strlen($record['event_type']) > 64
            || $record['schema_version'] === ''
            || strlen($record['schema_version']) > 10
            || $record['body'] === ''
        ) {
            throw new \InvalidArgumentException('Invalid webhook event record.');
        }
        $this->assertDate($record['occurred_at']);
        $this->assertDate($record['created_at']);

        return $record;
    }

    /** @param array<string, mixed> $existing @param array<string, string> $record */
    private function sameImmutableRecord(array $existing, array $record): bool
    {
        foreach ($record as $field => $value) {
            if ((string) ($existing[$field] ?? '') !== $value) {
                return false;
            }
        }

        return true;
    }

    private function validEventId(string $eventId): bool
    {
        return preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[1-5][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/i', $eventId) === 1;
    }

    private function assertDate(string $date): void
    {
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $date, new \DateTimeZone('UTC'));
        if ($parsed === false || $parsed->format('Y-m-d H:i:s') !== $date) {
            throw new \InvalidArgumentException('Invalid webhook event timestamp.');
        }
    }
}

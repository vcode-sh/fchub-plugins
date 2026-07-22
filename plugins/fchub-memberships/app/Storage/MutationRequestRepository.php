<?php

namespace FChubMemberships\Storage;

defined('ABSPATH') || exit;

class MutationRequestRepository
{
    private string $table;

    public function __construct()
    {
        global $wpdb;
        $this->table = $wpdb->prefix . 'fchub_membership_mutation_requests';
    }

    public function find(string $key): ?array
    {
        global $wpdb;

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table} WHERE request_key = %s",
            $key
        ), ARRAY_A);

        if (!$row) {
            return null;
        }

        $row['user_id'] = (int) $row['user_id'];
        $row['response_status'] = isset($row['response_status']) ? (int) $row['response_status'] : null;
        $row['response_body'] = !array_key_exists('response_body', $row) || $row['response_body'] === null
            ? null
            : json_decode((string) $row['response_body'], true);

        return $row;
    }

    public function reserve(string $key, string $fingerprint, int $userId): bool
    {
        global $wpdb;

        return $wpdb->insert($this->table, [
            'request_key' => $key,
            'fingerprint' => $fingerprint,
            'user_id' => $userId,
            'state' => 'reserved',
            'created_at' => current_time('mysql', true),
            'updated_at' => current_time('mysql', true),
        ]) !== false;
    }

    public function complete(string $key, int $status, mixed $body): bool
    {
        return $this->persistResponse($key, 'complete', $status, $body);
    }

    public function fail(string $key, int $status, mixed $body): bool
    {
        return $this->persistResponse($key, 'failed', $status, $body);
    }

    private function persistResponse(string $key, string $state, int $status, mixed $body): bool
    {
        global $wpdb;

        $encodedBody = wp_json_encode($body);
        if ($encodedBody === false) {
            return false;
        }

        $updated = $wpdb->update($this->table, [
            'state' => $state,
            'response_status' => $status,
            'response_body' => $encodedBody,
            'completed_at' => current_time('mysql', true),
            'updated_at' => current_time('mysql', true),
        ], [
            'request_key' => $key,
        ]);

        return $updated === 1;
    }
}

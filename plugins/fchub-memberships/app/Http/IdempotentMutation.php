<?php

namespace FChubMemberships\Http;

use FChubMemberships\Storage\MutationRequestRepository;

defined('ABSPATH') || exit;

class IdempotentMutation
{
    private MutationRequestRepository $requests;

    public function __construct(?MutationRequestRepository $requests = null)
    {
        $this->requests = $requests ?? new MutationRequestRepository();
    }

    public function execute(\WP_REST_Request $request, string $operation, callable $mutation): \WP_REST_Response
    {
        // Response replay is durable. Reclaim after a crash is intentionally
        // at-least-once and therefore still relies on domain-idempotent writes.
        $key = trim((string) $request->get_header('Idempotency-Key'));
        if ($key === '') {
            if (MembershipMutationPermission::requiresIdempotencyKey()) {
                return $this->error(
                    'fchub_idempotency_key_required',
                    'Idempotency-Key is required for Application Password writes.',
                    428
                );
            }

            return $mutation();
        }

        if (strlen($key) > 191) {
            return $this->error('fchub_idempotency_key_invalid', 'Idempotency-Key must not exceed 191 characters.', 400);
        }

        $fingerprint = $this->fingerprint($request, $operation);
        $userId = get_current_user_id();
        $existing = $this->requests->find($key);

        if ($existing) {
            $response = $this->existingResponse($existing, $fingerprint, $userId);
            if (($existing['state'] ?? '') !== 'reserved' || $response->get_status() !== 409) {
                return $response;
            }
        }

        $leaseToken = $this->requests->reserve($key, $fingerprint, $userId);
        if ($leaseToken === null) {
            $existing = $this->requests->find($key);

            if ($existing) {
                return $this->existingResponse($existing, $fingerprint, $userId);
            }

            return $this->error('fchub_idempotency_in_progress', 'The request is already being processed.', 409);
        }

        try {
            $response = $mutation();
        } catch (\Throwable) {
            $response = $this->error('fchub_idempotency_mutation_failed', 'The mutation could not be completed.', 500);
            if (!$this->requests->fail($key, $leaseToken, $response->get_status(), $response->get_data())) {
                return $this->persistenceFailure($key, $leaseToken);
            }

            return $response;
        }

        $body = $response->get_data();
        if (!$this->requests->complete($key, $leaseToken, $response->get_status(), $body)) {
            return $this->persistenceFailure($key, $leaseToken);
        }

        return $response;
    }

    public function fingerprint(\WP_REST_Request $request, string $operation): string
    {
        $body = $this->sortRecursively($request->get_json_params());

        return hash('sha256', json_encode([
            'operation' => $operation,
            'user_id' => get_current_user_id(),
            'body' => $body,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    private function existingResponse(array $existing, string $fingerprint, int $userId): \WP_REST_Response
    {
        if (!hash_equals((string) $existing['fingerprint'], $fingerprint)
            || (int) ($existing['user_id'] ?? 0) !== $userId
        ) {
            return $this->error('fchub_idempotency_conflict', 'Idempotency-Key is already associated with a different request.', 409);
        }

        if (in_array(($existing['state'] ?? ''), ['complete', 'failed'], true)) {
            // Replay intentionally preserves only status/body plus the replay marker;
            // arbitrary mutation headers are outside the persistence contract.
            return new \WP_REST_Response(
                $existing['response_body'] ?? null,
                (int) ($existing['response_status'] ?? 500),
                ['Idempotency-Replayed' => 'true']
            );
        }

        return $this->error('fchub_idempotency_in_progress', 'The request is already being processed.', 409);
    }

    private function error(string $code, string $message, int $status): \WP_REST_Response
    {
        return new \WP_REST_Response([
            'code' => $code,
            'message' => $message,
        ], $status);
    }

    private function persistenceFailure(string $key, string $leaseToken): \WP_REST_Response
    {
        $response = $this->error(
            'fchub_idempotency_persistence_failed',
            'The mutation result could not be stored safely.',
            500
        );

        $this->requests->fail($key, $leaseToken, $response->get_status(), $response->get_data());

        return $response;
    }

    private function sortRecursively(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        foreach ($value as $key => $item) {
            $value[$key] = $this->sortRecursively($item);
        }

        if (!array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        return $value;
    }
}

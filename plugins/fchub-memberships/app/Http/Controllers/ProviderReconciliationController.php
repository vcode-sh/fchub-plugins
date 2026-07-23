<?php

declare(strict_types=1);

namespace FChubMemberships\Http\Controllers;

use FChubMemberships\Domain\Reconciliation\ProviderReconciliationService;
use FChubMemberships\Http\IdempotentMutation;
use FChubMemberships\Http\MembershipMutationPermission;

defined('ABSPATH') || exit;

final class ProviderReconciliationController
{
    private \Closure $scanner;
    private \Closure $repairer;

    public function __construct(
        ?callable $scanner = null,
        ?callable $repairer = null,
        private ?IdempotentMutation $idempotency = null
    ) {
        if ($scanner === null || $repairer === null) {
            $service = new ProviderReconciliationService();
            $scanner ??= $service->scanPage(...);
            $repairer ??= $service->repair(...);
        }
        $this->scanner = \Closure::fromCallable($scanner);
        $this->repairer = \Closure::fromCallable($repairer);
        $this->idempotency ??= new IdempotentMutation();
    }

    public static function registerRoutes(): void
    {
        $namespace = 'fchub-memberships/v1';
        register_rest_route($namespace, '/admin/provider-reconciliation', [
            'methods' => 'GET',
            'callback' => [self::class, 'scanRoute'],
            'permission_callback' => [self::class, 'permission'],
            'args' => [
                'cursor' => ['type' => 'string', 'required' => false],
                'limit' => ['type' => 'integer', 'default' => 50, 'minimum' => 1, 'maximum' => 100],
            ],
        ]);
        register_rest_route($namespace, '/admin/provider-reconciliation/repair', [
            'methods' => 'POST',
            'callback' => [self::class, 'repairRoute'],
            'permission_callback' => [self::class, 'permission'],
            'args' => [
                'user_id' => ['type' => 'integer', 'required' => true, 'minimum' => 1],
                'provider' => ['type' => 'string', 'required' => true],
                'resource_type' => ['type' => 'string', 'required' => true],
                'resource_id' => ['type' => 'string', 'required' => true],
                'expected_classification' => ['type' => 'string', 'required' => true],
            ],
        ]);
    }

    public static function permission(\WP_REST_Request $request): bool
    {
        return MembershipMutationPermission::check($request);
    }

    public static function scanRoute(\WP_REST_Request $request): \WP_REST_Response
    {
        return (new self())->scan($request);
    }

    public static function repairRoute(\WP_REST_Request $request): \WP_REST_Response
    {
        return (new self())->repair($request);
    }

    public function scan(\WP_REST_Request $request): \WP_REST_Response
    {
        try {
            $cursor = trim((string) $request->get_param('cursor'));
            $page = ($this->scanner)($cursor === '' ? null : $cursor, (int) ($request->get_param('limit') ?: 50));

            return new \WP_REST_Response(['data' => $page]);
        } catch (\Throwable) {
            return $this->error('provider_reconciliation_failed', 500);
        }
    }

    public function repair(\WP_REST_Request $request): \WP_REST_Response
    {
        $requestId = trim((string) $request->get_header('Idempotency-Key'));
        $expectedClassification = trim((string) $request->get_param('expected_classification'));
        if ($requestId === '') {
            return $this->error(
                'fchub_idempotency_key_required',
                MembershipMutationPermission::requiresIdempotencyKey() ? 428 : 400
            );
        }
        if ($expectedClassification === '') {
            return $this->error('expected_classification_required', 400);
        }
        if (strlen($requestId) > 191) {
            return $this->error('fchub_idempotency_key_invalid', 400);
        }
        $request->set_header('Idempotency-Key', hash('sha256', $requestId));

        return $this->idempotency->execute(
            $request,
            'provider_reconciliation_repair',
            function () use ($request, $requestId, $expectedClassification): \WP_REST_Response {
                $result = ($this->repairer)([
                    'user_id' => (int) $request->get_param('user_id'),
                    'provider' => trim((string) $request->get_param('provider')),
                    'resource_type' => trim((string) $request->get_param('resource_type')),
                    'resource_id' => trim((string) $request->get_param('resource_id')),
                ], $requestId, $expectedClassification);
                $status = !empty($result['success'])
                    ? 202
                    : (($result['status'] ?? '') === 'refused' ? 409 : 500);

                return new \WP_REST_Response(['data' => $result], $status);
            }
        );
    }

    private function error(string $code, int $status): \WP_REST_Response
    {
        return new \WP_REST_Response(['code' => $code], $status);
    }
}

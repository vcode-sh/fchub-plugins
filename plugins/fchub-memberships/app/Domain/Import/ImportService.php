<?php

namespace FChubMemberships\Domain\Import;

defined('ABSPATH') || exit;

use FChubMemberships\Domain\AccessGrantService;
use FChubMemberships\Domain\Plan\PlanService;
use FChubMemberships\Storage\GrantRepository;

class ImportService
{
    private AccessGrantService $grantService;
    private PlanService $planService;
    private GrantRepository $grantRepo;

    public function __construct()
    {
        $this->grantService = new AccessGrantService();
        $this->planService = new PlanService();
        $this->grantRepo = new GrantRepository();
    }

    /**
     * Create plans for levels that have action='create_new' in their mapping.
     *
     * @param array $planMappings Each mapping: {level_name, action, plan_id?, title?, duration_type?, duration_days?}
     * @return array Updated mappings with plan_ids filled in for newly created plans.
     */
    public function createPlansForLevels(array $planMappings): array
    {
        foreach ($planMappings as &$mapping) {
            if (($mapping['action'] ?? '') !== 'create_new') {
                continue;
            }

            $title = $mapping['title'] ?? $mapping['level_name'] ?? '';
            if (empty(trim($title))) {
                $mapping['error'] = __('Plan title cannot be empty.', 'fchub-memberships');
                continue;
            }
            $durationType = $mapping['duration_type'] ?? 'lifetime';
            $durationDays = (int) ($mapping['duration_days'] ?? 0);

            $planData = [
                'title'          => $title,
                'status'         => 'active',
                'duration_type'  => $durationType,
                'duration_days'  => $durationDays,
            ];

            $result = $this->planService->create($planData);

            if (isset($result['error'])) {
                $mapping['error'] = $result['error'];
                continue;
            }

            $mapping['plan_id'] = $result['id'];
            $mapping['action'] = 'map_existing';
        }
        unset($mapping);

        return $planMappings;
    }

    /**
     * Process a batch of members for import.
     *
     * @param array  $members        Normalised member records from CsvParser.
     * @param array  $planMappings   Level-to-plan mappings (with plan_ids).
     * @param string $conflictMode   'skip', 'extend', or 'overwrite'.
     * @param bool   $createCustomers Whether to create FluentCart customers.
     * @return array Results per member and summary counts.
     */
    public function processBatch(array $members, array $planMappings, string $conflictMode = 'skip', bool $createCustomers = false): array
    {
        $results = [];
        $summary = ['imported' => 0, 'skipped' => 0, 'extended' => 0, 'failed' => 0];

        // Index mappings by level_name
        $mappingIndex = [];
        foreach ($planMappings as $m) {
            $mappingIndex[$m['level_name']] = $m;
        }

        foreach ($members as $member) {
            $email = $member['email'] ?? '';

            try {
                $result = $this->processMember($member, $mappingIndex, $conflictMode, $createCustomers);
                $results[] = $result;
                $status = $result['status'] ?? 'failed';
                if (!isset($summary[$status])) {
                    $summary[$status] = 0;
                }
                $summary[$status]++;
            } catch (\Throwable $e) {
                $results[] = [
                    'email'   => $email,
                    'status'  => 'failed',
                    'message' => $e->getMessage(),
                ];
                $summary['failed']++;
            }
        }

        return [
            'results' => $results,
            'summary' => $summary,
        ];
    }

    /**
     * Ensure a FluentCart customer record exists for the given member.
     *
     * @return int|null Customer ID or null on failure.
     */
    public function ensureFluentCartCustomer(array $member): ?int
    {
        global $wpdb;

        $email = $member['email'] ?? '';
        if (empty($email)) {
            return null;
        }

        $table = $wpdb->prefix . 'fct_customers';

        // Check if table exists (FluentCart may not be active)
        if (!$wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table))) {
            return null;
        }

        // Check existing customer by email
        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT id FROM {$table} WHERE email = %s LIMIT 1",
            $email
        ), ARRAY_A);

        if ($existing) {
            return (int) $existing['id'];
        }

        // Find WordPress user for user_id
        $wpUser = get_user_by('email', $email);
        $userId = $wpUser ? $wpUser->ID : null;

        $now = current_time('mysql');
        $wpdb->insert($table, [
            'user_id'    => $userId,
            'email'      => $email,
            'first_name' => $member['first_name'] ?? '',
            'last_name'  => $member['last_name'] ?? '',
            'status'     => 'active',
            'uuid'       => wp_generate_uuid4(),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $wpdb->insert_id ? (int) $wpdb->insert_id : null;
    }

    private function processMember(array $member, array $mappingIndex, string $conflictMode, bool $createCustomers): array
    {
        $email = $member['email'] ?? '';
        $username = $member['username'] ?? '';
        $levelName = $member['level_name'] ?? '';

        // Resolve plan mapping
        $mapping = $mappingIndex[$levelName] ?? null;
        if (!$mapping || ($mapping['action'] ?? '') === 'skip') {
            return [
                'email'   => $email,
                'status'  => 'skipped',
                'message' => $levelName ? "Level \"{$levelName}\" mapped to skip." : 'No level mapping found.',
            ];
        }

        $planId = (int) ($mapping['plan_id'] ?? 0);
        if (!$planId) {
            return [
                'email'   => $email,
                'status'  => 'skipped',
                'message' => "No plan ID for level \"{$levelName}\".",
            ];
        }

        // Find WordPress user
        $wpUser = get_user_by('email', $email);
        if (!$wpUser && $username) {
            $wpUser = get_user_by('login', $username);
        }

        if (!$wpUser) {
            return [
                'email'   => $email,
                'status'  => 'skipped',
                'message' => 'WordPress user not found.',
            ];
        }

        $userId = $wpUser->ID;

        // Optionally ensure FluentCart customer
        if ($createCustomers) {
            $this->ensureFluentCartCustomer($member);
        }

        // Check existing active or paused grants
        $existingGrants = array_merge(
            $this->grantRepo->getByUserId($userId, ['plan_id' => $planId, 'status' => 'active']),
            $this->grantRepo->getByUserId($userId, ['plan_id' => $planId, 'status' => 'paused'])
        );

        $expiresAt = $member['expires_at'] ?? null;
        if ($member['is_lifetime'] ?? false) {
            $expiresAt = null;
        }

        if (!empty($existingGrants)) {
            return $this->handleConflict($userId, $planId, $expiresAt, $conflictMode, $email);
        }

        // Grant the plan
        $this->grantService->grantPlan($userId, $planId, [
            'source_type' => 'import',
            'source_id'   => 0,
            'expires_at'  => $expiresAt,
        ]);

        return [
            'email'   => $email,
            'status'  => 'imported',
            'message' => "Granted plan #{$planId}.",
        ];
    }

    private function handleConflict(int $userId, int $planId, ?string $expiresAt, string $conflictMode, string $email): array
    {
        switch ($conflictMode) {
            case 'extend':
                if ($expiresAt) {
                    $this->grantService->extendExpiry($userId, $planId, $expiresAt);
                    return [
                        'email'   => $email,
                        'status'  => 'extended',
                        'message' => "Expiry extended to {$expiresAt}.",
                    ];
                }
                return [
                    'email'   => $email,
                    'status'  => 'skipped',
                    'message' => 'Active grant exists; no expiry to extend (lifetime).',
                ];

            case 'overwrite':
                $this->grantService->revokePlan($userId, $planId, [
                    'reason' => 'Overwritten by import',
                ]);
                $this->grantService->grantPlan($userId, $planId, [
                    'source_type' => 'import',
                    'source_id'   => 0,
                    'expires_at'  => $expiresAt,
                ]);
                return [
                    'email'   => $email,
                    'status'  => 'imported',
                    'message' => "Existing grant revoked and re-granted plan #{$planId}.",
                ];

            case 'skip':
            default:
                return [
                    'email'   => $email,
                    'status'  => 'skipped',
                    'message' => 'Active grant already exists.',
                ];
        }
    }
}

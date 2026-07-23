<?php

use FChubMemberships\Adapters\WordPressContentAdapter;
use FChubMemberships\Domain\AccessGrantService;
use FChubMemberships\Domain\Event\EventProcessingOutcome;
use FChubMemberships\Domain\GrantAdapterRegistry;
use FChubMemberships\Integration\MembershipAccessIntegration;
use FChubMemberships\Storage\GrantRepository;
use FChubMemberships\Support\Migrations;

if (!defined('WP_CLI') || !WP_CLI) {
    throw new RuntimeException('Run this smoke through WP-CLI.');
}

final class FChubMembershipsPlan004RuntimeAdapter extends WordPressContentAdapter
{
    public static int $checks = 0;
    public static int $grants = 0;
    public static int $revokes = 0;

    public function check(int $userId, string $resourceType, string $resourceId): bool
    {
        self::$checks++;

        return false;
    }

    public function grant(int $userId, string $resourceType, string $resourceId, array $context = []): array
    {
        self::$grants++;

        return parent::grant($userId, $resourceType, $resourceId, $context);
    }

    public function revoke(int $userId, string $resourceType, string $resourceId, array $context = []): array
    {
        self::$revokes++;

        return parent::revoke($userId, $resourceType, $resourceId, $context);
    }
}

final class FChubMembershipsPlan004RuntimeOrder
{
    /** @var list<array{string, string, string, string}> */
    public array $logs = [];

    public function __construct(
        public int $id,
        public int $user_id,
        public string $customer_email
    ) {
    }

    public function addLog(string $title, string $description, string $type, string $module): void
    {
        $this->logs[] = [$title, $description, $type, $module];
    }
}

function fchubPlan004Assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/** @return array<string, int> */
function fchubPlan004Counts(array $tables): array
{
    global $wpdb;

    $counts = [];
    foreach ($tables as $table) {
        $counts[$table] = (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$table}`");
    }

    return $counts;
}

function fchubPlan004CallbackName(mixed $callback): string
{
    if (is_string($callback)) {
        return $callback;
    }
    if (is_array($callback) && count($callback) === 2) {
        $owner = is_object($callback[0]) ? $callback[0]::class : (string) $callback[0];

        return $owner . '::' . (string) $callback[1];
    }

    return get_debug_type($callback);
}

/** @return list<string> */
function fchubPlan004HookCallbacks(string $hook): array
{
    $callbacks = [];
    foreach (($GLOBALS['wp_filter'][$hook]->callbacks ?? []) as $priorityCallbacks) {
        foreach ($priorityCallbacks as $entry) {
            $callbacks[] = fchubPlan004CallbackName($entry['function']);
        }
    }

    sort($callbacks);

    return $callbacks;
}

/** @return list<int> */
function fchubPlan004MergeIds(array ...$idSets): array
{
    $ids = [];
    foreach ($idSets as $idSet) {
        foreach ($idSet as $id) {
            $id = (int) $id;
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }
    }
    ksort($ids, SORT_NUMERIC);

    return array_values($ids);
}

function fchubPlan004ArgsContain(mixed $value, array $needles): bool
{
    if (is_array($value)) {
        foreach ($value as $item) {
            if (fchubPlan004ArgsContain($item, $needles)) {
                return true;
            }
        }
        return false;
    }

    foreach ($needles as $needle) {
        if ((string) $value === (string) $needle) {
            return true;
        }
    }

    return false;
}

function fchubPlan004Audit(array $proof, int $entityId): int
{
    global $wpdb;

    $inserted = $wpdb->insert(
        $wpdb->prefix . 'fchub_membership_audit_log',
        [
            'entity_type' => 'runtime_smoke',
            'entity_id' => $entityId,
            'action' => 'order_feed_idempotency',
            'actor_id' => 0,
            'actor_type' => 'system',
            'old_value' => wp_json_encode([]),
            'new_value' => wp_json_encode($proof),
            'context' => 'Plan 004 Task 6; disposable state verified removed',
            'created_at' => current_time('mysql'),
        ]
    );
    fchubPlan004Assert($inserted === 1, 'The permanent runtime audit could not be written.');

    return (int) $wpdb->insert_id;
}

function fchubPlan004DeletePassedAudit(int $auditId, int $entityId, string $setupId): void
{
    global $wpdb;

    fchubPlan004Assert(
        $auditId > 0 && $entityId > 0 && $setupId !== '',
        'The passed runtime audit was not removed before failure evidence was written.'
    );

    $table = $wpdb->prefix . 'fchub_membership_audit_log';
    $row = $wpdb->get_row($wpdb->prepare(
        "SELECT id, entity_type, entity_id, action, actor_id, actor_type, new_value, context
         FROM {$table}
         WHERE id = %d",
        $auditId
    ), ARRAY_A);
    $proof = is_array($row)
        ? json_decode((string) ($row['new_value'] ?? ''), true)
        : null;
    fchubPlan004Assert(
        is_array($row)
            && (int) $row['id'] === $auditId
            && $row['entity_type'] === 'runtime_smoke'
            && (int) $row['entity_id'] === $entityId
            && $row['action'] === 'order_feed_idempotency'
            && (int) $row['actor_id'] === 0
            && $row['actor_type'] === 'system'
            && $row['context'] === 'Plan 004 Task 6; disposable state verified removed'
            && is_array($proof)
            && ($proof['status'] ?? null) === 'passed'
            && ($proof['setup_id'] ?? null) === $setupId,
        'The passed runtime audit was not removed before failure evidence was written.'
    );

    $deleted = $wpdb->delete(
        $table,
        [
            'id' => $auditId,
            'entity_type' => 'runtime_smoke',
            'entity_id' => $entityId,
            'action' => 'order_feed_idempotency',
            'actor_id' => 0,
            'actor_type' => 'system',
            'new_value' => (string) $row['new_value'],
            'context' => 'Plan 004 Task 6; disposable state verified removed',
        ],
        ['%d', '%s', '%d', '%s', '%d', '%s', '%s', '%s']
    );
    fchubPlan004Assert(
        $deleted === 1,
        'The passed runtime audit was not removed before failure evidence was written.'
    );
}

global $wpdb;

$activePlugins = (array) get_option('active_plugins', []);
fchubPlan004Assert(in_array('fchub-memberships/fchub-memberships.php', $activePlugins, true), 'Memberships is not active.');
fchubPlan004Assert(in_array('fluent-cart/fluent-cart.php', $activePlugins, true), 'FluentCart is not active.');
fchubPlan004Assert(Migrations::verifySchema() === [], 'Memberships schema verification failed.');

$constructor = new ReflectionMethod(MembershipAccessIntegration::class, '__construct');
$processor = new ReflectionMethod(MembershipAccessIntegration::class, 'processAction');
fchubPlan004Assert($constructor->getNumberOfParameters() >= 3, 'Task 5 constructor injection is unavailable.');
fchubPlan004Assert(
    (string) $processor->getReturnType() === EventProcessingOutcome::class,
    'Task 5 typed process outcome is unavailable.'
);
fchubPlan004Assert(
    fchubPlan004HookCallbacks('fluent_cart/integration/run/memberships') !== [],
    'The Memberships FluentCart runtime handler is not registered.'
);
fchubPlan004Assert(
    fchubPlan004HookCallbacks('fchub_memberships/grant_created')
        === [
            'FChubMemberships\\Domain\\ContentProtection::invalidateUserCache',
            'FChubMemberships\\Integration\\WebhookDispatcher::onGrantCreated',
        ],
    'An unknown grant-created callback prevents an isolated smoke.'
);
fchubPlan004Assert(
    function_exists('as_enqueue_async_action'),
    'Action Scheduler is required to intercept grant notification delivery.'
);

$settings = (array) get_option('fchub_memberships_settings', []);
foreach (['fc_enabled', 'fluentcrm_enabled', 'webhook_enabled'] as $providerFlag) {
    fchubPlan004Assert(($settings[$providerFlag] ?? 'no') !== 'yes', "{$providerFlag} must be disabled for this smoke.");
}
fchubPlan004Assert(($settings['email_access_granted'] ?? 'yes') === 'yes', 'The grant notification path must be enabled.');
$settingsHash = hash('sha256', serialize($settings));
$settingsRow = $wpdb->get_row($wpdb->prepare(
    "SELECT option_value, autoload FROM {$wpdb->options} WHERE option_name = %s",
    'fchub_memberships_settings'
), ARRAY_A);
fchubPlan004Assert(is_array($settingsRow), 'The Memberships settings row is unavailable.');

$plansTable = $wpdb->prefix . 'fchub_membership_plans';
$rulesTable = $wpdb->prefix . 'fchub_membership_plan_rules';
$grantsTable = $wpdb->prefix . 'fchub_membership_grants';
$sourcesTable = $wpdb->prefix . 'fchub_membership_grant_sources';
$auditTable = $wpdb->prefix . 'fchub_membership_audit_log';
$edgesTable = $wpdb->prefix . 'fchub_membership_entitlement_edges';
$operationsTable = $wpdb->prefix . 'fchub_membership_provider_operations';
$locksTable = $wpdb->prefix . 'fchub_membership_event_locks';
$dripsTable = $wpdb->prefix . 'fchub_membership_drip_notifications';
$webhookEventsTable = $wpdb->prefix . 'fchub_membership_webhook_events';
$webhookDeliveriesTable = $wpdb->prefix . 'fchub_membership_webhook_deliveries';
$activityTable = $wpdb->prefix . 'fct_activity';
$actionTable = $wpdb->prefix . 'actionscheduler_actions';
$actionLogTable = $wpdb->prefix . 'actionscheduler_logs';
$actionGroupTable = $wpdb->prefix . 'actionscheduler_groups';
$membershipTables = $wpdb->get_col($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($wpdb->prefix . 'fchub_membership_') . '%'));
$observedTables = array_values(array_unique(array_merge(
    $membershipTables,
    [$wpdb->users, $wpdb->usermeta, $activityTable, $actionTable, $actionLogTable, $actionGroupTable]
)));
foreach ($observedTables as $table) {
    $engine = $wpdb->get_var($wpdb->prepare(
        'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
        $table
    ));
    fchubPlan004Assert($engine === 'InnoDB', "{$table} must use InnoDB for deterministic smoke cleanup.");
}

$baseline = fchubPlan004Counts($observedTables);
$setupId = bin2hex(random_bytes(6));
$orderId = random_int(2_000_000_000, 2_100_000_000);
$integrationId = random_int(1_000_000_000, 1_100_000_000);
$eventHash = hash('sha256', "order:{$orderId}|scope:product|feed:{$integrationId}|trigger:order_paid_done|mode:grant");
$login = 'fchub_p004_' . $setupId;
$email = $login . '@example.invalid';
$planSlug = 'plan-004-' . $setupId;
$resourceId = 'plan-004-' . $setupId;
$ownerToken = hash('sha256', 'plan-004-owner-' . $setupId);
$grantHookCount = 0;
$renewedHookCount = 0;
$trialHookCount = 0;
$notificationCount = 0;
$mutated = false;
$userId = 0;
$planId = 0;
$ruleId = 0;
$grantId = 0;
$sourceId = 0;
$edgeIds = [];
$operationIds = [];
$eventLockIds = [];
$dripIds = [];
$grantAuditIds = [];
$activityIds = [];
$webhookEventIds = [];
$webhookDeliveryIds = [];
$actionIds = [];
$actionLogIds = [];
$actionGroupIds = [];
$runtimeAuditId = 0;
$passedAuditId = 0;
$failureAuditId = 0;

$grantHook = static function () use (&$grantHookCount): void {
    $grantHookCount++;
};
$renewedHook = static function () use (&$renewedHookCount): void {
    $renewedHookCount++;
};
$trialHook = static function () use (&$trialHookCount): void {
    $trialHookCount++;
};
$emailFilter = static function ($pre, string $hook, array $args) use (&$notificationCount, $email) {
    if ($hook !== 'fchub_memberships_send_email') {
        return $pre;
    }

    fchubPlan004Assert(($args['to'] ?? null) === $email, 'Notification recipient does not belong to this smoke.');
    fchubPlan004Assert(trim((string) ($args['subject'] ?? '')) !== '', 'Notification subject is empty.');
    fchubPlan004Assert(trim((string) ($args['body'] ?? '')) !== '', 'Notification body is empty.');
    $notificationCount++;

    return 2_004_006;
};

$collectOwnedRows = static function () use (
    $wpdb,
    $login,
    $email,
    $planSlug,
    $resourceId,
    $orderId,
    $integrationId,
    $eventHash,
    $plansTable,
    $rulesTable,
    $grantsTable,
    $sourcesTable,
    $auditTable,
    $edgesTable,
    $operationsTable,
    $locksTable,
    $dripsTable,
    $webhookEventsTable,
    $webhookDeliveriesTable,
    $activityTable,
    $actionTable,
    $actionLogTable,
    &$userId,
    &$planId,
    &$ruleId,
    &$grantId,
    &$sourceId,
    &$edgeIds,
    &$operationIds,
    &$eventLockIds,
    &$dripIds,
    &$grantAuditIds,
    &$activityIds,
    &$webhookEventIds,
    &$webhookDeliveryIds,
    &$actionIds,
    &$actionLogIds,
    &$actionGroupIds
): void {
    $ownedUserId = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT ID FROM {$wpdb->users} WHERE user_login = %s AND user_email = %s LIMIT 1",
        $login,
        $email
    ));
    if ($ownedUserId > 0) {
        $userId = $ownedUserId;
    }

    $planIds = fchubPlan004MergeIds(
        [$planId],
        $wpdb->get_col($wpdb->prepare("SELECT id FROM {$plansTable} WHERE slug = %s", $planSlug))
    );
    if ($planIds !== []) {
        $planId = $planIds[0];
    }
    $ruleIds = fchubPlan004MergeIds(
        [$ruleId],
        $wpdb->get_col($wpdb->prepare(
            "SELECT id FROM {$rulesTable} WHERE resource_id = %s",
            $resourceId
        ))
    );
    if ($ruleIds !== []) {
        $ruleId = $ruleIds[0];
    }

    $grantIds = fchubPlan004MergeIds(
        [$grantId],
        $wpdb->get_col($wpdb->prepare(
            "SELECT id FROM {$grantsTable}
             WHERE resource_id = %s
               AND source_type = 'order'
               AND source_id = %d
               AND feed_id = %d",
            $resourceId,
            $orderId,
            $integrationId
        ))
    );
    if ($grantIds !== []) {
        $grantId = $grantIds[0];
        $grantList = implode(',', $grantIds);
        $sourceIds = fchubPlan004MergeIds(
            [$sourceId],
            $wpdb->get_col("SELECT id FROM {$sourcesTable} WHERE grant_id IN ({$grantList})")
        );
        if ($sourceIds !== []) {
            $sourceId = $sourceIds[0];
        }
        $dripIds = fchubPlan004MergeIds(
            $dripIds,
            $wpdb->get_col("SELECT id FROM {$dripsTable} WHERE grant_id IN ({$grantList})")
        );
    }

    $edgeIds = fchubPlan004MergeIds(
        $edgeIds,
        $wpdb->get_col($wpdb->prepare(
            "SELECT id FROM {$edgesTable}
             WHERE resource_id = %s
               AND source_type = 'order'
               AND source_id = %d
               AND feed_id = %d",
            $resourceId,
            $orderId,
            $integrationId
        ))
    );
    if ($edgeIds !== []) {
        $edgeList = implode(',', $edgeIds);
        $operationIds = fchubPlan004MergeIds(
            $operationIds,
            $wpdb->get_col("SELECT id FROM {$operationsTable} WHERE edge_id IN ({$edgeList})")
        );
    }

    $eventLockIds = fchubPlan004MergeIds(
        $eventLockIds,
        $wpdb->get_col($wpdb->prepare(
            "SELECT id FROM {$locksTable}
             WHERE event_hash = %s AND order_id = %d AND feed_id = %d",
            $eventHash,
            $orderId,
            $integrationId
        ))
    );
    $activityIds = fchubPlan004MergeIds(
        $activityIds,
        $wpdb->get_col($wpdb->prepare(
            "SELECT id FROM {$activityTable}
             WHERE module_id = %d
                OR (
                    title = 'Plan granted'
                    AND content LIKE %s
                )
                OR (
                    title = 'Access granted email sent'
                    AND content = %s
                )",
            $orderId,
            "User #{$userId} processed plan #{$planId}:%",
            "User {$userId}, Plan: Plan 004 Runtime"
        ))
    );

    $webhookEventIds = array_values(array_unique(array_merge(
        $webhookEventIds,
        array_map('strval', $wpdb->get_col($wpdb->prepare(
            "SELECT event_id FROM {$webhookEventsTable} WHERE body LIKE %s",
            '%' . $wpdb->esc_like($email) . '%'
        )))
    )));
    if ($webhookEventIds !== []) {
        $placeholders = implode(',', array_fill(0, count($webhookEventIds), '%s'));
        $webhookDeliveryIds = fchubPlan004MergeIds(
            $webhookDeliveryIds,
            $wpdb->get_col($wpdb->prepare(
                "SELECT id FROM {$webhookDeliveriesTable} WHERE event_id IN ({$placeholders})",
                ...$webhookEventIds
            ))
        );
    }

    $auditClauses = [];
    if ($grantIds !== []) {
        $auditClauses[] = "(entity_type = 'grant' AND entity_id IN (" . implode(',', $grantIds) . '))';
    }
    if ($edgeIds !== []) {
        $auditClauses[] = "(entity_type = 'entitlement_edge' AND entity_id IN (" . implode(',', $edgeIds) . '))';
    }
    if ($auditClauses !== []) {
        $grantAuditIds = fchubPlan004MergeIds(
            $grantAuditIds,
            $wpdb->get_col("SELECT id FROM {$auditTable} WHERE " . implode(' OR ', $auditClauses))
        );
    }

    $needles = array_merge([$email], $operationIds, $webhookDeliveryIds);
    $schedulerRows = $wpdb->get_results(
        "SELECT action_id, group_id, hook, args FROM {$actionTable}
         WHERE hook IN (
            'fchub_memberships_send_email',
            'fchub_memberships_process_provider_operation',
            'fchub_memberships_deliver_webhook'
         )",
        ARRAY_A
    );
    foreach ($schedulerRows as $schedulerRow) {
        $args = json_decode((string) ($schedulerRow['args'] ?? ''), true);
        if (!fchubPlan004ArgsContain($args, $needles)) {
            continue;
        }
        $actionIds[] = (int) $schedulerRow['action_id'];
        $actionGroupIds[] = (int) $schedulerRow['group_id'];
    }
    $actionIds = fchubPlan004MergeIds($actionIds);
    $actionGroupIds = fchubPlan004MergeIds($actionGroupIds);
    if ($actionIds !== []) {
        $actionLogIds = fchubPlan004MergeIds(
            $actionLogIds,
            $wpdb->get_col(
                "SELECT log_id FROM {$actionLogTable} WHERE action_id IN (" . implode(',', $actionIds) . ')'
            )
        );
    }
};

$cleanupOwnedRows = static function () use (
    $wpdb,
    $collectOwnedRows,
    $plansTable,
    $rulesTable,
    $grantsTable,
    $sourcesTable,
    $auditTable,
    $edgesTable,
    $operationsTable,
    $locksTable,
    $dripsTable,
    $webhookEventsTable,
    $webhookDeliveriesTable,
    $activityTable,
    $actionTable,
    $actionLogTable,
    $actionGroupTable,
    &$userId,
    &$planId,
    &$ruleId,
    &$grantId,
    &$sourceId,
    &$edgeIds,
    &$operationIds,
    &$eventLockIds,
    &$dripIds,
    &$grantAuditIds,
    &$activityIds,
    &$webhookEventIds,
    &$webhookDeliveryIds,
    &$actionIds,
    &$actionLogIds,
    &$actionGroupIds
): void {
    $collectOwnedRows();
    $errors = [];
    $deleteIds = static function (string $table, string $column, array $ids) use ($wpdb, &$errors): void {
        $ids = fchubPlan004MergeIds($ids);
        if ($ids === []) {
            return;
        }
        if ($wpdb->query(
            "DELETE FROM {$table} WHERE {$column} IN (" . implode(',', $ids) . ')'
        ) === false) {
            $errors[] = "{$table}.{$column}";
        }
    };

    $deleteIds($actionLogTable, 'log_id', $actionLogIds);
    $deleteIds($actionTable, 'action_id', $actionIds);
    foreach ($actionGroupIds as $groupId) {
        $deleted = $wpdb->query($wpdb->prepare(
            "DELETE FROM {$actionGroupTable}
             WHERE group_id = %d
               AND slug LIKE 'fchub-memberships-webhooks-%%'
               AND NOT EXISTS (
                    SELECT 1 FROM {$actionTable} WHERE group_id = %d
               )",
            $groupId,
            $groupId
        ));
        if ($deleted === false) {
            $errors[] = "{$actionGroupTable}.group_id";
        }
    }
    $deleteIds($webhookDeliveriesTable, 'id', $webhookDeliveryIds);
    if ($webhookEventIds !== []) {
        $placeholders = implode(',', array_fill(0, count($webhookEventIds), '%s'));
        if ($wpdb->query($wpdb->prepare(
            "DELETE FROM {$webhookEventsTable} WHERE event_id IN ({$placeholders})",
            ...$webhookEventIds
        )) === false) {
            $errors[] = "{$webhookEventsTable}.event_id";
        }
    }
    $deleteIds($operationsTable, 'id', $operationIds);
    $deleteIds($edgesTable, 'id', $edgeIds);
    $deleteIds($dripsTable, 'id', $dripIds);
    $deleteIds($sourcesTable, 'id', [$sourceId]);
    $deleteIds($auditTable, 'id', $grantAuditIds);
    $deleteIds($grantsTable, 'id', [$grantId]);
    $deleteIds($locksTable, 'id', $eventLockIds);
    $deleteIds($rulesTable, 'id', [$ruleId]);
    $deleteIds($plansTable, 'id', [$planId]);
    $deleteIds($activityTable, 'id', $activityIds);
    if ($userId > 0) {
        $deleteIds($wpdb->usermeta, 'umeta_id', $wpdb->get_col($wpdb->prepare(
            "SELECT umeta_id FROM {$wpdb->usermeta} WHERE user_id = %d",
            $userId
        )));
        $deleteIds($wpdb->users, 'ID', [$userId]);
        clean_user_cache($userId);
        delete_transient('fchub_user_' . $userId . '_accessible_posts_active');
    }

    if ($errors !== []) {
        throw new RuntimeException('Disposable cleanup queries failed: ' . implode(', ', array_unique($errors)));
    }
};

$verifyCleanup = static function () use (
    $wpdb,
    $observedTables,
    $baseline,
    $settingsHash,
    $settingsRow,
    $login,
    $email,
    $planSlug,
    $resourceId,
    $orderId,
    $integrationId,
    $eventHash,
    $plansTable,
    $rulesTable,
    $grantsTable,
    $sourcesTable,
    $auditTable,
    $edgesTable,
    $operationsTable,
    $locksTable,
    $dripsTable,
    $webhookEventsTable,
    $webhookDeliveriesTable,
    $activityTable,
    $actionTable,
    $actionLogTable,
    &$userId,
    &$planId,
    &$ruleId,
    &$grantId,
    &$sourceId,
    &$edgeIds,
    &$operationIds,
    &$eventLockIds,
    &$dripIds,
    &$grantAuditIds,
    &$activityIds,
    &$webhookEventIds,
    &$webhookDeliveryIds,
    &$actionIds,
    &$actionLogIds
): void {
    $residue = [
        'user' => (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->users}
             WHERE ID = %d OR user_login = %s OR user_email = %s",
            $userId,
            $login,
            $email
        )),
        'usermeta' => (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE user_id = %d",
            $userId
        )),
        'plan' => (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$plansTable} WHERE id = %d OR slug = %s",
            $planId,
            $planSlug
        )),
        'rule' => (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$rulesTable} WHERE id = %d OR resource_id = %s",
            $ruleId,
            $resourceId
        )),
        'grant' => (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$grantsTable}
             WHERE id = %d OR (
                resource_id = %s AND source_id = %d AND feed_id = %d
             )",
            $grantId,
            $resourceId,
            $orderId,
            $integrationId
        )),
        'source' => (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$sourcesTable}
             WHERE id = %d OR (source_type = 'order' AND source_id = %d)",
            $sourceId,
            $orderId
        )),
        'edge' => (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$edgesTable}
             WHERE resource_id = %s AND source_id = %d AND feed_id = %d",
            $resourceId,
            $orderId,
            $integrationId
        )),
        'event_lock' => (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$locksTable}
             WHERE event_hash = %s OR (order_id = %d AND feed_id = %d)",
            $eventHash,
            $orderId,
            $integrationId
        )),
        'activity' => (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$activityTable}
             WHERE module_id = %d
                OR (
                    title = 'Plan granted'
                    AND content LIKE %s
                )
                OR (
                    title = 'Access granted email sent'
                    AND content = %s
                )",
            $orderId,
            "User #{$userId} processed plan #{$planId}:%",
            "User {$userId}, Plan: Plan 004 Runtime"
        )),
        'webhook_event' => (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$webhookEventsTable} WHERE body LIKE %s",
            '%' . $wpdb->esc_like($email) . '%'
        )),
    ];
    foreach ([
        [$operationsTable, 'id', $operationIds, 'operation'],
        [$dripsTable, 'id', $dripIds, 'drip'],
        [$auditTable, 'id', $grantAuditIds, 'lifecycle_audit'],
        [$webhookDeliveriesTable, 'id', $webhookDeliveryIds, 'webhook_delivery'],
        [$actionTable, 'action_id', $actionIds, 'action'],
        [$actionLogTable, 'log_id', $actionLogIds, 'action_log'],
    ] as [$table, $column, $ids, $label]) {
        $ids = fchubPlan004MergeIds($ids);
        $residue[$label] = $ids === []
            ? 0
            : (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM {$table} WHERE {$column} IN (" . implode(',', $ids) . ')'
            );
    }
    foreach ($residue as $label => $count) {
        fchubPlan004Assert($count === 0, "Disposable {$label} residue remains after cleanup.");
    }

    $countsAfterCleanup = fchubPlan004Counts($observedTables);
    if ($countsAfterCleanup !== $baseline) {
        $differences = [];
        foreach ($baseline as $table => $before) {
            $after = $countsAfterCleanup[$table] ?? null;
            if ($after !== $before) {
                $differences[] = basename($table) . ":{$before}->" . ($after ?? 'missing');
            }
        }
        throw new RuntimeException(
            'Disposable table counts did not return to baseline: ' . implode(', ', $differences)
        );
    }
    $settingsAfter = (array) get_option('fchub_memberships_settings', []);
    fchubPlan004Assert(
        hash('sha256', serialize($settingsAfter)) === $settingsHash,
        'Membership settings changed during smoke.'
    );
    $settingsRowAfter = $wpdb->get_row($wpdb->prepare(
        "SELECT option_value, autoload FROM {$wpdb->options} WHERE option_name = %s",
        'fchub_memberships_settings'
    ), ARRAY_A);
    fchubPlan004Assert($settingsRowAfter === $settingsRow, 'The exact Memberships settings row changed during smoke.');
};

try {
    $mutated = true;
    fchubPlan004Assert($wpdb->insert($wpdb->users, [
        'user_login' => $login,
        'user_pass' => wp_hash_password($setupId),
        'user_nicename' => $login,
        'user_email' => $email,
        'user_registered' => current_time('mysql'),
        'display_name' => 'Plan 004 Runtime',
    ]) === 1, 'Could not create the disposable user.');
    $userId = (int) $wpdb->insert_id;

    fchubPlan004Assert($wpdb->insert($plansTable, [
        'title' => 'Plan 004 Runtime',
        'slug' => $planSlug,
        'status' => 'active',
        'level' => 0,
        'duration_type' => 'lifetime',
        'trial_days' => 0,
        'grace_period_days' => 0,
        'includes_plan_ids' => '[]',
        'settings' => '{}',
        'meta' => '{}',
        'created_at' => current_time('mysql'),
        'updated_at' => current_time('mysql'),
    ]) === 1, 'Could not create the disposable plan.');
    $planId = (int) $wpdb->insert_id;

    fchubPlan004Assert($wpdb->insert($rulesTable, [
        'plan_id' => $planId,
        'provider' => 'wordpress_core',
        'resource_type' => 'post',
        'resource_id' => $resourceId,
        'drip_delay_days' => 0,
        'drip_type' => 'immediate',
        'sort_order' => 0,
        'meta' => '{}',
        'created_at' => current_time('mysql'),
        'updated_at' => current_time('mysql'),
    ]) === 1, 'Could not create the disposable plan rule.');
    $ruleId = (int) $wpdb->insert_id;

    add_action('fchub_memberships/grant_created', $grantHook, PHP_INT_MAX, 3);
    add_action('fchub_memberships/grant_renewed', $renewedHook, PHP_INT_MAX, 2);
    add_action('fchub_memberships/trial_started', $trialHook, PHP_INT_MAX, 3);
    add_filter('pre_as_enqueue_async_action', $emailFilter, PHP_INT_MAX, 6);

    $service = new AccessGrantService(
        adapters: new GrantAdapterRegistry([
            'wordpress_core' => FChubMembershipsPlan004RuntimeAdapter::class,
        ])
    );
    $integration = new MembershipAccessIntegration(
        null,
        $service,
        static fn(): string => $ownerToken
    );
    $order = new FChubMembershipsPlan004RuntimeOrder($orderId, $userId, $email);
    $event = [
        'order' => $order,
        'integration_id' => $integrationId,
        'scope' => 'product',
        'trigger' => 'order_paid_done',
        'is_revoke_hook' => 'no',
        'feed' => [
            'plan_id' => $planId,
            'validity_mode' => 'lifetime',
            'membership_term_mode' => 'none',
            'grace_period_days' => 0,
            'auto_create_user' => 'no',
        ],
        'event_data' => [],
    ];

    $first = $integration->processAction($order, $event);
    $second = $integration->processAction($order, $event);
    fchubPlan004Assert($first->success && !$first->retryable && !$first->skipped, 'First delivery did not complete successfully.');
    fchubPlan004Assert($second->success && !$second->retryable && $second->skipped, 'Duplicate delivery was not skipped successfully.');

    $lock = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}fchub_membership_event_locks WHERE event_hash = %s",
        $eventHash
    ), ARRAY_A);
    fchubPlan004Assert(is_array($lock), 'Exactly one event lock was not persisted.');
    $eventLockIds = [(int) $lock['id']];
    fchubPlan004Assert(
        $lock['state'] === 'succeeded'
            && $lock['result'] === 'success'
            && (int) $lock['attempt_count'] === 1
            && (int) $lock['retryable'] === 0
            && $lock['owner_token'] === null
            && $lock['lease_expires_at'] === null
            && !empty($lock['completed_at'])
            && (int) $lock['order_id'] === $orderId
            && (int) $lock['feed_id'] === $integrationId
            && $lock['trigger_name'] === 'order_paid_done',
        'The terminal event-lock state is incorrect.'
    );

    $edges = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM {$edgesTable}
         WHERE user_id = %d
           AND provider = 'wordpress_core'
           AND resource_type = 'post'
           AND resource_id = %s
           AND plan_id = %d
           AND feed_id = %d
           AND feed_scope = 'product'
           AND source_type = 'order'
           AND source_id = %d",
        $userId,
        $resourceId,
        $planId,
        $integrationId,
        $orderId
    ), ARRAY_A);
    fchubPlan004Assert(count($edges) === 1, 'The canonical local entitlement edge was not created exactly once.');
    $edge = $edges[0];
    $edgeIds = [(int) $edge['id']];
    fchubPlan004Assert(
        $edge['owner'] === 'fchub'
            && $edge['assignment_provenance'] === 'fchub_created'
            && $edge['lifecycle'] === 'active'
            && $edge['access_status'] === 'active'
            && $edge['ended_at'] === null,
        'The canonical local entitlement edge state is incorrect.'
    );
    $operationIds = array_map('intval', $wpdb->get_col(
        "SELECT id FROM {$operationsTable} WHERE edge_id = " . (int) $edge['id']
    ));
    fchubPlan004Assert(
        $operationIds === [],
        'WordPress local access created an unexpected provider operation.'
    );

    $grantKey = GrantRepository::makeGrantKey($userId, 'wordpress_core', 'post', $resourceId);
    $grant = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}fchub_membership_grants WHERE grant_key = %s",
        $grantKey
    ), ARRAY_A);
    fchubPlan004Assert(is_array($grant), 'Exactly one disposable grant was not persisted.');
    $grantId = (int) $grant['id'];
    fchubPlan004Assert(
        (int) $grant['user_id'] === $userId
            && (int) $grant['plan_id'] === $planId
            && $grant['source_type'] === 'order'
            && (int) $grant['source_id'] === $orderId
            && (int) $grant['feed_id'] === $integrationId
            && $grant['status'] === 'active'
            && (int) $grant['renewal_count'] === 0,
        'The disposable grant contract is incorrect.'
    );

    $sources = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}fchub_membership_grant_sources WHERE grant_id = %d",
        $grantId
    ), ARRAY_A);
    fchubPlan004Assert(count($sources) === 1, 'The grant source was not created exactly once.');
    $sourceId = (int) $sources[0]['id'];
    fchubPlan004Assert($sources[0]['source_type'] === 'order' && (int) $sources[0]['source_id'] === $orderId, 'The grant source is incorrect.');

    $grantAudits = $wpdb->get_results($wpdb->prepare(
        "SELECT id, action FROM {$auditTable} WHERE entity_type = 'grant' AND entity_id = %d",
        $grantId
    ), ARRAY_A);
    fchubPlan004Assert($grantAudits === [], 'The canonical local cutover created a legacy grant audit.');
    fchubPlan004Assert($grantHookCount === 1 && $renewedHookCount === 0 && $trialHookCount === 0, 'Grant lifecycle hooks were not exactly once.');
    fchubPlan004Assert($notificationCount === 1, 'Grant notification was not intercepted exactly once.');
    fchubPlan004Assert(
        FChubMembershipsPlan004RuntimeAdapter::$checks === 0
            && FChubMembershipsPlan004RuntimeAdapter::$grants === 0
            && FChubMembershipsPlan004RuntimeAdapter::$revokes === 0,
        'WordPress local access unexpectedly called a provider adapter.'
    );
    fchubPlan004Assert(
        (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}fchub_membership_drip_notifications WHERE grant_id = %d",
            $grantId
        )) === 0,
        'An immediate grant created an unexpected drip row.'
    );
    fchubPlan004Assert(
        (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$webhookEventsTable} WHERE body LIKE %s",
            '%' . $wpdb->esc_like($email) . '%'
        )) === 0,
        'The disabled webhook callback persisted a smoke event.'
    );
    $collectOwnedRows();
    fchubPlan004Assert(
        $webhookDeliveryIds === [] && $actionIds === [] && $actionLogIds === [],
        'Disabled webhook or intercepted notification delivery created queue residue.'
    );

    $proof = [
        'status' => 'passed',
        'setup_id' => $setupId,
        'disposable_ids' => [
            'order' => $orderId,
            'integration' => $integrationId,
            'user' => $userId,
            'plan' => $planId,
            'rule' => $ruleId,
            'grant' => $grantId,
            'source' => $sourceId,
            'lock' => (int) $lock['id'],
            'edge' => (int) $edge['id'],
        ],
        'outcomes' => [
            ['success' => $first->success, 'retryable' => $first->retryable, 'skipped' => $first->skipped],
            ['success' => $second->success, 'retryable' => $second->retryable, 'skipped' => $second->skipped],
        ],
        'assertions' => [
            'lock_succeeded' => true,
            'edge_count' => 1,
            'grant_count' => 1,
            'source_count' => 1,
            'legacy_grant_audit_count' => 0,
            'grant_hook_count' => $grantHookCount,
            'notification_count' => $notificationCount,
            'provider_check_count' => FChubMembershipsPlan004RuntimeAdapter::$checks,
            'provider_grant_count' => FChubMembershipsPlan004RuntimeAdapter::$grants,
            'provider_revoke_count' => FChubMembershipsPlan004RuntimeAdapter::$revokes,
            'provider_operation_count' => 0,
            'action_scheduler_count' => 0,
            'webhook_event_count' => 0,
            'drip_count' => 0,
        ],
        'settings_hash_before' => $settingsHash,
        'cleanup' => ['explicit' => true, 'residual_rows' => 0],
    ];

    remove_action('fchub_memberships/grant_created', $grantHook, PHP_INT_MAX);
    remove_action('fchub_memberships/grant_renewed', $renewedHook, PHP_INT_MAX);
    remove_action('fchub_memberships/trial_started', $trialHook, PHP_INT_MAX);
    remove_filter('pre_as_enqueue_async_action', $emailFilter, PHP_INT_MAX);
    $cleanupOwnedRows();
    $verifyCleanup();

    $runtimeAuditId = fchubPlan004Audit($proof, $orderId);
    $passedAuditId = $runtimeAuditId;
    if (getenv('FCHUB_PLAN004_FAIL_AFTER_PASSED_AUDIT') === '1') {
        throw new RuntimeException('Injected failure after passed runtime audit insertion.');
    }
    fchubPlan004Assert(
        (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}fchub_membership_audit_log WHERE id = %d AND entity_type = 'runtime_smoke' AND action = 'order_feed_idempotency'",
            $runtimeAuditId
        )) === 1,
        'The permanent runtime audit is not readable.'
    );
    $afterAudit = fchubPlan004Counts($observedTables);
    $expectedAfterAudit = $baseline;
    $expectedAfterAudit[$wpdb->prefix . 'fchub_membership_audit_log']++;
    fchubPlan004Assert($afterAudit === $expectedAfterAudit, 'Only the permanent runtime audit may remain.');

    WP_CLI::success(wp_json_encode([
        'status' => 'passed',
        'audit_id' => $runtimeAuditId,
        'first' => $proof['outcomes'][0],
        'duplicate' => $proof['outcomes'][1],
        'provider' => [
            'check' => FChubMembershipsPlan004RuntimeAdapter::$checks,
            'grant' => FChubMembershipsPlan004RuntimeAdapter::$grants,
            'revoke' => FChubMembershipsPlan004RuntimeAdapter::$revokes,
        ],
        'cleanup_verified' => true,
    ]));
} catch (Throwable $exception) {
    $cleanupException = null;
    if ($passedAuditId > 0) {
        try {
            fchubPlan004DeletePassedAudit($passedAuditId, $orderId, $setupId);
            $runtimeAuditId = 0;
        } catch (Throwable $passedAuditCleanupException) {
            $cleanupException = $passedAuditCleanupException;
        }
    }

    remove_action('fchub_memberships/grant_created', $grantHook, PHP_INT_MAX);
    remove_action('fchub_memberships/grant_renewed', $renewedHook, PHP_INT_MAX);
    remove_action('fchub_memberships/trial_started', $trialHook, PHP_INT_MAX);
    remove_filter('pre_as_enqueue_async_action', $emailFilter, PHP_INT_MAX);
    try {
        $cleanupOwnedRows();
        $verifyCleanup();
    } catch (Throwable $caughtCleanupException) {
        $cleanupException ??= $caughtCleanupException;
    }
    if ($cleanupException !== null) {
        fwrite(STDERR, 'Cleanup diagnostic: ' . $cleanupException->getMessage() . "\n");
    }

    if ($mutated) {
        $failedProof = [
            'status' => 'failed',
            'setup_id' => $setupId,
            'stage' => 'runtime_execution',
            'exception_class' => $exception::class,
            'exception_hash' => hash('sha256', $exception->getMessage()),
            'cleanup_attempted' => true,
            'cleanup_succeeded' => $cleanupException === null,
            'cleanup_exception_hash' => $cleanupException === null
                ? null
                : hash('sha256', $cleanupException->getMessage()),
        ];
        if ($passedAuditId === 0 || $runtimeAuditId === 0) {
            if ($passedAuditId > 0) {
                fchubPlan004Assert(
                    (int) $wpdb->get_var($wpdb->prepare(
                        "SELECT COUNT(*) FROM {$auditTable} WHERE id = %d",
                        $passedAuditId
                    )) === 0,
                    'The passed runtime audit was not removed before failure evidence was written.'
                );
            }
            $failureAuditId = fchubPlan004Audit($failedProof, $orderId);
            $runtimeAuditId = $failureAuditId;
            $runAudits = array_values(array_filter(
                $wpdb->get_results($wpdb->prepare(
                    "SELECT id, new_value FROM {$auditTable}
                     WHERE entity_type = 'runtime_smoke'
                       AND entity_id = %d
                       AND action = 'order_feed_idempotency'",
                    $orderId
                ), ARRAY_A),
                static function (array $row) use ($setupId): bool {
                    $value = json_decode((string) ($row['new_value'] ?? ''), true);

                    return is_array($value) && ($value['setup_id'] ?? null) === $setupId;
                }
            ));
            $retainedProof = count($runAudits) === 1
                ? json_decode((string) ($runAudits[0]['new_value'] ?? ''), true)
                : null;
            fchubPlan004Assert(
                count($runAudits) === 1
                    && (int) $runAudits[0]['id'] === $runtimeAuditId
                    && is_array($retainedProof)
                    && ($retainedProof['status'] ?? null) === 'failed',
                'The failed runtime audit is not the sole retained audit for this run.'
            );
        }
    }

    WP_CLI::error(sprintf(
        'Plan 004 runtime smoke failed%s; explicit cleanup %s (message hash %s).',
        $failureAuditId > 0 ? " with audit #{$failureAuditId}" : '',
        $cleanupException === null ? 'succeeded' : 'failed',
        hash('sha256', $exception->getMessage())
    ));
}

<?php

namespace FChubMemberships\Support;

use FChubMemberships\Http\AccessApiCredential;
use FChubMemberships\Http\MembershipMutationPermission;
use FChubMemberships\Integration\MembershipSettingsOptionCoordinator;

defined('ABSPATH') || exit;

class Migrations
{
    /** @var array<string, list<string>> */
    private const REQUIRED_COLUMNS = [
        'plans' => [
            'id', 'title', 'slug', 'description', 'status', 'level', 'includes_plan_ids',
            'restriction_message', 'redirect_url', 'duration_type', 'duration_days', 'trial_days',
            'grace_period_days', 'settings', 'meta', 'scheduled_status', 'scheduled_at', 'created_at', 'updated_at',
        ],
        'plan_rules' => [
            'id', 'plan_id', 'provider', 'resource_type', 'resource_id', 'drip_delay_days',
            'drip_type', 'drip_date', 'sort_order', 'meta', 'created_at', 'updated_at',
        ],
        'grants' => [
            'id', 'user_id', 'plan_id', 'provider', 'resource_type', 'resource_id', 'source_type',
            'source_id', 'feed_id', 'grant_key', 'status', 'starts_at', 'expires_at', 'drip_available_at',
            'trial_ends_at', 'source_ids', 'meta', 'cancellation_requested_at', 'cancellation_effective_at',
            'cancellation_reason', 'renewal_count', 'created_at', 'updated_at',
        ],
        'event_locks' => [
            'id', 'event_hash', 'order_id', 'subscription_id', 'feed_id', 'trigger_name', 'processed_at',
            'result', 'error', 'state', 'owner_token', 'lease_expires_at', 'attempt_count', 'retryable',
            'next_retry_at', 'updated_at', 'completed_at', 'last_error',
        ],
        'mutation_requests' => [
            'id', 'request_key', 'fingerprint', 'user_id', 'state', 'response_status', 'response_body',
            'lease_token', 'lease_expires_at', 'attempt_count', 'created_at', 'updated_at', 'completed_at',
        ],
        'protection_rules' => [
            'id', 'resource_type', 'resource_id', 'plan_ids', 'protection_mode', 'restriction_message',
            'redirect_url', 'show_teaser', 'meta', 'created_at', 'updated_at',
        ],
        'validity_log' => ['id', 'subscription_id', 'last_valid_at', 'expired_at', 'dispatched_at'],
        'drip_notifications' => [
            'id', 'grant_id', 'plan_rule_id', 'user_id', 'notify_at', 'sent_at', 'status',
            'retry_count', 'next_retry_at',
        ],
        'stats_daily' => [
            'id', 'stat_date', 'plan_id', 'active_count', 'new_count', 'churned_count', 'revenue',
        ],
        'audit_log' => [
            'id', 'entity_type', 'entity_id', 'action', 'actor_id', 'actor_type', 'old_value',
            'new_value', 'context', 'created_at',
        ],
        'grant_sources' => ['id', 'grant_id', 'source_type', 'source_id', 'created_at'],
        'entitlement_edges' => [
            'id', 'user_id', 'provider', 'resource_type', 'resource_id', 'plan_id', 'feed_id',
            'feed_scope', 'source_type', 'source_id', 'owner', 'assignment_provenance', 'lifecycle',
            'access_status', 'starts_at', 'expires_at', 'drip_available_at', 'ended_at', 'end_reason', 'policy',
            'created_at', 'updated_at',
        ],
        'provider_operations' => [
            'id', 'edge_id', 'operation_key', 'desired_action', 'origin_event', 'state', 'lease_owner',
            'lease_expires_at', 'attempt_count', 'retryable', 'next_retry_at', 'last_error_code',
            'last_error_message', 'eligible_at', 'created_at', 'updated_at', 'completed_at',
        ],
        'crm_projection_jobs' => [
            'user_id', 'status', 'request_version', 'lease_owner', 'lease_expires_at',
            'attempt_count', 'next_retry_at', 'last_error_code', 'last_attempt_at',
            'last_success_at', 'created_at', 'updated_at',
        ],
        'webhook_events' => [
            'id', 'event_id', 'event_type', 'schema_version', 'body', 'occurred_at', 'created_at',
        ],
        'webhook_deliveries' => [
            'id', 'event_id', 'destination_url', 'destination_hash', 'status', 'attempt_count',
            'lease_owner', 'lease_expires_at', 'response_code', 'response_body', 'error_message',
            'next_attempt_at', 'last_attempt_at', 'delivered_at', 'created_at', 'updated_at',
        ],
    ];

    /** @var array<string, array<string, array{columns: list<string>, unique: bool}>> */
    private const REQUIRED_INDEXES = [
        'plans' => [
            'PRIMARY' => ['columns' => ['id'], 'unique' => true],
            'slug' => ['columns' => ['slug'], 'unique' => true],
            'status' => ['columns' => ['status'], 'unique' => false],
            'level' => ['columns' => ['level'], 'unique' => false],
        ],
        'plan_rules' => [
            'PRIMARY' => ['columns' => ['id'], 'unique' => true],
            'plan_provider_type' => ['columns' => ['plan_id', 'provider', 'resource_type'], 'unique' => false],
            'plan_sort' => ['columns' => ['plan_id', 'sort_order'], 'unique' => false],
        ],
        'grants' => [
            'PRIMARY' => ['columns' => ['id'], 'unique' => true],
            'grant_key' => ['columns' => ['grant_key'], 'unique' => true],
            'user_access' => ['columns' => ['user_id', 'provider', 'resource_type', 'resource_id', 'status'], 'unique' => false],
            'user_plan' => ['columns' => ['user_id', 'plan_id', 'status'], 'unique' => false],
            'source_lookup' => ['columns' => ['source_type', 'source_id'], 'unique' => false],
            'feed_id' => ['columns' => ['feed_id'], 'unique' => false],
            'status_expires' => ['columns' => ['status', 'expires_at'], 'unique' => false],
            'status_drip' => ['columns' => ['status', 'drip_available_at'], 'unique' => false],
            'idx_trial_ends' => ['columns' => ['trial_ends_at'], 'unique' => false],
            'idx_cancellation_effective' => ['columns' => ['cancellation_effective_at'], 'unique' => false],
            'idx_renewal_count' => ['columns' => ['plan_id', 'renewal_count'], 'unique' => false],
        ],
        'event_locks' => [
            'PRIMARY' => ['columns' => ['id'], 'unique' => true],
            'event_hash' => ['columns' => ['event_hash'], 'unique' => true],
            'idx_event_lock_state_lease' => ['columns' => ['state', 'lease_expires_at'], 'unique' => false],
            'idx_event_lock_completed' => ['columns' => ['completed_at'], 'unique' => false],
        ],
        'mutation_requests' => [
            'PRIMARY' => ['columns' => ['id'], 'unique' => true],
            'request_key' => ['columns' => ['request_key'], 'unique' => true],
            'state_updated' => ['columns' => ['state', 'updated_at'], 'unique' => false],
            'state_lease' => ['columns' => ['state', 'lease_expires_at'], 'unique' => false],
            'retention_completed' => ['columns' => ['completed_at', 'state', 'id'], 'unique' => false],
        ],
        'protection_rules' => [
            'PRIMARY' => ['columns' => ['id'], 'unique' => true],
            'resource_lookup' => ['columns' => ['resource_type', 'resource_id'], 'unique' => false],
        ],
        'validity_log' => [
            'PRIMARY' => ['columns' => ['id'], 'unique' => true],
            'subscription_id' => ['columns' => ['subscription_id'], 'unique' => false],
        ],
        'drip_notifications' => [
            'PRIMARY' => ['columns' => ['id'], 'unique' => true],
            'pending_notify' => ['columns' => ['status', 'notify_at'], 'unique' => false],
            'grant_id' => ['columns' => ['grant_id'], 'unique' => false],
            'user_id' => ['columns' => ['user_id'], 'unique' => false],
            'idx_retry' => ['columns' => ['status', 'next_retry_at', 'retry_count'], 'unique' => false],
        ],
        'stats_daily' => [
            'PRIMARY' => ['columns' => ['id'], 'unique' => true],
            'date_plan' => ['columns' => ['stat_date', 'plan_id'], 'unique' => true],
        ],
        'audit_log' => [
            'PRIMARY' => ['columns' => ['id'], 'unique' => true],
            'entity_lookup' => ['columns' => ['entity_type', 'entity_id'], 'unique' => false],
            'actor_lookup' => ['columns' => ['actor_id', 'actor_type'], 'unique' => false],
            'created_at' => ['columns' => ['created_at'], 'unique' => false],
        ],
        'grant_sources' => [
            'PRIMARY' => ['columns' => ['id'], 'unique' => true],
            'grant_source' => ['columns' => ['grant_id', 'source_type', 'source_id'], 'unique' => true],
            'source_lookup' => ['columns' => ['source_type', 'source_id'], 'unique' => false],
            'grant_id' => ['columns' => ['grant_id'], 'unique' => false],
        ],
        'entitlement_edges' => [
            'PRIMARY' => ['columns' => ['id'], 'unique' => true],
            'entitlement_identity' => [
                'columns' => [
                    'user_id', 'provider', 'resource_type', 'resource_id', 'plan_id', 'feed_id',
                    'feed_scope', 'source_type', 'source_id',
                ],
                'unique' => true,
            ],
            'active_resource' => [
                'columns' => ['user_id', 'provider', 'resource_type', 'resource_id', 'lifecycle'],
                'unique' => false,
            ],
            'source_lifecycle' => [
                'columns' => ['source_type', 'source_id', 'lifecycle'],
                'unique' => false,
            ],
            'plan_feed_lifecycle' => [
                'columns' => ['plan_id', 'feed_id', 'feed_scope', 'lifecycle'],
                'unique' => false,
            ],
            'lifecycle_expires' => ['columns' => ['lifecycle', 'expires_at'], 'unique' => false],
            'lifecycle_drip' => ['columns' => ['lifecycle', 'drip_available_at'], 'unique' => false],
            'lifecycle_ended' => ['columns' => ['lifecycle', 'ended_at'], 'unique' => false],
            'plan_access_lifecycle_user' => [
                'columns' => ['plan_id', 'access_status', 'lifecycle', 'user_id'],
                'unique' => false,
            ],
        ],
        'provider_operations' => [
            'PRIMARY' => ['columns' => ['id'], 'unique' => true],
            'operation_key' => ['columns' => ['operation_key'], 'unique' => true],
            'edge_state' => ['columns' => ['edge_id', 'state'], 'unique' => false],
            'state_due' => ['columns' => ['state', 'retryable', 'next_retry_at'], 'unique' => false],
            'state_lease' => ['columns' => ['state', 'lease_expires_at'], 'unique' => false],
            'state_eligible' => ['columns' => ['state', 'eligible_at'], 'unique' => false],
            'completed_at' => ['columns' => ['completed_at'], 'unique' => false],
        ],
        'crm_projection_jobs' => [
            'PRIMARY' => ['columns' => ['user_id'], 'unique' => true],
            'status_due' => ['columns' => ['status', 'next_retry_at'], 'unique' => false],
            'status_lease' => ['columns' => ['status', 'lease_expires_at'], 'unique' => false],
            'last_success' => ['columns' => ['last_success_at'], 'unique' => false],
        ],
        'webhook_events' => [
            'PRIMARY' => ['columns' => ['id'], 'unique' => true],
            'event_id' => ['columns' => ['event_id'], 'unique' => true],
            'type_occurred' => ['columns' => ['event_type', 'occurred_at'], 'unique' => false],
        ],
        'webhook_deliveries' => [
            'PRIMARY' => ['columns' => ['id'], 'unique' => true],
            'event_destination' => ['columns' => ['event_id', 'destination_hash'], 'unique' => true],
            'status_next' => ['columns' => ['status', 'next_attempt_at'], 'unique' => false],
            'status_lease' => ['columns' => ['status', 'lease_expires_at'], 'unique' => false],
            'created_at' => ['columns' => ['created_at'], 'unique' => false],
        ],
    ];

    /** @var array<string, array<string, array{column: string, referenced_table: string, referenced_column: string, delete_rule: string}>> */
    private const REQUIRED_FOREIGN_KEYS = [
        'plan_rules' => [
            'fk_plan_rules_plan' => [
                'column' => 'plan_id', 'referenced_table' => 'plans', 'referenced_column' => 'id',
                'delete_rule' => 'CASCADE',
            ],
        ],
        'provider_operations' => [
            'fk_provider_operations_edge' => [
                'column' => 'edge_id', 'referenced_table' => 'entitlement_edges', 'referenced_column' => 'id',
                'delete_rule' => 'RESTRICT',
            ],
        ],
        'grants' => [
            'fk_grants_plan' => [
                'column' => 'plan_id', 'referenced_table' => 'plans', 'referenced_column' => 'id',
                'delete_rule' => 'SET NULL',
            ],
        ],
        'drip_notifications' => [
            'fk_drip_grant' => [
                'column' => 'grant_id', 'referenced_table' => 'grants', 'referenced_column' => 'id',
                'delete_rule' => 'CASCADE',
            ],
            'fk_drip_rule' => [
                'column' => 'plan_rule_id', 'referenced_table' => 'plan_rules', 'referenced_column' => 'id',
                'delete_rule' => 'CASCADE',
            ],
        ],
    ];

    /** @var array<string, array{type: string, length: int|null, unsigned: bool, nullable: bool, default: string|null}> */
    private const EVENT_LOCK_COLUMN_DEFINITIONS = [
        'state' => ['type' => 'varchar', 'length' => 20, 'unsigned' => false, 'nullable' => false, 'default' => 'processing'],
        'owner_token' => ['type' => 'varchar', 'length' => 64, 'unsigned' => false, 'nullable' => true, 'default' => null],
        'lease_expires_at' => ['type' => 'datetime', 'length' => null, 'unsigned' => false, 'nullable' => true, 'default' => null],
        'attempt_count' => ['type' => 'int', 'length' => null, 'unsigned' => true, 'nullable' => false, 'default' => '1'],
        'retryable' => ['type' => 'tinyint', 'length' => 1, 'unsigned' => false, 'nullable' => false, 'default' => '1'],
        'next_retry_at' => ['type' => 'datetime', 'length' => null, 'unsigned' => false, 'nullable' => true, 'default' => null],
        'updated_at' => ['type' => 'datetime', 'length' => null, 'unsigned' => false, 'nullable' => false, 'default' => null],
        'completed_at' => ['type' => 'datetime', 'length' => null, 'unsigned' => false, 'nullable' => true, 'default' => null],
        'last_error' => ['type' => 'text', 'length' => null, 'unsigned' => false, 'nullable' => true, 'default' => null],
    ];

    /** @var array<string, array<string, array{type: string, length: int|null, unsigned: bool, nullable: bool, default: string|null}>> */
    private const V5_COLUMN_DEFINITIONS = [
        'entitlement_edges' => [
            'id' => ['type' => 'bigint', 'length' => null, 'unsigned' => true, 'nullable' => false, 'default' => null],
            'user_id' => ['type' => 'bigint', 'length' => null, 'unsigned' => true, 'nullable' => false, 'default' => null],
            'provider' => ['type' => 'varchar', 'length' => 50, 'unsigned' => false, 'nullable' => false, 'default' => null],
            'resource_type' => ['type' => 'varchar', 'length' => 50, 'unsigned' => false, 'nullable' => false, 'default' => null],
            'resource_id' => ['type' => 'varchar', 'length' => 100, 'unsigned' => false, 'nullable' => false, 'default' => null],
            'plan_id' => ['type' => 'bigint', 'length' => null, 'unsigned' => true, 'nullable' => false, 'default' => '0'],
            'feed_id' => ['type' => 'bigint', 'length' => null, 'unsigned' => true, 'nullable' => false, 'default' => '0'],
            'feed_scope' => ['type' => 'varchar', 'length' => 20, 'unsigned' => false, 'nullable' => false, 'default' => 'external_unknown'],
            'source_type' => ['type' => 'varchar', 'length' => 30, 'unsigned' => false, 'nullable' => false, 'default' => null],
            'source_id' => ['type' => 'bigint', 'length' => null, 'unsigned' => true, 'nullable' => false, 'default' => '0'],
            'owner' => ['type' => 'varchar', 'length' => 20, 'unsigned' => false, 'nullable' => false, 'default' => 'external_unknown'],
            'assignment_provenance' => ['type' => 'varchar', 'length' => 20, 'unsigned' => false, 'nullable' => false, 'default' => 'unknown'],
            'lifecycle' => ['type' => 'varchar', 'length' => 20, 'unsigned' => false, 'nullable' => false, 'default' => 'active'],
            'access_status' => ['type' => 'varchar', 'length' => 20, 'unsigned' => false, 'nullable' => false, 'default' => 'active'],
            'starts_at' => ['type' => 'datetime', 'length' => null, 'unsigned' => false, 'nullable' => true, 'default' => null],
            'expires_at' => ['type' => 'datetime', 'length' => null, 'unsigned' => false, 'nullable' => true, 'default' => null],
            'drip_available_at' => ['type' => 'datetime', 'length' => null, 'unsigned' => false, 'nullable' => true, 'default' => null],
            'ended_at' => ['type' => 'datetime', 'length' => null, 'unsigned' => false, 'nullable' => true, 'default' => null],
            'end_reason' => ['type' => 'varchar', 'length' => 191, 'unsigned' => false, 'nullable' => true, 'default' => null],
            'policy' => ['type' => 'longtext', 'length' => null, 'unsigned' => false, 'nullable' => true, 'default' => null],
            'created_at' => ['type' => 'datetime', 'length' => null, 'unsigned' => false, 'nullable' => false, 'default' => null],
            'updated_at' => ['type' => 'datetime', 'length' => null, 'unsigned' => false, 'nullable' => false, 'default' => null],
        ],
        'provider_operations' => [
            'id' => ['type' => 'bigint', 'length' => null, 'unsigned' => true, 'nullable' => false, 'default' => null],
            'edge_id' => ['type' => 'bigint', 'length' => null, 'unsigned' => true, 'nullable' => false, 'default' => null],
            'operation_key' => ['type' => 'char', 'length' => 64, 'unsigned' => false, 'nullable' => false, 'default' => null],
            'desired_action' => ['type' => 'varchar', 'length' => 30, 'unsigned' => false, 'nullable' => false, 'default' => null],
            'origin_event' => ['type' => 'varchar', 'length' => 100, 'unsigned' => false, 'nullable' => false, 'default' => null],
            'state' => ['type' => 'varchar', 'length' => 20, 'unsigned' => false, 'nullable' => false, 'default' => 'pending'],
            'lease_owner' => ['type' => 'varchar', 'length' => 64, 'unsigned' => false, 'nullable' => true, 'default' => null],
            'lease_expires_at' => ['type' => 'datetime', 'length' => null, 'unsigned' => false, 'nullable' => true, 'default' => null],
            'attempt_count' => ['type' => 'int', 'length' => null, 'unsigned' => true, 'nullable' => false, 'default' => '0'],
            'retryable' => ['type' => 'tinyint', 'length' => 1, 'unsigned' => false, 'nullable' => false, 'default' => '1'],
            'next_retry_at' => ['type' => 'datetime', 'length' => null, 'unsigned' => false, 'nullable' => true, 'default' => null],
            'last_error_code' => ['type' => 'varchar', 'length' => 100, 'unsigned' => false, 'nullable' => true, 'default' => null],
            'last_error_message' => ['type' => 'varchar', 'length' => 500, 'unsigned' => false, 'nullable' => true, 'default' => null],
            'eligible_at' => ['type' => 'datetime', 'length' => null, 'unsigned' => false, 'nullable' => true, 'default' => null],
            'created_at' => ['type' => 'datetime', 'length' => null, 'unsigned' => false, 'nullable' => false, 'default' => null],
            'updated_at' => ['type' => 'datetime', 'length' => null, 'unsigned' => false, 'nullable' => false, 'default' => null],
            'completed_at' => ['type' => 'datetime', 'length' => null, 'unsigned' => false, 'nullable' => true, 'default' => null],
        ],
    ];

    /** @var array<string, array{type: string, length: int|null, unsigned: bool, nullable: bool, default: string|null}> */
    private const V6_COLUMN_DEFINITIONS = [
        'user_id' => ['type' => 'bigint', 'length' => null, 'unsigned' => true, 'nullable' => false, 'default' => null],
        'status' => ['type' => 'varchar', 'length' => 20, 'unsigned' => false, 'nullable' => false, 'default' => 'pending'],
        'request_version' => ['type' => 'bigint', 'length' => null, 'unsigned' => true, 'nullable' => false, 'default' => '1'],
        'lease_owner' => ['type' => 'varchar', 'length' => 64, 'unsigned' => false, 'nullable' => true, 'default' => null],
        'lease_expires_at' => ['type' => 'datetime', 'length' => null, 'unsigned' => false, 'nullable' => true, 'default' => null],
        'attempt_count' => ['type' => 'int', 'length' => null, 'unsigned' => true, 'nullable' => false, 'default' => '0'],
        'next_retry_at' => ['type' => 'datetime', 'length' => null, 'unsigned' => false, 'nullable' => true, 'default' => null],
        'last_error_code' => ['type' => 'varchar', 'length' => 64, 'unsigned' => false, 'nullable' => true, 'default' => null],
        'last_attempt_at' => ['type' => 'datetime', 'length' => null, 'unsigned' => false, 'nullable' => true, 'default' => null],
        'last_success_at' => ['type' => 'datetime', 'length' => null, 'unsigned' => false, 'nullable' => true, 'default' => null],
        'created_at' => ['type' => 'datetime', 'length' => null, 'unsigned' => false, 'nullable' => false, 'default' => null],
        'updated_at' => ['type' => 'datetime', 'length' => null, 'unsigned' => false, 'nullable' => false, 'default' => null],
    ];

    /** @var array<string, array{type: string, length: int|null, unsigned: bool, nullable: bool, default: string|null}> */
    private const V7_COLUMN_DEFINITIONS = [
        'id' => ['type' => 'bigint', 'length' => null, 'unsigned' => true, 'nullable' => false, 'default' => null],
        'request_key' => ['type' => 'varchar', 'length' => 191, 'unsigned' => false, 'nullable' => false, 'default' => null],
        'fingerprint' => ['type' => 'char', 'length' => 64, 'unsigned' => false, 'nullable' => false, 'default' => null],
        'user_id' => ['type' => 'bigint', 'length' => null, 'unsigned' => true, 'nullable' => false, 'default' => null],
        'state' => ['type' => 'varchar', 'length' => 20, 'unsigned' => false, 'nullable' => false, 'default' => 'reserved'],
        'response_status' => ['type' => 'smallint', 'length' => null, 'unsigned' => true, 'nullable' => true, 'default' => null],
        'response_body' => ['type' => 'longtext', 'length' => null, 'unsigned' => false, 'nullable' => true, 'default' => null],
        'lease_token' => ['type' => 'varchar', 'length' => 64, 'unsigned' => false, 'nullable' => true, 'default' => null],
        'lease_expires_at' => ['type' => 'datetime', 'length' => null, 'unsigned' => false, 'nullable' => true, 'default' => null],
        'attempt_count' => ['type' => 'int', 'length' => null, 'unsigned' => true, 'nullable' => false, 'default' => '1'],
        'created_at' => ['type' => 'datetime', 'length' => null, 'unsigned' => false, 'nullable' => false, 'default' => null],
        'updated_at' => ['type' => 'datetime', 'length' => null, 'unsigned' => false, 'nullable' => false, 'default' => null],
        'completed_at' => ['type' => 'datetime', 'length' => null, 'unsigned' => false, 'nullable' => true, 'default' => null],
    ];

    /** @var array<string, array<string, array{type: string, length: int|null, unsigned: bool, nullable: bool, default: string|null}>> */
    private const V8_COLUMN_DEFINITIONS = [
        'webhook_events' => [
            'id' => ['type' => 'bigint', 'length' => null, 'unsigned' => true, 'nullable' => false, 'default' => null],
            'event_id' => ['type' => 'char', 'length' => 36, 'unsigned' => false, 'nullable' => false, 'default' => null],
            'event_type' => ['type' => 'varchar', 'length' => 64, 'unsigned' => false, 'nullable' => false, 'default' => null],
            'schema_version' => ['type' => 'varchar', 'length' => 10, 'unsigned' => false, 'nullable' => false, 'default' => '1.0'],
            'body' => ['type' => 'longtext', 'length' => null, 'unsigned' => false, 'nullable' => false, 'default' => null],
            'occurred_at' => ['type' => 'datetime', 'length' => null, 'unsigned' => false, 'nullable' => false, 'default' => null],
            'created_at' => ['type' => 'datetime', 'length' => null, 'unsigned' => false, 'nullable' => false, 'default' => null],
        ],
        'webhook_deliveries' => [
            'id' => ['type' => 'bigint', 'length' => null, 'unsigned' => true, 'nullable' => false, 'default' => null],
            'event_id' => ['type' => 'char', 'length' => 36, 'unsigned' => false, 'nullable' => false, 'default' => null],
            'destination_url' => ['type' => 'varchar', 'length' => 2048, 'unsigned' => false, 'nullable' => false, 'default' => null],
            'destination_hash' => ['type' => 'char', 'length' => 64, 'unsigned' => false, 'nullable' => false, 'default' => null],
            'status' => ['type' => 'varchar', 'length' => 20, 'unsigned' => false, 'nullable' => false, 'default' => 'pending'],
            'attempt_count' => ['type' => 'smallint', 'length' => null, 'unsigned' => true, 'nullable' => false, 'default' => '0'],
            'lease_owner' => ['type' => 'varchar', 'length' => 64, 'unsigned' => false, 'nullable' => true, 'default' => null],
            'lease_expires_at' => ['type' => 'datetime', 'length' => null, 'unsigned' => false, 'nullable' => true, 'default' => null],
            'response_code' => ['type' => 'smallint', 'length' => null, 'unsigned' => true, 'nullable' => true, 'default' => null],
            'response_body' => ['type' => 'text', 'length' => null, 'unsigned' => false, 'nullable' => true, 'default' => null],
            'error_message' => ['type' => 'text', 'length' => null, 'unsigned' => false, 'nullable' => true, 'default' => null],
            'next_attempt_at' => ['type' => 'datetime', 'length' => null, 'unsigned' => false, 'nullable' => true, 'default' => null],
            'last_attempt_at' => ['type' => 'datetime', 'length' => null, 'unsigned' => false, 'nullable' => true, 'default' => null],
            'delivered_at' => ['type' => 'datetime', 'length' => null, 'unsigned' => false, 'nullable' => true, 'default' => null],
            'created_at' => ['type' => 'datetime', 'length' => null, 'unsigned' => false, 'nullable' => false, 'default' => null],
            'updated_at' => ['type' => 'datetime', 'length' => null, 'unsigned' => false, 'nullable' => false, 'default' => null],
        ],
    ];

    /** @return array{success: bool, failures: list<string>} */
    public static function run(): array
    {
        self::ensureAdministratorCapability();

        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        $prefix = $wpdb->prefix . 'fchub_membership_';

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        // 1. Plans
        dbDelta("CREATE TABLE {$prefix}plans (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            title VARCHAR(255) NOT NULL,
            slug VARCHAR(100) NOT NULL,
            description TEXT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'active',
            level INT UNSIGNED NOT NULL DEFAULT 0,
            includes_plan_ids LONGTEXT NULL,
            restriction_message TEXT NULL,
            redirect_url VARCHAR(500) NULL,
            settings LONGTEXT NULL,
            meta LONGTEXT NULL,
            created_at TIMESTAMP NULL,
            updated_at TIMESTAMP NULL,
            PRIMARY KEY (id),
            UNIQUE KEY slug (slug),
            KEY status (status),
            KEY level (level)
        ) {$charset};");

        // 2. Plan Rules
        dbDelta("CREATE TABLE {$prefix}plan_rules (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            plan_id BIGINT UNSIGNED NOT NULL,
            provider VARCHAR(50) NOT NULL DEFAULT 'wordpress_core',
            resource_type VARCHAR(50) NOT NULL,
            resource_id VARCHAR(100) NOT NULL,
            drip_delay_days INT UNSIGNED NOT NULL DEFAULT 0,
            drip_type VARCHAR(20) NOT NULL DEFAULT 'immediate',
            drip_date DATETIME NULL,
            sort_order INT UNSIGNED NOT NULL DEFAULT 0,
            meta LONGTEXT NULL,
            created_at TIMESTAMP NULL,
            updated_at TIMESTAMP NULL,
            PRIMARY KEY (id),
            KEY plan_provider_type (plan_id, provider, resource_type),
            KEY plan_sort (plan_id, sort_order)
        ) {$charset};");

        // 3. Grants
        dbDelta("CREATE TABLE {$prefix}grants (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NOT NULL,
            plan_id BIGINT UNSIGNED NULL,
            provider VARCHAR(50) NOT NULL DEFAULT 'wordpress_core',
            resource_type VARCHAR(50) NOT NULL,
            resource_id VARCHAR(100) NOT NULL,
            source_type VARCHAR(30) NOT NULL DEFAULT 'order',
            source_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            feed_id BIGINT UNSIGNED NULL,
            grant_key VARCHAR(64) NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'active',
            starts_at DATETIME NULL,
            expires_at DATETIME NULL,
            drip_available_at DATETIME NULL,
            source_ids LONGTEXT NULL,
            meta LONGTEXT NULL,
            created_at TIMESTAMP NULL,
            updated_at TIMESTAMP NULL,
            PRIMARY KEY (id),
            UNIQUE KEY grant_key (grant_key),
            KEY user_access (user_id, provider, resource_type, resource_id, status),
            KEY user_plan (user_id, plan_id, status),
            KEY source_lookup (source_type, source_id),
            KEY feed_id (feed_id),
            KEY status_expires (status, expires_at),
            KEY status_drip (status, drip_available_at)
        ) {$charset};");

        // 4. Event Locks (idempotency)
        dbDelta("CREATE TABLE {$prefix}event_locks (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            event_hash VARCHAR(64) NOT NULL,
            order_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            subscription_id BIGINT UNSIGNED NULL,
            feed_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            trigger_name VARCHAR(100) NOT NULL DEFAULT '',
            processed_at DATETIME NOT NULL,
            result VARCHAR(20) NOT NULL DEFAULT 'success',
            error TEXT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY event_hash (event_hash)
        ) {$charset};");

        dbDelta("CREATE TABLE {$prefix}mutation_requests (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            request_key VARCHAR(191) NOT NULL,
            fingerprint CHAR(64) NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL,
            state VARCHAR(20) NOT NULL DEFAULT 'reserved',
            response_status SMALLINT UNSIGNED NULL,
            response_body LONGTEXT NULL,
            lease_token VARCHAR(64) NULL,
            lease_expires_at DATETIME NULL,
            attempt_count INT UNSIGNED NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            completed_at DATETIME NULL,
            PRIMARY KEY (id),
            UNIQUE KEY request_key (request_key),
            KEY state_updated (state, updated_at),
            KEY state_lease (state, lease_expires_at),
            KEY retention_completed (completed_at, state, id)
        ) {$charset};");

        // 5. Protection Rules
        dbDelta("CREATE TABLE {$prefix}protection_rules (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            resource_type VARCHAR(50) NOT NULL,
            resource_id VARCHAR(100) NOT NULL,
            plan_ids LONGTEXT NULL,
            protection_mode VARCHAR(20) NOT NULL DEFAULT 'explicit',
            restriction_message TEXT NULL,
            redirect_url VARCHAR(500) NULL,
            show_teaser VARCHAR(5) NOT NULL DEFAULT 'no',
            meta LONGTEXT NULL,
            created_at TIMESTAMP NULL,
            updated_at TIMESTAMP NULL,
            PRIMARY KEY (id),
            KEY resource_lookup (resource_type, resource_id)
        ) {$charset};");

        // 6. Validity Log (subscription validity tracking)
        dbDelta("CREATE TABLE {$prefix}validity_log (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            subscription_id BIGINT UNSIGNED NOT NULL,
            last_valid_at DATETIME NOT NULL,
            expired_at DATETIME NULL,
            dispatched_at DATETIME NULL,
            PRIMARY KEY (id),
            KEY subscription_id (subscription_id)
        ) {$charset};");

        // 7. Drip Notifications
        dbDelta("CREATE TABLE {$prefix}drip_notifications (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            grant_id BIGINT UNSIGNED NOT NULL,
            plan_rule_id BIGINT UNSIGNED NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL,
            notify_at DATETIME NOT NULL,
            sent_at DATETIME NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            PRIMARY KEY (id),
            KEY pending_notify (status, notify_at),
            KEY grant_id (grant_id),
            KEY user_id (user_id)
        ) {$charset};");

        // 8. Daily Stats (aggregation for reports)
        dbDelta("CREATE TABLE {$prefix}stats_daily (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            stat_date DATE NOT NULL,
            plan_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            active_count INT UNSIGNED NOT NULL DEFAULT 0,
            new_count INT UNSIGNED NOT NULL DEFAULT 0,
            churned_count INT UNSIGNED NOT NULL DEFAULT 0,
            revenue BIGINT NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            UNIQUE KEY date_plan (stat_date, plan_id)
        ) {$charset};");

        // V2: add new columns and audit_log table
        MigrationV2::run();

        // V3: fix subscription source_type, add FK constraints and indexes
        MigrationV3::run();

        // V4: add lease-owned event lock state and retry metadata
        $failures = MigrationV4::run();

        // V5: add authoritative entitlement edges and durable provider operations
        $failures = array_merge($failures, MigrationV5::run());

        // V6: add bounded FluentCRM projection recovery state
        $failures = array_merge($failures, MigrationV6::run());

        // V7: add lease-owned external mutation receipts and terminal retention
        $failures = array_merge($failures, MigrationV7::run());

        // V8: add durable webhook events and lease-owned delivery attempts
        $failures = array_merge($failures, MigrationV8::run());

        // V9: add durable per-lineage access status
        $failures = array_merge($failures, MigrationV9::run());
        $failures = array_values(array_unique(array_merge($failures, self::verifySchema())));

        return [
            'success' => $failures === [],
            'failures' => $failures,
        ];
    }

    /** @return list<string> */
    public static function verifySchema(): array
    {
        return self::verifySchemaFor(null);
    }

    /** @return list<string> */
    public static function verifyWebhookSchema(): array
    {
        return self::verifySchemaFor(['webhook_events', 'webhook_deliveries']);
    }

    /** @param list<string>|null $onlySuffixes @return list<string> */
    private static function verifySchemaFor(?array $onlySuffixes): array
    {
        global $wpdb;

        $prefix = $wpdb->prefix . 'fchub_membership_';
        $failures = [];
        $existingTables = [];
        $only = $onlySuffixes === null ? null : array_fill_keys($onlySuffixes, true);
        foreach (self::REQUIRED_COLUMNS as $suffix => $requiredColumns) {
            if ($only !== null && !isset($only[$suffix])) {
                continue;
            }
            $table = $prefix . $suffix;
            $tablePattern = $wpdb->esc_like($table);
            $foundTable = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $tablePattern));
            if ($foundTable !== $table) {
                $failures[] = "table:{$suffix} missing";
                continue;
            }
            $existingTables[$suffix] = true;

            $statusRows = $wpdb->get_results(
                $wpdb->prepare('SHOW TABLE STATUS LIKE %s', $tablePattern),
                ARRAY_A
            );
            $tableStatus = null;
            foreach ($statusRows as $statusRow) {
                if (($statusRow['Name'] ?? null) === $table) {
                    $tableStatus = $statusRow;
                    break;
                }
            }
            if ($tableStatus === null) {
                $failures[] = "table:{$suffix} engine metadata missing";
                continue;
            }
            $engine = (string) ($tableStatus['Engine'] ?? 'unknown');
            if (strcasecmp($engine, 'InnoDB') !== 0) {
                $failures[] = "table:{$suffix} engine expected InnoDB, got {$engine}";
            }

            $columnRows = $wpdb->get_results("SHOW COLUMNS FROM {$table}", ARRAY_A);
            $columns = [];
            foreach ($columnRows as $columnRow) {
                $columns[(string) ($columnRow['Field'] ?? '')] = $columnRow;
            }
            foreach ($requiredColumns as $requiredColumn) {
                if (!isset($columns[$requiredColumn])) {
                    $failures[] = "column:{$suffix}.{$requiredColumn} missing";
                }
            }

            $requiredDefinitions = match ($suffix) {
                'event_locks' => self::EVENT_LOCK_COLUMN_DEFINITIONS,
                'entitlement_edges', 'provider_operations' => self::V5_COLUMN_DEFINITIONS[$suffix],
                'crm_projection_jobs' => self::V6_COLUMN_DEFINITIONS,
                'mutation_requests' => self::V7_COLUMN_DEFINITIONS,
                'webhook_events', 'webhook_deliveries' => self::V8_COLUMN_DEFINITIONS[$suffix],
                default => [],
            };
            foreach ($requiredDefinitions as $column => $definition) {
                if (!isset($columns[$column])) {
                    continue;
                }
                if (!self::columnMatches($columns[$column], $definition)) {
                    $failures[] = sprintf(
                        'column:%s.%s expected %s',
                        $suffix,
                        $column,
                        self::describeColumn($definition)
                    );
                }
            }
        }

        foreach (self::REQUIRED_INDEXES as $suffix => $requiredIndexes) {
            if ($only !== null && !isset($only[$suffix])) {
                continue;
            }
            if (!isset($existingTables[$suffix])) {
                continue;
            }
            $table = $prefix . $suffix;
            $indexRows = $wpdb->get_results("SHOW INDEX FROM {$table}", ARRAY_A);
            $indexes = [];
            foreach ($indexRows as $indexRow) {
                $name = (string) ($indexRow['Key_name'] ?? '');
                $indexes[$name][] = $indexRow;
            }
            foreach ($requiredIndexes as $name => $definition) {
                if (!isset($indexes[$name])) {
                    $failures[] = "index:{$suffix}.{$name} missing";
                    continue;
                }

                usort($indexes[$name], static fn(array $left, array $right): int =>
                    (int) ($left['Seq_in_index'] ?? 0) <=> (int) ($right['Seq_in_index'] ?? 0));
                $columns = array_map(
                    static fn(array $row): string => (string) ($row['Column_name'] ?? ''),
                    $indexes[$name]
                );
                $unique = (int) ($indexes[$name][0]['Non_unique'] ?? 1) === 0;
                if ($columns !== $definition['columns'] || $unique !== $definition['unique']) {
                    $kind = $definition['unique'] ? 'unique' : 'non-unique';
                    $failures[] = sprintf(
                        'index:%s.%s expected %s (%s)',
                        $suffix,
                        $name,
                        $kind,
                        implode(', ', $definition['columns'])
                    );
                }
            }
        }

        foreach (self::REQUIRED_FOREIGN_KEYS as $suffix => $requiredForeignKeys) {
            if ($only !== null && !isset($only[$suffix])) {
                continue;
            }
            if (!isset($existingTables[$suffix])) {
                continue;
            }
            $table = $prefix . $suffix;
            $foreignKeyRows = $wpdb->get_results($wpdb->prepare(
                "SELECT rc.CONSTRAINT_NAME, kcu.COLUMN_NAME, kcu.REFERENCED_TABLE_NAME,
                        kcu.REFERENCED_COLUMN_NAME, rc.DELETE_RULE, kcu.ORDINAL_POSITION
                 FROM information_schema.REFERENTIAL_CONSTRAINTS rc
                 INNER JOIN information_schema.KEY_COLUMN_USAGE kcu
                    ON kcu.CONSTRAINT_SCHEMA = rc.CONSTRAINT_SCHEMA
                   AND kcu.TABLE_NAME = rc.TABLE_NAME
                   AND kcu.CONSTRAINT_NAME = rc.CONSTRAINT_NAME
                 WHERE rc.CONSTRAINT_SCHEMA = %s AND rc.TABLE_NAME = %s
                 ORDER BY rc.CONSTRAINT_NAME, kcu.ORDINAL_POSITION",
                $wpdb->dbname,
                $table
            ), ARRAY_A);
            $foreignKeys = [];
            foreach ($foreignKeyRows as $foreignKeyRow) {
                $foreignKeys[(string) ($foreignKeyRow['CONSTRAINT_NAME'] ?? '')] = $foreignKeyRow;
            }
            foreach ($requiredForeignKeys as $name => $definition) {
                if (!isset($foreignKeys[$name])) {
                    $failures[] = "foreign_key:{$suffix}.{$name} missing";
                    continue;
                }

                $foreignKey = $foreignKeys[$name];
                if ((string) ($foreignKey['COLUMN_NAME'] ?? '') !== $definition['column']
                    || (string) ($foreignKey['REFERENCED_TABLE_NAME'] ?? '') !== $prefix . $definition['referenced_table']
                    || (string) ($foreignKey['REFERENCED_COLUMN_NAME'] ?? '') !== $definition['referenced_column']
                    || strtoupper((string) ($foreignKey['DELETE_RULE'] ?? '')) !== $definition['delete_rule']
                ) {
                    $failures[] = sprintf(
                        'foreign_key:%s.%s expected %s -> %s.%s ON DELETE %s',
                        $suffix,
                        $name,
                        $definition['column'],
                        $definition['referenced_table'],
                        $definition['referenced_column'],
                        $definition['delete_rule']
                    );
                }
            }
        }

        if ($only !== null) {
            return array_values(array_unique($failures));
        }

        $orphanQueries = [
            'orphan:plan_rules.plan_id references missing plans rows' =>
                "SELECT COUNT(*) FROM {$prefix}plan_rules child
                 LEFT JOIN {$prefix}plans parent ON parent.id = child.plan_id
                 WHERE parent.id IS NULL",
            'orphan:grants.plan_id references missing plans rows' =>
                "SELECT COUNT(*) FROM {$prefix}grants child
                 LEFT JOIN {$prefix}plans parent ON parent.id = child.plan_id
                 WHERE child.plan_id IS NOT NULL AND parent.id IS NULL",
            'orphan:drip_notifications.grant_id references missing grants rows' =>
                "SELECT COUNT(*) FROM {$prefix}drip_notifications child
                 LEFT JOIN {$prefix}grants parent ON parent.id = child.grant_id
                 WHERE parent.id IS NULL",
            'orphan:drip_notifications.plan_rule_id references missing plan_rules rows' =>
                "SELECT COUNT(*) FROM {$prefix}drip_notifications child
                 LEFT JOIN {$prefix}plan_rules parent ON parent.id = child.plan_rule_id
                 WHERE parent.id IS NULL",
            'orphan:provider_operations.edge_id references missing entitlement_edges rows' =>
                "SELECT COUNT(*) FROM {$prefix}provider_operations child
                 LEFT JOIN {$prefix}entitlement_edges parent ON parent.id = child.edge_id
                 WHERE parent.id IS NULL",
        ];
        foreach ($orphanQueries as $failure => $query) {
            if ((int) $wpdb->get_var($query) > 0) {
                $failures[] = $failure;
            }
        }

        return array_values(array_unique($failures));
    }

    /**
     * @param array<string, mixed> $actual
     * @param array{type: string, length: int|null, unsigned: bool, nullable: bool, default: string|null} $expected
     */
    private static function columnMatches(array $actual, array $expected): bool
    {
        $type = strtolower(trim((string) ($actual['Type'] ?? '')));
        $unsigned = str_contains($type, ' unsigned');
        $type = trim(str_replace(' unsigned', '', $type));
        preg_match('/^([a-z]+)(?:\((\d+)\))?$/', $type, $matches);
        $baseType = $matches[1] ?? '';
        $length = isset($matches[2]) ? (int) $matches[2] : null;
        $nullable = strtoupper((string) ($actual['Null'] ?? '')) === 'YES';
        $default = $actual['Default'] ?? null;
        $default = $default === null ? null : (string) $default;

        return $baseType === $expected['type']
            && ($expected['length'] === null || $length === $expected['length'])
            && $unsigned === $expected['unsigned']
            && $nullable === $expected['nullable']
            && $default === $expected['default'];
    }

    /**
     * @param array{type: string, length: int|null, unsigned: bool, nullable: bool, default: string|null} $definition
     */
    private static function describeColumn(array $definition): string
    {
        $type = $definition['type'];
        if ($definition['length'] !== null) {
            $type .= '(' . $definition['length'] . ')';
        }
        if ($definition['unsigned']) {
            $type .= ' unsigned';
        }

        return sprintf(
            '%s %s default %s',
            $type,
            $definition['nullable'] ? 'NULL' : 'NOT NULL',
            $definition['default'] === null ? 'NULL' : $definition['default']
        );
    }

    /** @return array{success:bool, changed:bool, settings:array, reason?:string} */
    public static function migrateAccessApiCredential(): array
    {
        return (new MembershipSettingsOptionCoordinator())->mutate(
            static fn(array $settings): array => AccessApiCredential::migratePlaintext($settings)
        );
    }

    public static function ensureAdministratorCapability(): void
    {
        $administrator = get_role('administrator');

        if (!$administrator || $administrator->has_cap(MembershipMutationPermission::CAPABILITY)) {
            return;
        }

        $administrator->add_cap(MembershipMutationPermission::CAPABILITY);
    }

    /**
     * Drop all plugin tables. Only called if user opts in via settings.
     */
    public static function dropAll(): void
    {
        global $wpdb;
        $prefix = $wpdb->prefix . 'fchub_membership_';

        $tables = [
            'webhook_deliveries',
            'webhook_events',
            'crm_projection_jobs',
            'provider_operations',
            'entitlement_edges',
            'grant_sources',
            'audit_log',
            'stats_daily',
            'drip_notifications',
            'validity_log',
            'protection_rules',
            'event_locks',
            'mutation_requests',
            'grants',
            'plan_rules',
            'plans',
        ];

        foreach ($tables as $table) {
            $wpdb->query("DROP TABLE IF EXISTS {$prefix}{$table}");
        }

        delete_option('fchub_memberships_db_version');
        delete_option('fchub_memberships_settings');
    }
}

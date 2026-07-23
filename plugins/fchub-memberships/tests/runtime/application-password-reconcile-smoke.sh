#!/usr/bin/env bash

set -euo pipefail

plugin_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
repository_root="$(cd "${plugin_root}/../.." && pwd)"
playground_dir="${FCHUB_PLAYGROUND_DIR:-${repository_root}/../fchub-playground}"

cd "${playground_dir}"

prefix="fchub-health-smoke-${RANDOM}-$$"
login="${prefix}-user"
email="${prefix}@example.test"
user_password="$(openssl rand -hex 24)"
user_id=''
curl_config=''
invalid_curl_config=''
baseline=''
settings_hash=''
settings_snapshot=''
subscriber_ids='0'

snapshot_settings() {
    docker compose exec -T wpcli wp eval '
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT option_value, autoload FROM {$wpdb->options} WHERE option_name = %s",
                "fchub_memberships_settings"
            ),
            ARRAY_A
        );
        echo base64_encode(wp_json_encode([
            "exists" => is_array($row),
            "option_value" => is_array($row) ? $row["option_value"] : null,
            "autoload" => is_array($row) ? $row["autoload"] : null,
        ]));
    '
}

restore_settings() {
    [[ -n "${settings_snapshot}" ]] || return 0

    printf '%s' "${settings_snapshot}" | docker compose exec -T wpcli wp eval '
        global $wpdb;
        $snapshot = json_decode(
            base64_decode(trim(stream_get_contents(STDIN)), true),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        $option = "fchub_memberships_settings";
        if (!$snapshot["exists"]) {
            $wpdb->delete($wpdb->options, ["option_name" => $option], ["%s"]);
        } else {
            $exists = $wpdb->get_var($wpdb->prepare(
                "SELECT option_id FROM {$wpdb->options} WHERE option_name = %s",
                $option
            ));
            $values = [
                "option_value" => $snapshot["option_value"],
                "autoload" => $snapshot["autoload"],
            ];
            if ($exists === null) {
                $values["option_name"] = $option;
                if ($wpdb->insert($wpdb->options, $values) === false) {
                    throw new RuntimeException("Could not restore membership settings.");
                }
            } elseif ($wpdb->update($wpdb->options, $values, ["option_name" => $option]) === false) {
                throw new RuntimeException("Could not restore membership settings.");
            }
        }
        wp_cache_delete($option, "options");
        wp_cache_delete("alloptions", "options");
    ' >/dev/null
}

delete_disposable_data() {
    [[ -n "${user_id}" ]] || return 0

    docker compose exec -T wpcli wp user application-password list "${user_id}" --fields=uuid --format=csv 2>/dev/null \
        | tail -n +2 \
        | while IFS= read -r application_password_uuid; do
            [[ -z "${application_password_uuid}" ]] \
                || docker compose exec -T wpcli wp user application-password delete "${user_id}" "${application_password_uuid}" >/dev/null 2>&1
        done
    docker compose exec -T wpcli wp db query "
        DELETE logs FROM wp_actionscheduler_logs logs
        INNER JOIN wp_actionscheduler_actions actions ON actions.action_id = logs.action_id
        INNER JOIN wp_fchub_membership_provider_operations operations
            ON CAST(JSON_UNQUOTE(JSON_EXTRACT(actions.args, '$.operation_id')) AS UNSIGNED) = operations.id
        INNER JOIN wp_fchub_membership_entitlement_edges edges ON edges.id = operations.edge_id
        WHERE actions.hook = 'fchub_memberships_process_provider_operation'
          AND edges.user_id = ${user_id};
        DELETE actions FROM wp_actionscheduler_actions actions
        INNER JOIN wp_fchub_membership_provider_operations operations
            ON CAST(JSON_UNQUOTE(JSON_EXTRACT(actions.args, '$.operation_id')) AS UNSIGNED) = operations.id
        INNER JOIN wp_fchub_membership_entitlement_edges edges ON edges.id = operations.edge_id
        WHERE actions.hook = 'fchub_memberships_process_provider_operation'
          AND edges.user_id = ${user_id};
        DELETE logs FROM wp_actionscheduler_logs logs
        INNER JOIN wp_actionscheduler_actions actions ON actions.action_id = logs.action_id
        INNER JOIN wp_actionscheduler_groups groups ON groups.group_id = actions.group_id
        WHERE groups.slug LIKE 'fchub-memberships-crm-projection-${user_id}-v%';
        DELETE actions FROM wp_actionscheduler_actions actions
        INNER JOIN wp_actionscheduler_groups groups ON groups.group_id = actions.group_id
        WHERE groups.slug LIKE 'fchub-memberships-crm-projection-${user_id}-v%';
        DELETE FROM wp_actionscheduler_groups
        WHERE slug LIKE 'fchub-memberships-crm-projection-${user_id}-v%';
        DELETE audit FROM wp_fchub_membership_audit_log audit
        INNER JOIN wp_fchub_membership_provider_operations operations
            ON audit.entity_type = 'provider_operation' AND audit.entity_id = operations.id
        INNER JOIN wp_fchub_membership_entitlement_edges edges ON edges.id = operations.edge_id
        WHERE edges.user_id = ${user_id};
        DELETE audit FROM wp_fchub_membership_audit_log audit
        INNER JOIN wp_fchub_membership_entitlement_edges edges
            ON audit.entity_type = 'entitlement_edge' AND audit.entity_id = edges.id
        WHERE edges.user_id = ${user_id};
        DELETE operations FROM wp_fchub_membership_provider_operations operations
        INNER JOIN wp_fchub_membership_entitlement_edges edges ON edges.id = operations.edge_id
        WHERE edges.user_id = ${user_id};
        DELETE FROM wp_fchub_membership_entitlement_edges WHERE user_id = ${user_id};
        DELETE audit FROM wp_fchub_membership_audit_log audit
        INNER JOIN wp_fchub_membership_grants grants
            ON audit.entity_type = 'grant' AND audit.entity_id = grants.id
        WHERE grants.user_id = ${user_id};
        DELETE FROM wp_fchub_membership_audit_log WHERE actor_id = ${user_id};
        DELETE sources FROM wp_fchub_membership_grant_sources sources
        INNER JOIN wp_fchub_membership_grants grants ON sources.grant_id = grants.id
        WHERE grants.user_id = ${user_id};
        DELETE FROM wp_fchub_membership_grants WHERE user_id = ${user_id};
        DELETE FROM wp_fchub_membership_drip_notifications WHERE user_id = ${user_id};
        DELETE FROM wp_fchub_membership_crm_projection_jobs WHERE user_id = ${user_id};
        DELETE FROM wp_fchub_membership_mutation_requests WHERE user_id = ${user_id};
        DELETE pivot FROM wp_fc_subscriber_pivot pivot
        INNER JOIN wp_fc_subscribers subscribers ON subscribers.id = pivot.subscriber_id
        WHERE subscribers.user_id = ${user_id} OR subscribers.email = '${email}';
        DELETE meta FROM wp_fc_subscriber_meta meta
        INNER JOIN wp_fc_subscribers subscribers ON subscribers.id = meta.subscriber_id
        WHERE subscribers.user_id = ${user_id} OR subscribers.email = '${email}';
        DELETE notes FROM wp_fc_subscriber_notes notes
        INNER JOIN wp_fc_subscribers subscribers ON subscribers.id = notes.subscriber_id
        WHERE subscribers.user_id = ${user_id} OR subscribers.email = '${email}';
        DELETE funnels FROM wp_fc_funnel_subscribers funnels
        INNER JOIN wp_fc_subscribers subscribers ON subscribers.id = funnels.subscriber_id
        WHERE subscribers.user_id = ${user_id} OR subscribers.email = '${email}';
        DELETE FROM wp_fc_subscribers WHERE user_id = ${user_id} OR email = '${email}';
    " >/dev/null 2>&1
    docker compose exec -T wpcli wp user delete "${user_id}" --yes >/dev/null 2>&1
}

cleanup() {
    local exit_code=$?
    set +e

    if [[ -n "${curl_config}" && -e "${curl_config}" ]]; then
        rm -f -- "${curl_config}"
    fi
    if [[ -n "${invalid_curl_config}" && -e "${invalid_curl_config}" ]]; then
        rm -f -- "${invalid_curl_config}"
    fi
    restore_settings
    delete_disposable_data

    trap - EXIT
    exit "${exit_code}"
}

trap cleanup EXIT

baseline="$(docker compose exec -T wpcli wp db query "
    SELECT
        (SELECT COUNT(*) FROM wp_users),
        (SELECT COUNT(*) FROM wp_usermeta),
        (SELECT COUNT(*) FROM wp_fchub_membership_grants),
        (SELECT COUNT(*) FROM wp_fchub_membership_grant_sources),
        (SELECT COUNT(*) FROM wp_fchub_membership_entitlement_edges),
        (SELECT COUNT(*) FROM wp_fchub_membership_provider_operations),
        (SELECT COUNT(*) FROM wp_fchub_membership_crm_projection_jobs),
        (SELECT COUNT(*) FROM wp_fchub_membership_mutation_requests),
        (SELECT COUNT(*) FROM wp_fchub_membership_drip_notifications),
        (SELECT COUNT(*) FROM wp_fchub_membership_audit_log),
        (SELECT COUNT(*) FROM wp_fc_subscribers),
        (SELECT COUNT(*) FROM wp_fc_subscriber_meta),
        (SELECT COUNT(*) FROM wp_fc_subscriber_pivot),
        (SELECT COUNT(*) FROM wp_fc_subscriber_notes),
        (SELECT COUNT(*) FROM wp_fc_funnel_subscribers),
        (SELECT COUNT(*) FROM wp_actionscheduler_actions WHERE hook IN (
            'fchub_memberships_process_provider_operation',
            'fchub_memberships_process_crm_projection'
        )),
        (SELECT COUNT(*) FROM wp_actionscheduler_logs logs
         INNER JOIN wp_actionscheduler_actions actions ON actions.action_id = logs.action_id
         WHERE actions.hook IN (
             'fchub_memberships_process_provider_operation',
             'fchub_memberships_process_crm_projection'
         ));
" --skip-column-names | tr -s '[:space:]' ' ' | sed 's/ $//')"
settings_hash="$(docker compose exec -T wpcli wp option get fchub_memberships_settings --format=json | shasum -a 256 | awk '{print $1}')"
settings_snapshot="$(snapshot_settings)"

user_id="$(docker compose exec -T wpcli wp user create "${login}" "${email}" --role=subscriber --user_pass="${user_password}" --porcelain)"
docker compose exec -T wpcli wp user add-cap "${user_id}" manage_fchub_memberships >/dev/null
application_password="$(docker compose exec -T wpcli wp user application-password create "${user_id}" "${prefix}" --porcelain)"
curl_config="$(mktemp "${TMPDIR:-/tmp}/${prefix}.curl.XXXXXX")"
invalid_curl_config="$(mktemp "${TMPDIR:-/tmp}/${prefix}.invalid-curl.XXXXXX")"
chmod 600 "${curl_config}"
chmod 600 "${invalid_curl_config}"
printf 'user = "%s:%s"\n' "${login}" "${application_password}" > "${curl_config}"
printf 'user = "%s:%s"\n' "${login}" 'deliberately-invalid-application-password' > "${invalid_curl_config}"
api_root="${FCHUB_MEMBERSHIPS_SMOKE_URL:-http://localhost:9081}/wp-json/fchub-memberships/v1"
base_url="${api_root}/admin"
plan_id="$(docker compose exec -T wpcli wp db query 'SELECT id FROM wp_fchub_membership_plans ORDER BY id ASC LIMIT 1;' --skip-column-names | tr -d '[:space:]')"
plan_slug="$(docker compose exec -T wpcli wp db query "SELECT slug FROM wp_fchub_membership_plans WHERE id = ${plan_id};" --skip-column-names | tr -d '[:space:]')"

[[ "${plan_id}" =~ ^[1-9][0-9]*$ ]]
[[ -n "${plan_slug}" ]]
audit_digest="$(printf '%s|%s' "${prefix}" "${user_id}" | shasum -a 256 | awk '{print $1}')"
printf 'Application Password smoke audit: disposable_user_id=%s token_digest=%s\n' "${user_id}" "${audit_digest}"

request_code() {
    curl --config "${curl_config}" --silent --show-error --output /dev/null --write-out '%{http_code}' "$@"
}

request_replay() {
    curl --config "${curl_config}" --silent --show-error --write-out $'\n%{http_code}' "$@"
}

invalid_request_replay() {
    curl --config "${invalid_curl_config}" --silent --show-error --write-out $'\n%{http_code}' "$@"
}

options_response="$(request_replay -X OPTIONS "${base_url}/integrations/fluentcrm/reconcile")"
health_code="$(request_code "${base_url}/integrations/fluentcrm/health")"
settings_code="$(request_code "${base_url}/settings")"
before_dry_snapshot="$(docker compose exec -T wpcli wp db query "
    SELECT
        (SELECT COUNT(*) FROM wp_fc_subscribers WHERE user_id = ${user_id}) AS contacts,
        (SELECT COUNT(*) FROM wp_fchub_membership_grants WHERE user_id = ${user_id}) AS grants,
        (SELECT COUNT(*) FROM wp_fchub_membership_grant_sources sources
         INNER JOIN wp_fchub_membership_grants grants ON grants.id = sources.grant_id
         WHERE grants.user_id = ${user_id}) AS grant_sources,
        (SELECT COUNT(*) FROM wp_fchub_membership_entitlement_edges WHERE user_id = ${user_id}) AS edges,
        (SELECT COUNT(*) FROM wp_fchub_membership_provider_operations operations
         INNER JOIN wp_fchub_membership_entitlement_edges edges ON edges.id = operations.edge_id
         WHERE edges.user_id = ${user_id}) AS provider_operations,
        (SELECT COUNT(*) FROM wp_fchub_membership_crm_projection_jobs WHERE user_id = ${user_id}) AS projection_jobs,
        (SELECT COUNT(*) FROM wp_fchub_membership_mutation_requests WHERE user_id = ${user_id}) AS receipts,
        (SELECT COUNT(*) FROM wp_fchub_membership_drip_notifications WHERE user_id = ${user_id}) AS drips,
        (SELECT COUNT(*) FROM wp_fchub_membership_audit_log WHERE actor_id = ${user_id}) AS audit_rows,
        (SELECT COUNT(*) FROM wp_usermeta WHERE user_id = ${user_id} AND meta_key = '_fchub_memberships_fluentcrm_projection') AS ownership,
        (SELECT COUNT(*) FROM wp_fc_subscriber_meta meta
         INNER JOIN wp_fc_subscribers contacts ON contacts.id = meta.subscriber_id
         WHERE contacts.user_id = ${user_id} OR contacts.email = '${email}') AS contact_meta,
        (SELECT COUNT(*) FROM wp_fc_subscriber_pivot pivot
         INNER JOIN wp_fc_subscribers contacts ON contacts.id = pivot.subscriber_id
         WHERE contacts.user_id = ${user_id} OR contacts.email = '${email}') AS contact_pivot,
        (SELECT COUNT(*) FROM wp_fc_subscriber_notes notes
         INNER JOIN wp_fc_subscribers contacts ON contacts.id = notes.subscriber_id
         WHERE contacts.user_id = ${user_id} OR contacts.email = '${email}') AS contact_notes,
        (SELECT COUNT(*) FROM wp_fc_funnel_subscribers funnels
         INNER JOIN wp_fc_subscribers contacts ON contacts.id = funnels.subscriber_id
         WHERE contacts.user_id = ${user_id} OR contacts.email = '${email}') AS contact_funnels,
        (SELECT COUNT(*) FROM wp_actionscheduler_groups
         WHERE slug LIKE 'fchub-memberships-crm-projection-${user_id}-v%') AS action_groups,
        (SELECT COUNT(*) FROM wp_actionscheduler_actions actions
         INNER JOIN wp_actionscheduler_groups groups ON groups.group_id = actions.group_id
         WHERE groups.slug LIKE 'fchub-memberships-crm-projection-${user_id}-v%') AS actions,
        (SELECT COUNT(*) FROM wp_actionscheduler_logs logs
         INNER JOIN wp_actionscheduler_actions actions ON actions.action_id = logs.action_id
         INNER JOIN wp_actionscheduler_groups groups ON groups.group_id = actions.group_id
         WHERE groups.slug LIKE 'fchub-memberships-crm-projection-${user_id}-v%') AS action_logs,
        COALESCE((SELECT SHA2(option_value, 256) FROM wp_options
                  WHERE option_name = 'fchub_memberships_fluentcrm_reconciliation_health'), 'missing') AS health_summary,
        COALESCE((SELECT SHA2(option_value, 256) FROM wp_options
                  WHERE option_name = 'fchub_memberships_settings'), 'missing') AS settings;
" --skip-column-names | tr -d '[:space:]')"
dry_reconcile_response="$(request_replay -X POST -H 'Content-Type: application/json' --data "{\"user_id\":${user_id}}" "${base_url}/integrations/fluentcrm/reconcile")"
all_scope_code="$(request_code -X POST -H 'Content-Type: application/json' --data '{"scope":"all"}' "${base_url}/integrations/fluentcrm/reconcile")"
after_dry_snapshot="$(docker compose exec -T wpcli wp db query "
    SELECT
        (SELECT COUNT(*) FROM wp_fc_subscribers WHERE user_id = ${user_id}) AS contacts,
        (SELECT COUNT(*) FROM wp_fchub_membership_grants WHERE user_id = ${user_id}) AS grants,
        (SELECT COUNT(*) FROM wp_fchub_membership_grant_sources sources
         INNER JOIN wp_fchub_membership_grants grants ON grants.id = sources.grant_id
         WHERE grants.user_id = ${user_id}) AS grant_sources,
        (SELECT COUNT(*) FROM wp_fchub_membership_entitlement_edges WHERE user_id = ${user_id}) AS edges,
        (SELECT COUNT(*) FROM wp_fchub_membership_provider_operations operations
         INNER JOIN wp_fchub_membership_entitlement_edges edges ON edges.id = operations.edge_id
         WHERE edges.user_id = ${user_id}) AS provider_operations,
        (SELECT COUNT(*) FROM wp_fchub_membership_crm_projection_jobs WHERE user_id = ${user_id}) AS projection_jobs,
        (SELECT COUNT(*) FROM wp_fchub_membership_mutation_requests WHERE user_id = ${user_id}) AS receipts,
        (SELECT COUNT(*) FROM wp_fchub_membership_drip_notifications WHERE user_id = ${user_id}) AS drips,
        (SELECT COUNT(*) FROM wp_fchub_membership_audit_log WHERE actor_id = ${user_id}) AS audit_rows,
        (SELECT COUNT(*) FROM wp_usermeta WHERE user_id = ${user_id} AND meta_key = '_fchub_memberships_fluentcrm_projection') AS ownership,
        (SELECT COUNT(*) FROM wp_fc_subscriber_meta meta
         INNER JOIN wp_fc_subscribers contacts ON contacts.id = meta.subscriber_id
         WHERE contacts.user_id = ${user_id} OR contacts.email = '${email}') AS contact_meta,
        (SELECT COUNT(*) FROM wp_fc_subscriber_pivot pivot
         INNER JOIN wp_fc_subscribers contacts ON contacts.id = pivot.subscriber_id
         WHERE contacts.user_id = ${user_id} OR contacts.email = '${email}') AS contact_pivot,
        (SELECT COUNT(*) FROM wp_fc_subscriber_notes notes
         INNER JOIN wp_fc_subscribers contacts ON contacts.id = notes.subscriber_id
         WHERE contacts.user_id = ${user_id} OR contacts.email = '${email}') AS contact_notes,
        (SELECT COUNT(*) FROM wp_fc_funnel_subscribers funnels
         INNER JOIN wp_fc_subscribers contacts ON contacts.id = funnels.subscriber_id
         WHERE contacts.user_id = ${user_id} OR contacts.email = '${email}') AS contact_funnels,
        (SELECT COUNT(*) FROM wp_actionscheduler_groups
         WHERE slug LIKE 'fchub-memberships-crm-projection-${user_id}-v%') AS action_groups,
        (SELECT COUNT(*) FROM wp_actionscheduler_actions actions
         INNER JOIN wp_actionscheduler_groups groups ON groups.group_id = actions.group_id
         WHERE groups.slug LIKE 'fchub-memberships-crm-projection-${user_id}-v%') AS actions,
        (SELECT COUNT(*) FROM wp_actionscheduler_logs logs
         INNER JOIN wp_actionscheduler_actions actions ON actions.action_id = logs.action_id
         INNER JOIN wp_actionscheduler_groups groups ON groups.group_id = actions.group_id
         WHERE groups.slug LIKE 'fchub-memberships-crm-projection-${user_id}-v%') AS action_logs,
        COALESCE((SELECT SHA2(option_value, 256) FROM wp_options
                  WHERE option_name = 'fchub_memberships_fluentcrm_reconciliation_health'), 'missing') AS health_summary,
        COALESCE((SELECT SHA2(option_value, 256) FROM wp_options
                  WHERE option_name = 'fchub_memberships_settings'), 'missing') AS settings;
" --skip-column-names | tr -d '[:space:]')"
dry_reconcile_code="${dry_reconcile_response##*$'\n'}"; dry_reconcile_body="${dry_reconcile_response%$'\n'*}"
[[ "${dry_reconcile_code}" == '200' ]]
[[ "${all_scope_code}" == '403' ]]
[[ "${before_dry_snapshot}" == "${after_dry_snapshot}" ]]
printf '%s' "${dry_reconcile_body}" | php -r '
    $response = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
    if (($response["data"]["dry_run"] ?? null) !== true) exit(1);
    if (!isset($response["data"]["results"]) || !is_array($response["data"]["results"])) exit(1);
'
task8_runtime_output="$(docker compose exec -T \
    -e FCHUB_TASK8_USER_ID="${user_id}" \
    -e FCHUB_TASK8_PREFIX="${prefix}" \
    -e FCHUB_TASK8_LOGIN="${login}" \
    -e FCHUB_TASK8_EMAIL="${email}" \
    wpcli wp eval-file \
    /var/www/html/wp-content/plugins/fchub-memberships/tests/runtime/reconciliation-pagination-smoke.php)"
printf '%s\n' "${task8_runtime_output}"

missing_grant="$(request_replay -X POST -H 'Content-Type: application/json' --data "{\"user_id\":${user_id},\"plan_id\":${plan_id}}" "${base_url}/members/grant")"
missing_bulk="$(request_replay -X POST -H 'Content-Type: application/json' --data "{\"user_ids\":[${user_id}],\"plan_id\":${plan_id}}" "${base_url}/members/bulk-grant")"
missing_reconcile_apply="$(request_replay -X POST -H 'Content-Type: application/json' --data "{\"user_id\":${user_id},\"dry_run\":false}" "${base_url}/integrations/fluentcrm/reconcile")"
missing_provider_repair="$(request_replay -X POST -H 'Content-Type: application/json' --data "{\"user_id\":${user_id},\"provider\":\"fluentcrm\",\"resource_type\":\"fluentcrm_tag\",\"resource_id\":\"1\",\"expected_classification\":\"internal_active_provider_absent\"}" "${base_url}/provider-reconciliation/repair")"
invalid_auth_key="${prefix}-invalid-auth"
invalid_auth="$(invalid_request_replay -X POST -H 'Content-Type: application/json' -H "Idempotency-Key: ${invalid_auth_key}" --data "{\"user_id\":${user_id},\"plan_id\":${plan_id}}" "${base_url}/members/grant")"
after_missing_snapshot="$(docker compose exec -T wpcli wp db query "
    SELECT
        (SELECT COUNT(*) FROM wp_fchub_membership_grants WHERE user_id = ${user_id}),
        (SELECT COUNT(*) FROM wp_fchub_membership_entitlement_edges WHERE user_id = ${user_id}),
        (SELECT COUNT(*) FROM wp_fchub_membership_crm_projection_jobs WHERE user_id = ${user_id}),
        (SELECT COUNT(*) FROM wp_fchub_membership_mutation_requests WHERE user_id = ${user_id}),
        (SELECT COUNT(*) FROM wp_fchub_membership_mutation_requests WHERE request_key = '${invalid_auth_key}');
" --skip-column-names | tr -d '[:space:]')"

grant_key="${prefix}-grant"
grant_body="{\"user_id\":${user_id},\"plan_id\":${plan_id}}"
grant_one="$(request_replay -X POST -H 'Content-Type: application/json' -H "Idempotency-Key: ${grant_key}" --data "${grant_body}" "${base_url}/members/grant")"
expired_lease_token="$(printf '%s|expired-lease' "${prefix}" | shasum -a 256 | awk '{print $1}')"
expired_receipt_update="$(docker compose exec -T wpcli wp db query "
    UPDATE wp_fchub_membership_mutation_requests
    SET state = 'reserved',
        response_status = NULL,
        response_body = NULL,
        lease_token = '${expired_lease_token}',
        lease_expires_at = UTC_TIMESTAMP() - INTERVAL 1 SECOND,
        completed_at = NULL,
        updated_at = UTC_TIMESTAMP() - INTERVAL 1 SECOND
    WHERE request_key = '${grant_key}'
      AND user_id = ${user_id}
      AND state = 'complete'
      AND attempt_count = 1;
    SELECT ROW_COUNT();
" --skip-column-names | tail -n 1 | tr -d '[:space:]')"
[[ "${expired_receipt_update}" == '1' ]]
printf 'Application Password retry audit: request_digest=%s abandoned_attempt=1\n' "$(printf '%s' "${grant_key}" | shasum -a 256 | awk '{print $1}')"
grant_two="$(request_replay -X POST -H 'Content-Type: application/json' -H "Idempotency-Key: ${grant_key}" --data "${grant_body}" "${base_url}/members/grant")"
grant_three="$(request_replay -X POST -H 'Content-Type: application/json' -H "Idempotency-Key: ${grant_key}" --data "${grant_body}" "${base_url}/members/grant")"
grant_receipt="$(docker compose exec -T wpcli wp db query "
    SELECT CONCAT_WS('|', state, attempt_count, lease_token IS NULL, lease_expires_at IS NULL, completed_at IS NOT NULL)
    FROM wp_fchub_membership_mutation_requests
    WHERE request_key = '${grant_key}' AND user_id = ${user_id};
" --skip-column-names | tr -d '[:space:]')"
domain_grant="$(docker compose exec -T wpcli wp db query "
    SELECT CONCAT_WS(
        '|',
        COUNT(*),
        COALESCE(MIN(source_type), ''),
        COALESCE(MAX(source_type), ''),
        COALESCE(MIN(source_id), -1),
        COALESCE(MAX(source_id), -1),
        (
            SELECT COUNT(*)
            FROM wp_fchub_membership_grant_sources sources
            INNER JOIN wp_fchub_membership_grants source_grants ON source_grants.id = sources.grant_id
            WHERE source_grants.user_id = ${user_id} AND source_grants.plan_id = ${plan_id}
        )
    )
    FROM wp_fchub_membership_grants
    WHERE user_id = ${user_id} AND plan_id = ${plan_id};
" --skip-column-names | tr -d '[:space:]')"

revoke_key="${prefix}-revoke"
revoke_body="{\"user_id\":${user_id},\"plan_id\":${plan_id},\"reason\":\"runtime smoke cleanup\"}"
revoke_one="$(request_replay -X POST -H 'Content-Type: application/json' -H "Idempotency-Key: ${revoke_key}" --data "${revoke_body}" "${base_url}/members/revoke")"
revoke_two="$(request_replay -X POST -H 'Content-Type: application/json' -H "Idempotency-Key: ${revoke_key}" --data "${revoke_body}" "${base_url}/members/revoke")"

grant_code_one="${grant_one##*$'\n'}"
grant_code_two="${grant_two##*$'\n'}"; grant_body_two="${grant_two%$'\n'*}"
grant_code_three="${grant_three##*$'\n'}"; grant_body_three="${grant_three%$'\n'*}"
revoke_code_one="${revoke_one##*$'\n'}"; revoke_body_one="${revoke_one%$'\n'*}"
revoke_code_two="${revoke_two##*$'\n'}"; revoke_body_two="${revoke_two%$'\n'*}"
options_code="${options_response##*$'\n'}"; options_body="${options_response%$'\n'*}"
missing_grant_code="${missing_grant##*$'\n'}"; missing_grant_body="${missing_grant%$'\n'*}"
missing_bulk_code="${missing_bulk##*$'\n'}"; missing_bulk_body="${missing_bulk%$'\n'*}"
missing_reconcile_code="${missing_reconcile_apply##*$'\n'}"; missing_reconcile_body="${missing_reconcile_apply%$'\n'*}"
missing_provider_code="${missing_provider_repair##*$'\n'}"; missing_provider_body="${missing_provider_repair%$'\n'*}"
invalid_auth_code="${invalid_auth##*$'\n'}"

[[ "${options_code}" == '200' ]]
printf '%s' "${options_body}" | php -r '
    $response = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
    $args = $response["endpoints"][0]["args"] ?? [];
    if (($args["user_id"]["type"] ?? null) !== "integer" || ($args["user_id"]["minimum"] ?? null) !== 1) exit(1);
    if (($args["scope"]["enum"] ?? null) !== ["all"]) exit(1);
    if (($args["dry_run"]["type"] ?? null) !== "boolean" || ($args["dry_run"]["default"] ?? null) !== true) exit(1);
    if (($args["cursor"]["type"] ?? null) !== "integer" || ($args["cursor"]["minimum"] ?? null) !== 0 || ($args["cursor"]["default"] ?? null) !== 0) exit(1);
    if (($args["watermark"]["type"] ?? null) !== "integer" || ($args["watermark"]["minimum"] ?? null) !== 0) exit(1);
'
[[ "${health_code}" == '403' ]]
[[ "${settings_code}" == '403' ]]
[[ "${all_scope_code}" == '403' ]]
[[ "${dry_reconcile_code}" == '200' ]]
[[ "${before_dry_snapshot}" == "${after_dry_snapshot}" ]]
printf '%s' "${dry_reconcile_body}" | php -r '
    $response = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
    if (($response["data"]["dry_run"] ?? null) !== true) exit(1);
    if (!isset($response["data"]["results"]) || !is_array($response["data"]["results"])) exit(1);
'
for missing_code in "${missing_grant_code}" "${missing_bulk_code}" "${missing_reconcile_code}" "${missing_provider_code}"; do
    [[ "${missing_code}" == '428' ]]
done
for missing_body in "${missing_grant_body}" "${missing_bulk_body}" "${missing_reconcile_body}" "${missing_provider_body}"; do
    printf '%s' "${missing_body}" | php -r '
        $response = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
        if (($response["code"] ?? null) !== "fchub_idempotency_key_required") exit(1);
    '
done
[[ "${after_missing_snapshot}" == '00000' ]]
[[ "${invalid_auth_code}" == '401' ]]
[[ "${grant_code_one}" == '200' && "${grant_code_two}" == '200' && "${grant_code_three}" == '200' ]]
[[ "$(printf '%s' "${grant_body_two}" | shasum -a 256 | awk '{print $1}')" == "$(printf '%s' "${grant_body_three}" | shasum -a 256 | awk '{print $1}')" ]]
[[ "${grant_receipt}" == 'complete|2|1|1|1' ]]
[[ "${domain_grant}" == '1|manual|manual|0|0|0' ]]
[[ "${revoke_code_one}" == '200' && "${revoke_code_two}" == '200' ]]
[[ "$(printf '%s' "${revoke_body_one}" | shasum -a 256 | awk '{print $1}')" == "$(printf '%s' "${revoke_body_two}" | shasum -a 256 | awk '{print $1}')" ]]

docker compose exec -T wpcli wp user add-cap "${user_id}" manage_options >/dev/null
access_generate="$(request_replay -X POST "${base_url}/settings/generate-api-key")"
access_generate_code="${access_generate##*$'\n'}"
access_generate_body="${access_generate%$'\n'*}"
[[ "${access_generate_code}" == '200' ]]
access_key="$(printf '%s' "${access_generate_body}" | php -r '
    $response = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
    $key = $response["data"]["api_key"] ?? "";
    if (!is_string($key) || preg_match("/^fchub_[a-f0-9]{48}$/", $key) !== 1) exit(1);
    if (($response["data"]["access_api"]["configured"] ?? null) !== true) exit(1);
    echo $key;
')"

access_header_code="$(
    printf 'X-API-Key: %s\n' "${access_key}" \
        | curl --header @- --silent --show-error --output /dev/null --write-out '%{http_code}' \
            --get --data-urlencode "user_id=${user_id}" --data-urlencode "plan=${plan_slug}" \
            "${api_root}/check-access"
)"
access_query_code="$(
    printf '%s\n%s\n%s\n' "${access_key}" "${user_id}" "${plan_slug}" \
        | docker compose exec -T wpcli wp eval '
            $input = file("php://stdin", FILE_IGNORE_NEW_LINES);
            if (!is_array($input) || count($input) !== 3) {
                throw new RuntimeException("Invalid access-check smoke input.");
            }
            $request = new WP_REST_Request("GET", "/fchub-memberships/v1/check-access");
            $request->set_param("api_key", $input[0]);
            $request->set_param("user_id", (int) $input[1]);
            $request->set_param("plan", $input[2]);
            echo rest_do_request($request)->get_status();
        '
)"
[[ "${access_header_code}" == '200' ]]
[[ "${access_query_code}" == '401' ]]
unset access_key access_generate access_generate_body

restore_settings
after_access_settings_snapshot="$(snapshot_settings)"
[[ "${after_access_settings_snapshot}" == "${settings_snapshot}" ]]
settings_snapshot=''
docker compose exec -T wpcli wp user remove-cap "${user_id}" manage_options >/dev/null

subscriber_ids="$(docker compose exec -T wpcli wp db query "
    SELECT COALESCE(GROUP_CONCAT(id), '0')
    FROM wp_fc_subscribers
    WHERE user_id = ${user_id} OR email = '${email}';
" --skip-column-names | tr -d '[:space:]')"
[[ "${subscriber_ids}" =~ ^[0-9]+(,[0-9]+)*$ ]]

delete_disposable_data

remaining="$(docker compose exec -T wpcli wp db query "
    SELECT
        (SELECT COUNT(*) FROM wp_users WHERE ID = ${user_id})
        + (SELECT COUNT(*) FROM wp_usermeta WHERE user_id = ${user_id})
        + (SELECT COUNT(*) FROM wp_fchub_membership_grants WHERE user_id = ${user_id})
        + (SELECT COUNT(*) FROM wp_fchub_membership_entitlement_edges WHERE user_id = ${user_id})
        + (SELECT COUNT(*) FROM wp_fchub_membership_provider_operations operations
           INNER JOIN wp_fchub_membership_entitlement_edges edges ON edges.id = operations.edge_id
           WHERE edges.user_id = ${user_id})
        + (SELECT COUNT(*) FROM wp_fchub_membership_crm_projection_jobs WHERE user_id = ${user_id})
        + (SELECT COUNT(*) FROM wp_fchub_membership_drip_notifications WHERE user_id = ${user_id})
        + (SELECT COUNT(*) FROM wp_fchub_membership_mutation_requests WHERE user_id = ${user_id})
        + (SELECT COUNT(*) FROM wp_fchub_membership_mutation_requests WHERE request_key LIKE '${prefix}%')
        + (SELECT COUNT(*) FROM wp_fchub_membership_audit_log WHERE actor_id = ${user_id})
        + (SELECT COUNT(*) FROM wp_fc_subscribers WHERE user_id = ${user_id} OR email = '${email}')
        + (SELECT COUNT(*) FROM wp_fc_subscriber_meta WHERE subscriber_id IN (${subscriber_ids}))
        + (SELECT COUNT(*) FROM wp_fc_subscriber_pivot WHERE subscriber_id IN (${subscriber_ids}))
        + (SELECT COUNT(*) FROM wp_fc_subscriber_notes WHERE subscriber_id IN (${subscriber_ids}))
        + (SELECT COUNT(*) FROM wp_fc_funnel_subscribers WHERE subscriber_id IN (${subscriber_ids}))
        + (SELECT COUNT(*) FROM wp_actionscheduler_groups
           WHERE slug LIKE 'fchub-memberships-crm-projection-${user_id}-v%')
        + (SELECT COUNT(*) FROM wp_actionscheduler_actions actions
           INNER JOIN wp_actionscheduler_groups groups ON groups.group_id = actions.group_id
           WHERE groups.slug LIKE 'fchub-memberships-crm-projection-${user_id}-v%')
        + (SELECT COUNT(*) FROM wp_actionscheduler_logs logs
           INNER JOIN wp_actionscheduler_actions actions ON actions.action_id = logs.action_id
           INNER JOIN wp_actionscheduler_groups groups ON groups.group_id = actions.group_id
           WHERE groups.slug LIKE 'fchub-memberships-crm-projection-${user_id}-v%');
" --skip-column-names | tr -d '[:space:]')"
[[ "${remaining}" == '0' ]]

after_cleanup="$(docker compose exec -T wpcli wp db query "
    SELECT
        (SELECT COUNT(*) FROM wp_users),
        (SELECT COUNT(*) FROM wp_usermeta),
        (SELECT COUNT(*) FROM wp_fchub_membership_grants),
        (SELECT COUNT(*) FROM wp_fchub_membership_grant_sources),
        (SELECT COUNT(*) FROM wp_fchub_membership_entitlement_edges),
        (SELECT COUNT(*) FROM wp_fchub_membership_provider_operations),
        (SELECT COUNT(*) FROM wp_fchub_membership_crm_projection_jobs),
        (SELECT COUNT(*) FROM wp_fchub_membership_mutation_requests),
        (SELECT COUNT(*) FROM wp_fchub_membership_drip_notifications),
        (SELECT COUNT(*) FROM wp_fchub_membership_audit_log),
        (SELECT COUNT(*) FROM wp_fc_subscribers),
        (SELECT COUNT(*) FROM wp_fc_subscriber_meta),
        (SELECT COUNT(*) FROM wp_fc_subscriber_pivot),
        (SELECT COUNT(*) FROM wp_fc_subscriber_notes),
        (SELECT COUNT(*) FROM wp_fc_funnel_subscribers),
        (SELECT COUNT(*) FROM wp_actionscheduler_actions WHERE hook IN (
            'fchub_memberships_process_provider_operation',
            'fchub_memberships_process_crm_projection'
        )),
        (SELECT COUNT(*) FROM wp_actionscheduler_logs logs
         INNER JOIN wp_actionscheduler_actions actions ON actions.action_id = logs.action_id
         WHERE actions.hook IN (
             'fchub_memberships_process_provider_operation',
             'fchub_memberships_process_crm_projection'
         ));
" --skip-column-names | tr -s '[:space:]' ' ' | sed 's/ $//')"
after_settings_hash="$(docker compose exec -T wpcli wp option get fchub_memberships_settings --format=json | shasum -a 256 | awk '{print $1}')"
[[ "${after_cleanup}" == "${baseline}" ]]
[[ "${after_settings_hash}" == "${settings_hash}" ]]

curl_config_path="${curl_config}"
invalid_curl_config_path="${invalid_curl_config}"
rm -f -- "${curl_config}"
rm -f -- "${invalid_curl_config}"
[[ ! -e "${curl_config_path}" ]]
[[ ! -e "${invalid_curl_config_path}" ]]
curl_config=''
invalid_curl_config=''

user_id=''
trap - EXIT
printf 'Application Password reconciliation smoke passed; disposable user, password, and membership records were removed.\n'

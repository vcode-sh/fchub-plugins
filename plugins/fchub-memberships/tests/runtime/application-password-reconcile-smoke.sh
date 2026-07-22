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

cleanup() {
    local exit_code=$?
    set +e

    if [[ -n "${curl_config}" && -e "${curl_config}" ]]; then
        rm -f -- "${curl_config}"
    fi

    if [[ -n "${user_id}" ]]; then
        docker compose exec -T wpcli wp user application-password list "${user_id}" --fields=uuid --format=csv 2>/dev/null \
            | tail -n +2 \
            | while IFS= read -r application_password_uuid; do
                [[ -z "${application_password_uuid}" ]] \
                    || docker compose exec -T wpcli wp user application-password delete "${user_id}" "${application_password_uuid}" >/dev/null 2>&1
            done

        docker compose exec -T wpcli wp db query "
            DELETE a FROM wp_fchub_membership_audit_log a
            INNER JOIN wp_fchub_membership_grants g
                ON a.entity_type = 'grant' AND a.entity_id = g.id
            WHERE g.user_id = ${user_id};
            DELETE FROM wp_fchub_membership_audit_log WHERE actor_id = ${user_id};
            DELETE gs FROM wp_fchub_membership_grant_sources gs
            INNER JOIN wp_fchub_membership_grants g ON gs.grant_id = g.id
            WHERE g.user_id = ${user_id};
            DELETE FROM wp_fchub_membership_grants WHERE user_id = ${user_id};
            DELETE FROM wp_fchub_membership_drip_notifications WHERE user_id = ${user_id};
            DELETE FROM wp_fchub_membership_mutation_requests WHERE user_id = ${user_id};
        " >/dev/null 2>&1
        docker compose exec -T wpcli wp user delete "${user_id}" --yes >/dev/null 2>&1
    fi

    trap - EXIT
    exit "${exit_code}"
}

trap cleanup EXIT

user_id="$(docker compose exec -T wpcli wp user create "${login}" "${email}" --role=subscriber --user_pass="${user_password}" --porcelain)"
docker compose exec -T wpcli wp user add-cap "${user_id}" manage_fchub_memberships >/dev/null
application_password="$(docker compose exec -T wpcli wp user application-password create "${user_id}" "${prefix}" --porcelain)"
curl_config="$(mktemp "${TMPDIR:-/tmp}/${prefix}.curl.XXXXXX")"
chmod 600 "${curl_config}"
printf 'user = "%s:%s"\n' "${login}" "${application_password}" > "${curl_config}"
base_url="${FCHUB_MEMBERSHIPS_SMOKE_URL:-http://localhost:9081}/wp-json/fchub-memberships/v1/admin"
plan_id="$(docker compose exec -T wpcli wp db query 'SELECT id FROM wp_fchub_membership_plans ORDER BY id ASC LIMIT 1;' --skip-column-names | tr -d '[:space:]')"

[[ "${plan_id}" =~ ^[1-9][0-9]*$ ]]

request_code() {
    curl --config "${curl_config}" --silent --show-error --output /dev/null --write-out '%{http_code}' "$@"
}

request_replay() {
    curl --config "${curl_config}" --silent --show-error --write-out $'\n%{http_code}' "$@"
}

options_response="$(request_replay -X OPTIONS "${base_url}/integrations/fluentcrm/reconcile")"
health_code="$(request_code "${base_url}/integrations/fluentcrm/health")"
settings_code="$(request_code "${base_url}/settings")"
before_dry_snapshot="$(docker compose exec -T wpcli wp db query "
    SELECT
        (SELECT COUNT(*) FROM wp_fc_subscribers WHERE user_id = ${user_id}) AS contacts,
        (SELECT COUNT(*) FROM wp_fchub_membership_grants WHERE user_id = ${user_id}) AS grants,
        (SELECT COUNT(*) FROM wp_usermeta WHERE user_id = ${user_id} AND meta_key = '_fchub_memberships_fluentcrm_projection') AS ownership;
" --skip-column-names | tr -d '[:space:]')"
dry_reconcile_response="$(request_replay -X POST -H 'Content-Type: application/json' --data "{\"user_id\":${user_id},\"dry_run\":true}" "${base_url}/integrations/fluentcrm/reconcile")"
after_dry_snapshot="$(docker compose exec -T wpcli wp db query "
    SELECT
        (SELECT COUNT(*) FROM wp_fc_subscribers WHERE user_id = ${user_id}) AS contacts,
        (SELECT COUNT(*) FROM wp_fchub_membership_grants WHERE user_id = ${user_id}) AS grants,
        (SELECT COUNT(*) FROM wp_usermeta WHERE user_id = ${user_id} AND meta_key = '_fchub_memberships_fluentcrm_projection') AS ownership;
" --skip-column-names | tr -d '[:space:]')"

grant_key="${prefix}-grant"
grant_body="{\"user_id\":${user_id},\"plan_id\":${plan_id}}"
grant_one="$(request_replay -X POST -H 'Content-Type: application/json' -H "Idempotency-Key: ${grant_key}" --data "${grant_body}" "${base_url}/members/grant")"
grant_two="$(request_replay -X POST -H 'Content-Type: application/json' -H "Idempotency-Key: ${grant_key}" --data "${grant_body}" "${base_url}/members/grant")"

revoke_key="${prefix}-revoke"
revoke_body="{\"user_id\":${user_id},\"plan_id\":${plan_id},\"reason\":\"runtime smoke cleanup\"}"
revoke_one="$(request_replay -X POST -H 'Content-Type: application/json' -H "Idempotency-Key: ${revoke_key}" --data "${revoke_body}" "${base_url}/members/revoke")"
revoke_two="$(request_replay -X POST -H 'Content-Type: application/json' -H "Idempotency-Key: ${revoke_key}" --data "${revoke_body}" "${base_url}/members/revoke")"

grant_code_one="${grant_one##*$'\n'}"; grant_body_one="${grant_one%$'\n'*}"
grant_code_two="${grant_two##*$'\n'}"; grant_body_two="${grant_two%$'\n'*}"
revoke_code_one="${revoke_one##*$'\n'}"; revoke_body_one="${revoke_one%$'\n'*}"
revoke_code_two="${revoke_two##*$'\n'}"; revoke_body_two="${revoke_two%$'\n'*}"
options_code="${options_response##*$'\n'}"; options_body="${options_response%$'\n'*}"
dry_reconcile_code="${dry_reconcile_response##*$'\n'}"; dry_reconcile_body="${dry_reconcile_response%$'\n'*}"

[[ "${options_code}" == '200' ]]
printf '%s' "${options_body}" | php -r '
    $response = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
    $args = $response["endpoints"][0]["args"] ?? [];
    if (($args["user_id"]["type"] ?? null) !== "integer" || ($args["user_id"]["minimum"] ?? null) !== 1) exit(1);
    if (($args["scope"]["enum"] ?? null) !== ["all"]) exit(1);
    if (($args["dry_run"]["type"] ?? null) !== "boolean" || ($args["dry_run"]["default"] ?? null) !== true) exit(1);
'
[[ "${health_code}" == '403' ]]
[[ "${settings_code}" == '403' ]]
[[ "${dry_reconcile_code}" == '200' ]]
[[ "${before_dry_snapshot}" == "${after_dry_snapshot}" ]]
printf '%s' "${dry_reconcile_body}" | php -r '
    $response = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
    if (($response["data"]["dry_run"] ?? null) !== true) exit(1);
    if (!isset($response["data"]["results"]) || !is_array($response["data"]["results"])) exit(1);
'
[[ "${grant_code_one}" == '200' && "${grant_code_two}" == '200' ]]
[[ "$(printf '%s' "${grant_body_one}" | shasum -a 256 | awk '{print $1}')" == "$(printf '%s' "${grant_body_two}" | shasum -a 256 | awk '{print $1}')" ]]
[[ "${revoke_code_one}" == '200' && "${revoke_code_two}" == '200' ]]
[[ "$(printf '%s' "${revoke_body_one}" | shasum -a 256 | awk '{print $1}')" == "$(printf '%s' "${revoke_body_two}" | shasum -a 256 | awk '{print $1}')" ]]

docker compose exec -T wpcli wp user application-password list "${user_id}" --fields=uuid --format=csv 2>/dev/null \
    | tail -n +2 \
    | while IFS= read -r application_password_uuid; do
        [[ -z "${application_password_uuid}" ]] \
            || docker compose exec -T wpcli wp user application-password delete "${user_id}" "${application_password_uuid}" >/dev/null 2>&1
    done
docker compose exec -T wpcli wp db query "
    DELETE a FROM wp_fchub_membership_audit_log a
    INNER JOIN wp_fchub_membership_grants g
        ON a.entity_type = 'grant' AND a.entity_id = g.id
    WHERE g.user_id = ${user_id};
    DELETE FROM wp_fchub_membership_audit_log WHERE actor_id = ${user_id};
    DELETE gs FROM wp_fchub_membership_grant_sources gs
    INNER JOIN wp_fchub_membership_grants g ON gs.grant_id = g.id
    WHERE g.user_id = ${user_id};
    DELETE FROM wp_fchub_membership_grants WHERE user_id = ${user_id};
    DELETE FROM wp_fchub_membership_drip_notifications WHERE user_id = ${user_id};
    DELETE FROM wp_fchub_membership_mutation_requests WHERE user_id = ${user_id};
" >/dev/null
docker compose exec -T wpcli wp user delete "${user_id}" --yes >/dev/null

remaining="$(docker compose exec -T wpcli wp db query "
    SELECT
        (SELECT COUNT(*) FROM wp_users WHERE ID = ${user_id})
        + (SELECT COUNT(*) FROM wp_fchub_membership_grants WHERE user_id = ${user_id})
        + (SELECT COUNT(*) FROM wp_fchub_membership_drip_notifications WHERE user_id = ${user_id})
        + (SELECT COUNT(*) FROM wp_fchub_membership_mutation_requests WHERE user_id = ${user_id});
" --skip-column-names | tr -d '[:space:]')"
[[ "${remaining}" == '0' ]]

curl_config_path="${curl_config}"
rm -f -- "${curl_config}"
[[ ! -e "${curl_config_path}" ]]
curl_config=''

user_id=''
trap - EXIT
printf 'Application Password reconciliation smoke passed; disposable user, password, and membership records were removed.\n'

#!/usr/bin/env bash

set -euo pipefail

plugin_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
repository_root="$(cd "${plugin_root}/../.." && pwd)"
playground_dir="${FCHUB_PLAYGROUND_DIR:-${repository_root}/../fchub-playground}"
base_url="${FCHUB_MEMBERSHIPS_SMOKE_URL:-}"

prefix="fchub-feed-smoke-$(date +%s)-${RANDOM}-$$"
login="${prefix}-member"
email="${prefix}@example.test"
password="$(openssl rand -hex 24)"
sentinel="PROTECTED-BODY-${prefix}"
restriction="RESTRICTED-${prefix}"
temporary_dir="$(mktemp -d "${TMPDIR:-/tmp}/${prefix}.XXXXXX")"
cookie_jar="${temporary_dir}/cookies.txt"
anonymous_feed="${temporary_dir}/anonymous-feed.xml"
authorised_feed="${temporary_dir}/authorised-feed.xml"
anonymous_rest="${temporary_dir}/anonymous-rest.json"

user_id=''
post_id=''
plan_id=''
plan_rule_id=''
protection_rule_id=''
grant_id=''
runtime_cleaned=0

chmod 700 "${temporary_dir}"
touch "${cookie_jar}" "${anonymous_feed}" "${authorised_feed}" "${anonymous_rest}"
chmod 600 "${cookie_jar}" "${anonymous_feed}" "${authorised_feed}" "${anonymous_rest}"

cd "${playground_dir}"

if [[ -z "${base_url}" ]]; then
    base_url="$(docker compose exec -T wpcli wp option get home | tr -d '[:space:]')"
fi
base_url="${base_url%/}"

cleanup_runtime() {
    if [[ "${runtime_cleaned}" -eq 1 ]]; then
        return 0
    fi

    docker compose exec -T \
        -e FCHUB_SMOKE_USER_ID="${user_id:-0}" \
        -e FCHUB_SMOKE_POST_ID="${post_id:-0}" \
        -e FCHUB_SMOKE_PLAN_ID="${plan_id:-0}" \
        -e FCHUB_SMOKE_PLAN_RULE_ID="${plan_rule_id:-0}" \
        -e FCHUB_SMOKE_PROTECTION_RULE_ID="${protection_rule_id:-0}" \
        -e FCHUB_SMOKE_GRANT_ID="${grant_id:-0}" \
        wpcli wp eval '
            $userId = (int) getenv("FCHUB_SMOKE_USER_ID");
            $postId = (int) getenv("FCHUB_SMOKE_POST_ID");
            $planId = (int) getenv("FCHUB_SMOKE_PLAN_ID");
            $planRuleId = (int) getenv("FCHUB_SMOKE_PLAN_RULE_ID");
            $protectionRuleId = (int) getenv("FCHUB_SMOKE_PROTECTION_RULE_ID");
            $grantId = (int) getenv("FCHUB_SMOKE_GRANT_ID");

            global $wpdb;
            $wpdb->query("START TRANSACTION");
            try {
                if ($grantId > 0) {
                    $wpdb->delete($wpdb->prefix . "fchub_membership_audit_log", ["entity_type" => "grant", "entity_id" => $grantId]);
                    $wpdb->delete($wpdb->prefix . "fchub_membership_grant_sources", ["grant_id" => $grantId]);
                    $wpdb->delete($wpdb->prefix . "fchub_membership_drip_notifications", ["grant_id" => $grantId]);
                    (new \FChubMemberships\Storage\GrantRepository())->delete($grantId);
                }
                if ($protectionRuleId > 0) {
                    (new \FChubMemberships\Storage\ProtectionRuleRepository())->delete($protectionRuleId);
                }
                if ($planRuleId > 0) {
                    (new \FChubMemberships\Storage\PlanRuleRepository())->delete($planRuleId);
                }
                if ($planId > 0) {
                    (new \FChubMemberships\Storage\PlanRepository())->delete($planId);
                }
                $wpdb->query("COMMIT");
            } catch (\Throwable $exception) {
                $wpdb->query("ROLLBACK");
                throw $exception;
            }

            if ($postId > 0 && get_post($postId)) {
                wp_delete_post($postId, true);
            }
            if ($userId > 0 && get_userdata($userId)) {
                require_once ABSPATH . "wp-admin/includes/user.php";
                wp_delete_user($userId);
            }
        ' >/dev/null

    runtime_cleaned=1
}

verify_cleanup() {
    local remaining

    remaining="$(docker compose exec -T wpcli wp db query "
        SELECT
            (SELECT COUNT(*) FROM wp_users WHERE ID = ${user_id:-0})
            + (SELECT COUNT(*) FROM wp_usermeta WHERE user_id = ${user_id:-0})
            + (SELECT COUNT(*) FROM wp_posts WHERE ID = ${post_id:-0})
            + (SELECT COUNT(*) FROM wp_postmeta WHERE post_id = ${post_id:-0})
            + (SELECT COUNT(*) FROM wp_fchub_membership_plans WHERE id = ${plan_id:-0})
            + (SELECT COUNT(*) FROM wp_fchub_membership_plan_rules WHERE id = ${plan_rule_id:-0})
            + (SELECT COUNT(*) FROM wp_fchub_membership_protection_rules WHERE id = ${protection_rule_id:-0})
            + (SELECT COUNT(*) FROM wp_fchub_membership_grants WHERE id = ${grant_id:-0})
            + (SELECT COUNT(*) FROM wp_fchub_membership_grant_sources WHERE grant_id = ${grant_id:-0})
            + (SELECT COUNT(*) FROM wp_fchub_membership_drip_notifications WHERE grant_id = ${grant_id:-0})
            + (SELECT COUNT(*) FROM wp_fchub_membership_audit_log WHERE entity_type = 'grant' AND entity_id = ${grant_id:-0});
    " --skip-column-names | tr -d '[:space:]')"

    [[ "${remaining}" == '0' ]]
}

cleanup_on_exit() {
    local exit_code=$?
    local cleanup_code=0

    set +e
    cleanup_runtime
    cleanup_code=$?
    if [[ "${cleanup_code}" -eq 0 ]]; then
        verify_cleanup
        cleanup_code=$?
    fi
    rm -rf -- "${temporary_dir}"
    if [[ -e "${temporary_dir}" ]]; then
        cleanup_code=1
    fi

    if [[ "${cleanup_code}" -eq 0 ]]; then
        printf 'Cleanup verified: disposable post, rule, plan, grant, member, and credential files were removed.\n'
    else
        printf 'Cleanup verification failed for the disposable feed smoke data.\n' >&2
    fi

    trap - EXIT
    if [[ "${exit_code}" -ne 0 ]]; then
        exit "${exit_code}"
    fi
    exit "${cleanup_code}"
}

trap cleanup_on_exit EXIT

user_id="$(docker compose exec -T wpcli wp user create "${login}" "${email}" --role=subscriber --user_pass="${password}" --porcelain | tr -d '[:space:]')"
post_id="$(docker compose exec -T wpcli wp post create \
    --post_type=post \
    --post_status=publish \
    --post_title="${prefix}" \
    --post_content="${sentinel}" \
    --post_excerpt="${sentinel}" \
    --porcelain | tr -d '[:space:]')"

[[ "${user_id}" =~ ^[1-9][0-9]*$ ]]
[[ "${post_id}" =~ ^[1-9][0-9]*$ ]]

fixture_ids="$(docker compose exec -T \
    -e FCHUB_SMOKE_PREFIX="${prefix}" \
    -e FCHUB_SMOKE_RESTRICTION="${restriction}" \
    -e FCHUB_SMOKE_USER_ID="${user_id}" \
    -e FCHUB_SMOKE_POST_ID="${post_id}" \
    wpcli wp eval '
        $prefix = (string) getenv("FCHUB_SMOKE_PREFIX");
        $restriction = (string) getenv("FCHUB_SMOKE_RESTRICTION");
        $userId = (int) getenv("FCHUB_SMOKE_USER_ID");
        $postId = (int) getenv("FCHUB_SMOKE_POST_ID");

        global $wpdb;
        $wpdb->query("START TRANSACTION");
        try {
            $planId = (new \FChubMemberships\Storage\PlanRepository())->create([
                "title" => $prefix,
                "slug" => $prefix,
                "status" => "active",
                "duration_type" => "lifetime",
                "trial_days" => 0,
                "grace_period_days" => 0,
                "meta" => [],
            ]);
            $planRuleId = (new \FChubMemberships\Storage\PlanRuleRepository())->create([
                "plan_id" => $planId,
                "provider" => "wordpress_core",
                "resource_type" => "post",
                "resource_id" => (string) $postId,
                "drip_type" => "immediate",
            ]);
            $protectionRuleId = (new \FChubMemberships\Storage\ProtectionRuleRepository())->create([
                "resource_type" => "post",
                "resource_id" => (string) $postId,
                "plan_ids" => [$planId],
                "restriction_message" => $restriction,
                "show_teaser" => "no",
                "meta" => ["teaser_mode" => "none"],
            ]);
            $grantRepository = new \FChubMemberships\Storage\GrantRepository();
            $grantId = $grantRepository->create([
                "user_id" => $userId,
                "plan_id" => $planId,
                "provider" => "wordpress_core",
                "resource_type" => "post",
                "resource_id" => (string) $postId,
                "source_type" => "manual",
                "source_id" => 0,
                "grant_key" => \FChubMemberships\Storage\GrantRepository::makeGrantKey($userId, "wordpress_core", "post", (string) $postId),
                "status" => "active",
                "starts_at" => current_time("mysql"),
                "meta" => ["runtime_smoke" => $prefix],
            ]);

            foreach ([$planId, $planRuleId, $protectionRuleId, $grantId] as $id) {
                if ($id <= 0) {
                    throw new \RuntimeException("Disposable Memberships fixture could not be created.");
                }
            }

            $wpdb->query("COMMIT");
            echo implode("|", [$planId, $planRuleId, $protectionRuleId, $grantId]);
        } catch (\Throwable $exception) {
            $wpdb->query("ROLLBACK");
            throw $exception;
        }
    ' | tail -n 1 | tr -d '[:space:]')"

IFS='|' read -r plan_id plan_rule_id protection_rule_id grant_id <<< "${fixture_ids}"
for id in "${plan_id}" "${plan_rule_id}" "${protection_rule_id}" "${grant_id}"; do
    [[ "${id}" =~ ^[1-9][0-9]*$ ]]
done

curl --silent --show-error --fail --location --max-time 30 \
    "${base_url}/feed/?fchub_smoke=${prefix}" \
    --output "${anonymous_feed}"

curl --silent --show-error --fail --location --max-time 30 \
    "${base_url}/wp-json/wp/v2/posts/${post_id}?context=view&fchub_smoke=${prefix}" \
    --output "${anonymous_rest}"

curl --silent --show-error --fail --location --max-time 30 \
    --cookie-jar "${cookie_jar}" \
    "${base_url}/wp-login.php" \
    --output /dev/null
curl --silent --show-error --fail --location --max-time 30 \
    --cookie "${cookie_jar}" \
    --cookie-jar "${cookie_jar}" \
    --data-urlencode "log=${login}" \
    --data-urlencode "pwd=${password}" \
    --data-urlencode 'wp-submit=Log In' \
    --data-urlencode "redirect_to=${base_url}/wp-admin/profile.php" \
    --data-urlencode 'testcookie=1' \
    "${base_url}/wp-login.php" \
    --output /dev/null

grep -q 'wordpress_logged_in_' "${cookie_jar}"

curl --silent --show-error --fail --location --max-time 30 \
    --cookie "${cookie_jar}" \
    "${base_url}/feed/?fchub_smoke=${prefix}&authorised=1" \
    --output "${authorised_feed}"

anonymous_feed_state='clean'
restriction_state='present'
authorised_feed_state='present'

if grep -Fq "${sentinel}" "${anonymous_feed}"; then
    anonymous_feed_state='leaked'
fi
if ! grep -Fq "${restriction}" "${anonymous_feed}"; then
    restriction_state='missing'
fi
if ! grep -Fq "${sentinel}" "${authorised_feed}"; then
    authorised_feed_state='missing'
fi

# shellcheck disable=SC2016
rest_states="$(FCHUB_SMOKE_SENTINEL="${sentinel}" php -r '
    $response = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
    $content = $response["content"]["rendered"] ?? null;
    $excerpt = $response["excerpt"]["rendered"] ?? null;
    if (!is_string($content) || !is_string($excerpt)) {
        fwrite(STDERR, "WordPress REST response omitted rendered content or excerpt.\n");
        exit(1);
    }
    $sentinel = (string) getenv("FCHUB_SMOKE_SENTINEL");
    echo (str_contains($content, $sentinel) ? "leaked" : "clean")
        . "|"
        . (str_contains($excerpt, $sentinel) ? "leaked" : "clean");
' < "${anonymous_rest}")"
IFS='|' read -r rest_content_state rest_excerpt_state <<< "${rest_states}"

printf 'anonymous_feed_sentinel=%s\n' "${anonymous_feed_state}"
printf 'anonymous_feed_restriction=%s\n' "${restriction_state}"
printf 'authorised_feed_sentinel=%s\n' "${authorised_feed_state}"
printf 'anonymous_rest_content_sentinel=%s\n' "${rest_content_state}"
printf 'anonymous_rest_excerpt_sentinel=%s\n' "${rest_excerpt_state}"

failures=()
[[ "${anonymous_feed_state}" == 'clean' ]] || failures+=('Anonymous RSS exposed the protected source sentinel.')
[[ "${restriction_state}" == 'present' ]] || failures+=('Anonymous RSS omitted the restriction message.')
[[ "${authorised_feed_state}" == 'present' ]] || failures+=('The authorised member RSS omitted the protected source sentinel.')
[[ "${rest_content_state}" == 'clean' ]] || failures+=('Anonymous REST content exposed the protected source sentinel.')
[[ "${rest_excerpt_state}" == 'clean' ]] || failures+=('Anonymous REST excerpt exposed the protected source sentinel.')

if (( ${#failures[@]} > 0 )); then
    printf 'Feed protection smoke failed:\n' >&2
    printf ' - %s\n' "${failures[@]}" >&2
    exit 1
fi

cleanup_runtime
verify_cleanup
rm -rf -- "${temporary_dir}"
[[ ! -e "${temporary_dir}" ]]
trap - EXIT

printf 'Feed protection smoke passed; anonymous feed/REST output was restricted, authorised feed output remained available, and cleanup was verified.\n'

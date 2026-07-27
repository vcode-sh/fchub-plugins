#!/usr/bin/env bash

set -euo pipefail

plugin_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
repository_root="$(cd "${plugin_root}/../.." && pwd)"
playground_dir="$(cd "${FCHUB_PLAYGROUND_DIR:-${repository_root}/../fchub-playground}" && pwd -P)"
receiver="${plugin_root}/tests/runtime/webhook-receiver.php"

cd "${playground_dir}"

prefix="fchub-webhook-smoke-${RANDOM}-$$"
token="$(openssl rand -hex 24)"
secret="$(openssl rand -hex 32)"
receiver_log=''
receiver_output=''
filter_path="/tmp/${prefix}-safe-http.php"
queue_guard_path="/var/www/html/wp-content/mu-plugins/${prefix}-queue-guard.php"
receiver_pid=''
settings_snapshot=''
baseline=''
cleanup_finished='no'
stage='preflight'
port=''
receiver_host='8.8.8.8'
plan_id=''
last_delivery_id=''
last_action_id=''
runtime_lock_timeout="${FCHUB_WEBHOOK_SMOKE_LOCK_TIMEOUT:-180}"
runtime_lock_key="$(printf '%s' "${playground_dir}" | cksum | awk '{print $1}')"
runtime_lock_root="${TMPDIR:-/tmp}/fchub-memberships-runtime-locks"
runtime_lock_dir="${runtime_lock_root}/webhook-delivery-${runtime_lock_key}.lock"
runtime_lock_owned='no'
declare -a action_ids=()
declare -a delivery_ids=()
declare -a event_ids=()

acquire_runtime_lock() {
    local waited=0
    local holder=''

    case "${runtime_lock_timeout}" in
        ''|*[!0-9]*|0)
            printf 'FCHUB_WEBHOOK_SMOKE_LOCK_TIMEOUT must be a positive integer.\n' >&2
            return 2
            ;;
    esac

    mkdir -p "${runtime_lock_root}"
    until mkdir "${runtime_lock_dir}" 2>/dev/null; do
        holder="$(cat "${runtime_lock_dir}/pid" 2>/dev/null || true)"
        if [[ "${waited}" -eq 0 ]]; then
            printf 'Another webhook delivery smoke owns %s (process %s); waiting.\n' \
                "${runtime_lock_dir}" "${holder:-unknown}" >&2
        fi
        if [[ "${waited}" -ge "${runtime_lock_timeout}" ]]; then
            printf 'Timed out after %ss waiting for webhook smoke lock %s (process %s).\n' \
                "${runtime_lock_timeout}" "${runtime_lock_dir}" "${holder:-unknown}" >&2
            return 1
        fi
        sleep 1
        waited=$((waited + 1))
    done

    printf '%s\n' "$$" >"${runtime_lock_dir}/pid"
    runtime_lock_owned='yes'
}

release_runtime_lock() {
    local holder=''

    [[ "${runtime_lock_owned}" == 'yes' ]] || return 0
    holder="$(cat "${runtime_lock_dir}/pid" 2>/dev/null || true)"
    if [[ "${holder}" != "$$" ]]; then
        printf 'Refusing to release webhook smoke lock owned by process %s.\n' \
            "${holder:-unknown}" >&2
        return 1
    fi

    rm -f "${runtime_lock_dir}/pid"
    rmdir "${runtime_lock_dir}"
    runtime_lock_owned='no'
}

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
            if ($wpdb->delete($wpdb->options, ["option_name" => $option], ["%s"]) === false) {
                throw new RuntimeException("Unable to remove disposable webhook settings.");
            }
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
                    throw new RuntimeException("Unable to recreate membership settings.");
                }
            } elseif ($wpdb->update($wpdb->options, $values, ["option_name" => $option]) === false) {
                throw new RuntimeException("Unable to restore membership settings.");
            }
        }
        wp_cache_delete($option, "options");
        wp_cache_delete("alloptions", "options");
    ' >/dev/null
}

join_csv() {
    local IFS=','
    printf '%s' "$*"
}

track_action() {
    local candidate="$1"
    local existing
    [[ "${candidate}" =~ ^[1-9][0-9]*$ ]]
    for existing in "${action_ids[@]-}"; do
        [[ "${existing}" != "${candidate}" ]] || return 0
    done
    action_ids+=("${candidate}")
}

remove_filter() {
    docker compose exec -T wpcli php -r '
        $path = $argv[1];
        if (is_file($path) && !unlink($path)) {
            exit(1);
        }
    ' "${filter_path}" >/dev/null 2>&1
}

install_queue_guard() {
    docker compose exec -T --user 0 wpcli php -r '
        $path = $argv[1];
        $directory = dirname($path);
        if ((!is_dir($directory) && !mkdir($directory, 0755, true))
            || file_put_contents(
                $path,
                "<?php\n"
                . "add_filter(\"action_scheduler_allow_async_request_runner\", \"__return_false\", PHP_INT_MAX);\n"
                . "add_filter(\"action_scheduler_queue_runner_concurrent_batches\", static fn(): int => 0, PHP_INT_MAX);\n",
                LOCK_EX
            ) === false
            || !chmod($path, 0644)
        ) {
            exit(1);
        }
    ' "${queue_guard_path}"
}

remove_queue_guard() {
    docker compose exec -T --user 0 wpcli php -r '
        $path = $argv[1];
        if (is_file($path) && !unlink($path)) {
            exit(1);
        }
    ' "${queue_guard_path}" >/dev/null 2>&1
}

remove_local_file() {
    local path="$1"
    [[ -z "${path}" || ! -e "${path}" ]] || unlink "${path}"
}

delete_tracked_data() {
    local actions deliveries events
    actions="$(join_csv "${action_ids[@]-}")"
    deliveries="$(join_csv "${delivery_ids[@]-}")"
    events="$(join_csv "${event_ids[@]-}")"

    printf '%s\n%s\n%s\n' "${actions}" "${deliveries}" "${events}" \
        | docker compose exec -T wpcli wp eval '
            global $wpdb;
            $input = file("php://stdin", FILE_IGNORE_NEW_LINES);
            if (!is_array($input) || count($input) !== 3) {
                throw new RuntimeException("Invalid webhook cleanup input.");
            }
            $actionIds = array_values(array_filter(array_map("absint", explode(",", $input[0]))));
            $deliveryIds = array_values(array_filter(array_map("absint", explode(",", $input[1]))));
            $eventIds = array_values(array_filter(explode(",", $input[2]), static fn(string $id): bool =>
                preg_match("/^[a-f0-9-]{36}$/i", $id) === 1
            ));

            foreach ($deliveryIds as $deliveryId) {
                $scheduled = as_get_scheduled_actions([
                    "hook" => "fchub_memberships_deliver_webhook",
                    "args" => [$deliveryId],
                    "orderby" => "date",
                    "order" => "ASC",
                    "per_page" => -1,
                ], "ids");
                if (is_array($scheduled)) {
                    $actionIds = array_merge($actionIds, array_map("absint", $scheduled));
                }
            }
            $actionIds = array_values(array_unique(array_filter($actionIds)));

            $store = ActionScheduler::store();
            $actionExists = static function (int $actionId) use ($store): bool {
                try {
                    return $store->get_status($actionId) !== "";
                } catch (Throwable) {
                    return false;
                }
            };
            foreach ($actionIds as $actionId) {
                try {
                    if ($actionExists($actionId)) {
                        $store->delete_action($actionId);
                    }
                } catch (Throwable $exception) {
                    if ($actionExists($actionId)) {
                        throw $exception;
                    }
                }
            }
            foreach ($deliveryIds as $deliveryId) {
                $wpdb->delete(
                    $wpdb->prefix . "fchub_membership_webhook_deliveries",
                    ["id" => $deliveryId],
                    ["%d"]
                );
            }
            foreach ($eventIds as $eventId) {
                $wpdb->delete(
                    $wpdb->prefix . "fchub_membership_webhook_events",
                    ["event_id" => $eventId],
                    ["%s"]
                );
            }
        ' >/dev/null
}

cleanup_runtime() {
    local cleanup_status=0
    set +e

    delete_tracked_data || cleanup_status=1
    restore_settings || cleanup_status=1
    remove_filter || cleanup_status=1
    remove_queue_guard || cleanup_status=1

    if [[ -n "${receiver_pid}" ]] && kill -0 "${receiver_pid}" >/dev/null 2>&1; then
        kill "${receiver_pid}" >/dev/null 2>&1 || cleanup_status=1
        wait "${receiver_pid}" >/dev/null 2>&1 || true
    fi
    receiver_pid=''

    remove_local_file "${receiver_log}" || cleanup_status=1
    remove_local_file "${receiver_output}" || cleanup_status=1

    set -e
    return "${cleanup_status}"
}

exit_cleanup() {
    local exit_code=$?
    trap - EXIT
    if [[ "${cleanup_finished}" != 'yes' ]]; then
        cleanup_runtime || exit_code=1
    fi
    release_runtime_lock || exit_code=1
    if [[ "${exit_code}" -ne 0 ]]; then
        printf 'Webhook delivery smoke failed at stage: %s\n' "${stage}" >&2
    fi
    exit "${exit_code}"
}

trap exit_cleanup EXIT

acquire_runtime_lock

receiver_log="$(mktemp "${TMPDIR:-/tmp}/${prefix}.jsonl.XXXXXX")"
receiver_output="$(mktemp "${TMPDIR:-/tmp}/${prefix}.receiver.XXXXXX")"

install_filter() {
    docker compose exec -T wpcli php -r '
        $path = $argv[1];
        $content = stream_get_contents(STDIN);
        if (!is_string($content) || $content === "" || file_put_contents($path, $content, LOCK_EX) === false) {
            exit(1);
        }
        if (!chmod($path, 0600)) {
            exit(1);
        }
    ' "${filter_path}" <<'PHP'
<?php

if (!class_exists('WP_CLI')) {
    return;
}

WP_CLI::add_hook('after_wp_load', static function (): void {
    if (wp_get_environment_type() !== 'local') {
        return;
    }

    $fchubTask8Port = filter_var(
        getenv('FCHUB_TASK8_RECEIVER_PORT'),
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1024, 'max_range' => 65535]]
    );
    $fchubTask8Token = (string) getenv('FCHUB_TASK8_RECEIVER_TOKEN');
    if (!is_int($fchubTask8Port) || preg_match('/^[a-f0-9]{48}$/', $fchubTask8Token) !== 1) {
        return;
    }

    $fchubTask8Matches = static function (mixed $url) use ($fchubTask8Port, $fchubTask8Token): bool {
        if (!is_string($url)) {
            return false;
        }
        $parts = wp_parse_url($url);
        if (!is_array($parts)) {
            return false;
        }
        return strtolower((string) ($parts['scheme'] ?? '')) === 'http'
            && (string) ($parts['host'] ?? '') === '8.8.8.8'
            && (int) ($parts['port'] ?? 0) === $fchubTask8Port
            && (string) ($parts['path'] ?? '') === '/' . $fchubTask8Token
            && in_array((string) ($parts['query'] ?? ''), ['responses=204', 'responses=500,204', 'responses=400'], true);
    };

    add_filter(
        'pre_http_request',
        static function (mixed $pre, mixed $request, mixed $url) use (
            $fchubTask8Matches,
            $fchubTask8Port
        ): mixed {
            if (!$fchubTask8Matches($url)) {
                return $pre;
            }

            if (!function_exists('curl_init')) {
                return new WP_Error(
                    'fchub_runtime_transport_unavailable',
                    'The controlled webhook transport is unavailable.'
                );
            }

            $parts = wp_parse_url($url);
            $target = sprintf(
                'http://host.docker.internal:%d%s?%s',
                $fchubTask8Port,
                (string) ($parts['path'] ?? ''),
                (string) ($parts['query'] ?? '')
            );
            $headers = [];
            foreach ((array) ($request['headers'] ?? []) as $name => $value) {
                $headers[] = (string) $name . ': ' . (string) $value;
            }

            $handle = curl_init($target);
            if ($handle === false) {
                return new WP_Error(
                    'fchub_runtime_transport_unavailable',
                    'The controlled webhook transport could not be initialised.'
                );
            }
            curl_setopt_array($handle, [
                CURLOPT_CUSTOMREQUEST => (string) ($request['method'] ?? 'POST'),
                CURLOPT_POSTFIELDS => (string) ($request['body'] ?? ''),
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 2,
                CURLOPT_TIMEOUT => max(2, (int) ($request['timeout'] ?? 15)),
                CURLOPT_PROXY => '',
                CURLOPT_NOPROXY => '*',
            ]);
            $body = curl_exec($handle);
            $code = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
            $error = curl_error($handle);
            curl_close($handle);
            if (!is_string($body) || $code <= 0) {
                return new WP_Error(
                    'fchub_runtime_transport_failed',
                    $error !== '' ? $error : 'The controlled webhook request failed.'
                );
            }

            return [
                'headers' => [],
                'body' => $body,
                'response' => [
                    'code' => $code,
                    'message' => get_status_header_desc($code),
                ],
                'cookies' => [],
                'filename' => null,
            ];
        },
        PHP_INT_MIN,
        3
    );
    add_filter('action_scheduler_allow_async_request_runner', '__return_false', PHP_INT_MAX);
});
PHP
}

wp_filtered() {
    docker compose exec \
        -e FCHUB_TASK8_RECEIVER_PORT="${port}" \
        -e FCHUB_TASK8_RECEIVER_TOKEN="${token}" \
        -T wpcli wp --require="${filter_path}" "$@"
}

configure_destination() {
    local url="$1"
    local status="${2:-active}"
    printf '%s\n%s\n%s\n' "${url}" "${secret}" "${status}" | wp_filtered eval '
        $input = file("php://stdin", FILE_IGNORE_NEW_LINES);
        if (!is_array($input)
            || count($input) !== 3
            || !in_array($input[2], ["active", "paused"], true)
        ) {
            throw new RuntimeException("Invalid webhook settings input.");
        }
        $result = (new FChubMemberships\Integration\MembershipSettingsOptionCoordinator())->mutate(
            static function (array $settings) use ($input): array {
                unset($settings["webhook_enabled"], $settings["webhook_urls"], $settings["webhook_secret"]);
                $settings["webhook_endpoints"] = [[
                    "id" => "task8_runtime_endpoint",
                    "name" => "Runtime smoke receiver",
                    "url" => $input[0],
                    "secret" => $input[1],
                    "status" => $input[2],
                    "requires_rotation" => false,
                    "last_test_status" => "succeeded",
                    "last_tested_at" => gmdate("Y-m-d H:i:s"),
                ]];
                return $settings;
            }
        );
        if (!$result["success"]) {
            throw new RuntimeException("Unable to install disposable webhook settings.");
        }
    ' >/dev/null
}

assert_dispatch_suppressed() {
    local url="$1"
    printf '%s\n%s\n' "${url}" "${plan_id}" | wp_filtered eval '
        global $wpdb;
        $input = file("php://stdin", FILE_IGNORE_NEW_LINES);
        if (!is_array($input) || count($input) !== 2) {
            throw new RuntimeException("Invalid suppressed webhook dispatch input.");
        }
        $url = $input[0];
        $planId = (int) $input[1];
        $before = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}fchub_membership_webhook_deliveries
             WHERE destination_url = %s",
            $url
        ));
        do_action(
            "fchub_memberships/grant_created",
            1,
            $planId,
            ["source_type" => "task8_disabled_runtime_smoke", "source_id" => 0]
        );
        $after = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}fchub_membership_webhook_deliveries
             WHERE destination_url = %s",
            $url
        ));
        if ($after !== $before) {
            throw new RuntimeException("A disabled endpoint persisted a delivery.");
        }
    ' >/dev/null
}

dispatch_delivery() {
    local url="$1"
    local result delivery_id event_id
    result="$(printf '%s\n%s\n' "${url}" "${plan_id}" | wp_filtered eval '
        global $wpdb;
        $input = file("php://stdin", FILE_IGNORE_NEW_LINES);
        if (!is_array($input) || count($input) !== 2) {
            throw new RuntimeException("Invalid webhook dispatch input.");
        }
        $url = $input[0];
        $planId = (int) $input[1];
        $before = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}fchub_membership_webhook_deliveries
             WHERE destination_url = %s",
            $url
        ));
        do_action(
            "fchub_memberships/grant_created",
            1,
            $planId,
            ["source_type" => "task8_runtime_smoke", "source_id" => 0]
        );
        $after = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}fchub_membership_webhook_deliveries
             WHERE destination_url = %s",
            $url
        ));
        if ($after !== $before + 1) {
            throw new RuntimeException("Expected exactly one owned webhook delivery.");
        }
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT id, event_id
             FROM {$wpdb->prefix}fchub_membership_webhook_deliveries
             WHERE destination_url = %s
             ORDER BY id DESC LIMIT 1",
            $url
        ), ARRAY_A);
        if (!is_array($row)) {
            throw new RuntimeException("Controlled webhook delivery was not persisted.");
        }
        echo (int) $row["id"] . "|" . (string) $row["event_id"];
    ')"

    IFS='|' read -r delivery_id event_id <<<"${result}"
    [[ "${delivery_id}" =~ ^[1-9][0-9]*$ ]]
    [[ "${event_id}" =~ ^[a-f0-9]{8}-[a-f0-9]{4}-[1-5][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$ ]]
    delivery_ids+=("${delivery_id}")
    event_ids+=("${event_id}")
    last_delivery_id="${delivery_id}"
}

action_id_for() {
    local delivery_id="$1"
    local attempt="$2"
    printf '%s\n%s\n' "${delivery_id}" "${attempt}" | wp_filtered eval '
        $input = file("php://stdin", FILE_IGNORE_NEW_LINES);
        $deliveryId = absint($input[0] ?? 0);
        $attempt = absint($input[1] ?? 0);
        $group = "fchub-memberships-webhooks-{$deliveryId}-a{$attempt}";
        $findPendingAction = static function () use ($deliveryId, $group): int {
            $ids = as_get_scheduled_actions([
                "hook" => "fchub_memberships_deliver_webhook",
                "args" => [$deliveryId],
                "group" => $group,
                "status" => ActionScheduler_Store::STATUS_PENDING,
                "orderby" => "date",
                "order" => "DESC",
                "per_page" => 1,
            ], "ids");

            return is_array($ids) ? (int) ($ids[0] ?? 0) : 0;
        };
        $actionId = $findPendingAction();
        if (!is_numeric($actionId) || (int) $actionId <= 0) {
            $observed = as_get_scheduled_actions([
                "hook" => "fchub_memberships_deliver_webhook",
                "args" => [$deliveryId],
                "group" => $group,
                "orderby" => "date",
                "order" => "ASC",
                "per_page" => -1,
            ], "ids");
            throw new RuntimeException(
                "Tracked webhook action was not pending: " . wp_json_encode($observed)
            );
        }
        echo (int) $actionId;
    '
}

force_delivery_due() {
    local delivery_id="$1"
    printf '%s\n' "${delivery_id}" | wp_filtered eval '
        global $wpdb;
        $deliveryId = absint(trim(stream_get_contents(STDIN)));
        $due = gmdate("Y-m-d H:i:s", time() - 5);
        $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->prefix}fchub_membership_webhook_deliveries
             SET next_attempt_at = %s
             WHERE id = %d AND status = %s",
            $due,
            $deliveryId,
            "retrying"
        ));
    ' >/dev/null
}

assert_only_runnable_action() {
    local action_id="$1"
    local group="$2"
    printf '%s\n%s\n' "${action_id}" "${group}" | wp_filtered eval '
        global $wpdb;
        $input = file("php://stdin", FILE_IGNORE_NEW_LINES);
        $actionId = absint($input[0] ?? 0);
        $group = (string) ($input[1] ?? "");
        $ids = [];
        foreach ([ActionScheduler_Store::STATUS_PENDING, ActionScheduler_Store::STATUS_RUNNING] as $status) {
            $found = as_get_scheduled_actions([
                "hook" => "fchub_memberships_deliver_webhook",
                "group" => $group,
                "status" => $status,
                "orderby" => "date",
                "order" => "ASC",
                "per_page" => -1,
            ], "ids");
            if (is_array($found)) {
                $ids = array_merge($ids, array_map("intval", $found));
            }
        }
        sort($ids);
        if ($ids !== [$actionId]) {
            throw new RuntimeException(
                "The tracked group does not contain exactly one runnable action ID: expected "
                . $actionId . ", observed " . wp_json_encode($ids)
            );
        }
    ' >/dev/null
}

assert_action_complete() {
    local action_id="$1"
    printf '%s' "${action_id}" | wp_filtered eval '
        $id = absint(trim(stream_get_contents(STDIN)));
        $status = ActionScheduler::store()->get_status($id);
        if ($status !== ActionScheduler_Store::STATUS_COMPLETE) {
            $logs = array_map(
                static fn(ActionScheduler_LogEntry $entry): string => $entry->get_message(),
                ActionScheduler::logger()->get_logs($id)
            );
            throw new RuntimeException(
                "The tracked webhook action did not complete: "
                . wp_json_encode(["status" => $status, "logs" => $logs])
            );
        }
    ' >/dev/null
}

run_attempt() {
    local delivery_id="$1"
    local attempt="$2"
    local action_id group
    action_id="$(action_id_for "${delivery_id}" "${attempt}")"
    track_action "${action_id}"
    last_action_id="${action_id}"
    force_delivery_due "${delivery_id}"
    group="fchub-memberships-webhooks-${delivery_id}-a${attempt}"
    assert_only_runnable_action "${action_id}" "${group}"
    wp_filtered action-scheduler action run "${action_id}" >/dev/null
    assert_action_complete "${action_id}"
}

delivery_state() {
    local delivery_id="$1"
    printf '%s' "${delivery_id}" | wp_filtered eval '
        global $wpdb;
        $id = absint(trim(stream_get_contents(STDIN)));
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT status, attempt_count, response_code, response_body, error_message
             FROM {$wpdb->prefix}fchub_membership_webhook_deliveries WHERE id = %d",
            $id
        ), ARRAY_A);
        if (!is_array($row)) {
            throw new RuntimeException("Tracked webhook delivery is missing.");
        }
        echo implode("|", [
            (string) $row["status"],
            (int) $row["attempt_count"],
            $row["response_code"] === null ? "null" : (string) (int) $row["response_code"],
            strlen((string) ($row["response_body"] ?? "")),
            (string) ($row["error_message"] ?? ""),
        ]);
    '
}

receiver_record_count() {
    wc -l <"${receiver_log}" | tr -d '[:space:]'
}

runtime_snapshot() {
    docker compose exec -T wpcli wp eval '
        global $wpdb;
        $actionIds = as_get_scheduled_actions([
            "hook" => "fchub_memberships_deliver_webhook",
            "orderby" => "date",
            "order" => "ASC",
            "per_page" => -1,
        ], "ids");
        $actionIds = is_array($actionIds) ? array_map("absint", $actionIds) : [];
        $logCount = 0;
        foreach ($actionIds as $actionId) {
            $logCount += count(ActionScheduler::logger()->get_logs($actionId));
        }
        echo wp_json_encode([
            "users" => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->users}"),
            "usermeta" => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->usermeta}"),
            "application_password_rows" => (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE meta_key = %s",
                "_application_passwords"
            )),
            "grants" => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}fchub_membership_grants"),
            "mutation_requests" => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}fchub_membership_mutation_requests"),
            "events" => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}fchub_membership_webhook_events"),
            "deliveries" => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}fchub_membership_webhook_deliveries"),
            "actions" => count($actionIds),
            "logs" => $logCount,
        ]);
    '
}

tracked_residue() {
    printf '%s\n%s\n%s\n' \
        "$(join_csv "${action_ids[@]-}")" \
        "$(join_csv "${delivery_ids[@]-}")" \
        "$(join_csv "${event_ids[@]-}")" \
        | docker compose exec -T wpcli wp eval '
            global $wpdb;
            $input = file("php://stdin", FILE_IGNORE_NEW_LINES);
            $actionIds = array_values(array_filter(array_map("absint", explode(",", $input[0] ?? ""))));
            $deliveryIds = array_values(array_filter(array_map("absint", explode(",", $input[1] ?? ""))));
            $eventIds = array_values(array_filter(explode(",", $input[2] ?? "")));
            $residue = 0;
            foreach ($actionIds as $id) {
                try {
                    $residue += ActionScheduler::store()->get_status($id) === "" ? 0 : 1;
                } catch (Throwable) {
                    // The action was removed from the active store.
                }
                $residue += count(ActionScheduler::logger()->get_logs($id));
            }
            foreach ($deliveryIds as $id) {
                $residue += (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$wpdb->prefix}fchub_membership_webhook_deliveries WHERE id = %d",
                    $id
                ));
            }
            foreach ($eventIds as $id) {
                $residue += (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$wpdb->prefix}fchub_membership_webhook_events WHERE event_id = %s",
                    $id
                ));
            }
            echo $residue;
        '
}

port='8080'
php -r '
    $socket = @stream_socket_server(
        "tcp://127.0.0.1:" . $argv[1],
        $errorCode,
        $errorMessage
    );
    if (!is_resource($socket)) {
        fwrite(STDERR, "Webhook smoke receiver port {$argv[1]} is unavailable.\n");
        exit(1);
    }
    fclose($socket);
' "${port}"

settings_snapshot="$(snapshot_settings)"
baseline="$(runtime_snapshot)"
plan_id="$(docker compose exec -T wpcli wp db query \
    'SELECT id FROM wp_fchub_membership_plans ORDER BY id ASC LIMIT 1;' \
    --skip-column-names | tr -d '[:space:]')"
[[ "${plan_id}" =~ ^[1-9][0-9]*$ ]]

install_queue_guard
install_filter
chmod 600 "${receiver_log}" "${receiver_output}"
FCHUB_WEBHOOK_RECEIVER_SECRET="${secret}" \
    php "${receiver}" "${port}" "${token}" "${receiver_log}" >"${receiver_output}" 2>&1 &
receiver_pid=$!

receiver_ready='no'
for _ in {1..80}; do
    if curl --silent --fail --output /dev/null "http://127.0.0.1:${port}/${token}" 2>/dev/null; then
        receiver_ready='yes'
        break
    fi
    sleep 0.05
done
[[ "${receiver_ready}" == 'yes' ]]
kill -0 "${receiver_pid}"

stage='paused endpoint opt-in'
paused_url="http://${receiver_host}:${port}/${token}?responses=204"
configure_destination "${paused_url}" paused
assert_dispatch_suppressed "${paused_url}"
[[ ! -s "${receiver_log}" ]]

stage='success delivery'
success_url="http://${receiver_host}:${port}/${token}?responses=204"
configure_destination "${success_url}" active
dispatch_delivery "${success_url}"
success_delivery="${last_delivery_id}"
run_attempt "${success_delivery}" 1
[[ "$(delivery_state "${success_delivery}")" == 'succeeded|1|204|0|' ]]
[[ "$(receiver_record_count)" == '1' ]]

stage='retry delivery'
retry_url="http://${receiver_host}:${port}/${token}?responses=500,204"
configure_destination "${retry_url}" active
dispatch_delivery "${retry_url}"
retry_delivery="${last_delivery_id}"
run_attempt "${retry_delivery}" 1
[[ "$(delivery_state "${retry_delivery}")" == 'retrying|1|500|0|webhook_http_500' ]]
run_attempt "${retry_delivery}" 2
[[ "$(delivery_state "${retry_delivery}")" == 'succeeded|2|204|0|' ]]
[[ "$(receiver_record_count)" == '3' ]]

previous_retry_action="${last_action_id}"
[[ "${previous_retry_action}" =~ ^[1-9][0-9]*$ ]]
printf '%s' "${retry_delivery}" | wp_filtered eval '
    $deliveryId = absint(trim(stream_get_contents(STDIN)));
    if (!(new FChubMemberships\Integration\WebhookQueue())->schedule($deliveryId, 2, time())) {
        throw new RuntimeException("Unable to exercise webhook replay scheduling.");
    }
' >/dev/null
replay_action="$(action_id_for "${retry_delivery}" 2)"
if [[ "${replay_action}" != "${previous_retry_action}" ]]; then
    track_action "${replay_action}"
    force_delivery_due "${retry_delivery}"
    assert_only_runnable_action "${replay_action}" "fchub-memberships-webhooks-${retry_delivery}-a2"
    wp_filtered action-scheduler action run "${replay_action}" >/dev/null
    assert_action_complete "${replay_action}"
fi
retry_identity="$(printf '%s' "${retry_delivery}" | wp_filtered eval '
    global $wpdb;
    $id = absint(trim(stream_get_contents(STDIN)));
    $row = $wpdb->get_row($wpdb->prepare(
        "SELECT event_id, status, attempt_count
         FROM {$wpdb->prefix}fchub_membership_webhook_deliveries WHERE id = %d",
        $id
    ), ARRAY_A);
    $count = is_array($row) ? (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->prefix}fchub_membership_webhook_deliveries WHERE event_id = %s",
        $row["event_id"]
    )) : 0;
    echo $count . "|" . ($row["status"] ?? "") . "|" . (int) ($row["attempt_count"] ?? 0);
')"
[[ "${retry_identity}" == '1|succeeded|2' ]]

stage='terminal delivery'
failure_url="http://${receiver_host}:${port}/${token}?responses=400"
configure_destination "${failure_url}" active
dispatch_delivery "${failure_url}"
failure_delivery="${last_delivery_id}"
for attempt in 1 2 3 4 5 6; do
    run_attempt "${failure_delivery}" "${attempt}"
    [[ "$(delivery_state "${failure_delivery}")" == "retrying|${attempt}|400|2048|webhook_http_400" ]]
done
run_attempt "${failure_delivery}" 7
[[ "$(delivery_state "${failure_delivery}")" == 'failed|7|400|2048|webhook_http_400' ]]
[[ "$(receiver_record_count)" == '10' ]]

terminal_history="$(printf '%s' "${failure_delivery}" | wp_filtered eval '
    $id = absint(trim(stream_get_contents(STDIN)));
    wp_set_current_user(1);
    $request = new WP_REST_Request("GET", "/fchub-memberships/v1/admin/webhooks/deliveries");
    $request->set_param("page", 1);
    $request->set_param("per_page", 100);
    $request->set_param("status", "failed");
    $response = rest_do_request($request);
    $data = $response->get_data();
    $matches = array_values(array_filter(
        $data["data"]["deliveries"] ?? [],
        static fn(array $row): bool => (int) ($row["id"] ?? 0) === $id
            && ($row["status"] ?? "") === "failed"
    ));
    $forbidden = false;
    foreach ($matches as $row) {
        foreach (["body", "response_body", "payload", "signature", "webhook_secret"] as $key) {
            $forbidden = $forbidden || array_key_exists($key, $row);
        }
    }
    echo $response->get_status() . "|" . count($matches) . "|" . ($forbidden ? "unsafe" : "safe");
')"
[[ "${terminal_history}" == '200|1|safe' ]]

stage='manual retry eligibility'
manual_retry="$(printf '%s' "${failure_delivery}" | wp_filtered eval '
    $id = absint(trim(stream_get_contents(STDIN)));
    wp_set_current_user(1);
    $request = new WP_REST_Request(
        "POST",
        "/fchub-memberships/v1/admin/webhooks/deliveries/{$id}/retry"
    );
    $request->set_param("id", $id);
    $response = rest_do_request($request);
    $data = $response->get_data();
    echo $response->get_status() . "|" . (string) ($data["data"]["status"] ?? "");
')"
[[ "${manual_retry}" == '202|pending' ]]
manual_action="$(action_id_for "${failure_delivery}" 1)"
track_action "${manual_action}"

stage='scheduler and persistence secret audit'
printf '%s\n%s\n%s\n' \
    "${secret}" \
    "$(join_csv "${action_ids[@]-}")" \
    "$(join_csv "${delivery_ids[@]-}")" \
    | wp_filtered eval '
        global $wpdb;
        $input = file("php://stdin", FILE_IGNORE_NEW_LINES);
        $secret = (string) ($input[0] ?? "");
        $ids = array_values(array_filter(array_map("absint", explode(",", (string) ($input[1] ?? "")))));
        $deliveryIds = array_values(array_filter(array_map("absint", explode(",", (string) ($input[2] ?? "")))));
        foreach ($ids as $id) {
            $action = ActionScheduler::store()->fetch_action($id);
            $values = array_values($action->get_args());
            $logs = implode(" ", array_map(
                static fn(ActionScheduler_LogEntry $entry): string => $entry->get_message(),
                ActionScheduler::logger()->get_logs($id)
            ));
            if (count($values) !== 1 || !is_int($values[0]) || !in_array($values[0], $deliveryIds, true)) {
                throw new RuntimeException("Webhook action arguments are not the exact tracked delivery ID.");
            }
            if ($secret !== "" && (str_contains(wp_json_encode($values), $secret) || str_contains($logs, $secret))) {
                throw new RuntimeException("Webhook secret entered scheduler storage.");
            }
        }
        foreach ($deliveryIds as $deliveryId) {
            $row = $wpdb->get_row($wpdb->prepare(
                "SELECT event.body, delivery.destination_url, delivery.response_body, delivery.error_message
                 FROM {$wpdb->prefix}fchub_membership_webhook_deliveries delivery
                 INNER JOIN {$wpdb->prefix}fchub_membership_webhook_events event
                    ON event.event_id = delivery.event_id
                 WHERE delivery.id = %d",
                $deliveryId
            ), ARRAY_A);
            if (!is_array($row)) {
                throw new RuntimeException("Tracked webhook persistence is missing.");
            }
            foreach ($row as $value) {
                if ($secret !== "" && str_contains((string) $value, $secret)) {
                    throw new RuntimeException("Webhook secret entered durable event or delivery fields.");
                }
            }
        }
    ' >/dev/null

stage='receiver audit'
[[ "$(receiver_record_count)" == '10' ]]
kill -0 "${receiver_pid}"
printf '%s' "${secret}" | php -r '
    $fail = static function (string $reason): never {
        fwrite(STDERR, "Receiver audit failed: {$reason}\n");
        exit(1);
    };
    $secret = stream_get_contents(STDIN);
    $log = file_get_contents($argv[1]);
    $output = file_get_contents($argv[2]);
    if (!is_string($log) || !is_string($output)) {
        $fail("audit files unreadable");
    }
    if ($secret !== "" && (str_contains($log, $secret) || str_contains($output, $secret))) {
        $fail("shared secret present");
    }
    $lines = file($argv[1], FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!is_array($lines) || count($lines) !== 10) {
        $observed = ["204" => 0, "500,204" => 0, "400" => 0];
        foreach (is_array($lines) ? $lines : [] as $line) {
            $row = json_decode($line, true);
            $sequence = is_array($row) ? (string) ($row["response_sequence"] ?? "") : "";
            if (array_key_exists($sequence, $observed)) {
                $observed[$sequence]++;
            }
        }
        $fail(
            "unexpected record count " . (is_array($lines) ? count($lines) : -1)
            . " sequences " . json_encode($observed)
        );
    }
    if ((fileperms($argv[1]) & 0777) !== 0600) {
        $fail("audit file mode is not 0600");
    }
    $counts = ["204" => 0, "500,204" => 0, "400" => 0];
    $headerKeys = ["Content-Type", "X-FCHub-Event", "X-FCHub-Delivery", "X-FCHub-Timestamp", "X-FCHub-Signature"];
    foreach ($lines as $offset => $line) {
        $row = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
        $headers = is_array($row["headers"] ?? null) ? $row["headers"] : [];
        if (($row["ordinal"] ?? null) !== $offset + 1
            || !($row["signature_valid"] ?? false)
            || !($row["delivery_valid"] ?? false)
            || !($row["timestamp_valid"] ?? false)
            || !($row["event_type_valid"] ?? false)
            || preg_match("/^[a-f0-9]{64}$/", (string) ($row["body_sha256"] ?? "")) !== 1
            || (string) ($row["event_type"] ?? "") !== "grant_created"
            || array_keys($headers) !== $headerKeys
            || (string) ($headers["Content-Type"] ?? "") !== "application/json"
            || preg_match("/^[a-f0-9-]{36}$/i", (string) ($headers["X-FCHub-Delivery"] ?? "")) !== 1
            || preg_match("/^[a-f0-9]{64}$/", (string) ($headers["X-FCHub-Signature"] ?? "")) !== 1
        ) {
            $fail("invalid record at ordinal " . ($offset + 1));
        }
        $sequence = (string) ($row["response_sequence"] ?? "");
        if (!array_key_exists($sequence, $counts)) $fail("unexpected response sequence");
        $counts[$sequence]++;
    }
    if ($counts !== ["204" => 1, "500,204" => 2, "400" => 7]) {
        $fail("unexpected sequence counts " . json_encode($counts));
    }
' "${receiver_log}" "${receiver_output}"

stage='persisted body hash audit'
php -r 'echo base64_encode((string) file_get_contents($argv[1]));' "${receiver_log}" \
    | wp_filtered eval '
        global $wpdb;
        $decoded = base64_decode(trim(stream_get_contents(STDIN)), true);
        $lines = is_string($decoded)
            ? preg_split("/\\R/", trim($decoded), -1, PREG_SPLIT_NO_EMPTY)
            : false;
        if (!is_array($lines) || count($lines) !== 10) {
            throw new RuntimeException("Receiver audit could not be correlated to persistence.");
        }
        $seen = [];
        foreach ($lines as $line) {
            $record = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            $eventId = (string) ($record["event_id"] ?? "");
            $body = $wpdb->get_var($wpdb->prepare(
                "SELECT body FROM {$wpdb->prefix}fchub_membership_webhook_events WHERE event_id = %s",
                $eventId
            ));
            if (!is_string($body) || !hash_equals(hash("sha256", $body), (string) ($record["body_sha256"] ?? ""))) {
                throw new RuntimeException("Receiver body hash does not match the durable event body.");
            }
            $seen[$eventId] = true;
        }
        if (count($seen) !== 3) {
            throw new RuntimeException("Unexpected durable webhook event count.");
        }
        foreach (array_keys($seen) as $eventId) {
            $count = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}fchub_membership_webhook_deliveries WHERE event_id = %s",
                $eventId
            ));
            if ($count !== 1) {
                throw new RuntimeException("Durable webhook event does not have exactly one delivery row.");
            }
        }
    ' >/dev/null

stage='cleanup'
cleanup_runtime || exit 1
stage='cleanup verification'
[[ "$(snapshot_settings)" == "${settings_snapshot}" ]] || exit 1
[[ "$(runtime_snapshot)" == "${baseline}" ]] || exit 1
[[ "$(tracked_residue)" == '0' ]] || exit 1
[[ ! -e "${receiver_log}" ]] || exit 1
[[ ! -e "${receiver_output}" ]] || exit 1
if docker compose exec -T wpcli php -r 'exit(is_file($argv[1]) ? 0 : 1);' "${filter_path}" >/dev/null 2>&1; then
    exit 1
fi
if docker compose exec -T --user 0 wpcli php -r 'exit(is_file($argv[1]) ? 0 : 1);' "${queue_guard_path}" >/dev/null 2>&1; then
    exit 1
fi
cleanup_finished='yes'
release_runtime_lock
trap - EXIT

printf 'Webhook delivery smoke passed; settings, actions, rows, receiver, and temporary files were restored.\n'
